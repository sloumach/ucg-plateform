import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tenancyRequest } from '../../api/tenancy'
import { ApiClientError } from '../../api/errors'
import { ClientActivationForm } from './ClientActivationForm'

vi.mock('../../api/tenancy', () => ({ tenancyRequest: vi.fn() }))

describe('activation du client invité', () => {
  beforeEach(() => vi.resetAllMocks())

  it('envoie le jeton et le mot de passe puis confirme la création sans conserver le lien dans l’adresse', async () => {
    vi.mocked(tenancyRequest).mockResolvedValue({ data: { id: 'organization' } })
    const user = userEvent.setup()
    const completed = vi.fn()
    window.history.replaceState(null, '', '/#client_setup=example')
    render(<ClientActivationForm token="example" onCompleted={completed} />)
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'Example-test-password-123!')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'Example-test-password-123!')
    await user.click(screen.getByRole('button', { name: 'Créer mon compte et mon organisation' }))

    expect(await screen.findByText(/Votre compte et votre organisation sont créés/)).toBeVisible()
    expect(tenancyRequest).toHaveBeenCalledWith('client-onboarding/confirm', { method: 'POST', body: {
      token: 'example', password: 'Example-test-password-123!', password_confirmation: 'Example-test-password-123!',
    } })
    expect(window.location.hash).toBe('')
    await user.click(screen.getByRole('button', { name: 'Revenir à l’application' }))
    expect(completed).toHaveBeenCalledOnce()
  })

  it('affiche le refus du lien et permet de corriger une saisie', async () => {
    vi.mocked(tenancyRequest).mockRejectedValue(new ApiClientError('Saisie invalide', { status: 422, fieldErrors: { token: ['Ce lien est expiré.'] } }))
    const user = userEvent.setup()
    render(<ClientActivationForm token="expired" onCompleted={vi.fn()} />)
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'Example-test-password-123!')
    await user.type(screen.getByLabelText('Confirmer le nouveau mot de passe'), 'Example-test-password-123!')
    await user.click(screen.getByRole('button', { name: 'Créer mon compte et mon organisation' }))

    expect(await screen.findByText('Ce lien est expiré.')).toBeVisible()
    expect(screen.getByRole('button', { name: 'Créer mon compte et mon organisation' })).toBeEnabled()
  })
})
