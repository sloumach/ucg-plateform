import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { mkdirSync, readFileSync, renameSync, unlinkSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { performance } from 'node:perf_hooks'
import { test } from 'node:test'
import { assertRecoveryRoot, digest, privateFiles, sealSnapshot } from './recovery.mjs'
import { cleanupRecoveryLabs, connectionArguments, fixture, restore, startRecoveryLab } from './recovery-lab.mjs'

const reportPath = fileURLToPath(new URL('../../reports/recovery-local.json', import.meta.url))

test('a quiescent PostgreSQL database and private files recover into a separate owned cluster', { timeout: 240_000 }, async (context) => {
  const started = performance.now()
  const report = { ticket: 'UCG-OPS-004', version: 1, status: 'failed', topology: 'two-disposable-local-clusters',
    createdAt: new Date().toISOString(), runtime: { node: process.versions.node, platform: process.platform, postgresMajor: 17 },
    quiescent: true, synthetic: true, productionQualified: false, phasesMs: {}, cleanup: false }
  report.sourcesSha256 = Object.fromEntries(['recovery.mjs', 'recovery.test.mjs', 'recovery-lab.mjs', 'environment.mjs', 'postgresql.mjs',
    '../../../back/tests/Fixtures/recovery.php', '../../../back/tests/Fixtures/browser-application.php',
    '../../../back/tests/Fixtures/browser-postgresql.php'].map((path) => [path, digest(readFileSync(new URL(path, import.meta.url)))]))
  const labs = []
  const abort = new AbortController()
  const interrupt = () => abort.abort()
  context.signal.addEventListener('abort', interrupt, { once: true })
  process.once('SIGINT', interrupt)
  process.once('SIGTERM', interrupt)
  const appKey = `base64:${randomBytes(32).toString('base64')}`
  const backupKey = randomBytes(32)
  const startLab = () => startRecoveryLab(appKey, abort, labs)
  try {
    const source = await startLab()
    await fixture(source, 'seed')
    const before = await fixture(source, 'inspect')
    assert.ok(Object.keys(before.tables).length >= 116)
    assert.equal(before.tables.people.rows, 2)
    assert.equal(before.tables.tenant_artifacts.rows, 2)
    const files = privateFiles(source.root, source.token)
    assert.equal(files.length, 2)

    let phase = performance.now()
    const dumpPath = `${source.root}/source.dump`
    await source.postgres.command('pg_dump', [...connectionArguments(source), `--dbname=${source.environment.DB_DATABASE}`,
      '--format=custom', '--no-owner', '--no-acl', `--file=${dumpPath}`])
    const dump = readFileSync(dumpPath)
    const snapshot = { version: 1, appKeyHash: digest(appKey), dump: dump.toString('base64'), dumpHash: digest(dump), files }
    const backupPath = `${source.root}/snapshot.aes`
    writeFileSync(backupPath, sealSnapshot(snapshot, backupKey, appKey), { flag: 'wx', mode: 0o600 })
    const envelope = readFileSync(backupPath)
    report.phasesMs.backup = performance.now() - phase
    report.backupBytes = envelope.length
    report.tables = Object.keys(before.tables).length
    report.privateFiles = files.length
    report.schemaSha256 = digest(JSON.stringify({ constraints: before.constraints, triggers: before.triggers }))

    await fixture(source, 'advance')
    const advanced = await fixture(source, 'inspect')
    assert.notEqual(advanced.tables.people.sha256, before.tables.people.sha256)
    await source.postgres.stop()

    phase = performance.now()
    const target = await startLab()
    report.phasesMs.targetProvisioning = performance.now() - phase
    const altered = Buffer.from(envelope)
    altered[altered.length - 1] ^= 1
    await assert.rejects(restore(target, altered, backupKey, appKey), /authentication failed/)
    await assert.rejects(restore(target, envelope, randomBytes(32), appKey), /authentication failed/)
    await assert.rejects(restore(target, envelope, backupKey, 'lost application key'), /application key refused/)
    await assert.rejects(fixture(target, 'empty', { DB_DATABASE: 'postgres' }), /refused or failed/)
    assert.deepEqual(await fixture(target, 'empty'), { empty: true })
    assert.deepEqual(privateFiles(target.root, target.token), [])
    const occupiedPath = `${target.root}/storage/app/private/${files[0].path}`
    mkdirSync(dirname(occupiedPath), { recursive: true })
    writeFileSync(occupiedPath, 'Must remain untouched', { flag: 'wx', mode: 0o600 })
    await assert.rejects(restore(target, envelope, backupKey, appKey), /empty private storage/)
    assert.equal(readFileSync(occupiedPath, 'utf8'), 'Must remain untouched')
    assert.deepEqual(await fixture(target, 'empty'), { empty: true })
    assertRecoveryRoot(target.root, target.token)
    unlinkSync(occupiedPath)

    phase = performance.now()
    await restore(target, envelope, backupKey, appKey)
    report.phasesMs.restore = performance.now() - phase
    phase = performance.now()
    const restored = await fixture(target, 'inspect')
    for (const section of Object.keys(before)) {
      assert.equal(digest(JSON.stringify(restored[section])), digest(JSON.stringify(before[section])), `Restored ${section} differs from the backup point.`)
    }
    assert.deepEqual(privateFiles(target.root, target.token), files)
    assert.deepEqual(await fixture(target, 'verify'), { decryption: true, private_files: 2, tenant_isolation: true, history_guard: true, check_and_fk: true })
    await assert.rejects(fixture(target, 'verify', { APP_KEY: `base64:${randomBytes(32).toString('base64')}` }), /refused or failed/)
    const missingPath = `${target.root}/storage/app/private/${files[0].path}`
    renameSync(missingPath, `${target.root}/missing-file`)
    await assert.rejects(fixture(target, 'verify'), /refused or failed/)
    renameSync(`${target.root}/missing-file`, missingPath)
    assert.deepEqual(await fixture(target, 'verify'), { decryption: true, private_files: 2, tenant_isolation: true, history_guard: true, check_and_fk: true })
    await assert.rejects(restore(target, envelope, backupKey, appKey), /empty refused or failed/)
    assert.deepEqual(await fixture(target, 'inspect'), restored)
    report.phasesMs.verification = performance.now() - phase
    report.checks = { allTableRows: true, sequences: true, constraintsAndTriggers: true, pointInTime: true,
      decryption: true, privateFiles: true, tenantIsolation: true, historyGuard: true,
      corruptionRefused: true, foreignTargetRefused: true, populatedTargetRefused: true, occupiedStorageRefused: true, missingFileDetected: true }
    report.status = 'passed'
  } finally {
    const cleanupFailure = !await cleanupRecoveryLabs(labs)
    report.cleanup = !cleanupFailure
    report.elapsedMs = performance.now() - started
    if (cleanupFailure) report.status = 'failed'
    mkdirSync(dirname(reportPath), { recursive: true })
    writeFileSync(reportPath, JSON.stringify(report, null, 2))
    process.removeListener('SIGINT', interrupt)
    process.removeListener('SIGTERM', interrupt)
    context.signal.removeEventListener('abort', interrupt)
    assert.equal(cleanupFailure, false, 'Recovery cleanup failed; resources retained.')
  }
})
