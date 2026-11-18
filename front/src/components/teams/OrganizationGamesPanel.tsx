import { useEffect, useState, type FormEvent } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { tenancyRequest } from '../../api/tenancy'
import { getOrganizationGame, kindLabel, organizationGamesPath, restrictionKinds, type GameRestrictions, type OrganizationGame, type OrganizationGameDetail } from '../../api/gameCatalogue'
import { isAbortError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { WorkspaceEditor } from '../layout/WorkspaceEditor'
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
  return <section aria-label={t('games.orgTitle')} className="ui-panel grid min-w-0 gap-4 p-4">
    <Button className="justify-self-start" disabled={disabled || mutation.blocked} aria-expanded={open} onClick={() => { setOpen(!open); setSelected(null); setGame(null) }}>{open ? t('games.close') : t('games.orgTitle')}</Button>
    {open && <>
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {loading && <p role="status">{t('games.loading')}</p>}
      {mutation.feedback}
      {selected && error && !game && <Button className="justify-self-start" onClick={() => setSelected(null)}>{t('workspace.backToList')}</Button>}
      {!selected && <>
      <p className="text-sm text-slate-300">{t('games.orgHint')}</p>
      {!canManage && <p>{t('games.readOnly')}</p>}
      {list && <ul className="grid min-w-0 gap-3">{list.data.map((entry) => <li key={entry.id} className="ui-card flex min-w-0 flex-wrap items-center justify-between gap-3 p-4">
        <div className="min-w-0"><h3 className="font-semibold wrap-anywhere">{entry.name}</h3>
        <p className="text-sm text-muted">{!entry.available ? t('games.retired') : entry.enabled ? t('games.enabledStatus') : t('games.disabled')}</p></div>
        <Button disabled={blocked} onClick={() => setSelected(entry.id)}>{canManage ? t('games.edit') : t('games.available')}</Button>
      </li>)}</ul>}
      {list?.data.length === 0 && <p className="ui-state text-sm">{t('games.empty')}</p>}
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked}
        onPageChange={(next) => { setSelected(null); setPage(next) }} />}
      </>}
      {selected && game && <WorkspaceEditor key={game.id} title={game.name}>
        <OrganizationGameForm key={game.version ?? 'default'} game={game} disabled={blocked} readOnly={!canManage}
          errors={mutation.errors(organizationGamesPath(org, game.id))} onCancel={() => setSelected(null)}
          onSubmit={(event, body) => mutation.propose(event, t('games.orgConfirm', { name: game.name }), organizationGamesPath(org, game.id), body, true)} />
      </WorkspaceEditor>}
    </>}
  </section>
}

function OrganizationGameForm({ game, disabled, readOnly, errors, onCancel, onSubmit }: {
  game: OrganizationGameDetail; disabled: boolean; readOnly: boolean; errors: Record<string, string[]>; onCancel: () => void
  onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void
}) {
  // Laravel returns [] when there are no restrictions. Its map property is Array.prototype.map.
  const [restrictions, setRestrictions] = useState<GameRestrictions>(() => Array.isArray(game.restrictions) ? {} : game.restrictions)
  function submit(event: FormEvent<HTMLFormElement>) {
    const form = new FormData(event.currentTarget)
    onSubmit(event, { enabled: form.get('enabled') === 'yes', restrictions, version: game.version, catalogue_version: game.catalogue_version })
  }
  return <form aria-label={t('games.orgSave')} onSubmit={submit} className="ui-editor grid min-w-0 gap-4 p-4">
    {!game.available && <p className="text-amber-200">{t('games.retired')}</p>}
    {!game.available && <p className="text-sm text-amber-200">{t('games.retiredHint')}</p>}
    <SelectField name="enabled" title={t('games.enabled')} disabled={disabled || readOnly} defaultValue={game.available && game.configured_enabled ? 'yes' : 'no'} error={errors.enabled?.[0]}>
      <option value="no">{t('games.disabled')}</option><option value="yes" disabled={!game.available}>{t('games.enabledStatus')}</option>
    </SelectField>
    <p className="text-sm text-slate-400">{t('games.restrictionHint')}</p>
    <div className="grid min-w-0 items-start gap-3 lg:grid-cols-2">{restrictionKinds.map((kind) => <CatalogueChoices key={kind} title={kindLabel(kind)} choices={game.options[kind]} value={restrictions[kind]} inherit
      disabled={disabled || readOnly} error={errors['restrictions.' + kind]?.[0]} onChange={(keys) => setRestrictions((current) => {
        const next = { ...current }; if (keys === undefined) delete next[kind]; else next[kind] = keys; return next
      })} />)}</div>
    <div className="flex flex-wrap gap-3"><Button variant="primary" type="submit" disabled={disabled || readOnly}>{t('games.orgSave')}</Button>
      <Button disabled={disabled} onClick={onCancel}>{t('workspace.backToList')}</Button></div>
  </form>
}
