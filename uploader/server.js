'use strict'

/*
 * Viscous Wiki — video uploader
 *
 * Serves an upload/management page at BASE_PATH (default /upload) and issues
 * presigned R2 PUT URLs so the browser uploads large files DIRECTLY to R2 —
 * bypassing the Cloudflare 100 MB proxy cap and never touching this server
 * or the wiki.
 *
 * Access is gated by Wiki.js group membership: the browser sends the Wiki.js
 * `jwt` cookie (same-origin, since we live under viscous.wiki/upload), we
 * verify it with Wiki.js's own public signing key, and only act if the user
 * is in the configured group (default "Video Uploaders").
 *
 * Also: tracks total storage used vs. a quota (default 10 GB free tier),
 * refuses uploads that would exceed it, and lets users delete files.
 */

const fs = require('fs')
const path = require('path')
const crypto = require('crypto')
const express = require('express')
const cookieParser = require('cookie-parser')
const jwt = require('jsonwebtoken')
const { Pool } = require('pg')
const {
  S3Client, PutObjectCommand, ListObjectsV2Command, DeleteObjectCommand
} = require('@aws-sdk/client-s3')
const { getSignedUrl } = require('@aws-sdk/s3-request-presigner')

// ---- Config -------------------------------------------------------------
const PORT = parseInt(process.env.PORT || '8080', 10)
const BASE_PATH = (process.env.BASE_PATH || '/upload').replace(/\/+$/, '')
const UPLOAD_GROUP = process.env.UPLOAD_GROUP || 'Video Uploaders'
const WIKI_AUDIENCE = process.env.WIKI_AUDIENCE || 'urn:wiki.js'
const WIKI_ISSUER = process.env.WIKI_ISSUER || 'urn:wiki.js'

const R2_ACCOUNT_ID = process.env.R2_ACCOUNT_ID
const R2_ACCESS_KEY_ID = process.env.R2_ACCESS_KEY_ID
const R2_SECRET_ACCESS_KEY = process.env.R2_SECRET_ACCESS_KEY
const R2_BUCKET = process.env.R2_BUCKET || 'viscous-media'
const R2_PUBLIC_BASE = (process.env.R2_PUBLIC_BASE || 'https://media.viscous.wiki').replace(/\/+$/, '')
const R2_PREFIX = 'uploads/'

// Free tier is 10 GB. Default cap is a hair under 10e9*10 for a safety margin.
const QUOTA_BYTES = parseInt(process.env.R2_QUOTA_BYTES || '10000000000', 10) // 10 GB (decimal)
const MAX_UPLOAD_BYTES = parseInt(process.env.MAX_UPLOAD_BYTES || '5368709120', 10) // 5 GiB per file
const PRESIGN_TTL = 900 // seconds (15 min)
const ALLOWED_TYPE = /^(video|image|audio)\//

// ---- Wiki.js auth material (loaded from the shared Postgres at startup) --
const pool = new Pool({
  host: process.env.PGHOST || 'db',
  port: parseInt(process.env.PGPORT || '5432', 10),
  user: process.env.PGUSER,
  password: process.env.PGPASSWORD,
  database: process.env.PGDATABASE
})

let PUBLIC_KEY = null
let GROUP_ID = null

async function loadWikiAuthConfig () {
  const certRes = await pool.query("SELECT value FROM settings WHERE key = 'certs'")
  if (!certRes.rows.length) throw new Error('Wiki.js certs not found — has the wiki setup wizard completed?')
  let certVal = certRes.rows[0].value
  if (typeof certVal === 'string') certVal = JSON.parse(certVal)
  PUBLIC_KEY = certVal.public
  if (!PUBLIC_KEY) throw new Error('No public key found in settings.certs')

  const grpRes = await pool.query('SELECT id FROM groups WHERE name = $1', [UPLOAD_GROUP])
  if (!grpRes.rows.length) throw new Error(`Wiki.js group "${UPLOAD_GROUP}" not found — create it in the admin panel.`)
  GROUP_ID = grpRes.rows[0].id
  console.log(`[uploader] auth ready — group "${UPLOAD_GROUP}" = id ${GROUP_ID}`)
}

// ---- R2 (S3-compatible) client ------------------------------------------
const s3 = new S3Client({
  region: 'auto',
  endpoint: `https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com`,
  forcePathStyle: true,
  credentials: {
    accessKeyId: R2_ACCESS_KEY_ID || '',
    secretAccessKey: R2_SECRET_ACCESS_KEY || ''
  }
})

function hasR2 () {
  return !!(R2_ACCOUNT_ID && R2_ACCESS_KEY_ID && R2_SECRET_ACCESS_KEY)
}

async function listObjects () {
  const objects = []
  let token
  do {
    const out = await s3.send(new ListObjectsV2Command({
      Bucket: R2_BUCKET, Prefix: R2_PREFIX, ContinuationToken: token
    }))
    for (const o of out.Contents || []) {
      objects.push({ key: o.Key, size: o.Size || 0, lastModified: o.LastModified })
    }
    token = out.IsTruncated ? out.NextContinuationToken : undefined
  } while (token)
  return objects
}

async function computeUsage () {
  const objects = await listObjects()
  const usedBytes = objects.reduce((sum, o) => sum + o.size, 0)
  return { usedBytes, count: objects.length, objects }
}

function fmtBytes (n) {
  if (n < 1e6) return (n / 1e3).toFixed(0) + ' KB'
  if (n < 1e9) return (n / 1e6).toFixed(1) + ' MB'
  return (n / 1e9).toFixed(2) + ' GB'
}

function displayName (key) {
  // strip "uploads/<uuid>-" prefix for a friendly label
  return key.replace(R2_PREFIX, '').replace(/^[0-9a-f-]{36}-/i, '')
}

// ---- Auth ---------------------------------------------------------------
function getUser (req) {
  const token = (req.cookies && req.cookies.jwt) ||
    (req.headers.authorization && req.headers.authorization.replace(/^Bearer\s+/i, ''))
  if (!token) return null
  try {
    return jwt.verify(token, PUBLIC_KEY, {
      algorithms: ['RS256'], audience: WIKI_AUDIENCE, issuer: WIKI_ISSUER
    })
  } catch (e) {
    return null
  }
}

function isAuthorized (user) {
  return !!(user && Array.isArray(user.groups) && user.groups.includes(GROUP_ID))
}

function requireUploader (req, res, next) {
  const user = getUser(req)
  if (!user) return res.status(401).json({ error: 'You are not logged in to the wiki. Log in at viscous.wiki first.' })
  if (!isAuthorized(user)) return res.status(403).json({ error: `You must be in the "${UPLOAD_GROUP}" group to do this.` })
  if (!hasR2()) return res.status(503).json({ error: 'Uploader is not fully configured (missing R2 credentials).' })
  req.user = user
  next()
}

function sanitizeName (name) {
  const base = String(name || 'file').split(/[\\/]/).pop()
  const cleaned = base.replace(/[^\w.\-]+/g, '_').replace(/_{2,}/g, '_').replace(/^_+|_+$/g, '')
  return (cleaned || 'file').slice(0, 100)
}

// ---- App ----------------------------------------------------------------
const app = express()
app.set('trust proxy', true)
app.use(cookieParser())
app.use(express.json({ limit: '64kb' }))

const router = express.Router()

router.get('/healthz', (req, res) => res.json({ ok: true }))

router.get('/api/me', (req, res) => {
  const user = getUser(req)
  if (!user) return res.json({ authenticated: false, authorized: false, group: UPLOAD_GROUP })
  res.json({
    authenticated: true,
    authorized: isAuthorized(user),
    name: user.name,
    email: user.email,
    group: UPLOAD_GROUP
  })
})

// Storage usage + file library
router.get('/api/storage', requireUploader, async (req, res) => {
  try {
    const { usedBytes, count, objects } = await computeUsage()
    const files = objects
      .map(o => ({
        key: o.key,
        name: displayName(o.key),
        size: o.size,
        lastModified: o.lastModified,
        url: `${R2_PUBLIC_BASE}/${o.key}`
      }))
      .sort((a, b) => new Date(b.lastModified) - new Date(a.lastModified))
    res.json({ usedBytes, quotaBytes: QUOTA_BYTES, count, files })
  } catch (e) {
    console.error('[uploader] storage error:', e.message)
    res.status(500).json({ error: 'Failed to read storage.' })
  }
})

// Mint a presigned upload URL (after a quota check)
router.post('/api/presign', requireUploader, async (req, res) => {
  const { filename, contentType, size } = req.body || {}
  if (!filename || !contentType) return res.status(400).json({ error: 'filename and contentType are required.' })
  if (!ALLOWED_TYPE.test(contentType)) return res.status(400).json({ error: 'Only video, image, or audio files are allowed.' })
  const fileSize = Number(size)
  if (!fileSize || fileSize < 1) return res.status(400).json({ error: 'A valid file size is required.' })
  if (fileSize > MAX_UPLOAD_BYTES) {
    return res.status(413).json({ error: `File is too large. Max per file is ${(MAX_UPLOAD_BYTES / 1e9).toFixed(1)} GB.` })
  }

  try {
    const { usedBytes } = await computeUsage()
    if (usedBytes + fileSize > QUOTA_BYTES) {
      const free = Math.max(0, QUOTA_BYTES - usedBytes)
      return res.status(507).json({
        error: `Not enough free storage. ${fmtBytes(usedBytes)} of ${fmtBytes(QUOTA_BYTES)} used — only ${fmtBytes(free)} free, but this file needs ${fmtBytes(fileSize)}. Delete something first.`,
        usedBytes, quotaBytes: QUOTA_BYTES
      })
    }

    const key = `${R2_PREFIX}${crypto.randomUUID()}-${sanitizeName(filename)}`
    const cmd = new PutObjectCommand({ Bucket: R2_BUCKET, Key: key, ContentType: contentType })
    const uploadUrl = await getSignedUrl(s3, cmd, { expiresIn: PRESIGN_TTL })
    res.json({
      uploadUrl,
      publicUrl: `${R2_PUBLIC_BASE}/${key}`,
      key,
      contentType,
      method: 'PUT',
      expiresIn: PRESIGN_TTL
    })
  } catch (e) {
    console.error('[uploader] presign error:', e.message)
    res.status(500).json({ error: 'Failed to create upload URL.' })
  }
})

// Delete a file
router.post('/api/delete', requireUploader, async (req, res) => {
  const { key } = req.body || {}
  if (!key || !key.startsWith(R2_PREFIX) || key.includes('..')) {
    return res.status(400).json({ error: 'Invalid key.' })
  }
  try {
    await s3.send(new DeleteObjectCommand({ Bucket: R2_BUCKET, Key: key }))
    res.json({ ok: true })
  } catch (e) {
    console.error('[uploader] delete error:', e.message)
    res.status(500).json({ error: 'Failed to delete file.' })
  }
})

// The management page (BASE_PATH is injected so front-end calls hit the right paths)
let PAGE_TEMPLATE = null
function renderPage () {
  if (PAGE_TEMPLATE === null) {
    PAGE_TEMPLATE = fs.readFileSync(path.join(__dirname, 'public', 'index.html'), 'utf8')
  }
  return PAGE_TEMPLATE.replace(/__BASE_PATH__/g, BASE_PATH)
}
router.get('/', (req, res) => res.type('html').send(renderPage()))

app.use(BASE_PATH, router)
app.get('/healthz', (req, res) => res.json({ ok: true }))

// ---- Start --------------------------------------------------------------
loadWikiAuthConfig()
  .then(() => app.listen(PORT, () => console.log(`[uploader] listening on :${PORT} at ${BASE_PATH} (quota ${fmtBytes(QUOTA_BYTES)})`)))
  .catch(err => { console.error('[uploader] failed to start:', err.message); process.exit(1) })
