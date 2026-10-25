import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { performance } from 'node:perf_hooks'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { backup, cleanupRecoveryLabs, fixture, restore, startRecoveryLab } from './recovery-lab.mjs'
import { assertRecoveryRoot, digest, privateFiles } from './recovery.mjs'

const reportPath = fileURLToPath(new URL('../../reports/tenant-recovery-local.json', import.meta.url))
const tenantFixture = (lab, operation, input) => fixture(lab, operation, {}, { script: 'tenant-recovery.php', input })

test('selective Alpha recovery keeps all newer Beta rows, global data and private files', { timeout: 240_000 }, async (context) => {
  const started = performance.now()
  const report = { ticket: 'UCG-OPS-005', status: 'failed', synthetic: true, productionQualified: false,
    scope: ['people:three-fields', 'one-missing-private-artifact'], createdAt: new Date().toISOString(),
    runtime: { node: process.versions.node, platform: process.platform, postgresMajor: 17 }, phasesMs: {}, cleanup: false }
  report.sourcesSha256 = Object.fromEntries(['tenant-recovery.test.mjs', 'recovery-lab.mjs', 'recovery.mjs', 'environment.mjs', 'postgresql.mjs',
    '../../../back/tests/Fixtures/tenant-recovery.php', '../../../back/tests/Fixtures/recovery.php',
    '../../../back/tests/Fixtures/browser-application.php', '../../../back/tests/Fixtures/browser-postgresql.php']
    .map((path) => [path, digest(readFileSync(new URL(path, import.meta.url)))]))
  const labs = []
  const abort = new AbortController()
  const interrupt = () => abort.abort()
  context.signal.addEventListener('abort', interrupt, { once: true })
  process.once('SIGINT', interrupt)
  process.once('SIGTERM', interrupt)
  const appKey = `base64:${randomBytes(32).toString('base64')}`
  const backupKey = randomBytes(32)
  try {
    const current = await startRecoveryLab(appKey, abort, labs)
    await fixture(current, 'seed')
    const unprivileged = await tenantFixture(current, 'inspect')
    await assert.rejects(tenantFixture(current, 'damage'), /TENANT_RECOVERY_FORBIDDEN/)
    assert.deepEqual(await tenantFixture(current, 'inspect'), unprivileged)
    await tenantFixture(current, 'prepare')
    const baseline = await tenantFixture(current, 'inspect')
    const envelope = await backup(current, backupKey, appKey)
    report.backupSha256 = digest(envelope)

    // Read recovery inputs only from the restored backup, never from live Alpha.
    let phase = performance.now()
    const staging = await startRecoveryLab(appKey, abort, labs)
    await restore(staging, envelope, backupKey, appKey)
    assert.deepEqual(await tenantFixture(staging, 'inspect'), baseline)
    const source = await tenantFixture(staging, 'export-alpha')
    const foreign = await tenantFixture(staging, 'export-beta')
    await staging.postgres.stop()
    report.phasesMs.backupSelection = performance.now() - phase

    await tenantFixture(current, 'damage')
    const alphaPath = `tenants/${source.organization_id}/artifacts/${source.artifact.id}`
    const damagedPath = `${current.root}/storage/app/private/${alphaPath}`
    assertRecoveryRoot(current.root, current.token)
    unlinkSync(damagedPath)
    const advanced = await tenantFixture(current, 'inspect')
    assert.equal(advanced.beta.people.rows, 2)
    assert.equal(advanced.beta.tenant_artifacts.rows, 2)
    assert.notEqual(advanced.beta.people.sha256, baseline.beta.people.sha256)
    const preservedFiles = privateFiles(current.root, current.token)
    assert.equal(preservedFiles.length, 2)

    const request = { confirmation: source.organization_id, source }
    phase = performance.now()
    const plan = await tenantFixture(current, 'plan', request)
    assert.deepEqual(await tenantFixture(current, 'inspect'), advanced, 'Planning must not write data.')
    for (const input of [
      { ...request, confirmation: foreign.organization_id },
      { ...request, source: foreign },
      { ...request, source: { ...source, person_id: foreign.person_id } },
      { ...request, source: { ...source, artifact: foreign.artifact } },
    ]) {
      await assert.rejects(tenantFixture(current, 'plan', input), /TENANT_RECOVERY_SCOPE/)
    }
    const altered = structuredClone(plan)
    altered.plan.request.source.fields.pseudonym = 'Unreviewed replacement'
    await assert.rejects(tenantFixture(current, 'apply', altered), /TENANT_RECOVERY_SIGNATURE/)
    assert.deepEqual(await tenantFixture(current, 'inspect'), advanced)
    assert.deepEqual(privateFiles(current.root, current.token), preservedFiles)

    await tenantFixture(current, 'late-change')
    const afterLateChange = await tenantFixture(current, 'inspect')
    await assert.rejects(tenantFixture(current, 'apply', plan), /TENANT_RECOVERY_STALE/)
    assert.deepEqual(await tenantFixture(current, 'inspect'), afterLateChange)
    assert.equal(existsSync(damagedPath), false)
    const reviewed = await tenantFixture(current, 'plan', request)
    await assert.rejects(tenantFixture(current, 'apply-fail', reviewed), /TENANT_RECOVERY_SIMULATED_FAILURE/)
    assert.deepEqual(await tenantFixture(current, 'inspect'), afterLateChange)
    assert.equal(existsSync(damagedPath), false, 'Failed reconciliation must remove only its new file.')
    assert.deepEqual(privateFiles(current.root, current.token), preservedFiles)
    report.phasesMs.planningAndRefusals = performance.now() - phase

    phase = performance.now()
    assert.deepEqual(await tenantFixture(current, 'apply', reviewed), { applied: true })
    report.phasesMs.reconciliation = performance.now() - phase
    const verified = await tenantFixture(current, 'verify')
    assert.equal(verified.alphaRecovered, true)
    assert.equal(verified.betaNewRowsRetained, true)
    assert.equal(verified.alphaAuditRows, 3)
    assert.notEqual(verified.alphaVersion, source.snapshot_version, 'Recovery is a new audited change, not a version rollback.')
    const after = await tenantFixture(current, 'inspect')
    assert.deepEqual(after.beta, afterLateChange.beta)
    assert.deepEqual(after.global, afterLateChange.global)
    const recoveredFiles = privateFiles(current.root, current.token)
    assert.deepEqual(recoveredFiles.filter((file) => file.path !== alphaPath), preservedFiles)
    assert.equal(recoveredFiles.find((file) => file.path === alphaPath).sha256, source.artifact.sha256)
    await assert.rejects(tenantFixture(current, 'apply', reviewed), /TENANT_RECOVERY_FILE_EXISTS/)
    assert.deepEqual(await tenantFixture(current, 'inspect'), after)
    assert.deepEqual(privateFiles(current.root, current.token), recoveredFiles)
    report.betaTables = Object.keys(after.beta).length
    report.globalTables = Object.keys(after.global).length
    report.checks = { explicitPermissionsRequired: true, backupSelection: true, readonlyPlan: true, crossTenantInputsRefused: true,
      tamperedPlanRefused: true, stalePlanRefused: true, databaseAndFileRollback: true, auditedRecovery: true,
      betaAllRowsPreserved: true, globalAllRowsPreserved: true, betaPrivateFilesPreserved: true, repeatedApplyRefused: true }
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
    assert.equal(report.cleanup, true, 'Tenant recovery resources retained after cleanup failure.')
  }
})
