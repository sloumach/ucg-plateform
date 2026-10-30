import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { performance } from 'node:perf_hooks'
import { setTimeout as delay } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { backup, cleanupRecoveryLabs, fixture, restore, startRecoveryLab } from './recovery-lab.mjs'
import { assertRecoveryRoot, digest, privateFiles } from './recovery.mjs'

const reportPath = fileURLToPath(new URL('../../reports/tenant-qualification-local.json', import.meta.url))
const settled = (promise) => promise.then((value) => ({ value }), (error) => ({ error }))

async function waitFor(check, signal) {
  const deadline = performance.now() + 65_000
  while (!await check()) {
    if (performance.now() >= deadline) throw new Error('Recovery concurrency checkpoint timed out.')
    await delay(25, undefined, { signal })
  }
}

test('concurrent recovery serializes Alpha writes, preserves Beta and refuses genuine protected states', { timeout: 600_000 }, async (context) => {
  const started = performance.now()
  const report = { ticket: 'UCG-OPS-005', lots: ['concurrency', 'protected-states', 'hosted-recipe'], status: 'failed',
    synthetic: true, productionQualified: false, hostedRecipeExecuted: false, cleanup: false, checks: {} }
  report.sourcesSha256 = Object.fromEntries(['tenant-qualification.test.mjs', 'recovery-lab.mjs', 'recovery.mjs',
    'environment.mjs', 'postgresql.mjs', '../../../back/tests/Fixtures/tenant-graph.php',
    '../../../back/tests/Fixtures/RecoveryGraphRepository.php', '../../../back/tests/Fixtures/RecoveryGraphJournal.php',
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
  const sourceKey = randomBytes(32).toString('hex')
  const tenant = (lab, operation) => fixture(lab, operation, {}, { script: 'tenant-recovery.php' })
  const graph = (lab, operation, input, changes = {}) => fixture(lab, operation,
    { UCG_GRAPH_SOURCE_KEY: sourceKey, ...changes }, { script: 'tenant-graph.php', input })
  try {
    const origin = await startRecoveryLab(appKey, abort, labs)
    await fixture(origin, 'seed')
    await tenant(origin, 'prepare')
    await graph(origin, 'base')
    const baseline = await backup(origin, backupKey, appKey)
    const current = await startRecoveryLab(appKey, abort, labs)
    await restore(current, baseline, backupKey, appKey)
    await graph(origin, 'graph')
    const complete = await backup(origin, backupKey, appKey)
    const selection = await startRecoveryLab(appKey, abort, labs)
    await restore(selection, complete, backupKey, appKey)
    const packet = await graph(selection, 'export-alpha')
    await selection.postgres.stop()

    assert.deepEqual(await graph(origin, 'protected'), { contract: 'approved', match: 'validated', independent: true })
    const protectedBackup = await backup(origin, backupKey, appKey)
    const protectedSelection = await startRecoveryLab(appKey, abort, labs)
    await restore(protectedSelection, protectedBackup, backupKey, appKey)
    const protectedMatch = await graph(protectedSelection, 'export-alpha')
    const protectedContract = await graph(protectedSelection, 'export-alpha', undefined, { UCG_GRAPH_SELECTION: 'without-matches' })
    assert.ok(protectedMatch.source.rows.matches.some((row) => row.status === 'validated'))
    assert.equal(protectedContract.source.rows.contracts[0].status, 'approved')
    assert.equal(protectedMatch.source.rows.match_result_events.length, 2)
    assert.equal(protectedContract.source.rows.contract_transitions.length, 3)
    await protectedSelection.postgres.stop()
    await origin.postgres.stop()

    await tenant(current, 'damage')
    const alphaPath = `tenants/${packet.source.tenant}/artifacts/${packet.source.files[0].id}`
    const destination = `${current.root}/storage/app/private/${alphaPath}`
    assertRecoveryRoot(current.root, current.token)
    unlinkSync(destination)
    const before = await tenant(current, 'inspect')
    const beforeFiles = privateFiles(current.root, current.token)
    const request = { packet, confirmation: packet.source.tenant, preserve: [] }
    for (const archive of [protectedMatch, protectedContract]) {
      await assert.rejects(graph(current, 'plan', { ...request, packet: archive }), /GRAPH_PROTECTED_STATE/)
      assert.deepEqual(await tenant(current, 'inspect'), before)
      assert.deepEqual(privateFiles(current.root, current.token), beforeFiles)
      assert.equal(existsSync(`${current.root}/graph-journal.enc`), false)
    }
    report.checks.genuineApprovedContractAndValidatedMatchRefused = true
    const review = await graph(current, 'plan', request)
    request.preserve = review.plan.review.conflicts
    const stale = await graph(current, 'plan', request)
    await graph(current, 'alpha-change')
    await assert.rejects(graph(current, 'apply', stale), /GRAPH_STALE/)
    assert.equal(existsSync(`${current.root}/graph-journal.enc`), false)
    const approved = await graph(current, 'plan', request)
    const initial = await tenant(current, 'inspect')
    report.checks.alphaChangeRequiresNewReview = true

    // The same database and separate PHP processes exercise both transaction boundaries.
    for (const phase of ['sql', 'files']) {
      const operation = phase === 'sql' ? 'crash-after-sql' : 'resume'
      const recovery = settled(graph(current, operation, phase === 'sql' ? approved : undefined, { UCG_GRAPH_PAUSE: phase }))
      const ready = `${current.root}/graph-ready-${phase}`
      const release = `${current.root}/graph-release-${phase}`
      let writer
      let betaDuring
      try {
        await waitFor(() => existsSync(ready), abort.signal)
        const journalBytes = readFileSync(`${current.root}/graph-journal.enc`)
        await assert.rejects(graph(current, 'apply', approved), /GRAPH_BUSY/)
        if (phase === 'files') await assert.rejects(graph(current, 'resume'), /GRAPH_BUSY/)
        assert.deepEqual(readFileSync(`${current.root}/graph-journal.enc`), journalBytes)
        writer = settled(graph(current, 'alpha-change', undefined, { UCG_GRAPH_WRITER_PROBE: 'v1' }))
        await graph(current, 'beta-change')
        await waitFor(async () => (await graph(current, 'concurrency-status')).waitingWriters === 1, abort.signal)
        const status = await graph(current, 'concurrency-status')
        assert.equal(status.markers, phase === 'sql' ? 0 : 1)
        const during = await tenant(current, 'inspect')
        betaDuring = during.beta
        assert.deepEqual(during.global, initial.global)
        assert.equal(existsSync(destination), false, 'File installation must wait for the checked phase.')
      } finally {
        assertRecoveryRoot(current.root, current.token)
        writeFileSync(release, 'release', { flag: 'wx', mode: 0o600 })
        const outcome = await recovery
        const written = writer ? await writer : null
        if (phase === 'sql') assert.match(outcome.error?.message ?? '', /failed \(73\)/)
        else {
          assert.equal(outcome.error, undefined)
          assert.equal(outcome.value.completed, true)
        }
        if (written) {
          assert.equal(written.error, undefined)
          assert.deepEqual(written.value, { changed: true })
        }
      }
      const after = await tenant(current, 'inspect')
      assert.deepEqual(after.beta, betaDuring, 'Recovery must preserve the concurrent Beta commit.')
      assert.deepEqual(after.global, initial.global)
      assert.equal((await graph(current, 'concurrency-status')).markers, 1)
      report.checks[`${phase}PhaseCompetingRecoveryRefused`] = true
      report.checks[`${phase}PhaseAlphaWriterWaitedAndCommitted`] = true
      report.checks[`${phase}PhaseBetaWriterProgressed`] = true
    }
    assert.deepEqual(await graph(current, 'verify'), { graph: true, decryption: true, historyProtected: true, markers: 1 })
    const after = await tenant(current, 'inspect')
    await graph(current, 'resume')
    assert.deepEqual(await tenant(current, 'inspect'), after, 'Repeated resume must not duplicate rows, audit or markers.')
    const files = privateFiles(current.root, current.token)
    assert.deepEqual(files.filter((file) => file.path !== alphaPath), beforeFiles)
    assert.equal(files.find((file) => file.path === alphaPath).sha256, packet.source.files[0].sha256)
    report.checks.idempotentRecoveryAndPrivateFilesVerified = true
    report.checks.historyGuardsAndDecryptionVerified = true
    report.backupSha256 = { baseline: digest(baseline), graph: digest(complete), protected: digest(protectedBackup) }
    report.insertedRows = approved.plan.review.ordered.length
    report.betaTables = Object.keys(after.beta).length
    report.globalTables = Object.keys(after.global).length
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
