import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, realpathSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { assertHarnessRoot, isolatedEnvironment, playwrightArguments, postgresEnvironment } from './environment.mjs'
import { TemporaryPostgres } from './postgresql.mjs'

test('the runner cannot select another config or setup through CLI arguments', () => {
  assert.deepEqual(playwrightArguments([]), [])
  assert.deepEqual(playwrightArguments(['--headed']), ['--headed'])
  assert.deepEqual(playwrightArguments(['--postgresql', '--headed']), ['--postgresql', '--headed'])
  assert.throws(() => playwrightArguments(['--config=playwright.config.ts']))
  assert.throws(() => playwrightArguments(['--global-setup=foreign.js']))
})

test('the harness discards inherited application settings and refuses foreign paths', () => {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  try {
    const environment = isolatedEnvironment(root, 'a'.repeat(64), 18000, 15173, {
      PATH: process.env.PATH, DB_CONNECTION: 'pgsql', DB_DATABASE: 'production',
      APP_KEY: 'production-sentinel', AWS_SECRET_ACCESS_KEY: 'production-sentinel',
      MAIL_MAILER: 'smtp', MAIL_HOST: 'production-sentinel',
      VITE_APP_KEY: 'production-sentinel', CI: 'true',
      UCG_BROWSER_ENGINE: 'pgsql', PGHOST: 'production-sentinel', PGSERVICE: 'production-sentinel',
    })
    assert.equal(environment.DB_CONNECTION, 'sqlite')
    assert.equal(environment.DB_DATABASE, `${root}/browser.sqlite`)
    assert.equal(environment.QUEUE_CONNECTION, 'database')
    assert.equal(environment.REPORT_EXPORTS_ENABLED, 'true')
    assert.equal(environment.EXPIRE_TENANT_ARTIFACTS_ENABLED, 'false')
    assert.equal(environment.MAIL_MAILER, 'array')
    assert.equal(environment.CI, 'true')
    assert.equal(JSON.stringify(environment).includes('production-sentinel'), false)
    assert.throws(() => assertHarnessRoot(resolve(root, '..')))
    assert.throws(() => assertHarnessRoot(process.cwd()))
  } finally {
    assertHarnessRoot(root)
    rmSync(root, { recursive: true, force: true })
  }
})

test('PostgreSQL requires an explicit owned cluster before PHP can boot', () => {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  const token = 'e'.repeat(64)
  const backend = fileURLToPath(new URL('../../../back', import.meta.url))
  try {
    writeFileSync(`${root}/owner`, token)
    const environment = { ...isolatedEnvironment(root, token, 18000, 15173), ...postgresEnvironment(root, token, 15432) }
    assert.throws(() => postgresEnvironment(root, token, 543))
    assert.throws(() => postgresEnvironment(root, token, '15432'))
    assert.throws(() => postgresEnvironment(root, 'invalid', 15432))
    for (const change of [
      {}, { DB_HOST: 'foreign.example' }, { DB_DATABASE: 'ucg_platform' },
      { DB_USERNAME: 'postgres' }, { DB_PASSWORD: 'foreign' }, { DB_PORT: '15432;host=foreign' },
      { DB_SSLMODE: 'prefer' }, { DB_URL: 'postgres://foreign' }, { UCG_BROWSER_ENGINE: 'sqlite' },
    ]) {
      const result = spawnSync('php', ['tests/Fixtures/browser-seed.php'], { cwd: backend, env: { ...environment, ...change }, encoding: 'utf8', windowsHide: true })
      assert.notEqual(result.status, 0)
      assert.match(result.stderr, /Isolated browser environment required/)
      assert.equal(existsSync(`${root}/storage`), false)
    }
  } finally {
    assertHarnessRoot(root)
    rmSync(root, { recursive: true, force: true })
  }
})

test('an aborted startup creates no cluster and a foreign PID cannot be stopped', async () => {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  const token = 'a'.repeat(64)
  try {
    writeFileSync(`${root}/owner`, token)
    const abort = new AbortController()
    abort.abort()
    const postgres = new TemporaryPostgres(root, token, 15432, undefined, isolatedEnvironment(root, token, 18000, 15173), abort.signal)
    await assert.rejects(postgres.start(), /startup interrupted/)
    assert.equal(existsSync(`${root}/pgsql`), false)
    await postgres.stop()
    mkdirSync(`${root}/pgsql`)
    writeFileSync(`${root}/pgsql/postmaster.pid`, `123\n${root}\n0\n15432\n`)
    await assert.rejects(postgres.stop(), /PID ownership mismatch/)
    assert.equal(existsSync(`${root}/pgsql/postmaster.pid`), true)
  } finally {
    assertHarnessRoot(root)
    rmSync(root, { recursive: true, force: true })
  }
})

test('the browser worker refuses a synchronous queue or an external queue database', () => {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  const token = 'd'.repeat(64)
  const backend = fileURLToPath(new URL('../../../back', import.meta.url))
  try {
    writeFileSync(`${root}/owner`, token)
    writeFileSync(`${root}/.env`, '')
    writeFileSync(`${root}/browser.sqlite`, '')
    mkdirSync(`${root}/storage/framework`, { recursive: true })
    const environment = isolatedEnvironment(root, token, 18000, 15173)
    for (const change of [{ QUEUE_CONNECTION: 'sync' }, { DB_QUEUE_CONNECTION: 'pgsql' }]) {
      const result = spawnSync('php', ['tests/Fixtures/browser-worker.php'], { cwd: backend, env: { ...environment, ...change }, encoding: 'utf8', windowsHide: true })
      assert.notEqual(result.status, 0)
      assert.match(result.stdout + result.stderr, /An isolated database queue is required/)
      assert.equal(existsSync(`${root}/storage/framework/browser-worker-ready`), false)
      assert.equal(statSync(`${root}/browser.sqlite`).size, 0)
    }
  } finally {
    assertHarnessRoot(root)
    rmSync(root, { recursive: true, force: true })
  }
})

test('PHP bootstrap refuses wrong profile, DB, token and cached configuration before migrations', () => {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  const token = 'b'.repeat(64)
  const backend = fileURLToPath(new URL('../../../back', import.meta.url))
  try {
    writeFileSync(`${root}/owner`, token)
    writeFileSync(`${root}/browser.sqlite`, '')
    const environment = isolatedEnvironment(root, token, 18000, 15173)
    for (const change of [
      { APP_ENV: 'production' }, { DB_CONNECTION: 'pgsql' },
      { DB_DATABASE: '/foreign/production.sqlite' }, { DB_URL: 'postgres://foreign' },
      { UCG_BROWSER_TOKEN: 'c'.repeat(64) }, { APP_CONFIG_CACHE: '/foreign/config.php' },
      { APP_SERVICES_CACHE: '/foreign/services.php' }, { APP_PACKAGES_CACHE: '/foreign/packages.php' },
      { APP_ROUTES_CACHE: '/foreign/routes.php' }, { APP_EVENTS_CACHE: '/foreign/events.php' },
    ]) {
      const result = spawnSync('php', ['tests/Fixtures/browser-seed.php'], { cwd: backend, env: { ...environment, ...change }, encoding: 'utf8', windowsHide: true })
      assert.notEqual(result.status, 0)
      assert.match(result.stderr, /Isolated browser environment required/)
      assert.equal(statSync(`${root}/browser.sqlite`).size, 0)
    }
    writeFileSync(`${root}/config.php`, '<?php return [];')
    const cached = spawnSync('php', ['tests/Fixtures/browser-seed.php'], { cwd: backend, env: environment, encoding: 'utf8', windowsHide: true })
    assert.notEqual(cached.status, 0)
    assert.match(cached.stderr, /Isolated browser environment required/)
    assert.equal(statSync(`${root}/browser.sqlite`).size, 0)
  } finally {
    assertHarnessRoot(root)
    rmSync(root, { recursive: true, force: true })
  }
})
