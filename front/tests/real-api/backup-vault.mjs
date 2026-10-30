import { createCipheriv, createDecipheriv, createHmac, hkdfSync, randomBytes } from 'node:crypto'
import { existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, realpathSync, renameSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { assertHarnessRoot } from './environment.mjs'
import { digest } from './recovery.mjs'

const magic = Buffer.from('UCGPITR2')
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/
const maxBytes = 256 * 1024 * 1024
const maxFileBytes = 32 * 1024 * 1024
const maxEntries = 4096

export function assertVaultOwner(lab) {
  assertHarnessRoot(lab.root)
  const owner = `${lab.root}/owner`
  if (!/^[a-f0-9]{64}$/.test(lab.token) || !lstatSync(owner).isFile() || lstatSync(owner).isSymbolicLink()
    || readFileSync(owner, 'utf8') !== lab.token) throw new Error('VAULT_OWNER_REFUSED')
}

function logicalPath(path) {
  if (typeof path !== 'string' || path.length > 512 || !/^(base|wal|private)(\/[A-Za-z0-9_.-]+)*$/.test(path)
    || path.split('/').some((part) => part === '.' || part === '..')) throw new Error('VAULT_PATH_REFUSED')
  return path
}

function directory(lab, path) {
  assertVaultOwner(lab)
  const relative = path.slice(lab.root.length + 1)
  if (!path.startsWith(`${lab.root}/`) || relative.split(/[\\/]/).some((part) => !/^[A-Za-z0-9_.-]+$/.test(part) || part === '..' || part === '.')) {
    throw new Error('VAULT_DIRECTORY_REFUSED')
  }
  let current = lab.root
  for (const part of relative.split('/')) {
    current += `/${part}`
    if (!existsSync(current)) mkdirSync(current, { mode: 0o700 })
    if (!lstatSync(current).isDirectory() || lstatSync(current).isSymbolicLink() || realpathSync(current) !== resolve(current)) throw new Error('VAULT_LINK_REFUSED')
  }
}

function regular(path, maximum = maxFileBytes) {
  const stat = lstatSync(path)
  if (!stat.isFile() || stat.isSymbolicLink() || stat.size > maximum || realpathSync(path) !== resolve(path)) throw new Error('VAULT_FILE_REFUSED')
  return readFileSync(path)
}

export function inventoryTree(lab, base, prefix) {
  assertVaultOwner(lab)
  if (realpathSync(base) !== resolve(base) || !base.startsWith(`${lab.root}/`)) throw new Error('VAULT_SOURCE_REFUSED')
  const files = []
  const directories = []
  let bytes = 0
  function visit(path, name) {
    logicalPath(name)
    const stat = lstatSync(path)
    if (stat.isSymbolicLink() || realpathSync(path) !== resolve(path)) throw new Error('VAULT_LINK_REFUSED')
    if (files.length + directories.length >= maxEntries) throw new Error('VAULT_BOUND_EXCEEDED')
    if (stat.isDirectory()) {
      directories.push(name)
      for (const child of readdirSync(path).sort()) visit(`${path}/${child}`, `${name}/${child}`)
    } else {
      const contents = regular(path)
      bytes += contents.length
      if (bytes > maxBytes) throw new Error('VAULT_BOUND_EXCEEDED')
      files.push({ path: name, contents })
    }
  }
  visit(base, prefix)
  return { files, directories }
}

function derived(key, id, label) {
  if (!Buffer.isBuffer(key) || key.length !== 32 || !uuid.test(id)) throw new Error('VAULT_KEY_REFUSED')
  return hkdfSync('sha256', key, Buffer.from(id), Buffer.from(`ucg-ops004:${label}`), 32)
}

function seal(bytes, key, aad) {
  const nonce = randomBytes(12)
  const cipher = createCipheriv('aes-256-gcm', key, nonce)
  cipher.setAAD(Buffer.from(aad))
  const ciphertext = Buffer.concat([cipher.update(bytes), cipher.final()])
  return Buffer.concat([magic, nonce, cipher.getAuthTag(), ciphertext])
}

function open(bytes, key, aad, maximum = maxFileBytes) {
  if (!Buffer.isBuffer(bytes) || bytes.length < 36 || bytes.length > maximum + 36 || !bytes.subarray(0, 8).equals(magic)) throw new Error('VAULT_ENVELOPE_REFUSED')
  try {
    const cipher = createDecipheriv('aes-256-gcm', key, bytes.subarray(8, 20))
    cipher.setAAD(Buffer.from(aad))
    cipher.setAuthTag(bytes.subarray(20, 36))
    return Buffer.concat([cipher.update(bytes.subarray(36)), cipher.final()])
  } catch { throw new Error('VAULT_AUTHENTICATION_FAILED') }
}

function validate(manifest) {
  if (manifest?.version !== 2 || !uuid.test(manifest.id) || !uuid.test(manifest.keyId)
    || !/^[a-f0-9]{64}$/.test(manifest.appKeyHash) || !/^[0-9]{10,25}$/.test(manifest.systemId)
    || !/^ucg_browser_[a-f0-9]{24}$/.test(manifest.database)
    || !/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?\+00$/.test(manifest.targetTime)
    || !Array.isArray(manifest.files) || !Array.isArray(manifest.directories)
    || manifest.files.length + manifest.directories.length > maxEntries) throw new Error('VAULT_MANIFEST_REFUSED')
  const paths = new Set()
  let bytes = 0
  for (const path of manifest.directories) {
    logicalPath(path)
    if (paths.has(path)) throw new Error('VAULT_DUPLICATE_PATH')
    paths.add(path)
  }
  for (const file of manifest.files) {
    logicalPath(file.path)
    if (paths.has(file.path) || !/^[a-f0-9]{64}$/.test(file.sha256) || !Number.isSafeInteger(file.bytes)
      || file.bytes < 0 || file.bytes > maxFileBytes) throw new Error('VAULT_MANIFEST_REFUSED')
    paths.add(file.path)
    bytes += file.bytes
  }
  if (bytes > maxBytes) throw new Error('VAULT_BOUND_EXCEEDED')
  return manifest
}

function aad(manifest, file) { return `${manifest.id}:${file.path}:${file.bytes}:${file.sha256}` }
function objectPath(lab, id, path) { return `${lab.root}/vault/${id}/objects/${digest(path)}.aes` }

export function stageVault(lab, key, metadata, inventory, { interruptAfter = Infinity } = {}) {
  assertVaultOwner(lab)
  const manifest = validate({ ...metadata, version: 2, directories: inventory.directories,
    files: inventory.files.map((file) => ({ path: file.path, bytes: file.contents.length, sha256: digest(file.contents) })) })
  const manifestBytes = Buffer.from(JSON.stringify(manifest))
  if (manifestBytes.length > 1024 * 1024) throw new Error('VAULT_MANIFEST_BOUND_EXCEEDED')
  directory(lab, `${lab.root}/vault/${manifest.id}/objects`)
  const objectKey = derived(key, manifest.id, 'objects')
  let count = 0
  let reused = 0
  for (const file of manifest.files) {
    if (count++ >= interruptAfter) throw new Error('VAULT_INTERRUPTED')
    const path = objectPath(lab, manifest.id, file.path)
    if (existsSync(path)) {
      const bytes = open(regular(path, maxFileBytes + 36), objectKey, aad(manifest, file))
      if (digest(bytes) !== file.sha256 || bytes.length !== file.bytes) throw new Error('VAULT_OBJECT_CONFLICT')
      reused++
      continue
    }
    const source = inventory.files.find((entry) => entry.path === file.path).contents
    writeFileSync(`${path}.part`, seal(source, objectKey, aad(manifest, file)), { flag: 'wx', mode: 0o600 })
    renameSync(`${path}.part`, path)
  }
  const manifestPath = `${lab.root}/vault/${manifest.id}/manifest.aes`
  if (existsSync(manifestPath)) {
    if (JSON.stringify(readVault(lab, key, manifest.id).manifest) !== JSON.stringify(manifest)) throw new Error('VAULT_MANIFEST_CONFLICT')
  } else {
    writeFileSync(`${manifestPath}.part`, seal(manifestBytes, derived(key, manifest.id, 'manifest'), manifest.id), { flag: 'wx', mode: 0o600 })
    renameSync(`${manifestPath}.part`, manifestPath)
  }
  return { reused, manifest }
}

export function readVault(lab, key, id, appKeyHash) {
  assertVaultOwner(lab)
  if (!uuid.test(id)) throw new Error('VAULT_ID_REFUSED')
  const root = `${lab.root}/vault/${id}`
  for (const path of [`${lab.root}/vault`, root, `${root}/objects`]) {
    if (!lstatSync(path).isDirectory() || lstatSync(path).isSymbolicLink() || realpathSync(path) !== resolve(path)) throw new Error('VAULT_LINK_REFUSED')
  }
  const manifest = validate(JSON.parse(open(regular(`${root}/manifest.aes`, 1024 * 1024), derived(key, id, 'manifest'), id, 1024 * 1024).toString()))
  if (manifest.id !== id || (appKeyHash !== undefined && manifest.appKeyHash !== appKeyHash)) throw new Error('VAULT_APPLICATION_KEY_REFUSED')
  const expected = new Set(manifest.files.map((file) => `${digest(file.path)}.aes`))
  if (readdirSync(`${root}/objects`).some((name) => !expected.has(name))) throw new Error('VAULT_UNEXPECTED_OBJECT')
  const files = manifest.files.map((file) => {
    const contents = open(regular(objectPath(lab, id, file.path), maxFileBytes + 36), derived(key, id, 'objects'), aad(manifest, file))
    if (contents.length !== file.bytes || digest(contents) !== file.sha256) throw new Error('VAULT_OBJECT_REFUSED')
    return { path: file.path, contents }
  })
  return { manifest, files }
}

export function copyVault(source, target, key, id) {
  const checked = readVault(source, key, id)
  assertVaultOwner(target)
  if (source.root === target.root || source.token === target.token) throw new Error('VAULT_COPY_NOT_SEPARATE')
  directory(target, `${target.root}/vault/${id}/objects`)
  let reused = 0
  for (const name of [...checked.manifest.files.map((file) => `objects/${digest(file.path)}.aes`), 'manifest.aes']) {
    const from = `${source.root}/vault/${id}/${name}`
    const to = `${target.root}/vault/${id}/${name}`
    const bytes = regular(from, maxFileBytes + 36)
    if (existsSync(to)) {
      if (digest(regular(to, maxFileBytes + 36)) !== digest(bytes)) throw new Error('VAULT_COPY_CONFLICT')
      reused++
    } else writeFileSync(to, bytes, { flag: 'wx', mode: 0o600 })
  }
  readVault(target, key, id)
  return { reused, verified: true }
}

export function retentionPlan(entries, { keepLast = 2, minimumAgeHours = 168, now = Date.now() } = {}) {
  if (!Array.isArray(entries) || entries.length > 1000 || !Number.isInteger(keepLast) || keepLast < 2
    || !Number.isInteger(minimumAgeHours) || minimumAgeHours < 24 || !Number.isFinite(now)) throw new Error('VAULT_RETENTION_POLICY_REFUSED')
  const sorted = entries.map((entry) => {
    if (!uuid.test(entry.id) || !Number.isFinite(Date.parse(entry.createdAt)) || typeof entry.hold !== 'boolean'
      || typeof entry.verified !== 'boolean' || !Number.isInteger(entry.verifiedCopies) || entry.verifiedCopies < 0) throw new Error('VAULT_CATALOG_REFUSED')
    return entry
  }).sort((a, b) => Date.parse(b.createdAt) - Date.parse(a.createdAt))
  if (new Set(sorted.map((entry) => entry.id)).size !== sorted.length) throw new Error('VAULT_CATALOG_REFUSED')
  return sorted.map((entry, index) => ({ id: entry.id, deletionAuthorized: false,
    reviewCandidate: index >= keepLast && entry.verified && entry.verifiedCopies >= 2 && !entry.hold
      && now - Date.parse(entry.createdAt) >= minimumAgeHours * 3600000 }))
}

export function signBackupEvidence(payload, key) {
  if (!Buffer.isBuffer(key) || key.length !== 32) throw new Error('BACKUP_EVIDENCE_KEY_REFUSED')
  const serialized = JSON.stringify(payload)
  return { payload: serialized, signature: createHmac('sha256', key).update(serialized).digest('hex') }
}
