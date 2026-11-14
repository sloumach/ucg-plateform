import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { ClientOnboardingPanel } from './ClientOnboardingPanel'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))

async function fillForm() {
  const user = userEvent.setup()
  render(<NotificationProvider><ClientOnboardingPanel /></NotificationProvider>)
  await user.click(screen.getByRole('button', { name: 'Ajouter un client et son organisation' }))
  await user.type(screen.getByLabelText('Nom du client'), 'Client Example')
  await user.type(screen.getByLabelText('Adresse e-mail du client'), 'client@example.test')
  await user.type(screen.getByLabelText('Nom de l’organisation'), 'Example Club')
  await user.type(screen.getByLabelText('Identifiant de l’organisation'), 'example-club')
  await user.type(screen.getByLabelText('Motif de création'), 'Ouverture du compte client')
  return user
}

describe('invitation client par le super admin', () => {
  beforeEach(() => vi.resetAllMocks())

  it('exige une confirmation avant d’envoyer les coordonnées et affiche le lien local reçu', async () => {
    vi.mocked(tenancyRequest).mockResolvedValue({ data: { activation_url: 'http://localhost:5173/#client_setup=example' } })
    const user = await fillForm()
    await user.click(screen.getByRole('button', { name: 'Envoyer l’invitation' }))
    expect(tenancyRequest).not.toHaveBeenCalled()
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: 'Envoyer l’invitation' }))

    expect(await screen.findByRole('link', { name: 'Ouvrir le lien d’activation local' })).toHaveAttribute('href', 'http://localhost:5173/#client_setup=example')
    expect(tenancyRequest).toHaveBeenCalledWith('platform/client-invitations', { method: 'POST', body: {
      client_name: 'Client Example', email: 'client@example.test', name: 'Example Club', slug: 'example-club', timezone: 'Africa/Tunis', country: 'TN',
      reason: 'Ouverture du compte client', confirm: true, language: 'fr', settings: { week_starts_on: 1, date_format: 'd/m/Y' },
    } })
  })

  it('conserve la saisie et place le focus sur une adresse refusée', async () => {
    vi.mocked(tenancyRequest).mockRejectedValue(new ApiClientError('Saisie invalide', { status: 422, fieldErrors: { email: ['Cette adresse est déjà utilisée.'] } }))
    const user = await fillForm()
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: 'Envoyer l’invitation' }))

    expect(await screen.findByText('Cette adresse est déjà utilisée.')).toBeVisible()
    await waitFor(() => expect(screen.getByLabelText('Adresse e-mail du client')).toHaveFocus())
    expect(screen.getByLabelText('Nom de l’organisation')).toHaveValue('Example Club')
  })
})
