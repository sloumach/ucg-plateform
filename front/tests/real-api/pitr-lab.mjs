import { appendFileSync, existsSync, lstatSync, mkdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { setTimeout as delay } from 'node:timers/promises'
import { assertRecoveryRoot, digest } from './recovery.mjs'
import { assertVaultOwner, readVault } from './backup-vault.mjs'

export function assertLivePitrLab(lab) {
  assertRecoveryRoot(lab.root, lab.token)
  lab.postgres.assertOwner()
  const pid = `${lab.postgres.data}/postmaster.pid`
  const lines = readFileSync(pid, 'utf8').split('\n')
  if (lstatSync(pid).isSymbolicLink() || realpathSync(lines[1]) !== realpathSync(lab.postgres.data)
    || lines[3] !== lab.environment.DB_PORT) throw new Error('PITR_SERVER_OWNER_REFUSED')
}

export async function sql(lab, statement, { database = lab.environment.DB_DATABASE, user = 'ucg_browser' } = {}) {
  assertLivePitrLab(lab)
  if (!/^(postgres|ucg_browser_[a-f0-9]{24})$/.test(database) || !['ucg_browser', 'ucg_backup'].includes(user)
    || typeof statement !== 'string' || statement.length > 65536) throw new Error('PITR_SQL_FIXTURE_REFUSED')
  lab.sqlCounter = (lab.sqlCounter ?? 0) + 1
  const path = `${lab.root}/pitr-${lab.sqlCounter}.sql`
  writeFileSync(path, statement, { flag: 'wx', mode: 0o600 })
  const output = await lab.postgres.command('psql', ['--host=127.0.0.1', `--port=${lab.environment.DB_PORT}`,
    `--username=${user}`, '--no-password', `--dbname=${database}`, '--no-psqlrc', '--tuples-only', '--no-align', '--set=ON_ERROR_STOP=1', `--file=${path}`])
  return output.trim()
}

export function walRange(start, end, timeline) {
  if (!/^[A-F0-9]{1,8}\/[A-F0-9]{1,8}$/.test(start) || !/^[A-F0-9]{1,8}\/[A-F0-9]{1,8}$/.test(end)
    || !Number.isSafeInteger(timeline) || timeline < 1 || timeline > 0xFFFFFFFF) throw new Error('PITR_WAL_RANGE_REFUSED')
  const lsn = (text) => { const [hi, lo] = text.split('/'); return (BigInt(`0x${hi}`) << 32n) + BigInt(`0x${lo}`) }
  const first = lsn(start) / 16777216n
  const last = lsn(end) / 16777216n
  if (first > last || last - first >= 8n) throw new Error('PITR_WAL_RANGE_REFUSED')
  const names = []
  for (let segment = first; segment <= last; segment++) {
    names.push(timeline.toString(16).padStart(8, '0').toUpperCase()
      + (segment / 256n).toString(16).padStart(8, '0').toUpperCase()
      + (segment % 256n).toString(16).padStart(8, '0').toUpperCase())
  }
  return names
}

export async function waitForWal(source, names) {
  const start = Date.now()
  while (Date.now() - start < 30000) {
    assertLivePitrLab(source)
    if (names.every((name) => existsSync(`${source.root}/wal-archive/${name}`)
      && lstatSync(`${source.root}/wal-archive/${name}`).size === 16777216)) return
    await delay(200, undefined, { signal: source.abort.signal })
  }
  throw new Error('PITR_ARCHIVE_TIMEOUT')
}

function configPath(path) {
  const normalized = path.replaceAll('\\', '/')
  if (!/^[A-Za-z0-9_./: -]+$/.test(normalized)) throw new Error('PITR_CONFIG_PATH_REFUSED')
  return normalized
}

export async function configureArchiving(source) {
  assertLivePitrLab(source)
  await source.postgres.stop()
  mkdirSync(`${source.root}/wal-archive`, { mode: 0o700 })
  appendFileSync(`${source.postgres.data}/postgresql.conf`, `\ntimezone = 'UTC'\nwal_level = replica\narchive_mode = on\narchive_library = 'basic_archive'\nbasic_archive.archive_directory = '${configPath(`${source.root}/wal-archive`)}'\n`)
  await source.postgres.command('pg_ctl', ['-D', source.postgres.data, '-l', `${source.root}/postgresql.log`, '-w', '-t', '30', 'start'])
}

export async function physicalBaseBackup(source) {
  assertLivePitrLab(source)
  await sql(source, `CREATE ROLE ucg_backup WITH LOGIN REPLICATION PASSWORD '${source.token}';`)
  const path = `${source.root}/physical-base`
  if (existsSync(path)) throw new Error('PITR_BASE_TARGET_EXISTS')
  await source.postgres.command('pg_basebackup', ['--host=127.0.0.1', `--port=${source.environment.DB_PORT}`,
    '--username=ucg_backup', '--no-password', `--pgdata=${path}`, '--format=plain', '--wal-method=stream',
    '--checkpoint=fast', '--manifest-checksums=SHA256'])
  await source.postgres.command('pg_verifybackup', [path])
  return { path, manifest: JSON.parse(readFileSync(`${path}/backup_manifest`, 'utf8')) }
}

export async function materializePitr(target, vault, key, id, expectedAppKeyHash) {
  assertRecoveryRoot(target.root, target.token)
  assertVaultOwner(vault)
  if (existsSync(target.postgres.data) || existsSync(`${target.root}/pitr-wal`)) throw new Error('PITR_TARGET_NOT_EMPTY')
  const { manifest, files } = readVault(vault, key, id, expectedAppKeyHash)
  if (!manifest.walNames?.length || !Array.isArray(manifest.walNames)
    || JSON.stringify(walRange(manifest.startLsn, manifest.endLsn, manifest.timeline)) !== JSON.stringify(manifest.walNames)) throw new Error('PITR_WAL_CHAIN_REFUSED')
  for (const name of manifest.walNames) {
    const file = files.find((entry) => entry.path === `wal/${name}`)
    if (!file || file.contents.length !== 16777216) throw new Error('PITR_WAL_CHAIN_REFUSED')
  }
  const destinations = { base: target.postgres.data, wal: `${target.root}/pitr-wal`, private: `${target.root}/storage/app/private` }
  if ((await import('./recovery.mjs')).privateFiles(target.root, target.token).length !== 0) throw new Error('PITR_PRIVATE_TARGET_OCCUPIED')
  const destination = (path) => {
    const [kind, ...parts] = path.split('/')
    const result = resolve(destinations[kind], ...parts)
    if (result !== resolve(destinations[kind]) && !result.startsWith(resolve(destinations[kind]) + (process.platform === 'win32' ? '\\' : '/'))) throw new Error('PITR_TARGET_ESCAPE')
    return result
  }
  writeFileSync(`${target.root}/pitr-restore-started`, id, { flag: 'wx', mode: 0o600 })
  for (const path of manifest.directories) mkdirSync(destination(path), { recursive: true, mode: 0o700 })
  for (const file of files) {
    const path = destination(file.path)
    mkdirSync(dirname(path), { recursive: true, mode: 0o700 })
    writeFileSync(path, file.contents, { flag: 'wx', mode: 0o600 })
  }
  await target.postgres.command('pg_verifybackup', [target.postgres.data])
  const walPath = configPath(`${target.root}/pitr-wal`)
  const restoreCommand = process.platform === 'win32'
    ? `copy /B /Y "${walPath.replaceAll('/', '\\')}\\%f" "%p" >NUL`.replaceAll('\\', '\\\\')
    : `cp "${walPath}/%f" "%p"`
  appendFileSync(`${target.postgres.data}/postgresql.conf`, `\nlisten_addresses = '127.0.0.1'\nport = ${target.environment.DB_PORT}\nunix_socket_directories = ''\narchive_mode = off\narchive_library = ''\nrestore_command = '${restoreCommand}'\nrecovery_target_time = '${manifest.targetTime}'\nrecovery_target_timeline = '${manifest.timeline}'\nrecovery_target_action = 'promote'\n`)
  writeFileSync(`${target.postgres.data}/recovery.signal`, '', { flag: 'wx', mode: 0o600 })
  return manifest
}

export async function startPitrTarget(target, manifest, sourcePassword) {
  assertRecoveryRoot(target.root, target.token)
  target.postgres.environment.PGPASSWORD = sourcePassword
  try {
    await target.postgres.command('pg_ctl', ['-D', target.postgres.data, '-l', `${target.root}/postgresql.log`, '-w', '-t', '30', 'start'])
    const started = Date.now()
    while (await sql(target, 'SELECT pg_is_in_recovery();', { database: 'postgres' }) !== 'f') {
      if (Date.now() - started > 30000) throw new Error('PITR_TARGET_NOT_REACHED')
      await delay(200, undefined, { signal: target.abort.signal })
    }
    if (await sql(target, 'SELECT system_identifier FROM pg_control_system();', { database: 'postgres' }) !== manifest.systemId) throw new Error('PITR_SYSTEM_ID_MISMATCH')
    // Adapt only the disposable target's credentials/database name to its existing PHP ownership guard.
    await sql(target, `ALTER DATABASE "${manifest.database}" RENAME TO "${target.environment.DB_DATABASE}"; ALTER ROLE ucg_browser PASSWORD '${target.token}';`, { database: 'postgres' })
    target.postgres.environment.PGPASSWORD = target.token
    assertLivePitrLab(target)
    return Number(await sql(target, 'SELECT timeline_id FROM pg_control_checkpoint();'))
  } catch {
    const detail = readFileSync(`${target.root}/postgresql.log`, 'utf8').slice(-6000)
      .replaceAll(sourcePassword, '[fixture-secret]').replaceAll(target.token, '[fixture-owner]')
    throw new Error(`PITR_TARGET_START_FAILED: ${detail}`)
  }
}

export async function restartAfterCrash(target) {
  assertLivePitrLab(target)
  await target.postgres.command('pg_ctl', ['-D', target.postgres.data, '-w', '-t', '30', '-m', 'immediate', 'stop'])
  await target.postgres.command('pg_ctl', ['-D', target.postgres.data, '-l', `${target.root}/postgresql.log`, '-w', '-t', '30', 'start'])
}

export function pitrInventory(baseInventory, privateInventory, source, names) {
  assertLivePitrLab(source)
  return { directories: [...baseInventory.directories, ...privateInventory.directories, 'wal'],
    files: [...baseInventory.files, ...privateInventory.files, ...names.map((name) => {
      const path = `${source.root}/wal-archive/${name}`
      if (!/^[A-F0-9]{24}$/.test(name) || lstatSync(path).isSymbolicLink() || realpathSync(path) !== resolve(path)) throw new Error('PITR_WAL_SOURCE_REFUSED')
      return { path: `wal/${name}`, contents: readFileSync(path) }
    })] }
}

export function evidencePayload(manifest, { namespace = 'ops_pitr_local', walArchivedAt, backupCompletedAt } = {}) {
  return { version: 1, namespace, scope: 'local_simulation', backup_id: manifest.id, key_id: manifest.keyId,
    generated_at: new Date().toISOString(), backup_completed_at: backupCompletedAt, wal_archived_at: walArchivedAt,
    restore_tested_at: new Date().toISOString(), base_verified: true, wal_chain_verified: true,
    copy_verified: true, manifest_sha256: digest(JSON.stringify(manifest)) }
}
