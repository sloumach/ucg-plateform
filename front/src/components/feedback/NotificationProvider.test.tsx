import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import {
  NotificationProvider,
} from './NotificationProvider'
import { useNotifications } from './notificationContext'

function NotificationHarness() {
  const { notify } = useNotifications()

  return (
    <button
      type="button"
      onClick={() =>
        notify({
          type: 'error',
          message: 'Échec de l’opération.',
          requestId: 'request-456',
        })
      }
    >
      Déclencher
    </button>
  )
}

describe('NotificationProvider', () => {
  it('garde les erreurs visibles, affiche leur référence et évite les doublons', async () => {
    const user = userEvent.setup()

    render(
      <NotificationProvider>
        <NotificationHarness />
      </NotificationProvider>,
    )

    await user.click(screen.getByRole('button', { name: 'Déclencher' }))
    await user.click(screen.getByRole('button', { name: 'Déclencher' }))

    expect(screen.getAllByRole('alert')).toHaveLength(1)
    expect(screen.getByRole('alert')).toHaveTextContent('Échec de l’opération.')
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Référence de support : request-456',
    )

    await user.click(screen.getByRole('button', { name: 'Fermer la notification' }))

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
