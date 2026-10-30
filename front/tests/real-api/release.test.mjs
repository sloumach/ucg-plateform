import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, realpathSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { createCandidate, publicBuildEnvironment, verifyCandidate } from '../../release.mjs'
import { requiredWorkflows, selectQualityEvidence } from '../../release-ci.mjs'

function put(root, path, bytes) {
  mkdirSync(dirname(join(root, path)), { recursive: true })
  writeFileSync(join(root, path), bytes)
}

function fixture() {
  const root = realpathSync(mkdtempSync(join(tmpdir(), 'ucg-release-test-')))
  put(root, 'owner', 'release-test-owned')
  const files = { '.gitignore': 'front/reports/\n.env\nback/storage/\n', 'back/artisan': '<?php // fixture',
    'back/composer.json': '{"name":"fixture/test"}', 'back/composer.lock': '{"packages":[]}',
    'back/bootstrap/app.php': '<?php // fixture', 'back/bootstrap/providers.php': '<?php return [];',
    'back/app/Support/Cache/Fixture.php': '<?php // allowed application Cache directory', 'back/public/.htaccess': '# fixture',
    'front/src/main.ts': 'export const fixture = true', 'front/package-lock.json': '{"lockfileVersion":3}' }
  for (const [path, bytes] of Object.entries(files)) put(root, path, bytes)
  const git = (...args) => {
    const result = spawnSync('git', ['-c', `safe.directory=${root.replaceAll('\\', '/')}`, '-c', 'user.name=Fixture',
      '-c', 'user.email=fixture@example.test', '-c', 'commit.gpgsign=false', '-c', 'core.autocrlf=false',
      '-c', `core.hooksPath=${join(root, '.git/hooks')}`, ...args], { cwd: root, encoding: 'utf8', windowsHide: true })
    assert.equal(result.status, 0, result.stderr)
    return result.stdout.trim()
  }
  git('init', '--initial-branch=staging')
  git('add', '.')
  git('commit', '--no-verify', '-m', 'fictitious fixture')
  const revision = git('rev-parse', 'HEAD')
  return { root, revision, git, close() {
    assert.equal(dirname(root), realpathSync(tmpdir()))
    assert.match(root.split(/[\\/]/).at(-1), /^ucg-release-test-[A-Za-z0-9]+$/)
    assert.equal(realpathSync(root), root)
    assert.equal(readFileSync(join(root, 'owner'), 'utf8'), 'release-test-owned')
    rmSync(root, { recursive: true, force: true })
  } }
}

function fakeBuild(_root, work, environment) {
  assert.equal(environment.APP_KEY, undefined)
  put(work, 'dist/index.html', '<script type="module" src="/assets/app.js"></script>')
  put(work, 'dist/assets/app.js', 'console.log("fictitious")')
}

function candidate(f) { return createCandidate({ root: f.root, build: fakeBuild, publicEnvironment: {} }) }
function manifest(directory) { return JSON.parse(readFileSync(join(directory, 'manifest.json'), 'utf8')) }

test('candidate binds committed sources, frontend, inventory and a non-executable plan without local secrets or dependencies', () => {
  const f = fixture()
  try {
    put(f.root, 'back/.env', 'APP_KEY=PRIVATE_SENTINEL')
    put(f.root, 'back/storage/private.key', 'PRIVATE_SENTINEL')
    const directory = candidate(f)
    const report = verifyCandidate(directory, f.revision, 'local')
    assert.equal(report.valid, true)
    assert.equal(report.deploymentAuthorized, false)
    assert.equal(report.runtimeDependenciesIncluded, false)
    assert.equal(manifest(directory).qualityEvidence, null)
    const paths = manifest(directory).files.map((file) => file.path)
    assert.ok(paths.includes('back/public/.htaccess'))
    assert.ok(paths.includes('back/app/Support/Cache/Fixture.php'))
    assert.ok(paths.includes('front/assets/app.js'))
    for (const file of paths) assert.equal(readFileSync(join(directory, file), 'utf8').includes('PRIVATE_SENTINEL'), false)
    assert.equal(existsSync(join(directory, 'back/vendor')), false)
    assert.equal(existsSync(join(directory, 'back/.env')), false)
    assert.equal(existsSync(join(directory, 'back/storage')), false)
    const second = candidate(f)
    assert.notEqual(second, directory)
    assert.deepEqual(manifest(second).files, manifest(directory).files)
    assert.equal(manifest(second).sourceInputsSha256, manifest(directory).sourceInputsSha256)
  } finally { f.close() }
})

test('candidate refuses dirty, untracked and ignored source changes before invoking the build', () => {
  for (const path of ['back/artisan', 'front/src/untracked.ts', 'back/app/.env']) {
    const f = fixture()
    try {
      put(f.root, path, 'UNCOMMITTED_SENTINEL')
      let invoked = false
      assert.throws(() => createCandidate({ root: f.root, build: () => { invoked = true }, publicEnvironment: {} }), /committed and clean/)
      assert.equal(invoked, false)
      assert.equal(existsSync(join(f.root, 'front/reports')), false)
    } finally { f.close() }
  }
})

test('source drift during the build prevents a completed manifest', () => {
  const f = fixture()
  try {
    assert.throws(() => createCandidate({ root: f.root, publicEnvironment: {}, build(root, work, env) {
      fakeBuild(root, work, env)
      put(root, 'back/artisan', '<?php // changed during build')
    } }), /committed and clean|changed during/)
    for (const directory of readdirSync(join(f.root, 'front/reports/releases'))) {
      assert.equal(existsSync(join(f.root, 'front/reports/releases', directory, 'manifest.json')), false)
    }
  } finally { f.close() }
})

test('tracked forbidden filenames are refused before the frontend build', () => {
  const f = fixture()
  try {
    put(f.root, 'back/app/auth.json', 'PRIVATE_SENTINEL')
    f.git('add', 'back/app/auth.json')
    f.git('commit', '--no-verify', '-m', 'forbidden fixture')
    let invoked = false
    assert.throws(() => createCandidate({ root: f.root, publicEnvironment: {}, build() { invoked = true } }), /forbidden file/)
    assert.equal(invoked, false)
  } finally { f.close() }
})

test('failed build and invalid profile cannot complete a candidate', () => {
  const f = fixture()
  try {
    assert.throws(() => createCandidate({ root: f.root, profile: 'unknown', build: fakeBuild, publicEnvironment: {} }), /Unknown release profile/)
    assert.throws(() => createCandidate({ root: f.root, build: () => { throw new Error('BUILD_FAILED') }, publicEnvironment: {} }), /BUILD_FAILED/)
    assert.equal(existsSync(join(f.root, 'front/reports/releases')), false)
  } finally { f.close() }
})

test('missing frontend assets and oversized payloads are refused', () => {
  for (const oversized of [false, true]) {
    const f = fixture()
    try {
      assert.throws(() => createCandidate({ root: f.root, publicEnvironment: {}, build(_root, work) {
        put(work, 'dist/index.html', 'fixture')
        if (oversized) put(work, 'dist/assets/large.js', Buffer.alloc(16 * 1024 * 1024 + 1))
      } }), /assets are missing|size limit/)
    } finally { f.close() }
  }
})

test('verification rejects wrong targets, tampering, missing files and unlisted secret files', () => {
  const f = fixture()
  try {
    const directory = candidate(f)
    assert.throws(() => verifyCandidate(directory, 'f'.repeat(40), 'local'), /another version/)
    assert.throws(() => verifyCandidate(directory, f.revision, 'production'), /another version/)
    const asset = join(directory, 'front/assets/app.js')
    const original = readFileSync(asset)
    writeFileSync(asset, 'tampered')
    assert.throws(() => verifyCandidate(directory, f.revision, 'local'), /integrity/)
    rmSync(asset)
    assert.throws(() => verifyCandidate(directory, f.revision, 'local'))
    writeFileSync(asset, original)
    put(directory, 'back/.env', 'PRIVATE_SENTINEL')
    assert.throws(() => verifyCandidate(directory, f.revision, 'local'), /unlisted/)
  } finally { f.close() }
})

test('verification rejects traversal, duplicate entries, unauthorized flags and oversized inventories', () => {
  const f = fixture()
  try {
    const directory = candidate(f)
    const original = manifest(directory)
    for (const change of [
      { ...original, deploymentAuthorized: true }, { ...original, runtimeDependenciesIncluded: true },
      { ...original, files: [{ ...original.files[0], path: '../outside' }] },
      { ...original, files: [{ ...original.files[0], path: 'front/vendor/secret.txt' }] },
      { ...original, files: [...original.files, original.files[0]] }, { ...original, files: Array(12001).fill(original.files[0]) },
    ]) {
      writeFileSync(join(directory, 'manifest.json'), JSON.stringify(change))
      assert.throws(() => verifyCandidate(directory, f.revision, 'local'))
    }
  } finally { f.close() }
})

test('a self-consistent modified manifest cannot authorize the deployment plan or remove all frontend assets', () => {
  const f = fixture()
  try {
    const directory = candidate(f)
    const original = manifest(directory)
    const plan = JSON.parse(readFileSync(join(directory, 'deployment-plan.json'), 'utf8'))
    plan.deploymentAuthorized = true
    const bytes = Buffer.from(JSON.stringify(plan))
    writeFileSync(join(directory, 'deployment-plan.json'), bytes)
    const changed = structuredClone(original)
    const entry = changed.files.find((file) => file.path === 'deployment-plan.json')
    entry.bytes = bytes.length
    entry.sha256 = createHash('sha256').update(bytes).digest('hex')
    writeFileSync(join(directory, 'manifest.json'), JSON.stringify(changed))
    assert.throws(() => verifyCandidate(directory, f.revision, 'local'), /unauthorized/)
    const noAssets = candidate(f)
    const metadata = manifest(noAssets)
    metadata.files = metadata.files.filter((file) => file.path !== 'front/assets/app.js')
    rmSync(join(noAssets, 'front/assets/app.js'))
    writeFileSync(join(noAssets, 'manifest.json'), JSON.stringify(metadata))
    assert.throws(() => verifyCandidate(noAssets, f.revision, 'local'), /no built frontend assets/)
  } finally { f.close() }
})

test('candidate directory junctions are rejected even when their destination is readable', () => {
  const f = fixture()
  try {
    const directory = candidate(f)
    mkdirSync(join(f.root, 'private-target'))
    symlinkSync(join(f.root, 'private-target'), join(directory, 'front/foreign'), process.platform === 'win32' ? 'junction' : 'dir')
    assert.throws(() => verifyCandidate(directory, f.revision, 'local'), /symbolic link/)
    assert.deepEqual(readdirSync(join(f.root, 'private-target')), [])
  } finally { f.close() }
})

test('output junctions are refused before writing through them', () => {
  const f = fixture()
  try {
    const target = join(f.root, 'private-target')
    mkdirSync(target)
    symlinkSync(target, join(f.root, 'front/reports'), process.platform === 'win32' ? 'junction' : 'dir')
    assert.throws(() => candidate(f), /canonical directory/)
    assert.deepEqual(readdirSync(target), [])
  } finally { f.close() }
})

test('public configuration is isolated, remote profiles require complete settings and errors do not disclose values', () => {
  const local = publicBuildEnvironment('local', { APP_KEY: 'PRIVATE_SENTINEL', VITE_API_BASE_URL: 'PRIVATE_SENTINEL' })
  assert.equal(JSON.stringify(local).includes('PRIVATE_SENTINEL'), false)
  assert.equal(local.VITE_API_BASE_URL, 'http://127.0.0.1:8000/api/v1')
  assert.throws(() => publicBuildEnvironment('production', {}))
  assert.throws(() => publicBuildEnvironment('local', { VITE_APP_KEY: 'PRIVATE_SENTINEL' }), (error) => !error.message.includes('PRIVATE_SENTINEL'))
  const remote = { VITE_API_BASE_URL: 'https://api.ucg-staging.example.org/api/v1', VITE_REVERB_HOST: 'ws.ucg-staging.example.org',
    VITE_REVERB_APP_KEY: 'public-key', VITE_REVERB_PORT: '443', VITE_REVERB_SCHEME: 'https' }
  assert.equal(publicBuildEnvironment('staging', remote).VITE_DEPLOYMENT_ENV, 'staging')
})

const revision = 'a'.repeat(40)
const successful = (id = 10) => ({ id, run_number: 1, run_attempt: 1, head_sha: revision, head_branch: 'staging', event: 'push',
  head_repository: { full_name: 'fixture/project' }, status: 'completed', conclusion: 'success' })
const quality = () => Object.fromEntries(requiredWorkflows.map((name, index) => [name, [successful(10 + index)]]))

test('quality gate requires exact source, branch and repository for both successful push workflows', () => {
  const report = selectQualityEvidence('fixture/project', revision, 'staging', quality())
  assert.equal(report.runs.length, 2)
  assert.equal(report.sourceRevision, revision)
  for (const change of [{ conclusion: 'failure' }, { conclusion: 'cancelled' }, { conclusion: 'skipped' },
    { status: 'in_progress' }, { event: 'pull_request' }, { head_sha: 'b'.repeat(40) }, { head_branch: 'other' },
    { head_repository: { full_name: 'foreign/project' } }]) {
    const runs = quality()
    runs['backend-quality.yml'] = [{ ...successful(), ...change }]
    assert.throws(() => selectQualityEvidence('fixture/project', revision, 'staging', runs))
  }
  assert.throws(() => selectQualityEvidence('fixture/project', revision, 'staging', {}))
})

test('quality gate never substitutes an older green run for a newer failed, pending or failed-retry run', () => {
  for (const latest of [{ ...successful(30), run_number: 2, conclusion: 'failure' },
    { ...successful(30), run_number: 2, status: 'queued', conclusion: null },
    { ...successful(), run_attempt: 2, conclusion: 'failure' }, { ...successful(30), run_number: 2, id: null }]) {
    const runs = quality()
    runs['backend-quality.yml'].push(latest)
    assert.throws(() => selectQualityEvidence('fixture/project', revision, 'staging', runs))
  }
})

test('candidate binds CI references to its own commit and refuses mismatched evidence', () => {
  const f = fixture()
  try {
    const runs = Object.fromEntries(requiredWorkflows.map((name, index) => [name, [{ ...successful(10 + index), head_sha: f.revision }]]))
    const evidence = selectQualityEvidence('fixture/project', f.revision, 'staging', runs)
    const directory = createCandidate({ root: f.root, build: fakeBuild, publicEnvironment: {}, qualityEvidence: evidence })
    assert.deepEqual(manifest(directory).qualityEvidence, evidence)
    assert.equal(verifyCandidate(directory, f.revision, 'local').valid, true)
    assert.throws(() => createCandidate({ root: f.root, build: fakeBuild, publicEnvironment: {}, qualityEvidence: { ...evidence, sourceRevision: revision } }), /CI run references/)
  } finally { f.close() }
})

test('CLI refuses arbitrary arguments without building or contacting a deployment target', () => {
  const script = fileURLToPath(new URL('../../buildRelease.mjs', import.meta.url))
  for (const args of [[], ['--target=https://example.com'], ['build', '--profile=unknown'], ['deploy'], ['build', '--profile=local', '--force']]) {
    const result = spawnSync(process.execPath, [script, ...args], { encoding: 'utf8', windowsHide: true })
    assert.equal(result.status, 1)
    assert.match(result.stderr, /Use build/)
  }
})
