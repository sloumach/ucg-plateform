import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ActiveOrganization } from '../../api/tenancy'
import type { ExternalCompetition, CompetitionDocument } from '../../api/externalCompetitions'
import { ApiClientError } from '../../api/errors'
import { NotificationProvider } from '../feedback/NotificationProvider'
import { CompetitionDocuments } from './CompetitionDocuments'
const fake = vi.hoisted(() => ({ request: vi.fn(), invalid: vi.fn(), blob: vi.fn(), revoke: vi.fn(), anchor: vi.fn() }))
vi.mock('../../api/tenancy', async (original) => ({ ...await original<typeof import('../../api/tenancy')>(), tenancyRequest: fake.request }))
const org = '11111111-1111-4111-8111-111111111111', id = '22222222-2222-4222-8222-222222222222', version = '33333333-3333-4333-8333-333333333333'
const active: ActiveOrganization = { context: { organization_id: org, name: 'Alpha', slug: 'alpha', timezone: 'UTC', language: 'fr', roles: ['member'], permissions: ['sports.read', 'sports.competitions.read', 'sports.competitions.write'], actor_user_id: 42, is_owner: false, membership_id: id }, revision: version, confirmed: true }
const record: ExternalCompetition = { id, version, reference: 'LEAGUE', game_id: id, game_key: 'pilot', game_name: 'Jeu pilote', name: 'Circuit externe', organizer: 'Organisateur', region: 'Europe', season: '2026', website_url: null, contacts: [], status: 'active', created_at: '2026-10-18T10:00:00Z', updated_at: '2026-10-18T10:00:00Z' }
const document: CompetitionDocument = { document_id: id, version, number: 1, kind: 'rules', title: '<img>Règlement</img>', body: '<script>Règlement source</script>', source_url: 'https://league.example.test', reason: 'Source initiale conservée.', actor_user_id: 42, occurred_at: record.created_at }
const root = 'tenants/' + org + '/external-competitions', path = root + '/' + id + '/documents'
function page<T>(data: T[]) { return { data, meta: { current_page: 1, last_page: 1, total: data.length } } }
function show(selection = active, item = record) { return render(<NotificationProvider><CompetitionDocuments active={selection} item={item} root={root} disabled={false} onInvalid={fake.invalid} onClose={() => {}} /></NotificationProvider>) }
beforeEach(() => {
  vi.resetAllMocks(); fake.blob.mockReturnValue('blob:private')
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: fake.blob }); Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: fake.revoke })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(fake.anchor)
  fake.request.mockImplementation(async (_, options) => options?.download ? new Blob(['private text'], { type: 'text/plain' }) : options?.method ? { data: document } : page([document]))
})
describe('documents privés de compétition', () => {
  it('conserve le contenu texte échappé et autorise le téléchargement sans ouvrir de fenêtre externe', async () => {
    const view = show(); await screen.findByText(document.body); expect(view.container.querySelector('script,img')).toBeNull()
    await userEvent.click(screen.getByRole('button', { name: 'Télécharger la version texte' }))
    await waitFor(() => expect(fake.blob).toHaveBeenCalledOnce())
    expect(fake.request).toHaveBeenCalledWith(path + '/' + id + '/versions/' + version + '/download', expect.objectContaining({ download: true, revision: version }))
  })
  it('envoie deux versions distinctes et conserve le type du règlement lors de sa révision', async () => {
    show(); const user = userEvent.setup(); await screen.findByText(document.body); await user.click(screen.getByRole('button', { name: 'Nouvelle version du document' }))
    const form = screen.getByRole('form', { name: 'Enregistrer la version privée' }); expect(within(form).queryByLabelText('Type du document')).toBeNull()
    await user.clear(within(form).getByLabelText('Contenu texte du document')); await user.type(within(form).getByLabelText('Contenu texte du document'), 'Règlement révisé et conservé.')
    await user.type(within(form).getByLabelText('Motif du changement'), 'Nouvelle version reçue de l’organisateur.')
    fireEvent.submit(form); expect(fake.request.mock.calls.filter(([, options]) => options?.method)).toHaveLength(0)
    await user.click(screen.getByRole('button', { name: 'Confirmer et continuer' }))
    expect(fake.request).toHaveBeenCalledWith(path + '/' + id, expect.objectContaining({ method: 'PATCH', body: { competition_version: version, version, title: document.title, body: 'Règlement révisé et conservé.', source_url: document.source_url, reason: 'Nouvelle version reçue de l’organisateur.' } }))
  })
  it('garde les dossiers archivés et les lecteurs en consultation', async () => {
    const view = show(active, { ...record, status: 'archived' }); await screen.findByText(document.body)
    expect(screen.queryByRole('button', { name: 'Nouveau document privé' })).toBeNull(); expect(screen.queryByRole('button', { name: 'Nouvelle version du document' })).toBeNull()
    view.unmount(); show({ ...active, context: { ...active.context!, permissions: ['sports.read', 'sports.competitions.read'] } }); await screen.findByText(document.body)
    expect(screen.queryByRole('button', { name: 'Nouveau document privé' })).toBeNull()
  })
  it('refuse tout blob après révocation des droits et redemande le contexte', async () => {
    show(); await screen.findByText(document.body)
    fake.request.mockRejectedValue(new ApiClientError('Accès révoqué.', { status: 403 }))
    await userEvent.click(screen.getByRole('button', { name: 'Télécharger la version texte' })); await waitFor(() => expect(fake.invalid).toHaveBeenCalledOnce())
    expect(fake.blob).not.toHaveBeenCalled()
  })
  it('annule un transfert en cours à la fermeture sans exposer le texte reçu tardivement', async () => {
    const view = show(); await screen.findByText(document.body)
    let complete: ((blob: Blob) => void) | undefined
    fake.request.mockImplementation(() => new Promise<Blob>((resolve) => { complete = resolve }))
    await userEvent.click(screen.getByRole('button', { name: 'Télécharger la version texte' }))
    const signal = fake.request.mock.calls.at(-1)![1].signal as AbortSignal
    view.unmount(); expect(signal.aborted).toBe(true); complete!(new Blob(['late private text']))
    await Promise.resolve(); expect(fake.blob).not.toHaveBeenCalled()
  })
  it('consulte les anciennes versions avec le chemin du document et bloque les sources dangereuses', async () => {
    fake.request.mockImplementation(async (target) => page([{ ...document, version: target.includes('/history?') ? id : version, source_url: 'javascript:alert(1)' }]))
    show(); await screen.findByText(document.body); expect(screen.queryByRole('link', { name: 'Ouvrir la source du document' })).toBeNull()
    await userEvent.click(screen.getByRole('button', { name: 'Versions du document privé' }))
    await waitFor(() => expect(fake.request).toHaveBeenCalledWith(path + '/' + id + '/history?page=1&per_page=5', expect.anything()))
    expect(screen.getAllByText(document.body)).toHaveLength(2)
  })
})
