import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { assertLocalOrigin, loadProfile, percentile, summarize, validatePeople } from './load-protocol.mjs'

test('load runner refuses targets, custom volumes and unknown profiles before creating resources', () => {
  assert.equal(loadProfile([]).name, 'smoke')
  assert.equal(loadProfile(['--profile=smoke']).samples, 100)
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
      assert.match(result.stdout + result.stderr, /isolated PostgreSQL smoke load profile|Isolated browser environment required/)
    }
  }
})
