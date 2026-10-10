import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import { getCurrentUser, login, logout } from './api/auth'
import { ApiClientError } from './api/errors'
import { getSystemStatus } from './api/systemStatus'

vi.mock('./api/auth', async (importOriginal) => {
  const original = await importOriginal<typeof import('./api/auth')>()

  return {
    ...original,
    getCurrentUser: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
  }
})

vi.mock('./api/systemStatus', () => ({
  getSystemStatus: vi.fn(),
}))

const getCurrentUserMock = vi.mocked(getCurrentUser)
const getSystemStatusMock = vi.mocked(getSystemStatus)
const loginMock = vi.mocked(login)
const logoutMock = vi.mocked(logout)

describe('App', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getCurrentUserMock.mockResolvedValue(null)
    getSystemStatusMock.mockResolvedValue({
      apiVersion: 'v1',
      name: 'UCG Platform',
      status: 'operational',
    })
  })

  it('affiche le statut de la plateforme et permet de se connecter', async () => {
    const user = userEvent.setup()
    loginMock.mockResolvedValue({
      id: 42,
      name: 'UCG Admin',
      email: 'admin@ucg.test',
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
  })

  it('termine une session active avec la notification renvoyée par l API', async () => {
    const user = userEvent.setup()
    getCurrentUserMock.mockResolvedValue({
      id: 42,
      name: 'UCG Admin',
      email: 'admin@ucg.test',
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
