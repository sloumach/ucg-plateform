import { realpathSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, dirname } from 'node:path'

export function playwrightArguments(arguments_) {
  if (arguments_.some((argument) => !['--headed', '--postgresql'].includes(argument))) {
    throw new Error('Only --headed and --postgresql may be passed to the isolated browser runner.')
  }
  return arguments_
}

export function assertHarnessRoot(root) {
  if (realpathSync(root) !== root || realpathSync(dirname(root)) !== realpathSync(tmpdir())
    || !/^ucg-browser-[a-zA-Z0-9]+$/.test(basename(root))) {
    throw new Error('A dedicated temporary browser directory is required.')
  }
}

export function isolatedEnvironment(root, token, apiPort, frontPort, source = process.env) {
  assertHarnessRoot(root)
  // Only OS/runtime settings may cross into the test process; no application secrets.
  const allowed = /^(path|systemroot|windir|comspec|pathext|temp|tmp|tmpdir|home|userprofile|localappdata|appdata|lang|lc_all|tz|ci|playwright_browsers_path|playwright_browser_channel|playwright_video)$/i
  const inherited = Object.fromEntries(Object.entries(source).filter(([key]) => allowed.test(key)))
  const api = `http://127.0.0.1:${apiPort}`
  const front = `http://127.0.0.1:${frontPort}`
  return {
    ...inherited,
    UCG_BROWSER_ROOT: root, UCG_BROWSER_TOKEN: token,
    UCG_BROWSER_ENGINE: 'sqlite',
    UCG_BROWSER_API_URL: api, UCG_BROWSER_FRONT_URL: front,
    APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: api, FRONTEND_URL: front,
    APP_CONFIG_CACHE: `${root}/config.php`, APP_SERVICES_CACHE: `${root}/services.php`,
    APP_PACKAGES_CACHE: `${root}/packages.php`, APP_ROUTES_CACHE: `${root}/routes.php`,
    APP_EVENTS_CACHE: `${root}/events.php`, LARAVEL_STORAGE_PATH: `${root}/storage`,
    APP_LOCALE: 'fr', APP_FALLBACK_LOCALE: 'fr', BCRYPT_ROUNDS: '4',
    DB_CONNECTION: 'sqlite', DB_DATABASE: `${root}/browser.sqlite`, DB_URL: '', DB_FOREIGN_KEYS: 'true',
    CACHE_STORE: 'database', TENANCY_CACHE_STORE: 'database', QUEUE_CONNECTION: 'database',
    SESSION_DRIVER: 'file', SESSION_COOKIE: 'ucg_browser_session', SESSION_DOMAIN: '',
    SESSION_SECURE_COOKIE: 'false', SESSION_HTTP_ONLY: 'true', SESSION_SAME_SITE: 'lax',
    SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${frontPort},127.0.0.1:${apiPort}`,
    MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'log', LOG_CHANNEL: 'single', LOG_LEVEL: 'warning',
    FILESYSTEM_DISK: 'local', ARTIFACTS_DISK: 'local',
    OPS_BACKUP_MONITORING_ENABLED: 'false',
    REPORT_EXPORTS_ENABLED: 'true', EXPIRE_TENANT_ARTIFACTS_ENABLED: 'false',
    VITE_DEPLOYMENT_ENV: 'local', VITE_API_BASE_URL: `${api}/api/v1`,
    VITE_REVERB_APP_KEY: 'browser-public-key', VITE_REVERB_HOST: '127.0.0.1',
    VITE_REVERB_PORT: '8080', VITE_REVERB_SCHEME: 'http',
  }
}

export function postgresEnvironment(root, token, port) {
  assertHarnessRoot(root)
  if (!/^[a-f0-9]{64}$/.test(token) || !Number.isInteger(port) || port < 1024 || port > 65535) {
    throw new Error('A browser token and an ephemeral PostgreSQL port are required.')
  }
  return {
    UCG_BROWSER_ENGINE: 'pgsql', DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1',
    DB_PORT: String(port), DB_DATABASE: `ucg_browser_${token.slice(0, 24)}`,
    DB_USERNAME: 'ucg_browser', DB_PASSWORD: token, DB_URL: '', DB_SSLMODE: 'disable',
  }
}
