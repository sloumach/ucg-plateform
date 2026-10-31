import { spawn } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:net'
import { tmpdir } from 'node:os'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { isolatedEnvironment } from './environment.mjs'
import { TemporaryPostgres } from './postgresql.mjs'
import { assertRecoveryRoot, digest, openSnapshot, privateFiles, sealSnapshot } from './recovery.mjs'

const backend = fileURLToPath(new URL('../../../back', import.meta.url))

export function fixture(lab, operation, changes = {}, { script = 'recovery.php', input, timeout = 90_000 } = {}) {
  assertRecoveryRoot(lab.root, lab.token)
  if (!['recovery.php', 'tenant-recovery.php', 'tenant-graph.php', 'retention-erasure.php'].includes(script)) throw new Error('Unknown recovery fixture.')
  if (!Number.isSafeInteger(timeout) || timeout < 1 || timeout > 180_000) throw new Error('Recovery fixture timeout refused.')
  return new Promise((done, reject) => {
    const child = spawn('php', [`tests/Fixtures/${script}`, operation], {
      cwd: backend, env: { ...lab.environment, ...changes }, windowsHide: true,
      signal: lab.abort.signal, timeout, stdio: ['pipe', 'pipe', 'pipe'],
    })
    let output = ''
    child.stdout.on('data', (chunk) => { output += chunk })
    child.stderr.resume()
    child.stdin.on('error', () => {})
    child.stdin.end(input === undefined ? '' : JSON.stringify(input))
    let failure
    child.once('error', (error) => { failure = error })
    child.once('close', (code) => {
      let response
      try { response = JSON.parse(output) } catch { /* Refusals may have no JSON. */ }
      if (failure || code !== 0) {
        const reason = /^(?:TENANT_RECOVERY|GRAPH|ERASURE|RETENTION)_[A-Z_]+$/.test(response?.refused) ? ` ${response.refused}` : ''
        return reject(new Error(`Recovery fixture ${operation} refused or failed (${code}).${reason}`))
      }
      return response === undefined ? reject(new Error('Recovery fixture returned invalid JSON.')) : done(response)
    })
  })
}

export function connectionArguments(lab) {
  lab.postgres.assertOwner()
  return ['--host=127.0.0.1', `--port=${lab.environment.DB_PORT}`, '--username=ucg_browser', '--no-password']
}

export async function startRecoveryLab(appKey, abort, labs, { initialize = true } = {}) {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  const token = randomBytes(32).toString('hex')
  writeFileSync(`${root}/owner`, token, { flag: 'wx', mode: 0o600 })
  writeFileSync(`${root}/.env`, '', { flag: 'wx', mode: 0o600 })
  for (const directory of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
    mkdirSync(`${root}/storage/${directory}`, { recursive: true })
  }
  const server = createServer()
  await new Promise((done, reject) => server.once('error', reject).listen(0, '127.0.0.1', done))
  const port = server.address().port
  await new Promise((done, reject) => server.close((error) => error ? reject(error) : done()))
  const environment = { ...isolatedEnvironment(root, token, 18000, 15173), APP_KEY: appKey, UCG_RECOVERY_FIXTURE: 'v1' }
  const postgres = new TemporaryPostgres(root, token, port, process.env.UCG_BROWSER_PG_BIN, environment, abort.signal)
  const lab = { root, token, environment, postgres, abort }
  labs.push(lab)
  if (initialize) Object.assign(environment, await postgres.start())
  else {
    Object.assign(environment, postgres.databaseEnvironment)
    writeFileSync(`${root}/postgres.json`, JSON.stringify({ token, port: environment.DB_PORT }), { flag: 'wx', mode: 0o600 })
  }
  return lab
}

export async function backup(lab, key, appKey) {
  assertRecoveryRoot(lab.root, lab.token)
  const files = privateFiles(lab.root, lab.token)
  const path = `${lab.root}/selective-source.dump`
  await lab.postgres.command('pg_dump', [...connectionArguments(lab), `--dbname=${lab.environment.DB_DATABASE}`,
    '--format=custom', '--no-owner', '--no-acl', `--file=${path}`])
  const dump = readFileSync(path)
  return sealSnapshot({ version: 1, appKeyHash: digest(appKey), dump: dump.toString('base64'), dumpHash: digest(dump), files }, key, appKey)
}

export async function restore(lab, envelope, key, appKey) {
  assertRecoveryRoot(lab.root, lab.token)
  const snapshot = openSnapshot(envelope, key, appKey)
  await fixture(lab, 'empty')
  if (privateFiles(lab.root, lab.token).length !== 0) throw new Error('Recovery requires empty private storage.')
  const path = `${lab.root}/restore.dump`
  writeFileSync(path, Buffer.from(snapshot.dump, 'base64'), { flag: 'wx', mode: 0o600 })
  await lab.postgres.command('pg_restore', [...connectionArguments(lab), `--dbname=${lab.environment.DB_DATABASE}`,
    '--no-owner', '--no-acl', '--single-transaction', '--exit-on-error', path])
  for (const file of snapshot.files) {
    const destination = `${lab.root}/storage/app/private/${file.path}`
    mkdirSync(dirname(destination), { recursive: true })
    writeFileSync(destination, Buffer.from(file.contents, 'base64'), { flag: 'wx', mode: 0o600 })
  }
}

export async function cleanupRecoveryLabs(labs) {
  let failed = false
  for (const lab of [...labs].reverse()) {
    try {
      await lab.postgres.stop()
      assertRecoveryRoot(lab.root, lab.token)
      if (existsSync(`${lab.root}/pgsql/postmaster.pid`)) throw new Error('Recovery server still running.')
      rmSync(lab.root, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 })
      if (existsSync(lab.root)) throw new Error('Recovery directory still present.')
    } catch {
      failed = true
      console.error(`Recovery resources retained for inspection: ${lab.root}`)
    }
  }
  return !failed
}
