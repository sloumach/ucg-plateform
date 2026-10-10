import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { t } from '../../i18n/fr'
import type { FeedbackTone } from './Alert'
import {
  NotificationContext,
  type NotificationInput,
} from './notificationContext'

type VisibleNotification = NotificationInput & {
  durationMs: number
  id: string
}

const MAX_VISIBLE_NOTIFICATIONS = 5
const DEFAULT_DURATION_MS = 5_000

const toneClasses: Record<FeedbackTone, string> = {
  error: 'border-rose-400/30 bg-slate-950 text-rose-100',
  info: 'border-cyan-400/30 bg-slate-950 text-cyan-100',
  success: 'border-emerald-400/30 bg-slate-950 text-emerald-100',
  warning: 'border-amber-300/30 bg-slate-950 text-amber-100',
}

const toneMarkers: Record<FeedbackTone, string> = {
  error: '×',
  info: 'i',
  success: '✓',
  warning: '!',
}

export function NotificationProvider({ children }: { children: ReactNode }) {
  const [notifications, setNotifications] = useState<VisibleNotification[]>([])
  const nextId = useRef(1)

  const dismiss = useCallback((id: string) => {
    setNotifications((current) =>
      current.filter((notification) => notification.id !== id),
    )
  }, [])

  const notify = useCallback((notification: NotificationInput) => {
    const id = `notification-${nextId.current}`
    nextId.current += 1

    const visibleNotification: VisibleNotification = {
      ...notification,
      durationMs:
        notification.durationMs ??
        (notification.type === 'success' || notification.type === 'info'
          ? DEFAULT_DURATION_MS
          : 0),
      id,
    }

    setNotifications((current) => {
      const withoutDuplicate = current.filter(
        (item) =>
          item.message !== visibleNotification.message ||
          item.type !== visibleNotification.type,
      )

      return [...withoutDuplicate, visibleNotification].slice(
        -MAX_VISIBLE_NOTIFICATIONS,
      )
    })

    return id
  }, [])

  const value = useMemo(() => ({ dismiss, notify }), [dismiss, notify])

  return (
    <NotificationContext.Provider value={value}>
      {children}
      <div
        aria-label={t('notifications.regionLabel')}
        className="pointer-events-none fixed top-4 right-4 z-50 grid w-[min(24rem,calc(100vw-2rem))] gap-3"
      >
        {notifications.map((notification) => (
          <NotificationToast
            key={notification.id}
            notification={notification}
            onDismiss={dismiss}
          />
        ))}
      </div>
    </NotificationContext.Provider>
  )
}

function NotificationToast({
  notification,
  onDismiss,
}: {
  notification: VisibleNotification
  onDismiss: (id: string) => void
}) {
  useEffect(() => {
    if (notification.durationMs === 0) {
      return undefined
    }

    const timeout = window.setTimeout(
      () => onDismiss(notification.id),
      notification.durationMs,
    )

    return () => window.clearTimeout(timeout)
  }, [notification.durationMs, notification.id, onDismiss])

  const role =
    notification.type === 'error' || notification.type === 'warning'
      ? 'alert'
      : 'status'

  return (
    <div
      role={role}
      aria-atomic="true"
      className={`pointer-events-auto flex items-start gap-3 rounded-xl border p-4 shadow-2xl shadow-black/30 ${toneClasses[notification.type]}`}
    >
      <span
        aria-hidden="true"
        className="grid size-6 shrink-0 place-items-center rounded-full border border-current text-xs font-black"
      >
        {toneMarkers[notification.type]}
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-sm leading-6">{notification.message}</p>
        {notification.requestId && (
          <p className="mt-1 break-all font-mono text-xs opacity-70">
            {t('errors.requestReference', {
              requestId: notification.requestId,
            })}
          </p>
        )}
      </div>
      <button
        type="button"
        onClick={() => onDismiss(notification.id)}
        className="rounded p-1 text-current opacity-70 transition hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
        aria-label={t('notifications.close')}
      >
        <span aria-hidden="true">×</span>
      </button>
    </div>
  )
}
