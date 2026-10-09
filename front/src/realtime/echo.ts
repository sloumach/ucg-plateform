import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

type ReverbEcho = Echo<'reverb'>

type BroadcastAuthorization = {
  auth: string
  channel_data?: string
  shared_secret?: string
}

const apiBaseUrl =
  import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1'
const broadcastingAuthUrl = `${new URL(apiBaseUrl).origin}/api/broadcasting/auth`

let realtimeClient: ReverbEcho | null | undefined

export function getRealtimeClient(): ReverbEcho | null {
  if (realtimeClient !== undefined) {
    return realtimeClient
  }

  const appKey = import.meta.env.VITE_REVERB_APP_KEY

  if (!appKey) {
    realtimeClient = null

    return realtimeClient
  }

  const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https'
  const port = parsePort(
    import.meta.env.VITE_REVERB_PORT,
    scheme === 'https' ? 443 : 80,
  )

  realtimeClient = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: appKey,
    Pusher,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    channelAuthorization: {
      customHandler: ({ socketId, channelName }, callback) => {
        void authorizePrivateChannel(socketId, channelName)
          .then((authorization) => callback(null, authorization))
          .catch((error: unknown) => {
            callback(
              error instanceof Error
                ? error
                : new Error('Private channel authorization failed.'),
              null,
            )
          })
      },
    },
  })

  return realtimeClient
}

export function disconnectRealtimeClient(): void {
  realtimeClient?.disconnect()
  realtimeClient = undefined
}

async function authorizePrivateChannel(
  socketId: string,
  channelName: string,
): Promise<BroadcastAuthorization> {
  const csrfToken = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.split('=')[1]

  const response = await fetch(broadcastingAuthUrl, {
    method: 'POST',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(csrfToken ? { 'X-XSRF-TOKEN': decodeURIComponent(csrfToken) } : {}),
    },
    body: JSON.stringify({
      socket_id: socketId,
      channel_name: channelName,
    }),
  })

  if (!response.ok) {
    throw new Error(`Private channel authorization failed with status ${response.status}`)
  }

  return (await response.json()) as BroadcastAuthorization
}

function parsePort(value: string | undefined, fallback: number): number {
  const port = Number(value)

  return Number.isInteger(port) && port > 0 && port <= 65_535 ? port : fallback
}
