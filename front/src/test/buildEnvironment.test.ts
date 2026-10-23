import { describe, expect, it } from 'vitest'
import { validateBuildEnvironment } from '../../buildEnvironment'

const remote = {
  VITE_DEPLOYMENT_ENV: 'staging',
  VITE_API_BASE_URL: 'https://api.ucg-staging.example.org/api/v1',
  VITE_REVERB_APP_KEY: 'public-test-key',
  VITE_REVERB_HOST: 'ws.ucg-staging.example.org',
  VITE_REVERB_PORT: '443',
  VITE_REVERB_SCHEME: 'https',
}

describe('build environment', () => {
  it('preserves local builds and accepts explicit remote profiles', () => {
    expect(() => validateBuildEnvironment({})).not.toThrow()
    expect(() => validateBuildEnvironment(remote)).not.toThrow()
    expect(() => validateBuildEnvironment({ ...remote, VITE_DEPLOYMENT_ENV: 'production' })).not.toThrow()
  })

  it.each(['VITE_APP_KEY', 'VITE_DB_PASSWORD', 'VITE_REVERB_APP_SECRET', 'VITE_NEW_SETTING'])(
    'rejects undocumented public variable %s without reflecting names or values', (key) => {
      const run = () => validateBuildEnvironment({ ...remote, [key]: 'sensitive-sentinel' })
      expect(run).toThrow('Variable VITE_ inconnue')
      try { run() } catch (error) {
        expect(String(error)).not.toContain('sensitive-sentinel')
        expect(String(error)).not.toContain(key)
      }
    },
  )

  it.each([
    { VITE_DEPLOYMENT_ENV: 'recette' },
    { VITE_API_BASE_URL: 'http://api.ucg-staging.example.org/api/v1' },
    { VITE_API_BASE_URL: 'https://user:secret@api.ucg-staging.example.org/api/v1' },
    { VITE_API_BASE_URL: 'https://api.ucg-staging.example.org/api/v1?token=secret' },
    { VITE_API_BASE_URL: 'https://api.ucg-staging.example.invalid/api/v1' },
    { VITE_REVERB_HOST: 'ws.production.example.org' },
    { VITE_REVERB_SCHEME: 'http' },
    { VITE_REVERB_PORT: '8080' },
    { VITE_REVERB_APP_KEY: '' },
  ])('rejects incomplete or inconsistent remote settings %o', (change) => {
    expect(() => validateBuildEnvironment({ ...remote, ...change })).toThrow()
  })
})
