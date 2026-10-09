import { afterEach, describe, expect, it, vi } from 'vitest'
import { disconnectRealtimeClient, getRealtimeClient } from './echo'

describe('getRealtimeClient', () => {
  afterEach(() => {
    disconnectRealtimeClient()
    vi.unstubAllEnvs()
  })

  it('keeps realtime optional when Reverb is not configured', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '')

    expect(getRealtimeClient()).toBeNull()
  })
})
