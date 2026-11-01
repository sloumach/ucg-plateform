import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, readFileSync, realpathSync, rmSync, writeFileSync, existsSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { test } from 'node:test'
import { allowedContextPath, assertLocalDockerEndpoint, assertOwnedContainer, containerIdentity, LoadContainer } from './load-container.mjs'

const token = 'a'.repeat(64)

test('the load lab refuses remote Docker daemons and environment target overrides', () => {
  assertLocalDockerEndpoint('npipe:////./pipe/dockerDesktopLinuxEngine', {})
  assertLocalDockerEndpoint('unix:///var/run/docker.sock', {})
  for (const endpoint of ['ssh://production', 'tcp://127.0.0.1:2375', 'https://example.test']) assert.throws(() => assertLocalDockerEndpoint(endpoint, {}), /local Docker socket/)
  assert.throws(() => assertLocalDockerEndpoint('unix:///var/run/docker.sock', { DOCKER_HOST: 'ssh://production' }), /overrides/)
})

test('container cleanup refuses a matching name with another owner before any stop or removal', async () => {
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  writeFileSync(`${root}/owner`, token)
  try {
    const runner = new LoadContainer(root, token, root, { name: 'smoke' })
    runner.created = true
    const commands = []
    runner.command = async (args) => {
      commands.push(args[0])
      return JSON.stringify([{ Name: `/${containerIdentity(token)}`, Config: { Labels: { 'ucg.qa005.owner': 'b'.repeat(64) } } }])
    }
    await assert.rejects(runner.stop(), /label ownership mismatch/)
    assert.deepEqual(commands, ['inspect'])
    assert.throws(() => assertOwnedContainer({ Name: '/another-project', Config: { Labels: { 'ucg.qa005.owner': token } } }, token), /name ownership mismatch/)
    assert.throws(() => containerIdentity('../another-project'))
  } finally { rmSync(root, { recursive: true }) }
})

test('build context excludes secrets, mutable data and unrelated checkout files before Docker is called', async () => {
  const previousHost = process.env.DOCKER_HOST
  delete process.env.DOCKER_HOST
  const root = realpathSync(mkdtempSync(resolve(tmpdir(), 'ucg-browser-')))
  writeFileSync(`${root}/owner`, token)
  const project = `${root}/source`
  mkdirSync(`${project}/front/tests/real-api`, { recursive: true })
  mkdirSync(`${project}/back/app`, { recursive: true })
  mkdirSync(`${project}/back/app/Support/Storage`, { recursive: true })
  writeFileSync(`${project}/back/app/Support/Storage/Namespace.php`, 'required application code')
  mkdirSync(`${project}/back/vendor/composer`, { recursive: true })
  writeFileSync(`${project}/back/composer.lock`, '{}')
  writeFileSync(`${project}/back/vendor/composer/installed.json`, '{}')
  for (const file of ['environment.mjs', 'postgresql.mjs', 'load-runtime.mjs', 'load-runtime.Dockerfile']) writeFileSync(`${project}/front/tests/real-api/${file}`, 'fixture')
  for (const file of ['Safe.php', '.env', '.env.production', 'secret.key', 'business.sqlite']) writeFileSync(`${project}/back/app/${file}`, file)
  writeFileSync(`${project}/back/.env`, 'production credentials')
  try {
    const runner = new LoadContainer(root, token, `${project}/front`, { name: 'smoke' })
    runner.command = async (args) => {
      if (args[0] === 'context') return JSON.stringify([{ Endpoints: { docker: { Host: 'unix:///var/run/docker.sock' } } }])
      throw new Error('stop before Docker build')
    }
    await assert.rejects(runner.start(18001, 18002), /stop before Docker build/)
    assert.equal(readFileSync(`${root}/context/back/app/Safe.php`, 'utf8'), 'Safe.php')
    assert.equal(readFileSync(`${root}/context/back/app/Support/Storage/Namespace.php`, 'utf8'), 'required application code')
    for (const file of ['.env', '.env.production', 'secret.key', 'business.sqlite']) assert.equal(existsSync(`${root}/context/back/app/${file}`), false)
    assert.equal(existsSync(`${root}/context/back/.env`), false)
    for (const file of ['storage/app/private/export.csv', '.git/config', 'vendor/example/.env.local', 'reports/private.json']) assert.equal(allowedContextPath(file), false)
  } finally {
    if (previousHost === undefined) delete process.env.DOCKER_HOST
    else process.env.DOCKER_HOST = previousHost
    rmSync(root, { recursive: true })
  }
})
