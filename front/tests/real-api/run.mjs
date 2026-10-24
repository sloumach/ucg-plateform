import { spawn } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { closeSync, copyFileSync, existsSync, mkdirSync, mkdtempSync, openSync, readdirSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:net'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { assertHarnessRoot, isolatedEnvironment, playwrightArguments } from './environment.mjs'
import { TemporaryPostgres } from './postgresql.mjs'

const arguments_ = playwrightArguments(process.argv.slice(2))
const usePostgres = arguments_.includes('--postgresql')
const reportPrefix = usePostgres ? 'playwright-real-postgresql' : 'playwright-real'
const front = fileURLToPath(new URL('../..', import.meta.url))
const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
const token = randomBytes(32).toString('hex')
const servers = []
const abort = new AbortController()
let postgres = null
process.once('SIGINT', () => abort.abort())
process.once('SIGTERM', () => abort.abort())

async function availablePort() {
  const server = createServer()
  await new Promise((done, reject) => server.once('error', reject).listen(0, '127.0.0.1', done))
  const port = server.address().port
  await new Promise((done, reject) => server.close((error) => error ? reject(error) : done()))
  return port
}

function execute(command, args, environment, cwd, capture = false) {
  return new Promise((done, reject) => {
    const child = spawn(command, args, { cwd, env: environment, windowsHide: true, signal: abort.signal, stdio: capture ? ['ignore', 'pipe', 'inherit'] : 'inherit' })
    let output = ''
    let failure = null
    if (capture) child.stdout.on('data', (chunk) => { output += chunk })
    child.once('error', (error) => { failure = error })
    child.once('close', (code) => code === 0 && !failure ? done(output) : reject(failure || new Error(`Browser subprocess failed (${code}).`)))
  })
}

async function startServer(command, args, environment, cwd, url, name) {
  const log = `${root}/${name}.log`
  const descriptor = openSync(log, 'a')
  const child = spawn(command, args, { cwd, env: environment, windowsHide: true, signal: abort.signal, stdio: ['ignore', descriptor, descriptor] })
  closeSync(descriptor)
  const closed = new Promise((done) => child.once('close', done))
  const server = { child, closed, log, error: null }
  servers.push(server)
  child.once('error', (error) => { server.error = error })
  const deadline = Date.now() + 60_000
  while (Date.now() < deadline && !abort.signal.aborted) {
    if (server.error || child.exitCode !== null || child.signalCode !== null) {
      throw new Error(`${name} server stopped before readiness.`)
    }
    try {
      if (url === null) {
        if (existsSync(`${root}/storage/framework/browser-worker-ready`)) return
      } else {
        const response = await fetch(url, { signal: AbortSignal.timeout(1000) })
        if (response.ok) return
      }
    } catch { /* The process is still starting. */ }
    await new Promise((done) => setTimeout(done, 100))
  }
  throw new Error(`${name} server readiness timed out or was interrupted.`)
}

async function stopServer({ child, closed }) {
  await new Promise((done, reject) => {
    const timeout = setTimeout(() => reject(new Error('Temporary server did not stop.')), 5000)
    closed.then(() => { clearTimeout(timeout); done() })
    if (child.pid && child.exitCode === null && child.signalCode === null) child.kill()
  })
}

async function cleanUp() {
  try {
    const stopped = await Promise.allSettled(servers.map(stopServer))
    if (stopped.some((result) => result.status === 'rejected')) throw new Error('A browser process did not stop.')
    if (postgres) await postgres.stop()
    // Remove only the canonical directory freshly created by this process, after shutdown.
    assertHarnessRoot(root)
    rmSync(root, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 })
    console.log(`Isolated ${usePostgres ? 'PostgreSQL' : 'SQLite'} resources stopped and removed.`)
  } catch (error) {
    console.error(`${error.message} Temporary resources retained at ${root}.`)
    process.exitCode = 1
  }
}

try {
  rmSync(resolve(front, `reports/${reportPrefix}-application.log`), { force: true })
  writeFileSync(`${root}/owner`, token, { flag: 'wx', mode: 0o600 })
  writeFileSync(`${root}/.env`, '', { flag: 'wx', mode: 0o600 })
  if (!usePostgres) writeFileSync(`${root}/browser.sqlite`, '', { flag: 'wx', mode: 0o600 })
  for (const directory of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
    mkdirSync(`${root}/storage/${directory}`, { recursive: true })
  }
  const apiPort = await availablePort()
  let frontPort = await availablePort()
  while (frontPort === apiPort) frontPort = await availablePort()
  const environment = {
    ...isolatedEnvironment(root, token, apiPort, frontPort),
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  }
  if (usePostgres) {
    let port = await availablePort()
    while (port === apiPort || port === frontPort) port = await availablePort()
    postgres = new TemporaryPostgres(root, token, port, process.env.UCG_BROWSER_PG_BIN, environment, abort.signal)
    Object.assign(environment, await postgres.start())
  }
  const fixture = await execute('php', ['tests/Fixtures/browser-seed.php'], environment, resolve(front, '../back'), true)
  JSON.parse(fixture)
  writeFileSync(`${root}/fixture.json`, fixture, { flag: 'wx', mode: 0o600 })
  await startServer('php', ['tests/Fixtures/browser-worker.php'], environment, resolve(front, '../back'), null, 'worker')
  await startServer('php', ['-S', `127.0.0.1:${apiPort}`, 'tests/Fixtures/browser-server.php'], environment, resolve(front, '../back'), `${environment.UCG_BROWSER_API_URL}/api/v1/system/status`, 'api')
  await startServer(process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', String(frontPort), '--strictPort'], environment, front, environment.UCG_BROWSER_FRONT_URL, 'vite')
  await execute(process.execPath, ['node_modules/@playwright/test/cli.js', 'test', '--config=playwright.real-api.config.ts', ...arguments_.filter((argument) => argument !== '--postgresql')], environment, front)
  if (readdirSync(`${root}/storage/framework/sessions`).length === 0) {
    throw new Error('The HTTP session was not persisted inside the isolated storage directory.')
  }
} catch (error) {
  console.error(error.message)
  for (const server of servers) console.error(readFileSync(server.log, 'utf8').slice(-1000))
  const applicationLog = `${root}/storage/logs/laravel.log`
  if (existsSync(applicationLog)) {
    mkdirSync(resolve(front, 'reports'), { recursive: true })
    copyFileSync(applicationLog, resolve(front, `reports/${reportPrefix}-application.log`))
    console.error(readFileSync(applicationLog, 'utf8').split('\n').filter((line) => line.includes('testing.ERROR')).slice(-3).join('\n'))
  }
  process.exitCode = 1
} finally {
  await cleanUp()
}
