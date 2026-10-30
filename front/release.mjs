import { createHash, randomUUID } from 'node:crypto'
import { spawnSync } from 'node:child_process'
import { existsSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, realpathSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { validateBuildEnvironment } from './buildEnvironment.ts'
import { validateQualityEvidence } from './release-ci.mjs'

const maxFileBytes = 16 * 1024 * 1024
const maxTotalBytes = 128 * 1024 * 1024
const maxFiles = 12000
const inputs = ['back/app', 'back/bootstrap/app.php', 'back/bootstrap/providers.php', 'back/config', 'back/database/migrations',
  'back/lang', 'back/public', 'back/resources', 'back/routes', 'back/artisan', 'back/composer.json', 'back/composer.lock',
  'front/src', 'front/public', 'front/index.html', 'front/package.json', 'front/package-lock.json',
  'front/vite.config.ts', 'front/buildEnvironment.ts', 'front/tsconfig.json', 'front/tsconfig.app.json', 'front/tsconfig.node.json']
const runtimeRoots = ['app/', 'config/', 'database/migrations/', 'lang/', 'public/', 'resources/', 'routes/']
const runtimeFiles = ['artisan', 'composer.json', 'composer.lock', 'bootstrap/app.php', 'bootstrap/providers.php']
const sha = (bytes) => createHash('sha256').update(bytes).digest('hex')
const toolDirectory = dirname(fileURLToPath(import.meta.url))
const toolingDigest = () => sha(['release.mjs', 'release-ci.mjs', 'buildRelease.mjs']
  .map((path) => `${path}\0${sha(readFileSync(join(toolDirectory, path)))}\0`).join(''))
const loadedToolingSha256 = toolingDigest()
const osEnvironment = () => Object.fromEntries(Object.entries(process.env).filter(([key]) => /^(path|systemroot|windir|comspec|pathext|temp|tmp|tmpdir|home|userprofile|localappdata|appdata|lang|lc_all|tz)$/i.test(key)))

function git(root, args) {
  const result = spawnSync('git', ['-c', `safe.directory=${root.replaceAll('\\', '/')}`, ...args], {
    cwd: root, env: osEnvironment(), encoding: 'utf8', windowsHide: true, maxBuffer: 16 * 1024 * 1024, timeout: 30000,
  })
  if (result.status !== 0 || result.error) throw new Error('Unable to verify the Git source snapshot.')
  return result.stdout
}

function safePath(path) {
  return typeof path === 'string' && path.length <= 240 && /^[A-Za-z0-9_./-]+$/.test(path)
    && path.split('/').every((part) => part !== '' && part !== '.' && part !== '..'
      && !part.startsWith('.')
      && !/^(?:auth\.json|.*\.(?:pem|key|sqlite|sqlite3|log|map))$/i.test(part))
}

function allowedPayload(path) {
  if (typeof path !== 'string') return false
  if (path === 'deployment-plan.json') return true
  if (path.startsWith('front/')) return safePath(path) && !/^front\/(?:node_modules|vendor|storage|reports|tests|src)\//i.test(path)
  if (!path.startsWith('back/')) return false
  const local = path.slice(5)
  return (safePath(path) || path === 'back/public/.htaccess')
    && (runtimeFiles.includes(local) || runtimeRoots.some((prefix) => local.startsWith(prefix)))
}

function regularFile(root, path) {
  const absolute = resolve(root, path)
  if (!absolute.startsWith(root + sep)) throw new Error('A file escapes the release root.')
  let current = absolute
  while (current !== root) {
    if (lstatSync(current).isSymbolicLink()) throw new Error('Release files must not use symbolic links.')
    current = dirname(current)
  }
  const stat = lstatSync(absolute)
  if (!stat.isFile() || stat.size > maxFileBytes) throw new Error('Release file is not regular or exceeds its size limit.')
  return readFileSync(absolute)
}

function snapshot(root) {
  if (git(root, ['status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignored=matching', '--', ...inputs]) !== '') {
    throw new Error('Release inputs must be committed and clean, including ignored files inside source directories.')
  }
  const revision = git(root, ['rev-parse', 'HEAD']).trim()
  if (!/^[a-f0-9]{40}$/.test(revision)) throw new Error('An exact Git commit is required.')
  const paths = git(root, ['ls-files', '-z', '--', ...inputs]).split('\0').filter(Boolean).sort()
  if (!paths.length || paths.length > maxFiles) throw new Error('Unexpected source inventory.')
  const digest = createHash('sha256')
  let total = 0
  for (const path of paths) {
    if (path.startsWith('back/') ? !allowedPayload(path) : !safePath(path)) throw new Error('A forbidden file is tracked inside release inputs.')
    const bytes = regularFile(root, path)
    total += bytes.length
    if (total > maxTotalBytes) throw new Error('Release sources exceed their total size limit.')
    digest.update(path + '\0' + sha(bytes) + '\0')
  }
  return { revision, paths, digest: digest.digest('hex') }
}

export const deploymentPlan = Object.freeze({
  schemaVersion: 1, deploymentAuthorized: false, targetConfigured: false, databaseRollbackAutomatic: false,
  requiredBeforeInstallation: ['target_and_operator', 'REL-002_approval', 'verified_backup_and_keys', 'isolated_restore_test',
    'migration_compatibility_review', 'single_scheduler_owner', 'worker_and_realtime_supervision', 'stop_and_recovery_criteria'],
  operatorSteps: ['verify_candidate_and_CI_references', 'install_locked_production_composer_dependencies_in_new_release',
    'provision_private_storage_and_writable_Laravel_cache', 'inject_target_specific_secrets_and_configuration',
    'run_ops_configuration_check_on_target', 'apply_only_approved_compatible_migrations',
    'switch_web_workers_realtime_using_target_specific_plan', 'verify_MFA_tenant_isolation_jobs_files_and_alerts', 'record_open_or_hold_decision'],
  recovery: 'Protect writes and follow the approved target-specific recovery plan; never infer database rollback safety from the previous application version.',
})

export function publicBuildEnvironment(profile, source = process.env) {
  if (!['local', 'staging', 'production'].includes(profile)) throw new Error('Unknown release profile.')
  // Refuse unknown public keys before discarding inherited settings. Never echo their values.
  const publicValues = Object.fromEntries(Object.entries(source).filter(([key]) => key.startsWith('VITE_')))
  validateBuildEnvironment({ ...publicValues, VITE_DEPLOYMENT_ENV: profile })
  if (profile === 'local') return { VITE_DEPLOYMENT_ENV: 'local', VITE_API_BASE_URL: 'http://127.0.0.1:8000/api/v1',
    VITE_REVERB_APP_KEY: 'local-candidate-only', VITE_REVERB_HOST: '127.0.0.1', VITE_REVERB_PORT: '8080', VITE_REVERB_SCHEME: 'http' }
  return { ...publicValues, VITE_DEPLOYMENT_ENV: profile }
}

export function buildFrontend(root, work, environment) {
  const envDirectory = join(work, 'empty-env')
  mkdirSync(envDirectory)
  const env = { ...osEnvironment(), ...environment, UCG_BROWSER_ROOT: envDirectory }
  for (const [label, args] of [
    ['TypeScript', [join(root, 'front/node_modules/typescript/bin/tsc'), '-b', '--pretty', 'false']],
    ['Vite', [join(root, 'front/node_modules/vite/bin/vite.js'), 'build', '--outDir', join(work, 'dist'), '--emptyOutDir']],
  ]) {
    const result = spawnSync(process.execPath, args, { cwd: join(root, 'front'), env, windowsHide: true, encoding: 'utf8', timeout: 180000, maxBuffer: 4 * 1024 * 1024 })
    if (result.status !== 0 || result.error) throw new Error(`${label} release build failed; no candidate was completed.`)
  }
}

function walk(root, prefix = '') {
  if (prefix.length > 240) throw new Error('Candidate directory nesting exceeds its limit.')
  const paths = []
  for (const entry of readdirSync(join(root, prefix), { withFileTypes: true })) {
    const path = prefix ? `${prefix}/${entry.name}` : entry.name
    if (entry.isSymbolicLink()) throw new Error('A symbolic link cannot enter a candidate.')
    if (entry.isDirectory()) paths.push(...walk(root, path))
    else if (entry.isFile()) paths.push(path)
    else throw new Error('A special file cannot enter a candidate.')
    if (paths.length > maxFiles) throw new Error('Candidate contains too many files.')
  }
  return paths.sort()
}

function assertDirectory(path) {
  if (!lstatSync(path).isDirectory() || lstatSync(path).isSymbolicLink() || realpathSync(path) !== path) {
    throw new Error('A canonical directory without symbolic links is required.')
  }
}

function removeOwnedWork(work, owner) {
  if (dirname(work) !== realpathSync(tmpdir()) || !/^ucg-release-[A-Za-z0-9]+$/.test(work.split(sep).at(-1))
    || realpathSync(work) !== work || readFileSync(join(work, 'owner'), 'utf8') !== owner) throw new Error('Temporary release ownership changed; directory retained.')
  rmSync(work, { recursive: true, force: true })
}

export function createCandidate({ root, profile = 'local', build = buildFrontend, publicEnvironment = process.env, qualityEvidence = null }) {
  if (toolingDigest() !== loadedToolingSha256) throw new Error('Release tooling changed after loading; restart the build.')
  root = realpathSync(root)
  const source = snapshot(root)
  const evidence = qualityEvidence === null ? null : validateQualityEvidence(qualityEvidence, source.revision)
  const environment = publicBuildEnvironment(profile, publicEnvironment)
  for (const path of ['back/artisan', 'back/bootstrap/app.php', 'back/bootstrap/providers.php', 'back/composer.json', 'back/composer.lock', 'front/package-lock.json']) {
    if (!source.paths.includes(path)) throw new Error('Required release input is missing.')
  }
  const work = realpathSync(mkdtempSync(join(tmpdir(), 'ucg-release-')))
  const owner = randomUUID()
  writeFileSync(join(work, 'owner'), owner, { flag: 'wx', mode: 0o600 })
  try {
    build(root, work, environment)
    let destinationRoot = root
    for (const part of ['front', 'reports', 'releases']) {
      destinationRoot = join(destinationRoot, part)
      if (!existsSync(destinationRoot)) mkdirSync(destinationRoot, { mode: 0o700 })
      assertDirectory(destinationRoot)
    }
    const destination = join(destinationRoot, `${source.revision}-${profile}-${randomUUID()}`)
    mkdirSync(destination, { mode: 0o700 })
    const inventory = []
    let total = 0
    const add = (path, bytes) => {
      if (!allowedPayload(path) || bytes.length > maxFileBytes || inventory.length >= maxFiles) throw new Error('Candidate payload is not permitted.')
      total += bytes.length
      if (total > maxTotalBytes) throw new Error('Candidate exceeds its total size limit.')
      mkdirSync(dirname(join(destination, path)), { recursive: true })
      writeFileSync(join(destination, path), bytes, { flag: 'wx', mode: 0o600 })
      inventory.push({ path, bytes: bytes.length, sha256: sha(bytes) })
    }
    for (const path of source.paths.filter((path) => path.startsWith('back/'))) add(path, regularFile(root, path))
    const dist = join(work, 'dist')
    assertDirectory(dist)
    for (const path of walk(dist)) add(`front/${path}`, regularFile(dist, path))
    if (!inventory.some((item) => item.path === 'front/index.html') || !inventory.some((item) => item.path.startsWith('front/assets/'))) {
      throw new Error('Built frontend entry point or assets are missing.')
    }
    add('deployment-plan.json', Buffer.from(JSON.stringify(deploymentPlan, null, 2)))
    const after = snapshot(root)
    if (after.revision !== source.revision || after.digest !== source.digest) throw new Error('Release inputs changed during the build.')
    if (toolingDigest() !== loadedToolingSha256) throw new Error('Release tooling changed during the build.')
    const manifest = { schemaVersion: 1, ticket: 'UCG-OPS-002', sourceRevision: source.revision, sourceInputsSha256: source.digest,
      profile, nodeVersion: process.version, packagerSha256: loadedToolingSha256,
      publicEnvironmentSha256: sha(JSON.stringify(environment)), runtimeDependenciesIncluded: false, deploymentAuthorized: false, qualityEvidence: evidence,
      files: inventory.sort((a, b) => a.path.localeCompare(b.path, 'en')) }
    writeFileSync(join(destination, 'manifest.json'), JSON.stringify(manifest, null, 2), { flag: 'wx', mode: 0o600 })
    verifyCandidate(destination, source.revision, profile)
    return destination
  } finally {
    // Never recursively remove a computed directory until its exact owned temp root is verified.
    removeOwnedWork(work, owner)
  }
}

export function verifyCandidate(directory, expectedRevision, expectedProfile) {
  directory = resolve(directory)
  assertDirectory(directory)
  if (!/^[a-f0-9]{40}$/.test(expectedRevision) || !['local', 'staging', 'production'].includes(expectedProfile)) throw new Error('Expected commit and profile must be explicit.')
  const manifest = JSON.parse(regularFile(directory, 'manifest.json'))
  if (manifest.schemaVersion !== 1 || manifest.ticket !== 'UCG-OPS-002' || manifest.sourceRevision !== expectedRevision
    || manifest.profile !== expectedProfile || manifest.runtimeDependenciesIncluded !== false || manifest.deploymentAuthorized !== false
    || ![manifest.sourceInputsSha256, manifest.packagerSha256, manifest.publicEnvironmentSha256].every((value) => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value))
    || !Array.isArray(manifest.files) || !manifest.files.length || manifest.files.length > maxFiles) throw new Error('Candidate manifest is invalid or targets another version/profile.')
  const seen = new Set()
  if (manifest.qualityEvidence !== null) validateQualityEvidence(manifest.qualityEvidence, expectedRevision)
  let total = 0
  for (const item of manifest.files) {
    if (!item || !allowedPayload(item.path) || seen.has(item.path) || !Number.isSafeInteger(item.bytes) || item.bytes < 0 || typeof item.sha256 !== 'string') throw new Error('Invalid candidate inventory.')
    seen.add(item.path)
    const bytes = regularFile(directory, item.path)
    total += bytes.length
    if (total > maxTotalBytes || bytes.length !== item.bytes || sha(bytes) !== item.sha256) throw new Error('Candidate file integrity check failed.')
  }
  if (JSON.stringify(walk(directory)) !== JSON.stringify([...seen, 'manifest.json'].sort())) throw new Error('Candidate contains missing or unlisted files.')
  for (const required of ['back/artisan', 'back/bootstrap/app.php', 'back/bootstrap/providers.php', 'back/composer.json', 'back/composer.lock', 'front/index.html', 'deployment-plan.json']) {
    if (!seen.has(required)) throw new Error('Candidate is incomplete.')
  }
  if (![...seen].some((path) => path.startsWith('front/assets/'))) throw new Error('Candidate has no built frontend assets.')
  if (JSON.stringify(JSON.parse(regularFile(directory, 'deployment-plan.json'))) !== JSON.stringify(deploymentPlan)) throw new Error('Deployment plan must remain non-executable and unauthorized.')
  return { valid: true, sourceRevision: expectedRevision, profile: expectedProfile, files: seen.size, deploymentAuthorized: false, runtimeDependenciesIncluded: false }
}
