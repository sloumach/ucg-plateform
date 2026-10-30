import assert from 'node:assert/strict'
import { randomBytes, randomUUID } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { performance } from 'node:perf_hooks'
import { fileURLToPath } from 'node:url'
import { copyVault, inventoryTree, readVault, stageVault, signBackupEvidence } from './backup-vault.mjs'
import { cleanupRecoveryLabs, fixture, startRecoveryLab } from './recovery-lab.mjs'
import { digest, privateFiles } from './recovery.mjs'
import { configureArchiving, evidencePayload, materializePitr, physicalBaseBackup, pitrInventory, restartAfterCrash, sql, startPitrTarget, waitForWal, walRange } from './pitr-lab.mjs'
import { test } from 'node:test'

const reportPath = fileURLToPath(new URL('../../reports/pitr-local.json', import.meta.url))

test('physical PostgreSQL backup and encrypted WAL recover to the pre-incident instant on an isolated target', { timeout: 360000 }, async (context) => {
  const started = performance.now()
  const report = { ticket: 'UCG-OPS-004', version: 2, status: 'failed', synthetic: true,
    topology: 'owned-local-clusters-and-separate-local-vaults', hostingQualified: false,
    offsiteIndependenceVerified: false, productionRpoRtoQualified: false, phasesMs: {}, cleanup: false }
  const abort = new AbortController()
  const interrupt = () => abort.abort()
  context.signal.addEventListener('abort', interrupt, { once: true })
  const labs = []
  const appKey = `base64:${randomBytes(32).toString('base64')}`
  const key = randomBytes(32)
  const evidenceKey = randomBytes(32)
  try {
    const source = await startRecoveryLab(appKey, abort, labs)
    await fixture(source, 'seed')
    await configureArchiving(source)
    let phase = performance.now()
    const base = await physicalBaseBackup(source)
    report.phasesMs.baseBackupVerified = performance.now() - phase
    await assert.rejects(sql(source, 'SELECT COUNT(*) FROM people;', { user: 'ucg_backup' }), /failed/)
    await fixture(source, 'pitr-before')
    const expected = await fixture(source, 'inspect')
    const expectedFiles = privateFiles(source.root, source.token)
    assert.equal(expectedFiles.length, 3)
    const point = await sql(source, 'SELECT clock_timestamp();')
    assert.match(point, /^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9:.]+\+00$/)
    const fileSnapshot = inventoryTree(source, `${source.root}/storage/app/private`, 'private')
    await fixture(source, 'pitr-after')
    assert.equal(privateFiles(source.root, source.token).length, 4)
    assert.notDeepEqual(await fixture(source, 'inspect'), expected)
    const endLsn = await sql(source, 'SELECT pg_current_wal_lsn();')
    const systemId = await sql(source, 'SELECT system_identifier FROM pg_control_system();')
    const timeline = Number(await sql(source, 'SELECT timeline_id FROM pg_control_checkpoint();'))
    const startLsn = base.manifest['WAL-Ranges'][0]['Start-LSN']
    const names = walRange(startLsn, endLsn, timeline)
    await sql(source, 'SELECT pg_switch_wal();')
    await waitForWal(source, names)
    const walArchivedAt = new Date().toISOString()
    const backupCompletedAt = new Date().toISOString()
    const inventory = pitrInventory(inventoryTree(source, base.path, 'base'), fileSnapshot, source, names)
    const vault = await startRecoveryLab(appKey, abort, labs, { initialize: false })
    const copy = await startRecoveryLab(appKey, abort, labs, { initialize: false })
    const keys = await startRecoveryLab(appKey, abort, labs, { initialize: false })
    writeFileSync(`${keys.root}/backup.key`, key, { flag: 'wx', mode: 0o600 })
    writeFileSync(`${keys.root}/evidence.key`, evidenceKey, { flag: 'wx', mode: 0o600 })
    const metadata = { id: randomUUID(), keyId: randomUUID(), appKeyHash: digest(appKey), database: source.environment.DB_DATABASE,
      systemId, timeline, startLsn, endLsn, walNames: names, targetTime: point }
    phase = performance.now()
    assert.throws(() => stageVault(vault, key, metadata, inventory, { interruptAfter: 2 }), /VAULT_INTERRUPTED/)
    assert.equal(existsSync(`${vault.root}/vault/${metadata.id}/manifest.aes`), false)
    const staged = stageVault(vault, key, metadata, inventory)
    assert.equal(staged.reused, 2)
    assert.equal(copyVault(vault, copy, key, metadata.id).verified, true)
    assert.ok(copyVault(vault, copy, key, metadata.id).reused > 0)
    report.phasesMs.encryptedCopyVerified = performance.now() - phase
    await source.postgres.stop()
    assert.equal(existsSync(`${source.postgres.data}/postmaster.pid`), false)

    const target = await startRecoveryLab(appKey, abort, labs, { initialize: false })
    await assert.rejects(materializePitr(target, copy, randomBytes(32), metadata.id, digest(appKey)), /AUTHENTICATION/)
    await assert.rejects(materializePitr(target, copy, key, metadata.id, digest('wrong-app-key')), /APPLICATION_KEY/)
    assert.equal(existsSync(target.postgres.data), false)
    // A signed but incomplete chain must be refused before any target is written.
    const missing = { ...metadata, id: randomUUID() }
    const missingInventory = { ...inventory, files: inventory.files.filter((entry) => entry.path !== `wal/${names.at(-1)}`) }
    stageVault(vault, key, missing, missingInventory)
    await assert.rejects(materializePitr(target, vault, key, missing.id, digest(appKey)), /WAL_CHAIN_REFUSED/)
    assert.equal(existsSync(target.postgres.data), false)
    phase = performance.now()
    const manifest = await materializePitr(target, copy, readFileSync(`${keys.root}/backup.key`), metadata.id, digest(appKey))
    const promotedTimeline = await startPitrTarget(target, manifest, source.token)
    assert.ok(promotedTimeline > timeline)
    const restored = await fixture(target, 'inspect')
    assert.deepEqual(restored, expected)
    assert.deepEqual(privateFiles(target.root, target.token), expectedFiles)
    assert.deepEqual(await fixture(target, 'pitr-verify'), { decryption: true, private_files: 3, tenant_isolation: true, history_guard: true, check_and_fk: true })
    report.phasesMs.restoreAndVerify = performance.now() - phase
    await assert.rejects(materializePitr(target, copy, key, metadata.id, digest(appKey)), /TARGET_NOT_EMPTY/)
    phase = performance.now()
    await restartAfterCrash(target)
    assert.deepEqual(await fixture(target, 'inspect'), restored)
    assert.deepEqual(privateFiles(target.root, target.token), expectedFiles)
    report.phasesMs.crashRestartAndVerify = performance.now() - phase
    const evidence = evidencePayload(manifest, { walArchivedAt, backupCompletedAt })
    writeFileSync(`${copy.root}/backup-evidence.json`, JSON.stringify(signBackupEvidence(evidence, evidenceKey)), { flag: 'wx', mode: 0o600 })
    const monitoring = { OPS_BACKUP_MONITORING_ENABLED: 'true', OPS_RESOURCE_NAMESPACE: 'ops_pitr_local',
      OPS_BACKUP_EVIDENCE_FILE: `${copy.root}/backup-evidence.json`, OPS_BACKUP_EVIDENCE_KEY_FILE: `${keys.root}/evidence.key`,
      OPS_BACKUP_ARCHIVE_ROOT: `${copy.root}/vault` }
    const diagnostic = await fixture(target, 'pitr-evidence', monitoring)
    assert.equal(diagnostic.state, 'unverified')
    assert.equal(diagnostic.code, 'BACKUP_LOCAL_SIMULATION')
    assert.equal(diagnostic.observations.vendor_copy_and_rpo_rto_verified, false)
    const invalidEvidence = signBackupEvidence(evidence, evidenceKey)
    invalidEvidence.signature = '0'.repeat(64)
    writeFileSync(`${copy.root}/backup-evidence.json`, JSON.stringify(invalidEvidence))
    assert.equal((await fixture(target, 'pitr-evidence', monitoring)).code, 'BACKUP_EVIDENCE_SIGNATURE_INVALID')
    assert.equal(readVault(copy, key, metadata.id).manifest.id, metadata.id)
    report.inventory = { tables: Object.keys(expected.tables).length, privateFiles: 3,
      baseFiles: inventory.files.filter((entry) => entry.path.startsWith('base/')).length,
      walSegments: names.length, encryptedBytes: inventory.files.reduce((sum, file) => sum + file.contents.length + 36, 0) }
    report.checks = { nativeBaseBackupVerified: true, nativeWalArchiving: true, preIncidentPointReached: true,
      allPublicRowsAndSchemaMatch: true, privateFilesAndDecryption: true, tenantAndSqlGuards: true,
      wrongKeysRefusedBeforeWrite: true, missingWalRefusedBeforeWrite: true, occupiedTargetRefused: true,
      backupResumed: true, copiedCiphertextVerified: true, keyStoredOutsideVault: true,
      leastPrivilegeReplicationRole: true, databaseCrashRestart: true, sourceStoppedBeforeRestore: true,
      signedEvidenceReadByLaravel: true, tamperedEvidenceRefused: true, localEvidenceCannotQualifyHosting: true }
    report.runtime = { node: process.version, postgresql: await target.postgres.command('postgres', ['--version']) }
    report.sourceHashes = Object.fromEntries(['pitr.test.mjs', 'pitr-lab.mjs', 'backup-vault.mjs', 'recovery-lab.mjs', 'recovery.mjs']
      .map((name) => [name, digest(readFileSync(new URL(name, import.meta.url)))]))
    report.status = 'passed'
  } finally {
    report.cleanup = await cleanupRecoveryLabs(labs)
    report.elapsedMs = performance.now() - started
    if (!report.cleanup) report.status = 'failed'
    mkdirSync(dirname(reportPath), { recursive: true })
    writeFileSync(reportPath, JSON.stringify(report, null, 2))
    context.signal.removeEventListener('abort', interrupt)
    assert.equal(report.cleanup, true)
  }
})
