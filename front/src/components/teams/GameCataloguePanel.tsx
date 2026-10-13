import { useEffect, useRef, useState, type FormEvent } from 'react'
import type { ApiFieldErrors, ApiSuccessResponse } from '../../api/contracts'
import { tenancyRequest } from '../../api/tenancy'
import { catalogueKinds, cataloguePath, globalChoices, kindLabel, scopedKinds, type CatalogueEntry, type CatalogueKind, type CataloguePage, type GlobalChoices } from '../../api/gameCatalogue'
import { isAbortError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { useNotifications } from '../feedback/notificationContext'
import { SelectField } from '../people/peopleForms'
import { apiError } from '../people/peopleFormValues'
import { CatalogueChoices } from './CatalogueChoices'

type Pending = { body: Record<string, unknown>; entry: CatalogueEntry | null; name: string; form: HTMLFormElement }
export function GameCataloguePanel() {
  const [open, setOpen] = useState(false), [kind, setKind] = useState<CatalogueKind>('game'), [gameId, setGameId] = useState('')
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [list, setList] = useState<CataloguePage | null>(null), [choices, setChoices] = useState<GlobalChoices | null>(null)
  const [editor, setEditor] = useState<CatalogueEntry | 'new' | null>(null), [pending, setPending] = useState<Pending | null>(null)
  const [confirmed, setConfirmed] = useState(false), [busy, setBusy] = useState(false), [loading, setLoading] = useState(false)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const alive = useRef(true), region = useRef<HTMLDivElement>(null), returnFocus = useRef<HTMLElement | null>(null)
  const { notify } = useNotifications()
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  useEffect(() => { if (pending) region.current?.focus(); else if (!busy) returnFocus.current?.focus() }, [pending, busy])
  useEffect(() => {
    if (!open) return
    const controller = new AbortController()
    const load = async () => {
      setLoading(true); setList(null); setChoices(null); setEditor(null); setError(null)
      const scoped = scopedKinds.includes(kind)
      await Promise.all([
      !scoped || gameId ? tenancyRequest<CataloguePage>(cataloguePath(kind, page, scoped ? gameId : undefined), { signal: controller.signal }) : Promise.resolve(null),
      globalChoices(controller.signal),
    ]).then(([nextList, nextChoices]) => {
      if (!controller.signal.aborted) { setList(nextList); setChoices(nextChoices) }
    }).catch((failure: unknown) => {
      if (!controller.signal.aborted && !isAbortError(failure)) setError(apiError(failure))
    }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }
    void load()
    return () => controller.abort()
  }, [open, kind, gameId, page, refresh])
  const blocked = busy || loading || pending !== null
  function propose(event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) {
    event.preventDefault()
    if (!list || editor === null || blocked) return
    setError(null); setConfirmed(false)
    returnFocus.current = event.currentTarget.querySelector('button[type="submit"]')
    setPending({ body: { ...body, catalogue_version: list.meta.catalogue_version }, entry: editor === 'new' ? null : editor,
      name: String(body.name), form: event.currentTarget })
  }
  async function save() {
    if (!pending || !confirmed || busy) return
    const action = pending
    setBusy(true)
    try {
      const result = await tenancyRequest<ApiSuccessResponse<CatalogueEntry>>('game-catalogue' + (action.entry ? '/' + action.entry.id : ''), {
        method: action.entry ? 'PATCH' : 'POST', body: { ...action.body, confirm_global: true },
      })
      if (!alive.current) return
      notify(result.notification ?? { type: 'success', message: t('tenant.done') })
      setPending(null); setEditor(null); setRefresh((value) => value + 1)
    } catch (failure) {
      if (!alive.current) return
      const next = apiError(failure); setError(next); setPending(null)
      const field = Object.keys(next.fieldErrors)[0]
      const element = Array.from(action.form.elements).find((item) => item.getAttribute('name') === field)
      if (element instanceof HTMLElement) returnFocus.current = element
      if ([401, 403, 404, 409].includes(next.status ?? 0)) {
        notify({ type: 'warning', message: next.message, requestId: next.requestId })
        setEditor(null); setRefresh((value) => value + 1)
      }
    } finally { if (alive.current) setBusy(false) }
  }
  return <section aria-label={t('games.globalTitle')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <Button disabled={blocked} onClick={() => setOpen((value) => !value)}>{open ? t('games.close') : t('games.globalTitle')}</Button>
    {open && <>
      <p className="text-sm text-slate-300">{t('games.globalHint')}</p>
      <SelectField name="catalogue_kind" title={t('games.kind')} value={kind} disabled={blocked}
        onChange={(value) => { setKind(value as CatalogueKind); setPage(1); setGameId(''); setEditor(null) }}>
        {catalogueKinds.map((value) => <option key={value} value={value}>{kindLabel(value)}</option>)}
      </SelectField>
      {scopedKinds.includes(kind) && <SelectField name="catalogue_game" title={t('games.game')} value={gameId} disabled={blocked} onChange={(value) => { setGameId(value); setPage(1) }}>
        <option value="">{t('games.chooseGame')}</option>
        {choices?.games.map((game) => <option key={game.id} value={game.id}>{game.name}</option>)}
      </SelectField>}
      {loading && <p role="status">{t('games.loading')}</p>}
      {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
      {pending && <div ref={region} tabIndex={-1} role="region" aria-label={t('games.globalConfirmTitle')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/40 bg-amber-400/10 p-4">
        <p className="break-words [overflow-wrap:anywhere]">{t('games.globalConfirmMessage', { name: pending.name })}</p>
        <label className="flex items-start gap-2"><input type="checkbox" checked={confirmed} disabled={busy}
          onChange={(event) => setConfirmed(event.target.checked)} />{t('games.globalConfirmCheckbox')}</label>
        <div className="flex flex-wrap gap-3"><Button disabled={!confirmed} isLoading={busy} loadingLabel={t('tenant.busy')} onClick={() => void save()}>{t('games.globalConfirm')}</Button>
          <Button disabled={busy} onClick={() => setPending(null)}>{t('tenant.cancel')}</Button></div>
      </div>}
      {list && <>
        {!list.meta.can_manage && <p>{t('games.readOnly')}</p>}
        {list.meta.can_manage && <Button disabled={blocked} onClick={() => { setError(null); setEditor('new') }}>{t('games.create')}</Button>}
        <ul className="grid min-w-0 gap-3 sm:grid-cols-2">{list.data.map((entry) => <li key={entry.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3">
          <p className="break-words font-semibold [overflow-wrap:anywhere]">{entry.name}</p>
          <p className="break-words text-sm text-slate-400 [overflow-wrap:anywhere]">{entry.key} · {entry.active ? t('games.available') : t('games.retired')}</p>
          {list.meta.can_manage && <Button disabled={blocked} onClick={() => { setError(null); setEditor(entry) }}>{t('games.edit')}</Button>}
        </li>)}</ul>
        {list.data.length === 0 && <p>{t('games.empty')}</p>}
        <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked} onPageChange={setPage} />
      </>}
      {editor && choices && list?.meta.can_manage && <GameCatalogueForm key={editor === 'new' ? 'new:' + kind + ':' + gameId : editor.id + ':' + editor.version}
        entry={editor === 'new' ? null : editor} kind={kind} gameId={gameId} choices={choices} disabled={blocked}
        errors={error?.fieldErrors ?? {}} onSubmit={propose} onCancel={() => setEditor(null)} />}
      {error && !editor && <Button disabled={blocked} onClick={() => setRefresh((value) => value + 1)}>{t('games.reload')}</Button>}
    </>}
  </section>
}

function GameCatalogueForm({ entry, kind, gameId, choices, disabled, errors, onSubmit, onCancel }: {
  entry: CatalogueEntry | null; kind: CatalogueKind; gameId: string; choices: GlobalChoices; disabled: boolean; errors: ApiFieldErrors
  onSubmit: (event: FormEvent<HTMLFormElement>, body: Record<string, unknown>) => void; onCancel: () => void
}) {
  const [platforms, setPlatforms] = useState<Record<string, string[]>>(entry?.definition?.platforms ?? {})
  const [regions, setRegions] = useState<string[]>(entry?.definition?.regions ?? [])
  function submit(event: FormEvent<HTMLFormElement>) {
    const form = new FormData(event.currentTarget)
    onSubmit(event, { name: form.get('name'), active: form.has('active'),
      ...(entry ? { version: entry.version } : { kind, key: form.get('key'), ...(scopedKinds.includes(kind) ? { game_id: gameId } : {}) }),
      ...(kind === 'game' ? { definition: { platforms, regions } } : {}) })
  }
  return <form aria-label={t('games.save')} onSubmit={submit} className="grid min-w-0 gap-4 rounded-xl border border-cyan-400/30 p-4">
    <h3 className="font-bold">{kindLabel(kind)}</h3>
    {entry ? <p className="break-words [overflow-wrap:anywhere]">{t('games.key')} : {entry.key}</p> :
      <FormField name="key" label={t('games.key')} hint={t('games.keyHint')} required disabled={disabled}
        maxLength={['platform', 'provider', 'region'].includes(kind) ? 32 : 64} error={errors.key?.[0]} />}
    <FormField name="name" label={t('games.name')} defaultValue={entry?.name ?? ''} maxLength={120} required disabled={disabled} error={errors.name?.[0]} />
    <label className="flex items-start gap-2"><input name="active" type="checkbox" defaultChecked={entry?.active ?? true} disabled={disabled} />{t('games.active')}</label>
    {kind === 'game' && <>
      <p className="text-sm text-slate-400">{t('games.platformsHint')}</p>
      <CatalogueChoices title={kindLabel('platform')} choices={choices.platforms} value={Object.keys(platforms)} disabled={disabled} error={errors['definition.platforms']?.[0]}
        onChange={(keys) => setPlatforms((current) => Object.fromEntries((keys ?? []).map((key) => [key, current[key] ?? []])))} />
      {Object.keys(platforms).map((platform) => <CatalogueChoices key={platform} title={kindLabel('provider') + ' · ' + (choices.platforms.find((item) => item.key === platform)?.name ?? platform)}
        choices={choices.providers} value={platforms[platform]} disabled={disabled} onChange={(keys) => setPlatforms((current) => ({ ...current, [platform]: keys ?? [] }))} />)}
      <CatalogueChoices title={kindLabel('region')} choices={choices.regions} value={regions} disabled={disabled}
        error={errors['definition.regions']?.[0]} onChange={(keys) => setRegions(keys ?? [])} />
    </>}
    <div className="flex flex-wrap gap-3"><Button type="submit" disabled={disabled}>{t('games.save')}</Button>
      <Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
  </form>
}
