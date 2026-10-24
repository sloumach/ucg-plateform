import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:net'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { assertHarnessRoot, isolatedEnvironment } from './environment.mjs'
import { TemporaryPostgres } from './postgresql.mjs'

test('a disposable PostgreSQL cluster proves ownership, refuses populated databases and stops safely', { timeout: 120_000 }, async () => {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  const foreignRoot = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  const token = 'f'.repeat(64)
  const backend = fileURLToPath(new URL('../../../back', import.meta.url))
  const socket = createServer()
  await new Promise((done, reject) => socket.once('error', reject).listen(0, '127.0.0.1', done))
  const port = socket.address().port
  await new Promise((done) => socket.close(done))
  writeFileSync(`${root}/owner`, token)
  writeFileSync(`${root}/.env`, '')
  const environment = isolatedEnvironment(root, token, 18000, 15173)
  const abort = new AbortController()
  const postgres = new TemporaryPostgres(root, token, port, process.env.UCG_BROWSER_PG_BIN, environment, abort.signal)
  const php = (script, changes = {}) => spawnSync('php', ['-r', script], { cwd: backend, env: { ...environment, ...changes }, encoding: 'utf8', windowsHide: true, timeout: 30_000 })
  try {
    Object.assign(environment, await postgres.start())
    const accepted = php('require "tests/Fixtures/browser-application.php"; echo "owned";')
    assert.equal(accepted.status, 0, accepted.stderr)
    assert.equal(accepted.stdout, 'owned')
    for (const changes of [
      { DB_HOST: 'localhost' }, { DB_DATABASE: 'postgres' }, { DB_PORT: '5432' },
      { DB_PASSWORD: 'foreign' }, { UCG_BROWSER_TOKEN: 'a'.repeat(64) },
    ]) {
      const refused = php('require "tests/Fixtures/browser-application.php";', changes)
      assert.notEqual(refused.status, 0)
      assert.match(refused.stderr, /Isolated browser environment required/)
    }
    // A plausible manifest and name must not authorize a different data directory.
    writeFileSync(`${foreignRoot}/owner`, token)
    mkdirSync(`${foreignRoot}/pgsql`)
    writeFileSync(`${foreignRoot}/pgsql/PG_VERSION`, '17')
    writeFileSync(`${foreignRoot}/postgres.json`, readFileSync(`${root}/postgres.json`))
    const foreign = { ...isolatedEnvironment(foreignRoot, token, 18000, 15173), ...postgres.databaseEnvironment }
    const unowned = php('require "tests/Fixtures/browser-application.php";', foreign)
    assert.notEqual(unowned.status, 0)
    assert.match(unowned.stderr, /temporary PostgreSQL ownership not proven/)
    const populate = php('require "tests/Fixtures/browser-application.php"; $db = new PDO("pgsql:host=127.0.0.1;port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); $db->exec("CREATE TABLE sentinel (id integer)");')
    assert.equal(populate.status, 0, populate.stderr)
    const seed = php('require "tests/Fixtures/browser-seed.php";')
    assert.notEqual(seed.status, 0)
    assert.match(seed.stderr, /An empty isolated PostgreSQL database is required/)
    const preserved = php('require "tests/Fixtures/browser-application.php"; $db = new PDO("pgsql:host=127.0.0.1;port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()")->fetchColumn();')
    assert.equal(preserved.status, 0, preserved.stderr)
    assert.equal(preserved.stdout, '1')
    writeFileSync(`${root}/owner`, 'a'.repeat(64))
    await assert.rejects(postgres.stop(), /ownership check failed/)
    assert.equal(existsSync(`${root}/pgsql/postmaster.pid`), true)
    writeFileSync(`${root}/owner`, token)
    abort.abort()
    await postgres.stop()
    await postgres.stop()
    assert.equal(existsSync(`${root}/pgsql/postmaster.pid`), false)
  } finally {
    await postgres.stop()
    for (const directory of [root, foreignRoot]) {
      assertHarnessRoot(directory)
      rmSync(directory, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 })
    }
  }
})
