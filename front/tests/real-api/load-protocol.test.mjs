import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { assertLocalOrigin, backoffMilliseconds, loadProfile, measurementResource, percentile, readTimingHeaders, summarize, validatePeople } from './load-protocol.mjs'

test('load runner refuses targets, custom volumes and unknown profiles before creating resources', () => {
  assert.equal(loadProfile([]).name, 'smoke')
  assert.equal(loadProfile(['--profile=smoke']).samples, 100)
  assert.equal(loadProfile(['--profile=volume']).games, 100000)
  assert.equal(loadProfile(['--profile=volume']).accounts, 500)
  assert.equal(loadProfile(['--profile=volume']).readers + 1, 100)
  assert.equal(loadProfile(['--profile=profile']).name, 'profile')
  assert.equal(loadProfile(['--profile=pressure']).imports, 3)
  for (const args of [['--url=https://example.com'], ['--profile=cdc'], ['--users=100'], ['--profile=smoke', '--profile=smoke']]) {
    assert.throws(() => loadProfile(args))
  }
  for (const origin of ['https://example.com', 'http://localhost:8000', 'http://127.0.0.1:80/path', 'http://user@127.0.0.1:8000', 'http://127.0.0.1:8000?target=other']) {
    assert.throws(() => assertLocalOrigin(origin))
  }
  assert.equal(assertLocalOrigin('http://127.0.0.1:18001'), 'http://127.0.0.1:18001')
})

test('nearest-rank p95 retains the slow tail and does not mutate input', () => {
  const values = [900, ...Array.from({ length: 19 }, (_, index) => 19 - index)]
  assert.equal(percentile(values, 0.95), 19)
  assert.equal(percentile(values, 1), 900)
  assert.equal(values[0], 900)
  for (const invalid of [[], [NaN], [Infinity], [-1]]) assert.throws(() => percentile(invalid, 0.95))
  assert.throws(() => percentile([1], 0))
})

test('measurements count failed HTTP and transport requests in latency and errors', () => {
  const report = summarize([
    { status: 200, ok: true, ms: 10, pageSize: 25, queries: 8 },
    { status: 429, ok: false, ms: 300, pageSize: 25 },
    { status: 0, ok: false, ms: 1000, pageSize: 100 },
  ])
  assert.equal(report.requests, 3)
  assert.equal(report.errors, 2)
  assert.equal(report.p95Ms, 1000)
  assert.deepEqual(report.statusCounts, { 0: 1, 200: 1, 429: 1 })
  assert.deepEqual(report.queryCounts[25], { samples: 1, min: 8, max: 8, p95Ms: 10 })
  assert.equal(report.queryCounts[100], null)
  assert.throws(() => summarize([]))
})

test('latency success requires complete paginated responses from the expected tenant', () => {
  const ids = new Set(['beta-1', 'beta-2'])
  const valid = { data: [{ id: 'beta-1' }, { id: 'beta-2' }], meta: { total: 2 } }
  validatePeople(valid, ids, 25)
  for (const invalid of [
    { ...valid, data: [{ id: 'alpha-1' }, { id: 'beta-2' }] },
    { ...valid, data: [{ id: 'beta-1' }, { id: 'beta-1' }] },
    { ...valid, meta: { total: 3 } },
    { ...valid, data: [] },
  ]) assert.throws(() => validatePeople(invalid, ids, 25))
})

test('application residual is computed per successful sample without subtracting SQL twice', () => {
  const report = summarize([
    { status: 200, ok: true, ms: 1000, bootstrapMs: 20, handlerMs: 80, sqlMs: 40, pageSize: 25, queries: 8 },
    { status: 200, ok: true, ms: 200, bootstrapMs: 30, handlerMs: 170.001, sqlMs: 50, pageSize: 25, queries: 8 },
    { status: 200, ok: true, ms: 300, pageSize: 25, queries: 8 },
    { status: 429, ok: false, ms: 5000, bootstrapMs: 10, handlerMs: 20, pageSize: 25 },
  ])
  assert.deepEqual(report.diagnostics.outsideApplicationMs, { samples: 2, p50: 0, p95: 900 })
  assert.equal(report.p95Ms, 5000)
  assert.equal(report.errors, 1)
  assert.equal(summarize([{ status: 200, ok: true, ms: 5, pageSize: 25, queries: 1 }]).diagnostics.outsideApplicationMs, null)
})

test('connection and first-query diagnostics overlap SQL without reducing the latency residual', () => {
  const report = summarize([
    { status: 200, ok: true, ms: 1000, bootstrapMs: 20, handlerMs: 180, sqlMs: 100, connectionMs: 60, firstQueryMs: 65, pageSize: 25, queries: 8 },
    { status: 429, ok: false, ms: 5000, connectionMs: 900, firstQueryMs: 950, pageSize: 25 },
  ])
  assert.deepEqual(report.diagnostics.connectionMs, { samples: 1, p50: 60, p95: 60 })
  assert.deepEqual(report.diagnostics.firstQueryMs, { samples: 1, p50: 65, p95: 65 })
  assert.equal(report.diagnostics.outsideApplicationMs.p95, 800)
  assert.equal(report.p95Ms, 5000)
  const legacy = summarize([{ status: 200, ok: true, ms: 5, pageSize: 25, queries: 1 }])
  assert.equal(legacy.diagnostics.connectionMs, null)
  assert.equal(legacy.diagnostics.firstQueryMs, null)
  assert.deepEqual(legacy.servers, {})
})

test('server diagnostics retain failed requests and distinguish concurrent dispatches from a server queue', () => {
  const report = summarize([
    { server: 0, outstandingOnServer: 1, status: 200, ok: true, ms: 100, pageSize: 25, queries: 8 },
    { server: 0, outstandingOnServer: 25, status: 0, ok: false, ms: 30000, pageSize: 25 },
    { server: 1, outstandingOnServer: 2, status: 429, ok: false, ms: 400, pageSize: 25 },
  ])
  assert.deepEqual(report.servers, {
    0: { requests: 2, errors: 1, p95Ms: 30000, peakOutstandingAtDispatch: 25 },
    1: { requests: 1, errors: 1, p95Ms: 400, peakOutstandingAtDispatch: 2 },
  })
  assert.equal(report.errors, 2)
})

test('timing instrumentation refuses missing, blank and invalid values instead of coercing them to zero', () => {
  const headers = { 'x-ucg-query-ms': '80', 'x-ucg-bootstrap-ms': '20', 'x-ucg-handler-ms': '100', 'x-ucg-connection-ms': '0', 'x-ucg-first-query-ms': '50.125' }
  assert.deepEqual(readTimingHeaders(headers), { sqlMs: 80, bootstrapMs: 20, handlerMs: 100, connectionMs: 0, firstQueryMs: 50.125 })
  for (const header of Object.keys(headers)) {
    for (const invalid of [undefined, '', ' ', '-1', 'NaN', 'Infinity', 'abc', null]) {
      assert.throws(() => readTimingHeaders({ ...headers, [header]: invalid }))
    }
  }
})

test('PHP load fixtures refuse missing profiles, SQLite and foreign roots before boot', () => {
  const cwd = fileURLToPath(new URL('../../../back', import.meta.url))
  for (const file of ['load-seed.php', 'load-server.php', 'load-audit.php']) {
    for (const settings of [
      { UCG_LOAD_PROFILE: '', UCG_BROWSER_ENGINE: 'pgsql', DB_CONNECTION: 'pgsql' },
      { UCG_LOAD_PROFILE: 'smoke', UCG_BROWSER_ENGINE: 'sqlite', DB_CONNECTION: 'sqlite' },
      { UCG_LOAD_PROFILE: 'smoke', UCG_BROWSER_ENGINE: 'pgsql', DB_CONNECTION: 'mysql' },
      { UCG_LOAD_PROFILE: 'smoke', UCG_BROWSER_ENGINE: 'pgsql', DB_CONNECTION: 'pgsql', UCG_BROWSER_ROOT: cwd },
    ]) {
      const env = { ...process.env, ...settings, UCG_BROWSER_TOKEN: 'a'.repeat(64) }
      const result = spawnSync('php', [`tests/Fixtures/${file}`], { cwd, env, encoding: 'utf8', windowsHide: true })
      assert.notEqual(result.status, 0)
      assert.match(result.stdout + result.stderr, /isolated PostgreSQL load profile|Isolated browser environment required/)
    }
  }
})

test('resource metrics retain slow match pages and do not hide errors in a faster people average', () => {
  const report = summarize([
    { resource: 'people', pageSize: 25, status: 200, ok: true, ms: 10, queries: 48 },
    { resource: 'matches', pageSize: 25, status: 200, ok: true, ms: 900, queries: 51 },
    { resource: 'matches', pageSize: 100, status: 500, ok: false, ms: 1000 },
  ])
  assert.equal(report.resources['matches:25'].p95Ms, 900)
  assert.equal(report.resources['matches:100'].errors, 1)
  assert.equal(report.resources['matches:100'].p95Ms, 1000)
  assert.equal(report.errors, 1)
})

test('every supported PHP profile still refuses a foreign root before database access', () => {
  const cwd = fileURLToPath(new URL('../../../back', import.meta.url))
  for (const name of ['profile', 'volume', 'pressure']) {
    const result = spawnSync('php', ['tests/Fixtures/load-seed.php'], {
      cwd, env: { ...process.env, UCG_LOAD_PROFILE: name, UCG_BROWSER_ENGINE: 'pgsql', DB_CONNECTION: 'pgsql', UCG_BROWSER_ROOT: cwd, UCG_BROWSER_TOKEN: 'b'.repeat(64) },
      encoding: 'utf8', windowsHide: true,
    })
    assert.notEqual(result.status, 0)
    assert.match(result.stdout + result.stderr, /Isolated browser environment required/)
  }
})

test('backpressure waits are bounded and do not reinterpret malformed Retry-After values', () => {
  assert.equal(backoffMilliseconds('1'), 1000)
  assert.equal(backoffMilliseconds('60'), 60000)
  for (const value of [undefined, '0', '-1', '61', '1.5', '1 seconds', 'Infinity']) assert.throws(() => backoffMilliseconds(value))
})

test('pressure measurements allow Alpha imports to grow its people list while Beta stays strictly checked', () => {
  const pressure = loadProfile(['--profile=pressure'])
  assert.deepEqual(Array.from({ length: 3 }, (_, index) => measurementResource(pressure, 'beta', index)), ['people', 'matches', 'games'])
  assert.deepEqual(Array.from({ length: 4 }, (_, index) => measurementResource(pressure, 'alpha', index)), ['matches', 'games', 'matches', 'games'])
  assert.equal(measurementResource(loadProfile([]), 'alpha', 2), 'people')
  assert.equal(measurementResource(loadProfile(['--profile=volume']), 'alpha', 0), 'people')
})

test('rate refusal reasons keep every 429 and never infer an unobserved quota or contention', () => {
  const report = summarize([
    { status: 200, ok: true, ms: 5, pageSize: 25, queries: 1 },
    { status: 429, ok: false, ms: 10, pageSize: 25, rateRefusal: 'contention' },
    { status: 429, ok: false, ms: 10, pageSize: 25, rateRefusal: 'quota' },
    { status: 429, ok: false, ms: 10, pageSize: 25 },
    { status: 429, ok: false, ms: 10, pageSize: 25, rateRefusal: 'unknown' },
  ])
  assert.deepEqual(report.rateRefusals, { quota: 1, contention: 1, unclassified: 2 })
  assert.equal(report.errors, 4)
  assert.equal(Object.values(report.rateRefusals).reduce((sum, value) => sum + value, 0), report.statusCounts['429'])
})
