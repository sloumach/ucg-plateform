import { createContext, useContext } from 'react'
import type { ApiNotification } from '../../api/contracts'

export type NotificationInput = ApiNotification & {
  durationMs?: number
  requestId?: string
}

export type NotificationContextValue = {
  dismiss: (id: string) => void
  notify: (notification: NotificationInput) => string
}

export const NotificationContext = createContext<NotificationContextValue | null>(null)

export function useNotifications(): NotificationContextValue {
  const context = useContext(NotificationContext)

  if (!context) {
    throw new Error('useNotifications must be used within NotificationProvider')
  }

  return context
}
