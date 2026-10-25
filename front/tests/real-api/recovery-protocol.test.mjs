import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { mkdirSync, mkdtempSync, realpathSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { test } from 'node:test'
import { assertHarnessRoot } from './environment.mjs'
import { assertRecoveryRoot, digest, openSnapshot, privateFiles, sealSnapshot, validateSnapshot } from './recovery.mjs'

const appKey = 'fixture-application-key'
const key = randomBytes(32)
const path = 'tenants/0199cdf3-5a20-7000-8000-000000000001/artifacts/0199cdf3-5a20-7000-8000-000000000002'
function snapshot() {
  const dump = Buffer.from('PGDMP-fixture')
  const contents = Buffer.from('private fixture')
  return { version: 1, appKeyHash: digest(appKey), dump: dump.toString('base64'), dumpHash: digest(dump),
    files: [{ path, contents: contents.toString('base64'), sha256: digest(contents) }] }
}

test('backup encryption authenticates the dump and private files with a fresh nonce', () => {
  const original = snapshot()
  const envelope = sealSnapshot(original, key, appKey)
  assert.deepEqual(openSnapshot(envelope, key, appKey), original)
  assert.equal(envelope.includes(Buffer.from('private fixture')), false)
  assert.equal(envelope.equals(sealSnapshot(original, key, appKey)), false)
})

test('wrong keys, changed ciphertext and truncated envelopes fail before restore', () => {
  const envelope = sealSnapshot(snapshot(), key, appKey)
  assert.throws(() => openSnapshot(envelope, randomBytes(32), appKey), /authentication failed/)
  const altered = Buffer.from(envelope)
  altered[altered.length - 1] ^= 1
  assert.throws(() => openSnapshot(altered, key, appKey), /authentication failed/)
  assert.throws(() => openSnapshot(envelope.subarray(0, 30), key, appKey), /envelope refused/)
  assert.throws(() => openSnapshot(envelope, key, 'lost application key'), /application key refused/)
})

test('unsafe paths, duplicate files and mismatched content are never accepted', () => {
  for (const unsafe of ['../outside', '/absolute', 'C:\\outside', path + '\n', path.replace('/artifacts/', '/artifacts/../')]) {
    const value = snapshot()
    value.files[0].path = unsafe
    assert.throws(() => validateSnapshot(value, appKey), /path refused/)
  }
  const duplicate = snapshot()
  duplicate.files.push(duplicate.files[0])
  assert.throws(() => validateSnapshot(duplicate, appKey), /path refused/)
  const brokenDump = snapshot()
  brokenDump.dumpHash = '0'.repeat(64)
  assert.throws(() => validateSnapshot(brokenDump, appKey), /dump integrity/)
  const brokenFile = snapshot()
  brokenFile.files[0].sha256 = '0'.repeat(64)
  assert.throws(() => validateSnapshot(brokenFile, appKey), /file integrity/)
})

test('storage refuses foreign ownership and does not follow directory links', () => {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  const token = 'a'.repeat(64)
  try {
    writeFileSync(`${root}/owner`, token)
    mkdirSync(`${root}/storage/app/private`, { recursive: true })
    assert.throws(() => assertRecoveryRoot(root, 'b'.repeat(64)), /ownership refused/)
    symlinkSync(root, `${root}/storage/app/private/escape`, process.platform === 'win32' ? 'junction' : 'dir')
    assert.throws(() => privateFiles(root, token), /symbolic links/)
  } finally {
    assertHarnessRoot(root)
    rmSync(root, { recursive: true, force: true })
  }
})

test('unsupported snapshots and oversized inputs fail within the fixture bounds', () => {
  const unsupported = snapshot()
  unsupported.version = 2
  assert.throws(() => validateSnapshot(unsupported, appKey), /snapshot or application key refused/)
  const tooManyFiles = snapshot()
  tooManyFiles.files = Array(101).fill(tooManyFiles.files[0])
  assert.throws(() => validateSnapshot(tooManyFiles, appKey), /snapshot or application key refused/)
  assert.throws(() => openSnapshot(Buffer.alloc(32 * 1024 * 1024 + 37), key, appKey), /envelope refused/)
})
