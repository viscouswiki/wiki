'use strict'

/*
 * Viscous Wiki — Discord reader bot
 *
 * One process, two jobs:
 *   1. A discord.js bot logged into your server (guild) that can read channels,
 *      forum channels, and threads — in particular the "meta-builds" category,
 *      where each thread is a different build.
 *   2. An Express admin UI (at BASE_PATH, default /discord) — behind HTTP Basic
 *      auth for now — where you pick WHICH channels/categories the bot should
 *      read. The selection is persisted to DATA_DIR/selection.json.
 *
 * The read content is exposed as JSON (GET <BASE_PATH>/api/content) so it can
 * later be turned into wiki pages. Nothing is written to Discord — read only.
 *
 * Required Discord setup:
 *   - A bot application + token (Discord Developer Portal).
 *   - The bot invited to your server with "Read Messages/View Channels" and
 *     "Read Message History" permissions.
 *   - The "MESSAGE CONTENT INTENT" enabled (Developer Portal > Bot > Privileged
 *     Gateway Intents) so message/thread text is readable.
 */

const fs = require('fs')
const path = require('path')
const crypto = require('crypto')
const express = require('express')
const {
  Client, GatewayIntentBits, Partials, ChannelType
} = require('discord.js')

// ---- Config -------------------------------------------------------------
const PORT = parseInt(process.env.PORT || '8080', 10)
const BASE_PATH = (process.env.BASE_PATH || '/discord').replace(/\/+$/, '')
const DATA_DIR = process.env.DATA_DIR || '/data'
const SELECTION_FILE = path.join(DATA_DIR, 'selection.json')

const BOT_TOKEN = process.env.DISCORD_BOT_TOKEN || ''
const GUILD_ID = process.env.DISCORD_GUILD_ID || '' // optional; else first guild
const UI_USER = process.env.DISCORD_UI_USER || 'admin'
const UI_PASSWORD = process.env.DISCORD_UI_PASSWORD || ''

// How many threads/messages to pull per read (keeps things responsive).
const MAX_THREADS = parseInt(process.env.MAX_THREADS || '100', 10)
const MAX_MESSAGES = parseInt(process.env.MAX_MESSAGES || '50', 10)
const EXCERPT_LEN = 600

// ---- Selection persistence ----------------------------------------------
function loadSelection () {
  try {
    const raw = fs.readFileSync(SELECTION_FILE, 'utf8')
    const data = JSON.parse(raw)
    return Array.isArray(data.channelIds) ? data.channelIds : []
  } catch {
    return []
  }
}

function saveSelection (channelIds) {
  fs.mkdirSync(DATA_DIR, { recursive: true })
  const clean = [...new Set((channelIds || []).map(String))]
  fs.writeFileSync(SELECTION_FILE, JSON.stringify({ channelIds: clean }, null, 2))
  return clean
}

// ---- Discord client -----------------------------------------------------
const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildMessages,
    GatewayIntentBits.MessageContent
  ],
  partials: [Partials.Channel]
})

let botReady = false

client.once('ready', () => {
  botReady = true
  console.log(`[discord-bot] logged in as ${client.user.tag}`)
})

client.on('error', (e) => console.error('[discord-bot] client error:', e.message))

function getGuild () {
  if (!botReady) return null
  if (GUILD_ID) return client.guilds.cache.get(GUILD_ID) || null
  return client.guilds.cache.first() || null
}

// Return the guild's readable structure for the picker: categories with their
// child channels, plus any top-level (uncategorised) channels.
async function buildTree (guild) {
  await guild.channels.fetch() // populate cache
  const all = [...guild.channels.cache.values()]

  const isReadable = (c) => [
    ChannelType.GuildText,
    ChannelType.GuildAnnouncement,
    ChannelType.GuildForum,
    ChannelType.GuildMedia
  ].includes(c.type)

  const describe = (c) => ({
    id: c.id,
    name: c.name,
    type: c.type,
    isForum: c.type === ChannelType.GuildForum || c.type === ChannelType.GuildMedia,
    position: c.rawPosition ?? c.position ?? 0
  })

  const categories = all
    .filter(c => c.type === ChannelType.GuildCategory)
    .sort((a, b) => (a.rawPosition ?? 0) - (b.rawPosition ?? 0))
    .map(cat => ({
      id: cat.id,
      name: cat.name,
      channels: all
        .filter(c => c.parentId === cat.id && isReadable(c))
        .sort((a, b) => (a.rawPosition ?? 0) - (b.rawPosition ?? 0))
        .map(describe)
    }))

  const uncategorised = all
    .filter(c => !c.parentId && isReadable(c))
    .sort((a, b) => (a.rawPosition ?? 0) - (b.rawPosition ?? 0))
    .map(describe)

  return {
    guild: { id: guild.id, name: guild.name },
    categories,
    uncategorised
  }
}

// ---- Reading helpers ----------------------------------------------------
function tagName (parent, id) {
  const t = parent && parent.availableTags && parent.availableTags.find(x => x.id === id)
  return t ? t.name : null
}

async function fetchThreads (channel) {
  const threads = []
  try {
    const active = await channel.threads.fetchActive()
    for (const t of active.threads.values()) threads.push(t)
  } catch (e) { console.error('[discord-bot] fetchActive:', e.message) }
  try {
    const archived = await channel.threads.fetchArchived({ limit: MAX_THREADS })
    for (const t of archived.threads.values()) threads.push(t)
  } catch (e) { /* needs ReadMessageHistory; ignore if unavailable */ }
  return threads.slice(0, MAX_THREADS)
}

async function describeThread (thread) {
  let excerpt = ''
  let author = null
  try {
    const starter = await thread.fetchStarterMessage()
    if (starter) {
      excerpt = (starter.content || '').slice(0, EXCERPT_LEN)
      if (starter.author) author = { id: starter.author.id, name: starter.author.username }
    }
  } catch { /* starter may be deleted / inaccessible */ }
  const tags = (thread.appliedTags || []).map(id => tagName(thread.parent, id)).filter(Boolean)
  return {
    id: thread.id,
    name: thread.name,
    url: `https://discord.com/channels/${thread.guildId}/${thread.id}`,
    archived: !!thread.archived,
    messageCount: thread.messageCount ?? null,
    createdTimestamp: thread.createdTimestamp ?? null,
    tags,
    author,
    excerpt
  }
}

async function readMessages (channel, limit = MAX_MESSAGES) {
  const out = await channel.messages.fetch({ limit })
  return [...out.values()]
    .sort((a, b) => a.createdTimestamp - b.createdTimestamp)
    .map(m => ({
      id: m.id,
      author: m.author ? m.author.username : null,
      content: m.content || '',
      createdTimestamp: m.createdTimestamp,
      attachments: [...m.attachments.values()].map(a => ({ name: a.name, url: a.url }))
    }))
}

// Read a single selected channel by id and return structured content.
async function readSelected (guild, id) {
  const channel = guild.channels.cache.get(id) || await guild.channels.fetch(id).catch(() => null)
  if (!channel) return { id, error: 'Channel not found or not accessible.' }

  // Category: read every readable child (forums -> threads, text -> messages).
  if (channel.type === ChannelType.GuildCategory) {
    const children = [...guild.channels.cache.values()].filter(c => c.parentId === channel.id)
    const parts = []
    for (const child of children) {
      parts.push(await readSelected(guild, child.id))
    }
    return { id: channel.id, name: channel.name, kind: 'category', children: parts }
  }

  // Forum / media channel: each thread is a "build" post.
  if (channel.type === ChannelType.GuildForum || channel.type === ChannelType.GuildMedia) {
    const threads = await fetchThreads(channel)
    const described = []
    for (const t of threads) described.push(await describeThread(t))
    described.sort((a, b) => (b.createdTimestamp || 0) - (a.createdTimestamp || 0))
    return { id: channel.id, name: channel.name, kind: 'forum', threadCount: described.length, threads: described }
  }

  // A thread itself.
  if (channel.isThread && channel.isThread()) {
    return {
      id: channel.id,
      name: channel.name,
      kind: 'thread',
      messages: await readMessages(channel).catch(() => [])
    }
  }

  // Plain text / announcement channel: recent messages + any threads it has.
  const messages = await readMessages(channel).catch(() => [])
  let threads = []
  try {
    const t = await fetchThreads(channel)
    threads = []
    for (const th of t) threads.push(await describeThread(th))
  } catch { /* no threads */ }
  return { id: channel.id, name: channel.name, kind: 'text', messages, threads }
}

// ---- Express app --------------------------------------------------------
const app = express()
app.set('trust proxy', true)
app.use(express.json({ limit: '64kb' }))

// HTTP Basic auth (placeholder auth "for now").
function basicAuth (req, res, next) {
  if (!UI_PASSWORD) {
    return res.status(503).send('Admin UI auth is not configured (set DISCORD_UI_PASSWORD).')
  }
  const hdr = req.headers.authorization || ''
  const [scheme, encoded] = hdr.split(' ')
  if (scheme === 'Basic' && encoded) {
    const decoded = Buffer.from(encoded, 'base64').toString('utf8')
    const idx = decoded.indexOf(':')
    const user = decoded.slice(0, idx)
    const pass = decoded.slice(idx + 1)
    if (safeEqual(user, UI_USER) && safeEqual(pass, UI_PASSWORD)) return next()
  }
  res.set('WWW-Authenticate', 'Basic realm="Viscous Discord Bot", charset="UTF-8"')
  return res.status(401).send('Authentication required.')
}

function safeEqual (a, b) {
  const ab = Buffer.from(String(a))
  const bb = Buffer.from(String(b))
  if (ab.length !== bb.length) return false
  return crypto.timingSafeEqual(ab, bb)
}

const router = express.Router()

// Liveness (no auth) — handy for container health checks.
router.get('/healthz', (req, res) => res.json({ ok: true, botReady }))

// Everything below requires auth.
router.use(basicAuth)

router.get('/api/status', (req, res) => {
  const guild = getGuild()
  res.json({
    botReady,
    user: botReady ? client.user.tag : null,
    guild: guild ? { id: guild.id, name: guild.name } : null,
    selectedCount: loadSelection().length
  })
})

router.get('/api/tree', async (req, res) => {
  const guild = getGuild()
  if (!guild) return res.status(503).json({ error: 'Bot not ready or not in any guild yet.' })
  try {
    res.json(await buildTree(guild))
  } catch (e) {
    console.error('[discord-bot] tree error:', e.message)
    res.status(500).json({ error: 'Failed to read channel list: ' + e.message })
  }
})

router.get('/api/selection', (req, res) => res.json({ channelIds: loadSelection() }))

router.put('/api/selection', (req, res) => {
  const ids = (req.body && req.body.channelIds) || []
  if (!Array.isArray(ids)) return res.status(400).json({ error: 'channelIds must be an array.' })
  res.json({ channelIds: saveSelection(ids) })
})

// Read the currently-selected channels and return their content.
router.get('/api/content', async (req, res) => {
  const guild = getGuild()
  if (!guild) return res.status(503).json({ error: 'Bot not ready or not in any guild yet.' })
  const ids = loadSelection()
  if (!ids.length) return res.json({ channels: [], note: 'No channels selected yet.' })
  try {
    await guild.channels.fetch()
    const channels = []
    for (const id of ids) channels.push(await readSelected(guild, id))
    res.json({ channels })
  } catch (e) {
    console.error('[discord-bot] content error:', e.message)
    res.status(500).json({ error: 'Failed to read content: ' + e.message })
  }
})

// The admin page (BASE_PATH is injected so client calls hit the right paths).
let PAGE_TEMPLATE = null
function renderPage () {
  if (PAGE_TEMPLATE === null) {
    PAGE_TEMPLATE = fs.readFileSync(path.join(__dirname, 'public', 'index.html'), 'utf8')
  }
  return PAGE_TEMPLATE.replace(/__BASE_PATH__/g, BASE_PATH)
}
router.get('/', (req, res) => res.type('html').send(renderPage()))

app.use(BASE_PATH, router)
app.get('/healthz', (req, res) => res.json({ ok: true, botReady }))

app.listen(PORT, () => {
  console.log(`[discord-bot] web UI on :${PORT} at ${BASE_PATH}`)
})

// ---- Boot ---------------------------------------------------------------
if (!BOT_TOKEN) {
  console.error('[discord-bot] DISCORD_BOT_TOKEN is not set — the web UI will run but the bot cannot log in.')
} else {
  client.login(BOT_TOKEN).catch(e => console.error('[discord-bot] login failed:', e.message))
}
