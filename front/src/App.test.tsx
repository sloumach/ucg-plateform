import { act, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import {
  getCurrentUser,
  login,
  logout,
  requestPasswordReset,
} from './api/auth'
import { ApiClientError } from './api/errors'
import { mfaRequest } from './api/mfa'
import { getSystemStatus } from './api/systemStatus'
import { tenancyRequest, type ActiveOrganization } from './api/tenancy'

vi.mock('./api/tenancy', () => ({ tenancyRequest: vi.fn() }))
vi.mock('./api/mfa', () => ({ mfaRequest: vi.fn() }))

vi.mock('./api/auth', async (importOriginal) => {
  const original = await importOriginal<typeof import('./api/auth')>()

  return {
    ...original,
    getCurrentUser: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    requestPasswordReset: vi.fn(),
    resetPassword: vi.fn(),
    revokeOtherSessions: vi.fn(),
    sendEmailVerification: vi.fn(),
  }
})

vi.mock('./api/systemStatus', () => ({
  getSystemStatus: vi.fn(),
}))

const getCurrentUserMock = vi.mocked(getCurrentUser)
const getSystemStatusMock = vi.mocked(getSystemStatus)
const loginMock = vi.mocked(login)
const logoutMock = vi.mocked(logout)
const requestPasswordResetMock = vi.mocked(requestPasswordReset)

describe('App', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    window.history.replaceState(null, '', '/')
    vi.mocked(tenancyRequest).mockImplementation(async (path) => path === 'active-organization'
      ? { data: { context: null, revision: null, confirmed: false } }
      : { data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    getCurrentUserMock.mockResolvedValue(null)
    getSystemStatusMock.mockResolvedValue({
      apiVersion: 'v1',
      name: 'UCG Platform',
      status: 'operational',
    })
  })

  it('bloque l’espace organisation tant que le MFA privilégié reste en attente', async () => {
    window.history.replaceState(null, '', '/?espace=clients')
    getCurrentUserMock.mockResolvedValue({
      id: 42, name: 'Admin', email: 'admin@ucg.test', email_verified: true, can_provision_clients: true,
      mfa: { required: true, enabled: true, verified: false },
    })

    render(<App />)

    expect(await screen.findByLabelText('Code de l’application (6 chiffres)')).toBeVisible()
    expect(screen.queryByRole('heading', { name: 'Mes organisations' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ajouter un client et son organisation' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Déconnecter les autres appareils' })).not.toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Navigation principale' })).not.toBeInTheDocument()
    expect(tenancyRequest).not.toHaveBeenCalled()
  })

  it('affiche une seule demande de vérification MFA après la saisie des identifiants', async () => {
    const user = userEvent.setup()
    loginMock.mockResolvedValue({
      id: 42, name: 'Admin', email: 'admin@ucg.test', email_verified: true,
      mfa: { required: true, enabled: true, verified: false },
    })

    render(<App />)
    await user.type(await screen.findByLabelText('Adresse e-mail'), 'admin@ucg.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'fixture-password')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    const challenge = await screen.findByRole('region', { name: 'Authentification à deux facteurs' })
    const instruction = 'Confirmez votre connexion avec un deuxième facteur.'
    expect(within(challenge).getByText(instruction)).toBeVisible()
    expect(screen.getAllByText(instruction)).toHaveLength(1)
    expect(screen.queryByText('Connexion réussie.')).not.toBeInTheDocument()
  })

  it('ouvre le formulaire client depuis le menu du super admin', async () => {
    getCurrentUserMock.mockResolvedValue({
      id: 42, name: 'Super admin', email: 'superadmin@example.test', email_verified: true, can_provision_clients: true,
      mfa: { required: true, enabled: true, verified: true },
    })
    const user = userEvent.setup()
    render(<App />)

    await user.click(await screen.findByRole('link', { name: 'Clients' }))
    expect(screen.getByRole('heading', { name: 'Clients', level: 1 })).toHaveFocus()
    expect(screen.queryByRole('heading', { name: 'Mes organisations' })).not.toBeInTheDocument()
    await user.click(await screen.findByRole('button', { name: 'Ajouter un client et son organisation' }))

    expect(screen.getByLabelText('Adresse e-mail du client')).toBeVisible()
    expect(screen.getByLabelText('Nom de l’organisation')).toBeVisible()
  })

  it('affiche le statut de la plateforme et permet de se connecter', async () => {
    const user = userEvent.setup()
    loginMock.mockResolvedValue({
      id: 42,
      name: 'UCG Admin',
      email: 'admin@ucg.test',
      email_verified: true, mfa: { required: false, enabled: false, verified: false },
    })

    render(<App />)

    expect(await screen.findByText('UCG Platform v1 opérationnelle')).toBeVisible()
    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeVisible()

    await user.type(screen.getByLabelText('Adresse e-mail'), 'admin@ucg.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'secret-password')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(loginMock).toHaveBeenCalledWith('admin@ucg.test', 'secret-password')
    expect(await screen.findByText('Bienvenue, UCG Admin')).toBeVisible()
    expect(screen.getByText('Connexion réussie.')).toBeVisible()
    expect(screen.queryByRole('heading', { name: 'Connexion' })).not.toBeInTheDocument()
    expect(screen.queryByText('UCG Platform v1 opérationnelle')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Organisations' })).toHaveAttribute('aria-current', 'page')
    expect(screen.queryByRole('link', { name: 'Clients' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('link', { name: 'Mon compte' }))
    expect(screen.getByRole('heading', { name: 'Mon compte', level: 1 })).toHaveFocus()
    expect(screen.getByRole('button', { name: 'Déconnecter les autres appareils' })).toBeVisible()
    expect(screen.queryByRole('region', { name: 'Authentification à deux facteurs' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Mes organisations' })).not.toBeInTheDocument()
    expect(window.location.search).toBe('?espace=compte')

    await user.click(screen.getByRole('link', { name: 'Double authentification' }))
    expect(screen.getByRole('region', { name: 'Authentification à deux facteurs' })).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Déconnecter les autres appareils' })).not.toBeInTheDocument()

    window.history.back()
    expect(await screen.findByRole('button', { name: 'Déconnecter les autres appareils' })).toBeVisible()
    window.history.back()
    expect(await screen.findByRole('heading', { name: 'Mes organisations' })).toBeVisible()
  })

  it('refuse un lien direct vers une rubrique non accessible', async () => {
    window.history.replaceState(null, '', '/?espace=clients')
    getCurrentUserMock.mockResolvedValue({
      id: 42, name: 'Membre', email: 'member@example.test', email_verified: false,
      mfa: { required: false, enabled: false, verified: false },
    })
    render(<App />)

    expect(await screen.findByRole('heading', { name: 'Mes organisations' })).toBeVisible()
    expect(screen.queryByRole('link', { name: 'Clients' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Catalogues' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ajouter un client et son organisation' })).not.toBeInTheDocument()
  })

  it('garde les codes de récupération visibles avant d’ouvrir les organisations', async () => {
    const operator = userEvent.setup()
    const account = {
      id: 42, name: 'Admin', email: 'admin@example.test', email_verified: true,
      mfa: { required: true, enabled: false, verified: false },
    }
    getCurrentUserMock
      .mockResolvedValueOnce(account)
      .mockResolvedValue({ ...account, mfa: { ...account.mfa, enabled: true, verified: true } })
    vi.mocked(mfaRequest)
      .mockResolvedValueOnce({ data: { secret: 'TEST-SETUP-KEY' }, meta: { request_id: 'setup' } })
      .mockResolvedValueOnce({ data: { recovery_codes: ['fixture-recovery-code'] }, meta: { request_id: 'confirm' } })
    render(<App />)

    await operator.type(await screen.findByLabelText('Mot de passe'), 'fixture-password')
    await operator.click(screen.getByRole('button', { name: 'Configurer le MFA' }))
    await operator.type(await screen.findByLabelText('Code de l’application (6 chiffres)'), '012345')
    await operator.click(screen.getByRole('button', { name: 'Confirmer l’activation' }))

    expect(await screen.findByRole('region', { name: 'Codes de récupération' })).toBeVisible()
    expect(screen.queryByRole('heading', { name: 'Mes organisations' })).not.toBeInTheDocument()
    await operator.click(screen.getByRole('button', { name: 'J’ai conservé mes codes' }))
    expect(await screen.findByRole('heading', { name: 'Mes organisations' })).toBeVisible()
  })

  it('termine une session active avec la notification renvoyée par l API', async () => {
    const user = userEvent.setup()
    getCurrentUserMock.mockResolvedValue({
      id: 42,
      name: 'UCG Admin',
      email: 'admin@ucg.test',
      email_verified: true, mfa: { required: false, enabled: false, verified: false },
    })
    logoutMock.mockResolvedValue({
      type: 'success',
      message: 'Session fermée.',
    })

    render(<App />)

    await user.click(await screen.findByRole('button', { name: 'Se déconnecter' }))

    expect(logoutMock).toHaveBeenCalledOnce()
    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeVisible()
    expect(screen.getByText('Session fermée.')).toBeVisible()
  })

  it('retire les données de l’ancien compte si la session a changé dans un autre onglet', async () => {
    getCurrentUserMock.mockResolvedValue({
      id: 42, name: 'Premier compte', email: 'first@example.test', email_verified: true,
      mfa: { required: false, enabled: false, verified: false },
    })
    vi.mocked(tenancyRequest).mockImplementation(async (path) => path === 'active-organization'
      ? { data: { context: { organization_id: 'alpha', name: 'Organisation Alpha', slug: 'alpha', timezone: 'UTC',
        language: 'fr', roles: ['member'], permissions: [], is_owner: false, actor_user_id: 42, membership_id: 'membership-alpha' },
      revision: 'revision-alpha', confirmed: false } }
      : { data: [], meta: { request_id: 'test', current_page: 1, last_page: 1, total: 0 } })
    render(<App />)
    expect(await screen.findByLabelText('Contexte d’organisation')).toHaveTextContent('Organisation Alpha')
    getCurrentUserMock.mockResolvedValue({
      id: 43, name: 'Second compte', email: 'second@example.test', email_verified: true,
      mfa: { required: true, enabled: true, verified: false },
    })
    await act(async () => window.dispatchEvent(new Event('focus')))
    expect(await screen.findByText('Second compte')).toBeVisible()
    expect(screen.getByLabelText('Code de l’application (6 chiffres)')).toBeVisible()
    expect(screen.queryByLabelText('Contexte d’organisation')).not.toBeInTheDocument()
  })

  it('efface le contexte du compte précédent avant le MFA du nouveau compte', async () => {
    const user = userEvent.setup()
    getCurrentUserMock.mockResolvedValue({
      id: 42, name: 'Premier compte', email: 'first@example.test', email_verified: false,
      mfa: { required: true, enabled: true, verified: true },
    })
    const active: ActiveOrganization = {
      context: {
        organization_id: 'alpha', name: 'Organisation confidentielle Alpha', slug: 'alpha',
        timezone: 'UTC', language: 'fr', roles: ['member'], permissions: [],
        is_owner: false, actor_user_id: 42, membership_id: 'membership-alpha',
      },
      revision: 'revision-alpha', confirmed: false,
    }
    vi.mocked(tenancyRequest).mockImplementation(async (path) => {
      if (path === 'active-organization') return { data: active }
      if (path.includes('?')) return {
        data: [], meta: { request_id: 'test', current_page: 1, last_page: 1, total: 0 },
      }
      return { data: [] }
    })
    logoutMock.mockResolvedValue(null)
    loginMock.mockResolvedValue({
      id: 43, name: 'Second compte', email: 'second@example.test', email_verified: true,
      mfa: { required: true, enabled: true, verified: false },
    })

    render(<App />)

    const context = await screen.findByLabelText('Contexte d’organisation')
    expect(await within(context).findByText('Organisation confidentielle Alpha')).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Se déconnecter' }))
    await screen.findByRole('heading', { name: 'Connexion' })
    await user.type(screen.getByLabelText('Adresse e-mail'), 'second@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'fixture-password')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByLabelText('Code de l’application (6 chiffres)')).toBeVisible()
    expect(screen.queryByLabelText('Contexte d’organisation')).not.toBeInTheDocument()
    expect(screen.queryByText('Organisation confidentielle Alpha')).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Mes organisations' })).not.toBeInTheDocument()
  })

  it('affiche toutes les erreurs de validation près des champs et cible la première', async () => {
    const user = userEvent.setup()
    loginMock.mockRejectedValue(
      new ApiClientError('Les données transmises sont invalides.', {
        code: 'VALIDATION_FAILED',
        fieldErrors: {
          email: ['L’adresse e-mail est obligatoire.'],
          password: ['Le mot de passe est obligatoire.'],
        },
        requestId: 'request-123',
        status: 422,
      }),
    )

    render(<App />)

    await user.click(await screen.findByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Les données transmises sont invalides.',
    )
    expect(screen.getByText('Référence de support : request-123')).toBeVisible()
    expect(screen.getByText('L’adresse e-mail est obligatoire.')).toBeVisible()
    expect(screen.getByText('Le mot de passe est obligatoire.')).toBeVisible()
    expect(screen.getByLabelText('Adresse e-mail')).toHaveAttribute(
      'aria-invalid',
      'true',
    )
    expect(screen.getByLabelText('Adresse e-mail')).toHaveFocus()
  })

  it('propose une récupération sans révéler si le compte existe', async () => {
    const user = userEvent.setup()
    requestPasswordResetMock.mockResolvedValue({
      type: 'success',
      message: 'Si un compte correspond, un lien a été envoyé.',
    })

    render(<App />)

    await user.click(await screen.findByRole('button', { name: 'Mot de passe oublié ?' }))
    await user.type(screen.getByLabelText('Adresse e-mail'), 'member@ucg.test')
    await user.click(screen.getByRole('button', { name: 'Envoyer le lien' }))

    expect(requestPasswordResetMock).toHaveBeenCalledWith('member@ucg.test')
    expect(await screen.findByText('Si un compte correspond, un lien a été envoyé.')).toBeVisible()
    expect(screen.getByRole('heading', { name: 'Connexion' })).toBeVisible()
  })

  it('conserve la référence d une erreur système et permet une nouvelle tentative', async () => {
    const user = userEvent.setup()
    getSystemStatusMock
      .mockRejectedValueOnce(
        new ApiClientError('Le service est indisponible.', {
          code: 'INTERNAL_ERROR',
          requestId: 'request-system-123',
          status: 500,
        }),
      )
      .mockResolvedValueOnce({
        apiVersion: 'v1',
        name: 'UCG Platform',
        status: 'operational',
      })

    render(<App />)

    expect(await screen.findByText('Référence de support : request-system-123')).toBeVisible()

    await user.click(screen.getByRole('button', { name: 'Réessayer' }))

    expect(await screen.findByText('UCG Platform v1 opérationnelle')).toBeVisible()
    expect(getSystemStatusMock).toHaveBeenCalledTimes(2)
  })
})
