import assert from 'node:assert/strict'
import { randomBytes, randomUUID } from 'node:crypto'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { performance } from 'node:perf_hooks'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { backup, cleanupRecoveryLabs, fixture, restore, startRecoveryLab } from './recovery-lab.mjs'
import { digest, privateFiles } from './recovery.mjs'

const reportPath = fileURLToPath(new URL('../../reports/retention-erasure-local.json', import.meta.url))
const protectedTables = ['organizations', 'organization_retention_events', 'tenant_portability_operations', 'tenant_storage_usage',
  'tenant_erasure_operations', 'tenant_erasure_events', 'tenant_erasure_permits']

function assertErased(snapshot) {
  for (const [table, value] of Object.entries(snapshot.alpha)) {
    if (!protectedTables.includes(table)) assert.equal(value.rows, 0, `${table} must have no remaining Alpha data`)
  }
  assert.equal(snapshot.alpha.organizations.rows, 1)
  assert.equal(snapshot.alpha.tenant_erasure_permits.rows, 0)
}

test('exported protected tenant data is erased with crash recovery and reconciled after a genuine old PostgreSQL restore', { timeout: 600_000 }, async (context) => {
  const started = performance.now()
  const report = { ticket: 'UCG-OPS-007', synthetic: true, productionQualified: false, status: 'failed', checks: {}, cleanup: false }
  const sources = ['retention-erasure.test.mjs', 'recovery-lab.mjs', 'recovery.mjs', 'environment.mjs', 'postgresql.mjs',
    '../../../back/tests/Fixtures/retention-erasure.php', '../../../back/tests/Fixtures/tenant-graph.php', '../../../back/tests/Fixtures/tenant-recovery.php',
    '../../../back/tests/Fixtures/recovery.php', '../../../back/tests/Fixtures/browser-application.php', '../../../back/tests/Fixtures/browser-postgresql.php',
    '../../../back/app/Modules/Tenancy/Application/Services/ManualErasureService.php',
    '../../../back/app/Modules/Tenancy/Application/Services/ErasureReconciliationService.php',
    '../../../back/app/Modules/Tenancy/Infrastructure/Persistence/DatabaseManualErasureRepository.php',
    '../../../back/app/Modules/Tenancy/Infrastructure/Persistence/IndependentErasureJournal.php',
    '../../../back/app/Modules/Tenancy/Infrastructure/Persistence/ScopedErasurePermit.php',
    '../../../back/app/Modules/Tenancy/Infrastructure/Resources/ManualErasureFiles.php',
    '../../../back/app/Modules/Tenancy/Application/Services/ErasureAccessGate.php']
  report.sourcesSha256 = Object.fromEntries(sources.map((path) => [path, digest(readFileSync(new URL(path, import.meta.url)))]))
  const labs = []
  const abort = new AbortController()
  const interrupt = () => abort.abort()
  context.signal.addEventListener('abort', interrupt, { once: true })
  process.once('SIGINT', interrupt)
  process.once('SIGTERM', interrupt)
  const appKey = `base64:${randomBytes(32).toString('base64')}`
  const backupKey = randomBytes(32)
  const installation = randomUUID()
  const journalKey = randomBytes(64).toString('hex')
  try {
    const origin = await startRecoveryLab(appKey, abort, labs)
    const environment = { UCG_ERASURE_JOURNAL_ROOT: origin.root, UCG_ERASURE_JOURNAL_TOKEN: origin.token,
      UCG_ERASURE_INSTALLATION: installation, UCG_ERASURE_KEY: journalKey }
    const erasure = (lab, operation, input, changes = {}) => fixture(lab, operation, { ...environment, ...changes },
      { script: 'retention-erasure.php', input, timeout: operation === 'drain' ? 180_000 : 90_000 })
    await fixture(origin, 'seed')
    await fixture(origin, 'prepare', {}, { script: 'tenant-recovery.php' })
    for (const operation of ['base', 'graph', 'protected']) await fixture(origin, operation, {}, { script: 'tenant-graph.php' })
    const prepared = await erasure(origin, 'prepare')
    const input = { organization: prepared.organization }
    const initial = await erasure(origin, 'snapshot', input)
    const initialFiles = privateFiles(origin.root, origin.token)
    assert.ok(initial.alpha.contract_transitions.rows >= 3)
    assert.equal(initial.alpha.match_result_events.rows, 2)
    assert.ok(prepared.export_rows > 30)
    assert.ok(prepared.export_files > 0)
    report.checks.privateExportIncludesProtectedRowsAndFiles = true
    report.export = { rows: prepared.export_rows, files: prepared.export_files, sha256: prepared.export_sha256 }
    const oldBackup = await backup(origin, backupKey, appKey)
    report.backupSha256 = digest(oldBackup)
    const restored = await startRecoveryLab(appKey, abort, labs)
    await restore(restored, oldBackup, backupKey, appKey)
    const schemaBefore = await erasure(origin, 'schema', input)
    const schemaAfter = await erasure(restored, 'schema', input)
    const differences = Object.keys(schemaBefore).filter((table) => JSON.stringify(schemaBefore[table]) !== JSON.stringify(schemaAfter[table]))
    const plan = (await erasure(origin, 'plan', input)).plan
    const restoredPlan = (await erasure(restored, 'plan', input)).plan
    assert.deepEqual(restoredPlan, plan, 'Equivalent pg_restore expressions must preserve the native approved plan')
    report.schemaCatalogSpellingChanges = differences
    report.checks.equivalentRestoredSchemaKeepsApproval = true
    assert.deepEqual(await erasure(origin, 'snapshot', input), initial, 'Planning must be read-only')
    assert.deepEqual(privateFiles(origin.root, origin.token), initialFiles)
    const beginInput = { ...input, digest: plan.source_digest, confirmation: input.organization }
    await assert.rejects(erasure(origin, 'begin', beginInput, { UCG_ERASURE_DISABLED: 'true' }), /ERASURE_DISABLED/)
    await assert.rejects(erasure(origin, 'begin', { ...beginInput, digest: '0'.repeat(64) }), /ERASURE_PLAN_CHANGED/)
    assert.deepEqual(await erasure(origin, 'snapshot', input), initial)
    await erasure(origin, 'hold', input)
    await assert.rejects(erasure(origin, 'begin', beginInput), /ERASURE_BLOCKED/)
    await erasure(origin, 'release-hold', input)
    await assert.rejects(erasure(origin, 'begin', beginInput), /ERASURE_PLAN_CHANGED/)
    const reviewed = (await erasure(origin, 'plan', input)).plan
    assert.deepEqual(reviewed.blockers, [])
    const operation = (await erasure(origin, 'begin', { ...beginInput, digest: reviewed.source_digest })).operation
    const operationInput = { ...input, operation: operation.id }
    await assert.rejects(erasure(origin, 'access', input), /ERASURE_RESTORE_BLOCKED/)
    await erasure(origin, 'beta-change', input)
    const beforeCrash = await erasure(origin, 'snapshot', input)
    const beforeFiles = privateFiles(origin.root, origin.token)
    await assert.rejects(erasure(origin, 'crash-after-file', operationInput), /failed \(73\)/)
    assert.deepEqual(await erasure(origin, 'snapshot', input), beforeCrash, 'A crash after filesystem I/O rolls back SQL progress')
    assert.equal(privateFiles(origin.root, origin.token).length, beforeFiles.length - 1)
    assert.equal((await erasure(origin, 'inspect', operationInput)).operation.progress.files_cursor, 0)
    await assert.rejects(erasure(origin, 'crash-after-row', operationInput), /failed \(74\)/)
    assert.equal((await erasure(origin, 'snapshot', input)).alpha.contract_document_versions.rows, 1, 'Interrupted row deletion must roll back')
    const drained = await erasure(origin, 'drain', operationInput)
    assert.equal(drained.operation.status, 'awaiting_copies')
    assert.equal(drained.remaining_copy_reviews.length, 11)
    const after = await erasure(origin, 'snapshot', input)
    assertErased(after)
    assert.deepEqual(after.beta, beforeCrash.beta)
    assert.deepEqual(after.global, beforeCrash.global)
    const betaFiles = beforeFiles.filter((file) => file.path.startsWith(`tenants/${prepared.beta}/`))
    assert.deepEqual(privateFiles(origin.root, origin.token), betaFiles)
    const completed = await erasure(origin, 'acknowledge', operationInput)
    assert.equal(completed.operation.status, 'completed')
    assert.equal(completed.copy_evidence, 'operator_attestations')
    assert.equal(completed.production_qualified, false)
    assert.equal((await erasure(origin, 'snapshot', input)).quarantine, `erasure:${operation.id}`)
    report.checks.disabledStaleAndHeldErasureRefused = true
    report.checks.fileCrashResumesWithoutDiscardingMetadata = true
    report.checks.protectedRowCrashRollsBackAndResumes = true
    report.checks.allBusinessRowsAndPrivateFilesRemoved = true
    report.checks.newerBetaWritesAndGlobalAccountsPreserved = true
    report.checks.copyReviewsExplicitAndPermanentlyQuarantined = true

    const beforeReplay = await erasure(restored, 'snapshot', input)
    assert.deepEqual(beforeReplay.alpha, initial.alpha, 'Real pg_restore must resurrect the exact old business state')
    assert.deepEqual(privateFiles(restored.root, restored.token), initialFiles)
    await assert.rejects(erasure(restored, 'access', input), /ERASURE_RESTORE_BLOCKED/)
    const replayEnvironment = { UCG_ERASURE_RESTORE_EPOCH: randomUUID() }
    const head = (await erasure(restored, 'reconcile', input)).journal_head
    await assert.rejects(erasure(restored, 'reconcile', { ...input, head: '0'.repeat(64), confirmation: input.organization }, replayEnvironment), /ERASURE_RESTORE_BLOCKED/)
    const approval = { ...input, head, confirmation: input.organization }
    const holds = await erasure(restored, 'reconcile', approval, replayEnvironment)
    assert.equal(holds.preservation_holds_imported, 1)
    assert.equal(holds.manual_hold_review_required, true)
    await erasure(restored, 'release-hold', input, replayEnvironment)
    await assert.rejects(erasure(restored, 'changed-generation', approval, replayEnvironment), /ERASURE_PLAN_CHANGED/)
    report.checks.realGeneratedColumnChangeStillRefused = true
    const replay = (await erasure(restored, 'reconcile', approval, replayEnvironment)).operation
    assert.equal(replay.source_intent_id, operation.id)
    const replayInput = { ...input, operation: replay.id }
    await erasure(restored, 'drain', replayInput, replayEnvironment)
    await erasure(restored, 'acknowledge', replayInput, replayEnvironment)
    assert.equal((await erasure(restored, 'reconcile', approval, replayEnvironment)).organization_permanently_quarantined, true)
    const final = await erasure(restored, 'snapshot', input, replayEnvironment)
    assertErased(final)
    assert.equal(final.quarantine, `erasure:${replay.id}`)
    assert.deepEqual(final.beta, initial.beta)
    assert.deepEqual(final.global, initial.global)
    assert.deepEqual(privateFiles(restored.root, restored.token), initialFiles.filter((file) => file.path.startsWith(`tenants/${prepared.beta}/`)))
    await erasure(restored, 'reconcile', approval, replayEnvironment)
    assert.deepEqual(await erasure(restored, 'snapshot', input, replayEnvironment), final, 'Repeated reconciliation must not add events or operations')
    await assert.rejects(erasure(restored, 'access', input), /ERASURE_RESTORE_BLOCKED/)
    report.checks.genuineOldBackupCannotReopenErasedData = true
    report.checks.missingPreservationImportedBeforeReplay = true
    report.checks.replayedErasureIsIdempotentAndPreservesBeta = true
    report.betaTables = Object.keys(final.beta).length
    report.globalTables = Object.keys(final.global).length
    report.deletedRows = drained.operation.progress.deleted
    for (const path of sources) assert.equal(digest(readFileSync(new URL(path, import.meta.url))), report.sourcesSha256[path], 'Qualification sources must remain unchanged during the test')
    report.checks.sourceFingerprintsReverified = true
    report.status = 'passed'
  } finally {
    report.cleanup = await cleanupRecoveryLabs(labs)
    if (!report.cleanup) report.status = 'failed'
    report.elapsedMs = performance.now() - started
    mkdirSync(dirname(reportPath), { recursive: true })
    writeFileSync(reportPath, JSON.stringify(report, null, 2))
    process.removeListener('SIGINT', interrupt)
    process.removeListener('SIGTERM', interrupt)
    context.signal.removeEventListener('abort', interrupt)
    assert.equal(report.cleanup, true)
  }
})
