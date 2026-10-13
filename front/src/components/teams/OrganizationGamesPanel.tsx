import { useEffect, useState, type FormEvent } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { tenancyRequest } from '../../api/tenancy'
import { getOrganizationGame, kindLabel, organizationGamesPath, restrictionKinds, type GameRestrictions, type OrganizationGame, type OrganizationGameDetail } from '../../api/gameCatalogue'
import { isAbortError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { SelectField } from '../people/peopleForms'
import { apiError, shouldReloadContext } from '../people/peopleFormValues'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { CatalogueChoices } from './CatalogueChoices'

export function OrganizationGamesPanel({ active, disabled, onInvalid }: { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }) {
  const org = active.context?.organization_id, revision = active.revision ?? undefined
  const allowed = active.context?.permissions.includes('sports.read') ?? false
  const canManage = allowed && (active.context?.permissions.includes('games.manage') ?? false)
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [list, setList] = useState<Page<OrganizationGame> | null>(null)
  const [selected, setSelected] = useState<string | null>(null), [game, setGame] = useState<OrganizationGameDetail | null>(null)
  const [loading, setLoading] = useState(false), [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  // Reload sibling profile choices after an activation or restriction change.
  const mutation = usePeopleMutation<OrganizationGameDetail>(active, (result) => { setGame(result); setRefresh((value) => value + 1); onInvalid() }, onInvalid)
  useEffect(() => {
    if (!org || !allowed || !open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setGame(null); setError(null)
      await Promise.all([
      tenancyRequest<Page<OrganizationGame>>(organizationGamesPath(org) + '?per_page=10&page=' + page, { revision, signal: controller.signal }),
      selected ? getOrganizationGame(org, selected, revision, controller.signal) : Promise.resolve(null),
    ]).then(([nextList, detail]) => { if (!controller.signal.aborted) { setList(nextList); setGame(detail?.data ?? null) } })
      .catch((failure: unknown) => {
        if (controller.signal.aborted || isAbortError(failure)) return
        const next = apiError(failure); setError(next)
        if (shouldReloadContext(next)) onInvalid()
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }
    void load()
    return () => controller.abort()
  }, [org, revision, allowed, open, page, selected, refresh, onInvalid])
  if (!org || !allowed) return null
  const blocked = disabled || loading || mutation.blocked
  return <section aria-label={t('games.orgTitle')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <Button disabled={disabled || mutation.blocked} onClick={() => setOpen((value) => !value)}>{open ? t('games.close') : t('games.orgTitle')}</Button>
    {open && <>
      <p className="text-sm text-slate-300">{t('games.orgHint')}</p>
      {!canManage && <p>{t('games.readOnly')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {loading && <p role="status">{t('games.loading')}</p>}
      {mutation.feedback}
      {list && <ul className="grid min-w-0 gap-3 sm:grid-cols-2">{list.data.map((entry) => <li key={entry.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
        <p className="break-words font-semibold [overflow-wrap:anywhere]">{entry.name}</p>
        <p>{!entry.available ? t('games.retired') : entry.enabled ? t('games.enabledStatus') : t('games.disabled')}</p>
        <Button disabled={blocked} onClick={() => setSelected(entry.id)}>{canManage ? t('games.edit') : t('games.available')}</Button>
      </li>)}</ul>}
      {list?.data.length === 0 && <p>{t('games.empty')}</p>}
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked}
        onPageChange={(next) => { setSelected(null); setPage(next) }} />}
      {game && <OrganizationGameForm key={game.id + ':' + game.version} game={game} disabled={blocked || !canManage}
        errors={mutation.errors(organizationGamesPath(org, game.id))} onCancel={() => setSelected(null)}
        onSubmit={(event, body) => mutation.propose(event, t('games.orgConfirm', { name: game.name }), organizationGamesPath(org, game.id), body, true)} />}
    </>}
  </section>
}

function OrganizationGameForm({ game, disabled, errors, onCancel, onSubmit }: {
  game: OrganizationGameDetail; disabled: boolean; errors: Record<string, string[]>; onCancel: () => void
  onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void
}) {
  const [restrictions, setRestrictions] = useState<GameRestrictions>(game.restrictions)
  function submit(event: FormEvent<HTMLFormElement>) {
    const form = new FormData(event.currentTarget)
    onSubmit(event, { enabled: form.get('enabled') === 'yes', restrictions, version: game.version, catalogue_version: game.catalogue_version })
  }
  return <form aria-label={t('games.orgSave')} onSubmit={submit} className="grid min-w-0 gap-4 rounded-xl border border-cyan-400/30 p-4">
    <h3 className="break-words text-lg font-bold [overflow-wrap:anywhere]">{game.name}</h3>
    {!game.available && <p className="text-amber-200">{t('games.retired')}</p>}
    {!game.available && <p className="text-sm text-amber-200">{t('games.retiredHint')}</p>}
    <SelectField name="enabled" title={t('games.enabled')} disabled={disabled} defaultValue={game.available && game.configured_enabled ? 'yes' : 'no'} error={errors.enabled?.[0]}>
      <option value="no">{t('games.disabled')}</option><option value="yes" disabled={!game.available}>{t('games.enabledStatus')}</option>
    </SelectField>
    <p className="text-sm text-slate-400">{t('games.restrictionHint')}</p>
    {restrictionKinds.map((kind) => <CatalogueChoices key={kind} title={kindLabel(kind)} choices={game.options[kind]} value={restrictions[kind]} inherit
      disabled={disabled} error={errors['restrictions.' + kind]?.[0]} onChange={(keys) => setRestrictions((current) => {
        const next = { ...current }; if (keys === undefined) delete next[kind]; else next[kind] = keys; return next
      })} />)}
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{t('games.orgSave')}</Button>
      <Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}
