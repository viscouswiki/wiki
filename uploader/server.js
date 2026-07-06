'use strict'

/*
 * Viscous Wiki — media uploader
 *
 * Serves an upload/management page at BASE_PATH (default /upload) and issues
 * presigned R2 PUT URLs so the browser uploads large files DIRECTLY to R2 —
 * bypassing the Cloudflare 100 MB proxy cap and never touching this server
 * or the wiki.
 *
 * Access is gated by MediaWiki group membership. Because the uploader lives
 * under viscous.wiki/upload (same origin as the wiki), the visitor's MediaWiki
 * session cookie is sent here; we forward it to MediaWiki's API
 * (meta=userinfo&uiprop=groups) and only act if the user is logged in AND in
 * the configured group (default "videouploader"). No shared secret needed —
 * MediaWiki is the source of truth.
 *
 * Also: tracks total storage used vs. a quota (default 10 GB free tier),
 * refuses uploads that would exceed it, and lets users delete files.
 */

const fs = require('fs')
const path = require('path')
const crypto = require('crypto')
const express = require('express')
const {
  S3Client, PutObjectCommand, ListObjectsV2Command, DeleteObjectCommand
} = require('@aws-sdk/client-s3')
const { getSignedUrl } = require('@aws-sdk/s3-request-presigner')

// ---- Config -------------------------------------------------------------
const PORT = parseInt(process.env.PORT || '8080', 10)
const BASE_PATH = (process.env.BASE_PATH || '/upload').replace(/\/+$/, '')
const UPLOAD_GROUP = process.env.UPLOAD_GROUP || 'videouploader'
const MEDIAWIKI_API_URL = process.env.MEDIAWIKI_API_URL || 'http://mediawiki/api.php'

const R2_ACCOUNT_ID = process.env.R2_ACCOUNT_ID
const R2_ACCESS_KEY_ID = process.env.R2_ACCESS_KEY_ID
const R2_SECRET_ACCESS_KEY = process.env.R2_SECRET_ACCESS_KEY
const R2_BUCKET = process.env.R2_BUCKET || 'viscous-media'
const R2_PUBLIC_BASE = (process.env.R2_PUBLIC_BASE || 'https://media.viscous.wiki').replace(/\/+$/, '')
const R2_PREFIX = 'uploads/'

// Free tier is 10 GB.
const QUOTA_BYTES = parseInt(process.env.R2_QUOTA_BYTES || '10000000000', 10) // 10 GB (decimal)
const MAX_UPLOAD_BYTES = parseInt(process.env.MAX_UPLOAD_BYTES || '5368709120', 10) // 5 GiB per file
const PRESIGN_TTL = 900 // seconds (15 min)
const ALLOWED_TYPE = /^(video|image|audio)\//

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

// ---- Auth: ask MediaWiki who this cookie belongs to ---------------------
async function getUser (req) {
  const cookie = req.headers.cookie
  if (!cookie) return null
  try {
    const url = `${MEDIAWIKI_API_URL}?action=query&meta=userinfo&uiprop=groups&format=json`
    const r = await fetch(url, {
      headers: { cookie, 'user-agent': 'viscous-uploader' }
    })
    if (!r.ok) return null
    const data = await r.json()
    const info = data && data.query && data.query.userinfo
    // Anonymous users come back with id 0 and an `anon` marker.
    if (!info || !info.id || info.anon !== undefined) return null
    return {
      id: info.id,
      name: info.name,
      groups: Array.isArray(info.groups) ? info.groups : []
    }
  } catch (e) {
    console.error('[uploader] MediaWiki auth check failed:', e.message)
    return null
  }
}

function isAuthorized (user) {
  return !!(user && Array.isArray(user.groups) && user.groups.includes(UPLOAD_GROUP))
}

async function requireUploader (req, res, next) {
  try {
    const user = await getUser(req)
    if (!user) return res.status(401).json({ error: 'You are not logged in to the wiki. Log in at viscous.wiki first.' })
    if (!isAuthorized(user)) return res.status(403).json({ error: `You must be in the "${UPLOAD_GROUP}" group to do this.` })
    if (!hasR2()) return res.status(503).json({ error: 'Uploader is not fully configured (missing R2 credentials).' })
    req.user = user
    next()
  } catch (e) {
    console.error('[uploader] auth error:', e.message)
    res.status(500).json({ error: 'Auth check failed.' })
  }
}

function sanitizeName (name) {
  const base = String(name || 'file').split(/[\\/]/).pop()
  const cleaned = base.replace(/[^\w.\-]+/g, '_').replace(/_{2,}/g, '_').replace(/^_+|_+$/g, '')
  return (cleaned || 'file').slice(0, 100)
}

// ---- App ----------------------------------------------------------------
const app = express()
app.set('trust proxy', true)
app.use(express.json({ limit: '64kb' }))

const router = express.Router()

router.get('/healthz', (req, res) => res.json({ ok: true }))

router.get('/api/me', async (req, res) => {
  const user = await getUser(req)
  if (!user) return res.json({ authenticated: false, authorized: false, group: UPLOAD_GROUP })
  res.json({
    authenticated: true,
    authorized: isAuthorized(user),
    name: user.name,
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

app.listen(PORT, () => console.log(`[uploader] listening on :${PORT} at ${BASE_PATH} (auth via ${MEDIAWIKI_API_URL}, group "${UPLOAD_GROUP}", quota ${fmtBytes(QUOTA_BYTES)})`))
