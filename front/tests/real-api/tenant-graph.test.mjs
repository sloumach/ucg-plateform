import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { performance } from 'node:perf_hooks'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { backup, cleanupRecoveryLabs, fixture, restore, startRecoveryLab } from './recovery-lab.mjs'
import { assertRecoveryRoot, digest, privateFiles } from './recovery.mjs'

const reportPath = fileURLToPath(new URL('../../reports/tenant-graph-local.json', import.meta.url))
const tenant = (lab, operation, input) => fixture(lab, operation, {}, { script: 'tenant-recovery.php', input })

test('missing People, Teams, Match and Contract records recover with conflict review and restartable files', { timeout: 480_000 }, async (context) => {
  const started = performance.now()
  const report = { ticket: 'UCG-OPS-005', lots: [2, 3, 4], status: 'failed', synthetic: true, productionQualified: false,
    createdAt: new Date().toISOString(), runtime: { node: process.versions.node, platform: process.platform, postgresMajor: 17 }, phasesMs: {}, cleanup: false }
  report.sourcesSha256 = Object.fromEntries(['tenant-graph.test.mjs', 'recovery-lab.mjs', 'recovery.mjs', 'environment.mjs', 'postgresql.mjs',
    '../../../back/tests/Fixtures/tenant-graph.php', '../../../back/tests/Fixtures/RecoveryGraphRepository.php',
    '../../../back/tests/Fixtures/RecoveryGraphJournal.php', '../../../back/tests/Fixtures/recovery.php',
    '../../../back/tests/Fixtures/tenant-recovery.php', '../../../back/tests/Fixtures/browser-application.php', '../../../back/tests/Fixtures/browser-postgresql.php']
    .map((path) => [path, digest(readFileSync(new URL(path, import.meta.url)))]))
  const labs = []
  const abort = new AbortController()
  const interrupt = () => abort.abort()
  context.signal.addEventListener('abort', interrupt, { once: true })
  process.once('SIGINT', interrupt)
  process.once('SIGTERM', interrupt)
  const appKey = `base64:${randomBytes(32).toString('base64')}`
  const backupKey = randomBytes(32)
  const sourceKey = randomBytes(32).toString('hex')
  const graph = (lab, operation, input, changes = {}) => fixture(lab, operation,
    { UCG_GRAPH_SOURCE_KEY: sourceKey, ...changes }, { script: 'tenant-graph.php', input })
  try {
    let phase = performance.now()
    const origin = await startRecoveryLab(appKey, abort, labs)
    await fixture(origin, 'seed')
    await tenant(origin, 'prepare')
    await graph(origin, 'base')
    const base = await backup(origin, backupKey, appKey)
    const current = await startRecoveryLab(appKey, abort, labs)
    await restore(current, base, backupKey, appKey)
    await graph(origin, 'graph')
    const complete = await backup(origin, backupKey, appKey)
    const staging = await startRecoveryLab(appKey, abort, labs)
    await restore(staging, complete, backupKey, appKey)
    const packet = await graph(staging, 'export-alpha')
    const foreign = await graph(staging, 'export-beta')
    const faults = []
    for (const [fault, reason] of [['column', 'GRAPH_COLUMNS'], ['reference', 'GRAPH_REFERENCE'],
      ['global-reference', 'GRAPH_REFERENCE'], ['state', 'GRAPH_PROTECTED_STATE'], ['duplicate', 'GRAPH_DUPLICATE'], ['scope', 'GRAPH_SCOPE'], ['access', 'GRAPH_ACCESS_REVIEW']]) {
      faults.push({ packet: await graph(staging, 'export-alpha', undefined, { UCG_GRAPH_FAULT: fault }), reason })
    }
    await staging.postgres.stop()
    await origin.postgres.stop()
    report.backupSha256 = digest(complete)
    report.phasesMs.backupAndSelection = performance.now() - phase

    await tenant(current, 'damage')
    const alphaPath = `tenants/${packet.source.tenant}/artifacts/${packet.source.files[0].id}`
    const destination = `${current.root}/storage/app/private/${alphaPath}`
    assertRecoveryRoot(current.root, current.token)
    unlinkSync(destination)
    const before = await tenant(current, 'inspect')
    const betaFiles = privateFiles(current.root, current.token)
    assert.equal(before.beta.people.rows, 2)
    assert.equal(before.all.person_game_profiles.rows, 0, 'The graph must be genuinely absent from the current database.')
    const request = { packet, confirmation: packet.source.tenant, preserve: [] }
    phase = performance.now()
    const review = await graph(current, 'plan', request)
    assert.equal(review.plan.review.conflicts.length, 1)
    assert.ok(review.plan.review.ordered.length >= 16)
    assert.ok(review.plan.review.derived.length > 0, 'Derived generations must not be restored from the past.')
    assert.deepEqual(await tenant(current, 'inspect'), before, 'Planning is read-only.')
    await assert.rejects(graph(current, 'apply', review), /GRAPH_UNRESOLVED/)
    await assert.rejects(graph(current, 'plan', { ...request, packet: foreign }), /GRAPH_SCOPE_OR_SCHEMA/)
    await assert.rejects(graph(current, 'plan', { ...request, confirmation: foreign.source.tenant }), /GRAPH_SCOPE_OR_SCHEMA/)
    const altered = structuredClone(packet)
    altered.source.rows.people[0].pseudonym = 'Altered archive'
    await assert.rejects(graph(current, 'plan', { ...request, packet: altered }), /GRAPH_SOURCE_SIGNATURE/)
    for (const fault of faults) {
      await assert.rejects(graph(current, 'plan', { ...request, packet: fault.packet }), new RegExp(fault.reason))
    }
    assert.deepEqual(await tenant(current, 'inspect'), before)
    assert.deepEqual(privateFiles(current.root, current.token), betaFiles)
    const decisions = review.plan.review.conflicts
    const approved = await graph(current, 'plan', { ...request, preserve: decisions })
    assert.deepEqual(approved.plan.review.unresolved, [], 'Every existing conflict needs an explicit preserve decision.')
    const tampered = structuredClone(approved)
    tampered.plan.review.ordered = []
    await assert.rejects(graph(current, 'apply', tampered), /GRAPH_PLAN_SIGNATURE/)
    await graph(current, 'alpha-change')
    await assert.rejects(graph(current, 'apply', approved), /GRAPH_STALE/)
    assert.equal(existsSync(`${current.root}/graph-journal.enc`), false)
    const fresh = await graph(current, 'plan', { ...request, preserve: decisions })
    assert.deepEqual(fresh.plan.review.unresolved, [], 'The refreshed plan must resolve all conflicts.')
    await graph(current, 'hold')
    await assert.rejects(graph(current, 'apply', fresh), /GRAPH_HOLD_OR_PURGE/)
    await graph(current, 'unhold')
    // Beta may progress after Alpha's plan; its current state must survive.
    await graph(current, 'beta-change')
    const latest = await tenant(current, 'inspect')
    report.phasesMs.planningAndConflicts = performance.now() - phase

    phase = performance.now()
    await assert.rejects(graph(current, 'rollback', fresh), /GRAPH_ROLLBACK/)
    assert.deepEqual(await tenant(current, 'inspect'), latest)
    assert.equal(existsSync(destination), false)
    await assert.rejects(graph(current, 'crash-after-sql', fresh), /failed \(73\)/)
    const committed = await tenant(current, 'inspect')
    assert.equal(committed.all.person_game_profiles.rows, 1)
    assert.equal(committed.all.contract_transitions.rows, 1)
    assert.equal(existsSync(destination), false)
    assert.deepEqual(committed.beta, latest.beta)
    assert.deepEqual(committed.global, latest.global)
    // A pending SQL commit must never be replayed when its recovery material is unavailable.
    const journalPath = `${current.root}/graph-journal.enc`
    const savedJournal = readFileSync(journalPath)
    const pendingFiles = privateFiles(current.root, current.token)
    for (const [label, changes, reason] of [
      ['application-key', { APP_KEY: `base64:${randomBytes(32).toString('base64')}` }, 'GRAPH_FIXTURE_FAILED'],
      ['source-key', { UCG_GRAPH_SOURCE_KEY: randomBytes(32).toString('hex') }, 'GRAPH_SOURCE_SIGNATURE'],
    ]) {
      await assert.rejects(graph(current, 'resume', undefined, changes), new RegExp(reason), label)
      assert.deepEqual(await tenant(current, 'inspect'), committed, `${label}: no SQL, audit or marker change`)
      assert.deepEqual(privateFiles(current.root, current.token), pendingFiles, `${label}: no file installation`)
      assert.deepEqual(readFileSync(journalPath), savedJournal, `${label}: keep original encrypted journal`)
    }
    for (const [label, replacement, reason] of [
      ['missing', null, 'GRAPH_JOURNAL'],
      ['truncated', savedJournal.subarray(0, Math.floor(savedJournal.length / 2)), 'GRAPH_FIXTURE_FAILED'],
      ['altered', Buffer.from('unauthenticated recovery journal'), 'GRAPH_FIXTURE_FAILED'],
    ]) {
      assertRecoveryRoot(current.root, current.token)
      try {
        if (replacement === null) unlinkSync(journalPath)
        else writeFileSync(journalPath, replacement)
        await assert.rejects(graph(current, 'resume'), new RegExp(reason), label)
        assert.deepEqual(await tenant(current, 'inspect'), committed, `${label}: no SQL, audit or marker change`)
        assert.deepEqual(privateFiles(current.root, current.token), pendingFiles, `${label}: no file installation`)
        if (replacement === null) assert.equal(existsSync(journalPath), false, 'Never recreate a missing journal on resume.')
        else assert.deepEqual(readFileSync(journalPath), replacement, 'Keep the refused journal for investigation.')
      } finally {
        writeFileSync(journalPath, savedJournal, { mode: 0o600 })
      }
    }
    await graph(current, 'hold')
    try {
      await assert.rejects(graph(current, 'resume'), /GRAPH_HOLD_OR_PURGE/)
      assert.deepEqual(await tenant(current, 'inspect'), committed, 'A new hold blocks even a committed recovery.')
      assert.deepEqual(privateFiles(current.root, current.token), pendingFiles)
      assert.deepEqual(readFileSync(journalPath), savedJournal)
    } finally {
      await graph(current, 'unhold')
    }
    await assert.rejects(graph(current, 'changed-row-probe'), /GRAPH_RECOVERED_ROW_CHANGED/)
    assert.deepEqual(await tenant(current, 'inspect'), committed)
    assert.equal(existsSync(destination), false)
    // An unrelated object at the destination must never be overwritten on resume.
    writeFileSync(destination, 'Unexpected object', { flag: 'wx', mode: 0o600 })
    await assert.rejects(graph(current, 'resume'), /GRAPH_FILE_CONFLICT/)
    assert.equal(readFileSync(destination, 'utf8'), 'Unexpected object')
    assert.deepEqual(await tenant(current, 'inspect'), committed)
    assertRecoveryRoot(current.root, current.token)
    unlinkSync(destination)
    await assert.rejects(graph(current, 'crash-after-file'), /failed \(74\)/)
    assert.equal(existsSync(destination), true)
    assert.deepEqual(await tenant(current, 'inspect'), committed)
    const completed = await graph(current, 'resume')
    assert.equal(completed.completed, true)
    assert.equal(completed.insertedRows, fresh.plan.review.ordered.length)
    const verified = await graph(current, 'verify')
    assert.deepEqual(verified, { graph: true, decryption: true, historyProtected: true, markers: 1 })
    const after = await tenant(current, 'inspect')
    assert.deepEqual(after.beta, latest.beta)
    assert.deepEqual(after.global, latest.global)
    const allFiles = privateFiles(current.root, current.token)
    assert.deepEqual(allFiles.filter((file) => file.path !== alphaPath), betaFiles)
    assert.equal(allFiles.find((file) => file.path === alphaPath).sha256, packet.source.files[0].sha256)
    assert.deepEqual(await graph(current, 'resume'), completed)
    assert.deepEqual(await tenant(current, 'inspect'), after, 'Repeated resume must not duplicate rows or audit.')
    await graph(current, 'purge-marker')
    const blocked = await tenant(current, 'inspect')
    await assert.rejects(graph(current, 'resume'), /GRAPH_HOLD_OR_PURGE/)
    await assert.rejects(graph(current, 'plan', { ...request, preserve: decisions }), /GRAPH_HOLD_OR_PURGE/)
    assert.deepEqual(await tenant(current, 'inspect'), blocked)
    report.phasesMs.applyAndRestart = performance.now() - phase
    report.insertedRows = completed.insertedRows
    report.restoredTables = [...new Set(Object.values(fresh.plan.review.missing).map((row) => row.table))].sort()
    report.betaTables = Object.keys(after.beta).length
    report.globalTables = Object.keys(after.global).length
    report.checks = { missingGraph: true, readonlyPlan: true, authenticatedSource: true, closedCatalog: true,
      foreignReferencesRefused: true, protectedStatesRefused: true, explicitConflictPreservation: true,
      tamperedAndStalePlansRefused: true, holdAndPurgeRefused: true, rollback: true, committedSqlRestart: true,
      installedFileRestart: true, postCommitDriftRefused: true, noDuplicateRowsOrAudit: true, betaNewRowsAndFilesPreserved: true,
      wrongApplicationAndSourceKeysRefused: true, missingTruncatedAndAlteredJournalsRefused: true,
      pendingRecoveryHoldRefused: true, recoveryMaterialPreservedOnRefusal: true,
      globalDataPreserved: true, encryptedFieldsReadable: true, historyGuardsActive: true }
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
