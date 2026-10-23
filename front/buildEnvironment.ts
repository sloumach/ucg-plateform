const publicVariables = new Set([
  'VITE_DEPLOYMENT_ENV', 'VITE_API_BASE_URL', 'VITE_REVERB_APP_KEY',
  'VITE_REVERB_HOST', 'VITE_REVERB_PORT', 'VITE_REVERB_SCHEME', 'VITE_APP_NAME',
])

/** Vite exposes every VITE_ variable to the browser. Never include values in errors. */
export function validateBuildEnvironment(environment: Record<string, string | undefined>): void {
  if (Object.keys(environment).some((key) => key.startsWith('VITE_') && !publicVariables.has(key))) {
    throw new Error('Variable VITE_ inconnue : utiliser uniquement les variables publiques documentées.')
  }

  const profile = environment.VITE_DEPLOYMENT_ENV ?? 'local'
  if (!['local', 'staging', 'production'].includes(profile)) {
    throw new Error('VITE_DEPLOYMENT_ENV doit être local, staging ou production.')
  }
  if (profile === 'local') return

  let api: URL
  try {
    api = new URL(environment.VITE_API_BASE_URL ?? '')
  } catch {
    throw new Error('Le profil distant exige une URL API HTTPS valide.')
  }
  if (api.protocol !== 'https:' || api.username || api.password || api.search || api.hash
    || api.pathname !== '/api/v1' || api.hostname.endsWith('.invalid')
    || api.hostname === 'localhost' || !api.hostname.startsWith('api.')) {
    throw new Error('Le profil distant exige une URL API HTTPS sans identifiants, avec le chemin /api/v1.')
  }
  const reverbHost = environment.VITE_REVERB_HOST ?? ''
  if (environment.VITE_REVERB_SCHEME !== 'https' || environment.VITE_REVERB_PORT !== '443'
    || !environment.VITE_REVERB_APP_KEY?.trim()
    || reverbHost !== `ws.${api.hostname.slice(4)}`) {
    throw new Error('Le profil distant exige Reverb HTTPS sur le domaine API dédié et une clé publique.')
  }
}
