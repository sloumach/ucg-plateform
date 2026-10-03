import type { ApiSuccessResponse } from './contracts'

export type SystemStatus = {
  apiVersion: string
  name: string
  status: 'operational'
}

type SystemStatusData = {
  api_version: string
  name: string
  status: 'operational'
}

const apiBaseUrl =
  import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1'

export async function getSystemStatus(signal?: AbortSignal): Promise<SystemStatus> {
  const response = await fetch(`${apiBaseUrl}/system/status`, {
    credentials: 'include',
    headers: {
      Accept: 'application/json',
    },
    signal,
  })

  if (!response.ok) {
    throw new Error(`API request failed with status ${response.status}`)
  }

  const payload = (await response.json()) as ApiSuccessResponse<SystemStatusData>

  return {
    apiVersion: payload.data.api_version,
    name: payload.data.name,
    status: payload.data.status,
  }
}
