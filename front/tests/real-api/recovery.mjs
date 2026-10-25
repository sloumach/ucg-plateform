import { createCipheriv, createDecipheriv, createHash, randomBytes } from 'node:crypto'
import { lstatSync, readFileSync, readdirSync, realpathSync } from 'node:fs'
import { resolve } from 'node:path'
import { assertHarnessRoot } from './environment.mjs'

// A bounded fixture format, not a production backup format or an external restore API.
const magic = Buffer.from('UCGOPS04')
const maxBytes = 32 * 1024 * 1024
const uuid = '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}'
const artifactPath = new RegExp(`^tenants/${uuid}/artifacts/${uuid}$`)
export const digest = (value) => createHash('sha256').update(value).digest('hex')

export function assertRecoveryRoot(root, token) {
  assertHarnessRoot(root)
  if (!/^[a-f0-9]{64}$/.test(token) || lstatSync(`${root}/owner`).isSymbolicLink()
    || readFileSync(`${root}/owner`, 'utf8') !== token) throw new Error('Recovery ownership refused.')
  for (const relative of ['storage', 'storage/app', 'storage/app/private']) {
    const path = resolve(root, relative)
    if (!lstatSync(path).isDirectory() || realpathSync(path) !== path) throw new Error('Recovery storage must be an owned canonical directory.')
  }
}

export function privateFiles(root, token) {
  assertRecoveryRoot(root, token)
  const base = `${root}/storage/app/private`
  const files = []
  let totalBytes = 0
  function visit(directory, prefix = '') {
    if (prefix.length > 512) throw new Error('Recovery fixture path refused.')
    for (const name of readdirSync(directory).sort()) {
      const path = `${directory}/${name}`
      const relative = `${prefix}${name}`
      const entry = lstatSync(path)
      if (entry.isSymbolicLink()) throw new Error('Recovery refuses symbolic links.')
      if (entry.isDirectory()) visit(path, `${relative}/`)
      else {
        totalBytes += entry.size
        if (!entry.isFile() || relative.trim() !== relative || !artifactPath.test(relative) || totalBytes > maxBytes || files.length >= 100) throw new Error('Recovery fixture file refused.')
        const contents = readFileSync(path)
        files.push({ path: relative, sha256: digest(contents), contents: contents.toString('base64') })
      }
    }
  }
  visit(base)
  return files
}

export function validateSnapshot(snapshot, appKey) {
  if (snapshot?.version !== 1 || snapshot.appKeyHash !== digest(appKey)
    || !Array.isArray(snapshot.files) || snapshot.files.length > 100
    || typeof snapshot.dump !== 'string' || snapshot.dump.length > maxBytes * 2) throw new Error('Recovery snapshot or application key refused.')
  const dump = Buffer.from(snapshot.dump, 'base64')
  if (dump.length > maxBytes || dump.subarray(0, 5).toString() !== 'PGDMP' || digest(dump) !== snapshot.dumpHash) throw new Error('Recovery dump integrity refused.')
  const paths = new Set()
  let bytes = dump.length
  for (const file of snapshot.files) {
    if (typeof file?.path !== 'string' || file.path.trim() !== file.path || !artifactPath.test(file.path) || paths.has(file.path)
      || typeof file.contents !== 'string' || file.contents.length > maxBytes * 2) throw new Error('Recovery fixture path refused.')
    paths.add(file.path)
    const contents = Buffer.from(file.contents, 'base64')
    bytes += contents.length
    if (bytes > maxBytes || digest(contents) !== file.sha256) throw new Error('Recovery file integrity refused.')
  }
  return snapshot
}

export function sealSnapshot(snapshot, key, appKey) {
  validateSnapshot(snapshot, appKey)
  const plaintext = Buffer.from(JSON.stringify(snapshot))
  if (plaintext.length > maxBytes) throw new Error('Recovery fixture exceeds its memory bound.')
  const iv = randomBytes(12)
  const cipher = createCipheriv('aes-256-gcm', key, iv)
  cipher.setAAD(magic)
  const ciphertext = Buffer.concat([cipher.update(plaintext), cipher.final()])
  return Buffer.concat([magic, iv, cipher.getAuthTag(), ciphertext])
}

export function openSnapshot(envelope, key, appKey) {
  if (!Buffer.isBuffer(envelope) || envelope.length < 37 || envelope.length > maxBytes + 36
    || !envelope.subarray(0, 8).equals(magic)) throw new Error('Recovery envelope refused.')
  let plaintext
  try {
    const decipher = createDecipheriv('aes-256-gcm', key, envelope.subarray(8, 20))
    decipher.setAAD(magic)
    decipher.setAuthTag(envelope.subarray(20, 36))
    plaintext = Buffer.concat([decipher.update(envelope.subarray(36)), decipher.final()])
  } catch {
    throw new Error('Recovery authentication failed.')
  }
  return validateSnapshot(JSON.parse(plaintext.toString()), appKey)
}
