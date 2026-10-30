import assert from 'node:assert/strict'
import { randomBytes, randomUUID } from 'node:crypto'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { test } from 'node:test'
import { assertVaultOwner, copyVault, inventoryTree, readVault, retentionPlan, signBackupEvidence, stageVault } from './backup-vault.mjs'
import { digest } from './recovery.mjs'
import { walRange } from './pitr-lab.mjs'

function owned() {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  const token = randomBytes(32).toString('hex')
  writeFileSync(`${root}/owner`, token, { flag: 'wx', mode: 0o600 })
  return { root, token }
}
function cleanup(labs) {
  for (const lab of labs) {
    assertVaultOwner(lab)
    rmSync(lab.root, { recursive: true, force: true })
  }
}
function metadata() {
  return { version: 2, id: randomUUID(), keyId: randomUUID(), appKeyHash: digest('fixture-app-key'),
    systemId: '1234567890123456789', database: 'ucg_browser_' + 'a'.repeat(24), targetTime: '2026-10-30 12:00:00.123456+00' }
}
function inventory() {
  return { directories: ['base', 'base/global', 'private', 'wal'], files: [
    { path: 'base/PG_VERSION', contents: Buffer.from('17\n') },
    { path: 'base/global/pg_control', contents: Buffer.from('Fictitious control bytes') },
    { path: 'wal/000000010000000000000003', contents: Buffer.from('Fictitious WAL') },
  ] }
}

test('interrupted generation has no committed manifest and resumes authenticated completed objects', () => {
  const lab = owned()
  try {
    const key = randomBytes(32)
    const data = metadata()
    assert.throws(() => stageVault(lab, key, data, inventory(), { interruptAfter: 1 }), /INTERRUPTED/)
    assert.equal(existsSync(`${lab.root}/vault/${data.id}/manifest.aes`), false)
    assert.throws(() => readVault(lab, key, data.id))
    assert.equal(stageVault(lab, key, data, inventory()).reused, 1)
    assert.equal(stageVault(lab, key, data, inventory()).reused, 3)
    assert.deepEqual(readVault(lab, key, data.id, data.appKeyHash).files, inventory().files)
    assert.equal(readFileSync(`${lab.root}/vault/${data.id}/manifest.aes`).includes(Buffer.from(data.database)), false)
  } finally { cleanup([lab]) }
})

test('wrong keys, swapped objects, bit flips and other application keys fail authentication', () => {
  const lab = owned()
  try {
    const key = randomBytes(32)
    const data = metadata()
    stageVault(lab, key, data, inventory())
    assert.throws(() => readVault(lab, randomBytes(32), data.id), /AUTHENTICATION/)
    assert.throws(() => readVault(lab, key, data.id, digest('other-app-key')), /APPLICATION_KEY/)
    const path = `${lab.root}/vault/${data.id}/objects/${digest('base/PG_VERSION')}.aes`
    const original = readFileSync(path)
    writeFileSync(path, readFileSync(`${lab.root}/vault/${data.id}/objects/${digest('base/global/pg_control')}.aes`))
    assert.throws(() => readVault(lab, key, data.id), /AUTHENTICATION/)
    const changed = Buffer.from(original)
    changed[changed.length - 1] ^= 1
    writeFileSync(path, changed)
    assert.throws(() => stageVault(lab, key, data, inventory()), /AUTHENTICATION/)
  } finally { cleanup([lab]) }
})

test('separate copy is immutable, rechecked and idempotent and never copies a key file', () => {
  const labs = [owned(), owned()]
  try {
    const key = randomBytes(32)
    const data = metadata()
    stageVault(labs[0], key, data, inventory())
    writeFileSync(`${labs[0].root}/backup.key`, key, { mode: 0o600 })
    assert.equal(copyVault(labs[0], labs[1], key, data.id).verified, true)
    assert.equal(existsSync(`${labs[1].root}/backup.key`), false)
    assert.equal(copyVault(labs[0], labs[1], key, data.id).reused, 4)
    assert.throws(() => copyVault(labs[0], labs[0], key, data.id), /NOT_SEPARATE/)
    const target = `${labs[1].root}/vault/${data.id}/manifest.aes`
    writeFileSync(target, 'incomplete upload')
    assert.throws(() => copyVault(labs[0], labs[1], key, data.id), /COPY_CONFLICT/)
  } finally { cleanup(labs) }
})

test('unsafe and duplicate logical paths and file bounds are refused before backup publication', () => {
  const lab = owned()
  try {
    const key = randomBytes(32)
    for (const path of ['../escape', 'base/../escape', '/base/file', 'C:\\file', 'private/bad\n', 'base/./file']) {
      assert.throws(() => stageVault(lab, key, metadata(), { directories: [], files: [{ path, contents: Buffer.from('x') }] }), /PATH_REFUSED/)
    }
    const duplicate = inventory()
    duplicate.files.push(duplicate.files[0])
    assert.throws(() => stageVault(lab, key, metadata(), duplicate), /MANIFEST_REFUSED/)
    assert.throws(() => stageVault(lab, key, metadata(), { directories: ['base'], files: [{ path: 'base/large', contents: Buffer.alloc(32 * 1024 * 1024 + 1) }] }), /MANIFEST_REFUSED/)
  } finally { cleanup([lab]) }
})

test('inventory and vault reads refuse links without following another root', () => {
  const labs = [owned(), owned()]
  try {
    mkdirSync(`${labs[0].root}/source`)
    symlinkSync(labs[1].root, `${labs[0].root}/source/escape`, process.platform === 'win32' ? 'junction' : 'dir')
    assert.throws(() => inventoryTree(labs[0], `${labs[0].root}/source`, 'base'), /LINK_REFUSED/)
    writeFileSync(`${labs[0].root}/owner`, 'b'.repeat(64))
    assert.throws(() => assertVaultOwner(labs[0]), /OWNER_REFUSED/)
    writeFileSync(`${labs[0].root}/owner`, labs[0].token)
  } finally { cleanup(labs) }
})

test('WAL segment range is contiguous across a log boundary and rejects gaps in its bounds', () => {
  assert.deepEqual(walRange('0/FF000000', '1/00000010', 1), ['0000000100000000000000FF', '000000010000000100000000'])
  assert.throws(() => walRange('1/0', '0/1', 1), /RANGE_REFUSED/)
  assert.throws(() => walRange('0/0', '1/0', 1), /RANGE_REFUSED/)
  assert.throws(() => walRange('malformed', '1/0', 1), /RANGE_REFUSED/)
})

test('retention planning preserves recent generations, holds, unverified copies and never authorizes deletion', () => {
  const now = Date.parse('2026-10-30T12:00:00Z')
  const rows = Array.from({ length: 5 }, (_, i) => ({ id: randomUUID(), createdAt: `2026-10-${25 - i}T12:00:00Z`, hold: false, verified: true, verifiedCopies: 2 }))
  rows[3].hold = true
  rows[4].verifiedCopies = 1
  const plan = retentionPlan(rows, { now, minimumAgeHours: 24 })
  assert.deepEqual(plan.map((item) => item.reviewCandidate), [false, false, true, false, false])
  assert.ok(plan.every((item) => item.deletionAuthorized === false))
  assert.throws(() => retentionPlan(rows, { keepLast: 0 }), /POLICY_REFUSED/)
  assert.throws(() => retentionPlan([...rows, rows[0]]), /CATALOG_REFUSED/)
})

test('backup evidence authenticates the exact bounded JSON payload with its separate signing key', () => {
  const signed = signBackupEvidence({ version: 1, scope: 'local_simulation' }, randomBytes(32))
  assert.equal(JSON.parse(signed.payload).scope, 'local_simulation')
  assert.match(signed.signature, /^[a-f0-9]{64}$/)
  assert.throws(() => signBackupEvidence({}, Buffer.alloc(1)), /KEY_REFUSED/)
})
