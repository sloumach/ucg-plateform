import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { closeSync, mkdirSync, mkdtempSync, openSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import { randomBytes } from 'node:crypto'
import { setTimeout as delay } from 'node:timers/promises'
import { assertHarnessRoot, isolatedEnvironment } from './environment.mjs'
import { TemporaryPostgres } from './postgresql.mjs'

const [action, ...args] = process.argv.slice(2)
const manifest = '/tmp/ucg-load-ready.json'
const children = []
let postgres

function run(file, args_, environment, log) {
  const descriptor = openSync(log, 'a', 0o600)
  const child = spawn(file, args_, { cwd: '/work/back', env: environment, stdio: ['ignore', descriptor, descriptor] })
  closeSync(descriptor)
  child.on('error', (error) => { console.error(error.message); process.exitCode = 1 })
  children.push(child)
  return child
}

function ownedEnvironment() {
  const value = JSON.parse(readFileSync(manifest, 'utf8'))
  assertHarnessRoot(value.environment.UCG_BROWSER_ROOT)
  assert.equal(readFileSync(`${value.environment.UCG_BROWSER_ROOT}/owner`, 'utf8'), value.token)
  assert.equal(value.environment.APP_ENV, 'testing')
  return value.environment
}

if (action === 'serve') {
  const [profile, token, apiPort, frontPort, mode] = args
  assert.ok(['smoke', 'profile', 'volume', 'pressure', 'endurance'].includes(profile))
  assert.match(token, /^[a-f0-9]{64}$/)
  assert.ok(['fpm', 'sequential'].includes(mode))
  for (const port of [apiPort, frontPort]) assert.ok(/^\d{4,5}$/.test(port) && Number(port) <= 65535)
  const root = realpathSync(mkdtempSync('/tmp/ucg-browser-'))
  writeFileSync(`${root}/owner`, token, { mode: 0o600, flag: 'wx' })
  writeFileSync(`${root}/.env`, '', { mode: 0o600, flag: 'wx' })
  for (const path of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) mkdirSync(`${root}/storage/${path}`, { recursive: true })
  const environment = { ...isolatedEnvironment(root, token, Number(apiPort), Number(frontPort)), APP_KEY: `base64:${randomBytes(32).toString('base64')}`, UCG_LOAD_PROFILE: profile,
    REDIS_CLIENT: 'predis', REDIS_HOST: '127.0.0.1', REDIS_PORT: '6379', REDIS_PREFIX: `${token}:`, HORIZON_PREFIX: `${token}:horizon:`,
    CACHE_STORE: 'redis', TENANCY_CACHE_STORE: 'redis', QUEUE_CONNECTION: 'redis', UCG_LOAD_CONCURRENT: 'true',
  }
  postgres = new TemporaryPostgres(root, token, 5432, '/usr/libexec/postgresql17', environment)
  Object.assign(environment, await postgres.start())
  run('redis-server', ['--bind', '127.0.0.1', '--port', '6379', '--save', '', '--appendonly', 'no', '--dir', root], environment, `${root}/redis.log`)
  if (mode === 'fpm') {
    writeFileSync(`${root}/fpm.conf`, `[global]\npid=${root}/fpm.pid\nerror_log=${root}/fpm.log\ndaemonize=no\n[load]\nlisten=127.0.0.1:9000\npm=static\npm.max_children=16\nclear_env=no\ncatch_workers_output=yes\nphp_admin_value[memory_limit]=256M\n`)
    run('php-fpm', ['--fpm-config', `${root}/fpm.conf`], environment, `${root}/fpm-master.log`)
  } else {
    run('php', ['-d', 'opcache.enable_cli=1', '-S', '127.0.0.1:9001', 'tests/Fixtures/load-server.php'], environment, `${root}/sequential.log`)
  }
  writeFileSync(`${root}/nginx.conf`, `pid ${root}/nginx.pid;\nerror_log ${root}/nginx.log;\nevents { worker_connections 1024; }\nhttp { access_log off; client_body_temp_path ${root}/body; fastcgi_temp_path ${root}/fastcgi; proxy_temp_path ${root}/proxy; uwsgi_temp_path ${root}/uwsgi; scgi_temp_path ${root}/scgi; server { listen 8080; client_max_body_size 2m; location / { ${mode === 'fpm'
    ? 'fastcgi_pass 127.0.0.1:9000; include /etc/nginx/fastcgi_params; fastcgi_param SCRIPT_FILENAME /work/back/tests/Fixtures/load-server.php; fastcgi_param SCRIPT_NAME /index.php; fastcgi_param HTTP_PROXY "";'
    : 'proxy_pass http://127.0.0.1:9001; proxy_set_header Host $http_host;'} } } }`)
  run('nginx', ['-c', `${root}/nginx.conf`, '-g', 'daemon off;'], environment, `${root}/nginx-master.log`)
  writeFileSync(manifest, JSON.stringify({ token, environment }), { mode: 0o600, flag: 'wx' })
  let stopping = false
  const stop = async () => {
    if (stopping) return
    stopping = true
    for (const child of children) child.kill('SIGTERM')
    try { await postgres.stop() } finally { process.exit() }
  }
  process.once('SIGTERM', stop)
  process.once('SIGINT', stop)
  while (!stopping) {
    await delay(500)
    if (stopping) break
    for (const child of children) if (child.exitCode !== null || child.signalCode !== null) {
      console.error(`Load service failed: ${child.spawnfile}; log tail: ${readFileSync(`${root}/${child.spawnfile === 'php-fpm' ? 'fpm-master' : child.spawnfile === 'nginx' ? 'nginx-master' : child.spawnfile === 'php' ? 'sequential' : 'redis'}.log`, 'utf8').slice(-1500)}`)
      await stop()
    }
  }
} else if (action === 'execute' || action === 'worker') {
  const environment = ownedEnvironment()
  const file = action === 'worker' ? 'load-horizon.php' : args[0]
  assert.ok(['load-seed.php', 'load-audit.php', 'load-queue-audit.php', 'load-horizon.php'].includes(file))
  const result = spawnSync('php', [`tests/Fixtures/${file}`], { cwd: '/work/back', env: environment, encoding: 'utf8', timeout: action === 'worker' ? 900_000 : 420_000, maxBuffer: 8 * 1024 * 1024 })
  if (result.status !== 0) {
    if (result.stderr?.includes('An empty owned PostgreSQL load database is required.')) console.log('An empty owned PostgreSQL load database is required.')
    else { process.stderr.write(result.stderr ?? ''); process.exitCode = 1 }
  } else {
    if (file === 'load-seed.php') writeFileSync(`${environment.UCG_BROWSER_ROOT}/fixture.json`, result.stdout, { flag: 'wx', mode: 0o600 })
    process.stdout.write(result.stdout)
  }
} else {
  throw new Error('Unknown fixed load-runtime action')
}
