import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { createHash, createHmac, randomBytes, randomUUID } from 'node:crypto'
import { closeSync, existsSync, mkdirSync, mkdtempSync, openSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:net'
import { cpus, platform, release, tmpdir, totalmem } from 'node:os'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { performance } from 'node:perf_hooks'
import { setTimeout as delay } from 'node:timers/promises'
import { request } from '@playwright/test'
import { assertHarnessRoot, isolatedEnvironment } from './environment.mjs'
import { TemporaryPostgres } from './postgresql.mjs'
import { assertLocalOrigin, backoffMilliseconds, loadProfile, measurementResource, summarize, validatePeople } from './load-protocol.mjs'

// Validate CLI before creating a directory, starting a process or contacting a target.
const profile = loadProfile(process.argv.slice(2))
const front = fileURLToPath(new URL('../..', import.meta.url))
const backend = resolve(front, '../back')
const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
const token = randomBytes(32).toString('hex')
const abort = new AbortController()
const servers = []
const contexts = []
const reservedPorts = new Set()
const reportPath = resolve(front, `reports/load-${profile.name}-${Date.now()}.json`)
const git = spawnSync('git', ['-c', `safe.directory=${resolve(front, '..')}`, 'rev-parse', 'HEAD'], { cwd: front, encoding: 'utf8', windowsHide: true })
const gitStatus = spawnSync('git', ['-c', `safe.directory=${resolve(front, '..')}`, 'status', '--porcelain'], { cwd: front, encoding: 'utf8', windowsHide: true })
const report = {
  schemaVersion: 1, ticket: 'UCG-QA-005', profile: profile.name,
  sourceCommit: git.status === 0 ? git.stdout.trim() : null,
  sourceWorkingTreeDirty: gitStatus.status === 0 ? Boolean(gitStatus.stdout.trim()) : null,
  harnessSha256: Object.fromEntries([
    'tests/real-api/load-run.mjs', 'tests/real-api/load-protocol.mjs', 'tests/real-api/environment.mjs', 'tests/real-api/postgresql.mjs',
    '../back/tests/Fixtures/load-application.php', '../back/tests/Fixtures/load-seed.php', '../back/tests/Fixtures/load-server.php',
    '../back/tests/Fixtures/load-audit.php', '../back/tests/Fixtures/browser-application.php', '../back/tests/Fixtures/browser-postgresql.php', '../back/tests/Fixtures/browser-worker.php',
    '../back/tests/Fixtures/load-volume.php',
    '../back/app/Modules/Tenancy/Application/Services/TenantDataProjectionService.php',
    '../back/app/Modules/Tenancy/Presentation/Http/Middleware/LimitTenantRequests.php',
  ].map((path) => [path, createHash('sha256').update(readFileSync(resolve(front, path))).digest('hex')])),
  startedAt: new Date().toISOString(), completed: false, cleanup: false,
  topology: { database: 'PostgreSQL 17 disposable', httpServer: 'PHP built-in development server', httpProcesses: profile.httpProcesses ?? 2, workers: 1, queue: 'database', cache: 'database', loadModel: 'closed-loop authenticated sessions', tls: false },
  host: { os: platform(), release: release(), cpu: cpus()[0]?.model, logicalCpus: cpus().length, memoryBytes: totalmem(), node: process.version },
  limits: ['local development topology only', 'no public visitors', 'no Redis/Horizon qualification', 'no browser rendering or real-time delivery measurement', 'authentication excluded from measured phases', 'loopback timings include test ownership checks and application bootstrap', 'SQL counters exclude bootstrap, ownership checks and termination', 'load generator and services share an uncontrolled workstation', 'played game fixtures are synthetic and do not certify approved match results'],
  phases: {}, samples: {},
}
let postgres
let environment
let fixtureData
let activeRequests = 0
let peakRequests = 0
const stop = () => abort.abort()
process.once('SIGINT', stop)
process.once('SIGTERM', stop)
const deadlineTimer = setTimeout(stop, profile.timeoutMs ?? 300_000)

async function port() {
  for (;;) {
    const server = createServer()
    await new Promise((done, reject) => server.once('error', reject).listen(0, '127.0.0.1', done))
    const value = server.address().port
    await new Promise((done, reject) => server.close((error) => error ? reject(error) : done()))
    if (!reservedPorts.has(value)) { reservedPorts.add(value); return value }
  }
}

function execute(file) {
  return new Promise((done, reject) => {
    const child = spawn('php', [`tests/Fixtures/${file}`], { cwd: backend, env: environment, windowsHide: true, signal: abort.signal, timeout: profile.games ? 420_000 : 90_000, stdio: ['ignore', 'pipe', 'pipe'] })
    let output = ''
    let nonEmptySchema = false
    let databaseFailure = ''
    let failure
    child.stdout.on('data', (chunk) => { output += chunk })
    // stderr is deliberately not printed: database exceptions can contain DSNs.
    let diagnosticTail = ''
    child.stderr.on('data', (chunk) => {
      diagnosticTail = (diagnosticTail + chunk).slice(-4096)
      if (diagnosticTail.includes('An empty owned PostgreSQL load database is required.')) nonEmptySchema = true
      const sqlState = diagnosticTail.match(/SQLSTATE\[([A-Z0-9]+)\]/)
      const guard = diagnosticTail.match(/ERROR:\s*([a-zA-Z -]+)(?:\r?\n|\s*\()/)
      if (sqlState) databaseFailure = ` SQLSTATE ${sqlState[1]}${guard ? ` (${guard[1].trim()})` : ''}.`
    })
    child.once('error', (error) => { failure = error })
    child.once('close', (code) => code === 0 && !failure && !nonEmptySchema ? done(output) : reject(new Error(nonEmptySchema
      ? 'An empty owned PostgreSQL load database is required.'
      : `${file} failed (${code ?? 'spawn/abort'}).${databaseFailure}`)))
  })
}

async function start(args, env, ready) {
  const descriptor = openSync(`${root}/process-${servers.length}.log`, 'a', 0o600)
  const child = spawn('php', args, { cwd: backend, env, windowsHide: true, signal: abort.signal, stdio: ['ignore', descriptor, descriptor] })
  closeSync(descriptor)
  const entry = { child, error: null, closed: new Promise((done) => child.once('close', done)) }
  servers.push(entry)
  child.once('error', (error) => { entry.error = error })
  const until = performance.now() + 45_000
  while (performance.now() < until && !abort.signal.aborted) {
    if (entry.error || child.exitCode !== null || child.signalCode !== null) throw new Error('A temporary load process failed to start.')
    if (ready === null) {
      if (existsSync(`${root}/storage/framework/browser-worker-ready`)) return
    } else {
      try {
        const response = await fetch(ready, { signal: AbortSignal.timeout(1000), redirect: 'error' })
        await response.arrayBuffer()
        if (response.ok) return
      } catch { /* bounded readiness polling */ }
    }
    await new Promise((done) => setTimeout(done, 100))
  }
  throw new Error('Temporary load process readiness timed out.')
}

function totp() {
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000)))
  const digest = createHmac('sha1', '12345678901234567890').update(counter).digest()
  const offset = digest[digest.length - 1] & 15
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0')
}

async function actor(origin, email, organization) {
  const context = await request.newContext({ baseURL: assertLocalOrigin(origin), extraHTTPHeaders: { Accept: 'application/json', Origin: environment.FRONTEND_URL }, timeout: profile.readers > 1 ? 30_000 : 15_000, maxRedirects: 0 })
  contexts.push(context)
  const csrf = await context.get('/sanctum/csrf-cookie')
  assert.equal(csrf.status(), 204, 'CSRF preparation failed')
  await csrf.dispose()
  const headers = async () => {
    const state = await context.storageState()
    const cookie = state.cookies.find((value) => value.name === 'XSRF-TOKEN')
    assert.ok(cookie, 'CSRF cookie missing')
    return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) }
  }
  const json = async (method, path, data, extra = {}) => {
    if (abort.signal.aborted) throw new Error('Load run interrupted.')
    const response = await context.fetch(path, { method, data, headers: { ...await headers(), ...extra } })
    try {
      const payload = await response.json()
      if (!response.ok()) throw new Error(`Load setup/workload HTTP ${response.status()} (${payload.code ?? 'no code'}).`)
      return payload
    } finally { await response.dispose() }
  }
  const signedIn = await json('POST', '/api/v1/auth/login', { email, password: 'Load-fixture-only-2026!' })
  if (signedIn.data.mfa.enabled || signedIn.data.mfa.required) await json('POST', '/api/v1/auth/mfa/challenge', { code: totp() })
  const selected = (await json('POST', '/api/v1/active-organization', { organization_id: organization })).data
  assert.equal(selected.context.organization_id, organization)
  await json('POST', '/api/v1/active-organization/confirmation', { organization_id: organization, revision: selected.revision, confirm: true })
  return { context, json: (method, path, data) => json(method, path, data, { 'X-Tenant-Revision': selected.revision }), revision: selected.revision }
}

async function peopleSample(actor_, organization, ids, pageSize, resource = 'people', parent = null) {
  const started = performance.now()
  activeRequests++
  peakRequests = Math.max(peakRequests, activeRequests)
  let response
  let status = 0
  let backoffMs = 0
  let rateRefusal = 'unclassified'
  try {
    if (abort.signal.aborted) throw new Error('Interrupted')
    const path = resource === 'games' ? `matches/${parent}/games` : resource
    response = await actor_.context.get(`/api/v1/tenants/${organization}/${path}?per_page=${pageSize}`, { headers: { 'X-Tenant-Revision': actor_.revision } })
    status = response.status()
    if (status === 429) {
      backoffMs = backoffMilliseconds(response.headers()['retry-after'])
      const reason = response.headers()['x-ucg-rate-refusal']
      if (['quota', 'contention'].includes(reason)) rateRefusal = reason
    }
    const payload = await response.json()
    const header = response.headers()['x-ucg-query-count']
    const queries = Number(header)
    const sqlMs = Number(response.headers()['x-ucg-query-ms'])
    const bootstrapMs = Number(response.headers()['x-ucg-bootstrap-ms'])
    const handlerMs = Number(response.headers()['x-ucg-handler-ms'])
    if (status !== 200 || !header || !Number.isInteger(queries) || queries < 1
      || [sqlMs, bootstrapMs, handlerMs].some((value) => !Number.isFinite(value) || value < 0)) throw new Error('HTTP or query instrumentation failure')
    validatePeople(payload, ids, pageSize)
    return { status, ok: true, ms: performance.now() - started, startedMs: started, endedMs: performance.now(), queries, sqlMs, bootstrapMs, handlerMs, pageSize, resource }
  } catch {
    if (status === 200 || (status === 429 && backoffMs === 0)) abort.abort()
    return { status, ok: false, ms: performance.now() - started, startedMs: started, endedMs: performance.now(), pageSize, resource, backoffMs, rateRefusal }
  } finally { activeRequests--; if (response) await response.dispose() }
}

async function phase(name, readers) {
  console.log(`QA-005 ${name}: ${profile.samples * readers.length} measured reads, ${readers.length} reader sessions.`)
  const samples = []
  const phaseStarted = performance.now()
  peakRequests = activeRequests
  report.samples[name] = samples
  await Promise.all(readers.map(async (reader, actorIndex) => {
    for (let index = 0; index < profile.samples; index++) {
      // Alpha's people list legitimately grows during imports; immutable ID checks
      // stay on its match history while Beta's people list remains protected.
      const resource = measurementResource(profile, reader.tenant, index + actorIndex)
      const parent = resource === 'games' ? fixtureData.matches[reader.tenant][0] : null
      const sample = await peopleSample(reader, fixtureData.organizations[reader.tenant], new Set(fixtureData[resource][reader.tenant]), (index + actorIndex) % 2 ? 100 : 25, resource, parent)
      samples.push({ ...sample, tenant: reader.tenant, actor: actorIndex })
      if (abort.signal.aborted) break
      if (sample.backoffMs) await delay(sample.backoffMs, undefined, { signal: abort.signal }).catch(() => {})
    }
  }))
  report.phases[name] = summarize(samples)
  report.phases[name].readerSessions = readers.length
  report.phases[name].peakOutstandingRequests = peakRequests
  report.phases[name].durationMs = performance.now() - phaseStarted
  report.phases[name].requestsPerSecond = samples.length / (report.phases[name].durationMs / 1000)
  report.phases[name].tenants = Object.fromEntries([...new Set(samples.map((sample) => sample.tenant))].map((tenant) => [tenant, summarize(samples.filter((sample) => sample.tenant === tenant))]))
  assert.equal(samples.length, profile.samples * readers.length, 'Measurement phase incomplete')
}

try {
  writeFileSync(`${root}/owner`, token, { flag: 'wx', mode: 0o600 })
  writeFileSync(`${root}/.env`, '', { flag: 'wx', mode: 0o600 })
  for (const path of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) mkdirSync(`${root}/storage/${path}`, { recursive: true })
  const alphaPort = await port()
  const betaPort = await port()
  const frontPort = await port()
  const pgPort = await port()
  environment = { ...isolatedEnvironment(root, token, alphaPort, frontPort), APP_KEY: `base64:${randomBytes(32).toString('base64')}`, UCG_LOAD_PROFILE: profile.name }
  postgres = new TemporaryPostgres(root, token, pgPort, process.env.UCG_BROWSER_PG_BIN, environment, abort.signal)
  Object.assign(environment, await postgres.start())
  const fixture = JSON.parse(await execute('load-seed.php'))
  fixtureData = fixture
  writeFileSync(`${root}/fixture.json`, JSON.stringify(fixture), { flag: 'wx', mode: 0o600 })
  report.dataset = fixture.counts
  if (profile.games) {
    assert.equal(fixture.counts.accounts, profile.accounts, 'Full account volume was not seeded')
    assert.equal(fixture.counts.games, profile.games, 'Full played-game volume was not seeded')
    assert.equal(fixture.counts.matches, 1000, 'Unexpected parent-match volume')
  }
  report.dataset.peoplePerTenant = Object.fromEntries(Object.entries(fixture.people).map(([key, ids]) => [key, ids.length]))
  report.before = JSON.parse(await execute('load-audit.php'))
  await assert.rejects(execute('load-seed.php'), /An empty owned PostgreSQL load database is required/, 'Reusing a populated schema must be refused')
  assert.deepEqual(JSON.parse(await execute('load-audit.php')), report.before, 'Refused reseed changed fixture data')
  report.populatedSchemaRefused = true
  await start(['-S', `127.0.0.1:${alphaPort}`, 'tests/Fixtures/load-server.php'], environment, `http://127.0.0.1:${alphaPort}/api/v1/system/status`)
  const origins = [`http://127.0.0.1:${alphaPort}`, `http://127.0.0.1:${betaPort}`]
  for (let index = 2; index < (profile.httpProcesses ?? 2); index++) origins.push(`http://127.0.0.1:${await port()}`)
  for (const origin of origins.slice(1)) await start(['-S', new URL(origin).host, 'tests/Fixtures/load-server.php'], { ...environment, APP_URL: origin }, `${origin}/api/v1/system/status`)
  const alpha = await actor(`http://127.0.0.1:${alphaPort}`, fixture.actors.alpha, fixture.organizations.alpha)
  const beta = await actor(`http://127.0.0.1:${betaPort}`, fixture.actors.beta, fixture.organizations.beta)
  alpha.tenant = 'alpha'
  beta.tenant = 'beta'
  const readers = [beta]
  if (profile.readers > 1) {
    for (const entry of fixture.readers) {
      const reader = await actor(origins[readers.length % origins.length], entry.email, fixture.organizations[entry.tenant])
      reader.tenant = entry.tenant
      readers.push(reader)
      if (readers.length % 20 === 0) console.log(`QA-005 authenticated readers: ${readers.length}.`)
    }
    assert.equal(readers.length, profile.readers)
  }
  report.dataset.activeSessions = contexts.length
  const protectedReaders = readers.filter((reader) => reader.tenant === 'beta')
  const betaIds = new Set(fixture.people.beta)
  for (let index = 0; index < profile.warmup; index++) assert.equal((await peopleSample(beta, fixture.organizations.beta, betaIds, index % 2 ? 100 : 25)).ok, true)
  await phase('baseline', protectedReaders)

  // Queue exports before starting the worker so their dispatch and completion are observable.
  const exports = []
  for (let index = 0; index < profile.exports; index++) {
    const payload = await alpha.json('POST', `/api/v1/tenants/${fixture.organizations.alpha}/exports`, {
      card: 'active_people', view: 'direction', from: fixture.from, to: fixture.to, confirm: true, request_key: randomUUID(),
    })
    assert.equal(payload.data.state, 'queued')
    exports.push(payload.data.id)
  }
  const imports = []
  if (profile.imports) {
    const csv = (batch, rows) => Buffer.from('pseudonym\n' + Array.from({ length: rows }, (_, index) => `QA005_${batch}_${index}`).join('\n') + '\n')
    const path = `/api/v1/tenants/${fixture.organizations.alpha}/people-imports`
    const csrf = (await alpha.context.storageState()).cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')
    const mutationHeaders = { 'X-XSRF-TOKEN': decodeURIComponent(csrf.value), 'X-Tenant-Revision': alpha.revision }
    const oversized = await alpha.context.post(path, { headers: mutationHeaders, multipart: { delimiter: ',', file: { name: 'oversized.csv', mimeType: 'text/csv', buffer: csv('oversized', 1001) } } })
    assert.equal(oversized.status(), 422, 'Oversized import must remain bounded')
    await oversized.dispose()
    report.importLimitRejected = true
    for (let index = 0; index < profile.imports; index++) {
      const upload = await alpha.context.post(path, { headers: mutationHeaders, multipart: { delimiter: ',', file: { name: 'load.csv', mimeType: 'text/csv', buffer: csv(index, profile.importRows) } } })
      assert.equal(upload.status(), 200, 'Import upload failed')
      const uploaded = (await upload.json()).data
      await upload.dispose()
      const preview = (await alpha.json('POST', `${path}/${uploaded.id}/preview`, { version: uploaded.version, mapping: { pseudonym: 0 }, defaults: { language: 'fr', timezone: 'UTC', status: 'active' } })).data
      assert.equal(preview.counts.ready, profile.importRows, 'Import preview must accept all synthetic rows')
      const confirmed = (await alpha.json('POST', `${path}/${uploaded.id}/confirm`, { version: preview.version, confirm: true })).data
      assert.equal(confirmed.status, 'queued')
      imports.push(uploaded.id)
    }
    report.queuedWork = { exports: exports.length, imports: imports.length, importRows: profile.imports * profile.importRows, workerPolicy: 'one database worker, FIFO reports queue with bounded import chunks' }
  }
  await start(['tests/Fixtures/browser-worker.php'], environment, null)
  let makingNoise = true
  const noiseStarted = performance.now()
  const noiseSamples = []
  const noise = (async () => {
    const resource = profile.games ? 'matches' : 'people'
    const alphaIds = new Set(fixture[resource].alpha)
    while (makingNoise && noiseSamples.length < 300 && !abort.signal.aborted) {
      noiseSamples.push(await peopleSample(alpha, fixture.organizations.alpha, alphaIds, 100, resource))
      if (noiseSamples.at(-1).backoffMs) await delay(noiseSamples.at(-1).backoffMs, undefined, { signal: abort.signal }).catch(() => {})
    }
  })()
  try { await phase('noisy', readers) }
  finally { makingNoise = false; await noise }
  report.samples.alphaNoise = noiseSamples
  report.alphaNoise = summarize(noiseSamples)
  const noiseEnded = performance.now()
  report.overlap = { readsWithinAlphaSession: report.samples.noisy.filter((sample) => sample.startedMs >= noiseStarted && sample.endedMs <= noiseEnded).length,
    readsIntersectingAlphaRequest: report.samples.noisy.filter((sample) => noiseSamples.some((noise_) => noise_.startedMs < sample.endedMs && noise_.endedMs > sample.startedMs)).length }
  assert.equal(report.overlap.readsWithinAlphaSession, profile.samples * readers.length, 'Noisy session ended before all reader measurements')
  const until = performance.now() + (profile.imports ? 300_000 : 60_000)
  for (const id of exports) {
    let row
    do {
      row = (await alpha.json('GET', `/api/v1/tenants/${fixture.organizations.alpha}/exports/${id}`)).data
      if (row.state === 'completed') break
      assert.equal(row.state, 'queued', 'Export failed or became unavailable')
      assert.ok(performance.now() < until, 'Export completion timed out')
      await new Promise((done) => setTimeout(done, 200))
    } while (!abort.signal.aborted)
    assert.equal(row.state, 'completed')
    assert.equal(row.row_count, 500)
  }
  for (const id of imports) {
    let row
    do {
      row = (await alpha.json('GET', `/api/v1/tenants/${fixture.organizations.alpha}/people-imports/${id}`)).data
      if (row.status === 'completed') break
      assert.ok(['queued', 'processing'].includes(row.status), 'Import failed')
      assert.ok(performance.now() < until, 'Import completion timed out')
      await new Promise((done) => setTimeout(done, 1000))
    } while (!abort.signal.aborted)
    assert.equal(row.status, 'completed')
    assert.equal(row.counts.created, profile.importRows)
  }
  await phase('recovery', protectedReaders)
  report.after = JSON.parse(await execute('load-audit.php'))
  assert.deepEqual(report.after.untouched, report.before.untouched, 'Beta or Gamma records/artifacts changed')
  assert.equal(report.after.failedJobs, 0)
  assert.equal(report.after.pendingJobs, 0)
  assert.equal(report.after.alphaExports.length, profile.exports)
  assert.ok(report.after.alphaExports.every((row) => row.status === 'completed' && row.row_count === 500))
  if (profile.imports) {
    assert.equal(report.after.alphaPeople, 500 + profile.imports * profile.importRows)
    assert.equal(report.after.alphaImports.length, profile.imports)
    assert.ok(report.after.alphaImports.every((row) => row.status === 'completed' && row.row_count === profile.importRows))
  }
  const protectedBefore = report.phases.baseline.tenants.beta
  const protectedDuring = report.phases.noisy.tenants.beta
  report.impact = { p95DeltaMs: protectedDuring.p95Ms - protectedBefore.p95Ms, p95Ratio: protectedDuring.p95Ms / protectedBefore.p95Ms }
  report.interactiveTarget = { p95LessThanMs: profile.targetP95Ms, metInAllPhases: Object.values(report.phases).every((value) => value.p95Ms < profile.targetP95Ms
    && value.errors === 0 && Object.values(value.tenants).every((tenant) => Object.values(tenant.resources).every((size) => size.p95Ms < profile.targetP95Ms)))
    && report.alphaNoise.errors === 0 }
  report.queryGrowth = Object.fromEntries(Object.entries(report.phases).map(([name, phase_]) => [name, phase_.queryCounts[100].max - phase_.queryCounts[25].max]))
  report.completed = true
  if (!report.interactiveTarget.metInAllPhases) process.exitCode = 1
} catch (error) {
  report.error = error.message
  console.error(`QA-005 ${profile.name} failed: ${error.message}`)
  process.exitCode = 1
} finally {
  clearTimeout(deadlineTimer)
  await Promise.allSettled(contexts.map((context) => context.dispose()))
  if (profile.name === 'profile' && existsSync(`${root}/query-profile.jsonl`)) {
    try {
      const groups = new Map()
      for (const line of readFileSync(`${root}/query-profile.jsonl`, 'utf8').trim().split('\n')) {
        for (const value of JSON.parse(line).groups) {
          const current = groups.get(value.fingerprint) ?? { ...value, count: 0, ms: 0 }
          current.count += value.count
          current.ms += value.ms
          groups.set(value.fingerprint, current)
        }
      }
      report.sqlProfile = [...groups.values()].sort((left, right) => right.ms - left.ms)
      assert.ok(report.sqlProfile.length > 0, 'SQL profiling produced no data')
    } catch { report.profilingIncomplete = true; process.exitCode = 1 }
  }
  if (profile.name === 'profile' && !report.sqlProfile) { report.profilingIncomplete = true; process.exitCode = 1 }
  try {
    const results = await Promise.allSettled(servers.map(async ({ child, closed }) => {
      if (child.exitCode === null && child.signalCode === null && child.pid) child.kill()
      let timer
      try { await Promise.race([closed, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Temporary HTTP/worker process did not stop.')), 5000) })]) }
      finally { clearTimeout(timer) }
    }))
    assert.equal(results.some((value) => value.status === 'rejected'), false, 'Temporary load process shutdown failed.')
    if (postgres) await postgres.stop()
    assertHarnessRoot(root)
    assert.equal(readFileSync(`${root}/owner`, 'utf8'), token, 'Temporary load root ownership changed')
    rmSync(root, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 })
    report.cleanup = true
    console.log('Isolated load resources stopped and removed.')
  } catch {
    report.cleanup = false
    report.retainedRoot = root
    console.error('Cleanup could not be proven; temporary resources retained (path in report).')
    process.exitCode = 1
  }
  report.finishedAt = new Date().toISOString()
  mkdirSync(resolve(front, 'reports'), { recursive: true })
  writeFileSync(reportPath, JSON.stringify(report, null, 2))
  console.log(`QA-005 report: ${reportPath}`)
}
