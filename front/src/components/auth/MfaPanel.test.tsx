import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getCurrentUser, type AuthenticatedUser } from '../../api/auth'
import { mfaRequest } from '../../api/mfa'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { MfaPanel } from './MfaPanel'

vi.mock('../../api/mfa', () => ({ mfaRequest: vi.fn() }))
vi.mock('../../api/auth', () => ({ getCurrentUser: vi.fn() }))

const requiredUser: AuthenticatedUser = {
  id: 42, name: 'Admin', email: 'admin@ucg.test', email_verified: true,
  mfa: { required: true, enabled: false, verified: false },
}

function show(account = requiredUser) {
  const changed = vi.fn()
  render(<NotificationProvider><MfaPanel user={account} onUserChanged={changed} /></NotificationProvider>)
  return changed
}

describe('MFA account protection', () => {
  beforeEach(() => { vi.clearAllMocks(); localStorage.clear(); sessionStorage.clear() })

  it('confirme l’activation avant de montrer les codes de récupération une seule fois', async () => {
    const actor = userEvent.setup()
    vi.mocked(mfaRequest)
      .mockResolvedValueOnce({ data: { secret: 'TEST-SETUP-KEY', provisioning_uri: 'otpauth://totp/test', expires_in: 600 }, meta: { request_id: 'setup' } })
      .mockResolvedValueOnce({ data: { recovery_codes: ['test-recovery-code'] }, meta: { request_id: 'confirm' } })
    const verified = { ...requiredUser, mfa: { required: true, enabled: true, verified: true } }
    vi.mocked(getCurrentUser).mockResolvedValue(verified)
    const changed = show()

    await actor.type(screen.getByLabelText('Mot de passe'), 'Strong-password-456!')
    await actor.click(screen.getByRole('button', { name: 'Configurer le MFA' }))
    expect(await screen.findByLabelText('Clé de configuration MFA')).toHaveTextContent('TEST-SETUP-KEY')
    expect(screen.queryByLabelText('Codes de récupération')).not.toBeInTheDocument()
    await actor.type(screen.getByLabelText('Code de l’application (6 chiffres)'), '012345')
    await actor.click(screen.getByRole('button', { name: 'Confirmer l’activation' }))

    expect(await screen.findByText('test-recovery-code')).toBeVisible()
    await waitFor(() => expect(changed).toHaveBeenCalledWith(verified))
    expect(mfaRequest).toHaveBeenLastCalledWith('confirmation', { code: '012345' })
    expect(localStorage.length).toBe(0)
    expect(sessionStorage.length).toBe(0)
    await actor.click(screen.getByRole('button', { name: 'J’ai conservé mes codes' }))
    expect(screen.queryByText('test-recovery-code')).not.toBeInTheDocument()
  })

  it('propose un code de récupération quand l’authentificateur est indisponible', async () => {
    const actor = userEvent.setup()
    const pending = { ...requiredUser, mfa: { required: true, enabled: true, verified: false } }
    const verified = { ...pending, mfa: { ...pending.mfa, verified: true } }
    vi.mocked(mfaRequest).mockResolvedValue({ data: verified.mfa, meta: { request_id: 'challenge' } })
    vi.mocked(getCurrentUser).mockResolvedValue(verified)
    const changed = show(pending)

    await actor.click(screen.getByRole('button', { name: 'Utiliser un code de récupération' }))
    await actor.type(screen.getByLabelText('Code de récupération'), '01234567-89abcdef-01234567-89abcdef')
    await actor.click(screen.getByRole('button', { name: 'Valider le code' }))

    await waitFor(() => expect(changed).toHaveBeenCalledWith(verified))
    expect(mfaRequest).toHaveBeenCalledWith('challenge', { recovery_code: '01234567-89abcdef-01234567-89abcdef' })
  })

  it('affiche l’erreur locale et garde le challenge en attente', async () => {
    const actor = userEvent.setup()
    vi.mocked(mfaRequest).mockRejectedValue(new ApiClientError('Code déjà utilisé.', {
      status: 422, fieldErrors: { code: ['Code déjà utilisé.'] }, requestId: 'failed',
    }))
    const changed = show({ ...requiredUser, mfa: { required: true, enabled: true, verified: false } })
    await actor.type(screen.getByLabelText('Code de l’application (6 chiffres)'), '012345')
    await actor.click(screen.getByRole('button', { name: 'Valider le code' }))

    expect(await screen.findByLabelText('Code de l’application (6 chiffres)')).toHaveFocus()
    expect(screen.getAllByText('Code déjà utilisé.').length).toBeGreaterThan(0)
    expect(changed).not.toHaveBeenCalled()
  })

  it('masque la désactivation pour les comptes privilégiés et bloque l’activation avant vérification e-mail', () => {
    const { unmount } = render(<NotificationProvider><MfaPanel user={{ ...requiredUser, mfa: { required: true, enabled: true, verified: true } }} onUserChanged={vi.fn()} /></NotificationProvider>)
    expect(screen.queryByRole('button', { name: 'Désactiver le MFA' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remplacer l’authentificateur' })).toBeVisible()
    unmount()
    show({ ...requiredUser, email_verified: false })
    expect(screen.getByRole('button', { name: 'Configurer le MFA' })).toBeDisabled()
  })
})