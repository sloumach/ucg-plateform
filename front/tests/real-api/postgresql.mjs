import { spawn } from 'node:child_process'
import { appendFileSync, closeSync, existsSync, openSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import { isAbsolute, resolve } from 'node:path'
import { assertHarnessRoot, postgresEnvironment } from './environment.mjs'

// No existing server, service, database URL or inherited PG* setting is accepted.
export class TemporaryPostgres {
  constructor(root, token, port, binaryDirectory, environment, signal) {
    assertHarnessRoot(root)
    this.databaseEnvironment = postgresEnvironment(root, token, port)
    this.root = root
    this.token = token
    this.environment = { ...environment, PGPASSWORD: token, PGCONNECT_TIMEOUT: '3' }
    this.signal = signal
    this.data = `${root}/pgsql`
    if (binaryDirectory && (!isAbsolute(binaryDirectory) || realpathSync(binaryDirectory) !== binaryDirectory)) {
      throw new Error('UCG_BROWSER_PG_BIN must be a canonical absolute PostgreSQL 17 bin directory.')
    }
    this.binary = (name) => binaryDirectory ? resolve(binaryDirectory, `${name}${process.platform === 'win32' ? '.exe' : ''}`) : name
  }

  assertOwner() {
    assertHarnessRoot(this.root)
    if (readFileSync(`${this.root}/owner`, 'utf8') !== this.token
      || (existsSync(this.data) && realpathSync(this.data) !== resolve(this.data))) {
      throw new Error('Temporary PostgreSQL ownership check failed; resources retained.')
    }
  }

  command(name, args, cleanup = false) {
    return new Promise((done, reject) => {
      if (!cleanup && this.signal?.aborted) return reject(new Error('Temporary PostgreSQL startup interrupted.'))
      // Do not interrupt pg_ctl between daemon creation and its readiness result.
      // Daemons must not inherit Node pipes, which can keep close pending on Windows.
      const log = `${this.root}/pg-${name}.log`
      const descriptor = openSync(log, 'w', 0o600)
      const child = spawn(this.binary(name), args, {
        env: this.environment, cwd: this.root, windowsHide: true,
        stdio: ['ignore', descriptor, descriptor], timeout: 45_000,
      })
      closeSync(descriptor)
      let failure = null
      child.once('error', (error) => { failure = error })
      child.once('close', (code) => {
        const output = readFileSync(log, 'utf8')
        return code === 0 && !failure ? done(output) : reject(failure || new Error(`Temporary PostgreSQL ${name} failed (${code}): ${output.slice(-1500)}`))
      })
    })
  }

  async start() {
    this.assertOwner()
    const version = await this.command('initdb', ['--version'])
    if (!/\(PostgreSQL\) 17\./.test(version)) throw new Error('PostgreSQL 17 binaries are required.')
    writeFileSync(`${this.root}/pg-password`, this.token, { flag: 'wx', mode: 0o600 })
    await this.command('initdb', ['-D', this.data, '--username=ucg_browser', `--pwfile=${this.root}/pg-password`, '--auth=scram-sha-256', '--encoding=UTF8', '--no-locale'])
    appendFileSync(`${this.data}/postgresql.conf`, `\nlisten_addresses = '127.0.0.1'\nport = ${this.databaseEnvironment.DB_PORT}\nunix_socket_directories = ''\n`)
    // The manifest and data directory are private to the freshly created harness root.
    writeFileSync(`${this.root}/postgres.json`, JSON.stringify({ token: this.token, port: this.databaseEnvironment.DB_PORT }), { flag: 'wx', mode: 0o600 })
    await this.command('pg_ctl', ['-D', this.data, '-l', `${this.root}/postgresql.log`, '-w', '-t', '30', 'start'])
    await this.command('createdb', ['--host=127.0.0.1', `--port=${this.databaseEnvironment.DB_PORT}`, '--username=ucg_browser', '--no-password', this.databaseEnvironment.DB_DATABASE])
    return this.databaseEnvironment
  }

  async stop() {
    this.assertOwner()
    if (!existsSync(`${this.data}/postmaster.pid`)) return
    const lines = readFileSync(`${this.data}/postmaster.pid`, 'utf8').split('\n')
    if (realpathSync(lines[1]) !== realpathSync(this.data) || lines[3] !== this.databaseEnvironment.DB_PORT) {
      throw new Error('Temporary PostgreSQL PID ownership mismatch; resources retained.')
    }
    // Cleanup is deliberately independent of an aborted browser run.
    await this.command('pg_ctl', ['-D', this.data, '-w', '-t', '30', '-m', 'fast', 'stop'], true)
    if (existsSync(`${this.data}/postmaster.pid`)) throw new Error('Temporary PostgreSQL is still running; resources retained.')
  }
}
