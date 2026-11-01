import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { createHash } from 'node:crypto'
import { cpSync, existsSync, lstatSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { setTimeout as delay } from 'node:timers/promises'
import { assertHarnessRoot } from './environment.mjs'

const label = 'ucg.qa005.owner'
const image = 'ucg-qa005-runtime:php85'
const dependencyImage = `${image}-dependencies`

function imageTag(id) {
  assert.match(id, /^sha256:[a-f0-9]{64}$/)
  return `ucg-qa005-image:${id.slice(7)}`
}

export function dependencyFingerprint(front) {
  return createHash('sha256').update('context-filter-v2').update(readFileSync(resolve(front, '../back/composer.lock')))
    .update(readFileSync(resolve(front, '../back/vendor/composer/installed.json')))
    .update(readFileSync(resolve(front, 'tests/real-api/load-runtime.Dockerfile'))).digest('hex')
}

export function containerIdentity(token) {
  assert.match(token, /^[a-f0-9]{64}$/)
  return `ucg-qa005-${token.slice(0, 24)}`
}

export function assertOwnedContainer(details, token) {
  assert.equal(details.Name, `/${containerIdentity(token)}`, 'Container name ownership mismatch')
  assert.equal(details.Config.Labels[label], token, 'Container label ownership mismatch')
}

export function assertLocalDockerEndpoint(endpoint, environment = process.env) {
  assert.ok(!environment.DOCKER_HOST, 'DOCKER_HOST overrides are refused by the local load lab')
  assert.ok(typeof endpoint === 'string' && /^(?:unix:\/\/\/|npipe:\/\/\/\/\.\/pipe\/)/.test(endpoint), 'A local Docker socket is required; remote daemons are refused')
}

export function allowedContextPath(path) {
  return !/(^|[\\/])(?:\.env(?:\..*)?|\.git|\.codex|\.agents)(?:[\\/]|$)/i.test(path)
    && !/^(?:storage|reports|node_modules)(?:[\\/]|$)/i.test(path)
    && !/\.(?:sqlite|sqlite3|db|pem|key|pfx|sql|dump)$/i.test(path)
}

export class LoadContainer {
  constructor(root, token, front, profile, sequential = false) {
    assertHarnessRoot(root)
    assert.equal(readFileSync(`${root}/owner`, 'utf8'), token)
    this.root = root
    this.token = token
    this.front = front
    this.profile = profile
    this.sequential = sequential
    this.name = containerIdentity(token)
    this.created = false
  }

  command(args, timeout = 60_000, visible = false) {
    return new Promise((done, reject) => {
      const child = spawn('docker', args, { windowsHide: true, timeout, stdio: ['ignore', 'pipe', 'pipe'] })
      let output = ''
      let diagnostic = ''
      let failure
      child.stdout.on('data', (chunk) => { output += chunk; if (visible) process.stdout.write(chunk) })
      child.stderr.on('data', (chunk) => { diagnostic = (diagnostic + chunk).slice(-6000); if (visible) process.stderr.write(chunk) })
      child.once('error', (error) => { failure = error })
      child.once('close', (code) => code === 0 && !failure ? done(output) : reject(new Error(`QA-005 Docker ${args[0]} failed (${code}): ${diagnostic.slice(-1800)}`)))
    })
  }

  async assertOwner() {
    const details = JSON.parse(await this.command(['inspect', this.name]))[0]
    assertOwnedContainer(details, this.token)
    return details
  }

  archive(context, build) {
    return new Promise((done, reject) => {
      const child = spawn('tar', ['-czf', `${build}/application.tar.gz`, '-C', context, 'back', 'front'], { windowsHide: true, timeout: 600_000, stdio: ['ignore', 'ignore', 'pipe'] })
      let diagnostic = ''
      let failure
      child.stderr.on('data', (chunk) => { diagnostic = (diagnostic + chunk).slice(-1500) })
      child.once('error', (error) => { failure = error })
      child.once('close', (code) => code === 0 && !failure ? done() : reject(new Error(`Filtered load context archive failed (${code}): ${diagnostic}`)))
    })
  }

  async start(apiPort, frontPort) {
    assert.ok(!process.env.DOCKER_HOST, 'DOCKER_HOST overrides are refused by the local load lab')
    const contextDetails = JSON.parse(await this.command(['context', 'inspect']))[0]
    assertLocalDockerEndpoint(contextDetails.Endpoints.docker.Host)
    this.dependencyFingerprint = dependencyFingerprint(this.front)
    let dependencyBase
    try {
      const candidate = JSON.parse(await this.command(['image', 'inspect', dependencyImage]))[0]
      if (candidate.Config.Labels?.['ucg.qa005.dependencies'] === this.dependencyFingerprint) {
        dependencyBase = imageTag(candidate.Id)
        await this.command(['tag', candidate.Id, dependencyBase])
      }
    } catch { /* The first run builds the filtered dependency layer. */ }
    console.log('QA-005: preparing filtered temporary application context (no checkout or secrets mounted).')
    const context = `${this.root}/context`
    mkdirSync(`${context}/back/bootstrap/cache`, { recursive: true })
    mkdirSync(`${context}/front/tests/real-api`, { recursive: true })
    // Only application code and installed PHP libraries enter the lab. Never mount the checkout.
    const paths = ['app', 'config', 'database/factories', 'database/migrations', 'database/seeders', 'lang', 'routes', 'resources', 'tests/Fixtures', 'tests/TestCase.php', 'vendor', 'artisan', 'composer.json', 'composer.lock', 'bootstrap/app.php', 'bootstrap/providers.php']
    for (const path of paths) {
      if (path === 'vendor' && dependencyBase) continue
      const source = resolve(this.front, '../back', path)
      if (!existsSync(source)) continue
      cpSync(source, `${context}/back/${path}`, { recursive: true, filter: (file) => {
        assert.equal(lstatSync(file).isSymbolicLink(), false, 'Symlinks are refused in the load context')
        return allowedContextPath(file.slice(resolve(this.front, '../back').length + 1))
      } })
    }
    for (const file of ['environment.mjs', 'postgresql.mjs', 'load-runtime.mjs']) cpSync(resolve(this.front, 'tests/real-api', file), `${context}/front/tests/real-api/${file}`)
    const build = `${this.root}/build`
    mkdirSync(build)
    cpSync(resolve(this.front, 'tests/real-api/load-runtime.Dockerfile'), `${build}/Dockerfile`)
    console.log('QA-005: building isolated Linux runtime.')
    if (!dependencyBase) await this.command(['build', '--tag', `${image}-base`, build], 600_000)
    await this.archive(context, build)
    writeFileSync(`${build}/Dockerfile`, `FROM ${dependencyBase ?? `${image}-base`}\nADD --chown=1000:1000 application.tar.gz /work/\nLABEL ucg.qa005.dependencies=${this.dependencyFingerprint}\n`)
    await this.command(['build', '--tag', image, build], 300_000)
    if (!dependencyBase) {
      const sourceImage = JSON.parse(await this.command(['image', 'inspect', image]))[0].Id
      const sourceTag = imageTag(sourceImage)
      await this.command(['tag', sourceImage, sourceTag])
      const dependencies = `${this.root}/dependencies`
      mkdirSync(dependencies)
      writeFileSync(`${dependencies}/Dockerfile`, `FROM ${image}-base\nCOPY --from=${sourceTag} /work/back/vendor /work/back/vendor\nLABEL ucg.qa005.dependencies=${this.dependencyFingerprint}\n`)
      await this.command(['build', '--tag', dependencyImage, dependencies], 180_000)
    }
    this.created = true
    await this.command(['create', '--name', this.name, '--label', `${label}=${this.token}`, '--cpus=4', '--memory=6g', '--pids-limit=256',
      '--cap-drop=ALL', '--security-opt=no-new-privileges', '--stop-signal=SIGTERM', '--publish', `127.0.0.1:${apiPort}:8080`, image,
      'serve', this.profile.name, this.token, String(apiPort), String(frontPort), this.sequential ? 'sequential' : 'fpm'])
    await this.assertOwner()
    await this.command(['start', this.name])
    const until = Date.now() + 90_000
    while (Date.now() < until) {
      const state = await this.assertOwner()
      if (!state.State.Running) throw new Error(`Owned load container stopped: ${(await this.command(['logs', this.name])).slice(-1500)}`)
      try {
        const value = JSON.parse(await this.command(['exec', this.name, 'cat', '/tmp/ucg-load-ready.json']))
        assert.equal(value.token, this.token)
        this.environment = value.environment
        return value.environment
      } catch { await delay(500) }
    }
    throw new Error('Owned load container startup timed out')
  }

  async execute(file) {
    assert.ok(['load-seed.php', 'load-audit.php', 'load-queue-audit.php'].includes(file), 'Unknown load fixture')
    await this.assertOwner()
    const output = await this.command(['exec', this.name, 'node', '/work/front/tests/real-api/load-runtime.mjs', 'execute', file], this.profile.games ? 420_000 : 90_000)
    if (output.includes('An empty owned PostgreSQL load database is required.')) throw new Error('An empty owned PostgreSQL load database is required.')
    return output
  }

  async worker() {
    await this.assertOwner()
    await this.command(['exec', '--detach', this.name, 'node', '/work/front/tests/real-api/load-runtime.mjs', 'worker'])
    const until = Date.now() + 30_000
    while (Date.now() < until) {
      const audit = JSON.parse(await this.execute('load-queue-audit.php'))
      if (audit.supervisors > 0) return audit
      await delay(500)
    }
    throw new Error('Horizon supervisors did not become ready')
  }

  async stop() {
    if (!this.created) return
    const details = await this.assertOwner()
    this.runtime = { imageId: details.Image, dependencyFingerprint: this.dependencyFingerprint, nanoCpus: details.HostConfig.NanoCpus, memoryBytes: details.HostConfig.Memory, pidsLimit: details.HostConfig.PidsLimit }
    if (this.environment) {
      try {
        const log = `${this.root}/application.log`
        await this.command(['cp', `${this.name}:${this.environment.UCG_BROWSER_ROOT}/storage/logs/laravel.log`, log])
        this.diagnostic = readFileSync(log, 'utf8').split('\n').filter((line) => line.includes('.ERROR:')).slice(-3).map((line) => line.slice(0, 1800).replaceAll(this.token, '[test-token]'))
      } catch { /* A successful run need not have an application error log. */ }
    }
    if (this.profile.name === 'profile') {
      try { await this.command(['cp', `${this.name}:${this.environment.UCG_BROWSER_ROOT}/query-profile.jsonl`, `${this.root}/query-profile.jsonl`]) }
      catch { /* Caller marks missing instrumentation as a failed run. */ }
    }
    await this.command(['stop', '--time=30', this.name])
    await this.assertOwner()
    await this.command(['rm', this.name])
    const names = await this.command(['ps', '--all', '--filter', `label=${label}=${this.token}`, '--format', '{{.Names}}'])
    assert.equal(names.trim(), '', 'Owned container remains after cleanup')
    this.created = false
  }
}
