import { useCallback, useEffect, useRef, useState } from 'react'
import { listGameProfiles, listExternalGameAccounts, type ExternalGameAccount, type GameProfile, type GameProfileCatalogue } from '../../api/gameProfiles'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import { isAbortError } from '../../api/errors'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { AsyncState } from '../feedback/AsyncState'
import { SelectField } from './peopleForms'
import { apiError, changedValues, label, localDate, shouldReloadContext } from './peopleFormValues'
import { usePeopleMutation } from './usePeopleMutation'

type Catalogue = GameProfileCatalogue['data']
export function GameProfilesPanel({ active, personId, catalogue, disabled: parentDisabled, onInvalid, closed = false }: {
  active: ActiveOrganization; personId: string; catalogue: Catalogue | null; disabled: boolean; onInvalid: () => void; closed?: boolean
}) {
  const organizationId = active.context?.organization_id
  const revision = active.revision ?? undefined
  const canWrite = !closed && Boolean(active.context?.permissions.includes('sports.write'))
  const [profiles, setProfiles] = useState<Page<GameProfile> | null>(null)
  const [accounts, setAccounts] = useState<Page<ExternalGameAccount> | null>(null)
  const [selected, setSelected] = useState<GameProfile | null>(null)
  const [profilePage, setProfilePage] = useState(1)
  const [accountPage, setAccountPage] = useState(1)
  const [profileEditor, setProfileEditor] = useState<GameProfile | null | undefined>(undefined)
  const [accountEditor, setAccountEditor] = useState<ExternalGameAccount | null | undefined>(undefined)
  const [refresh, setRefresh] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<ReturnType<typeof apiError> | null>(null)
  const alive = useRef(true)
  const onSaved = () => { setProfileEditor(undefined); setAccountEditor(undefined); setSelected(null); setRefresh((value) => value + 1) }
  const mutation = usePeopleMutation(active, onSaved, onInvalid)
  const load = useCallback(async (signal: AbortSignal) => {
    if (!organizationId) return
    setLoading(true); setProfiles(null); setAccounts(null); setError(null)
    try {
      const options = { perPage: 10, revision, signal }
      const [nextProfiles, nextAccounts] = await Promise.all([
        listGameProfiles(organizationId, personId, { ...options, page: profilePage }),
        selected ? listExternalGameAccounts(organizationId, personId, selected.id, { ...options, page: accountPage }) : null,
      ])
      if (signal.aborted || !alive.current) return
      setProfiles(nextProfiles); setAccounts(nextAccounts)
    } catch (failure) {
      if (signal.aborted || !alive.current || isAbortError(failure)) return
      const next = apiError(failure); setError(next)
      if (shouldReloadContext(next)) onInvalid()
    } finally { if (!signal.aborted && alive.current) setLoading(false) }
  }, [organizationId, personId, revision, profilePage, selected, accountPage, onInvalid])
  useEffect(() => {
    alive.current = true
    const controller = new AbortController()
    void Promise.resolve().then(() => { if (!controller.signal.aborted) void load(controller.signal) })
    return () => { alive.current = false; controller.abort() }
  }, [load, refresh])
  const disabled = parentDisabled || loading || mutation.blocked
  const basePath = 'tenants/' + organizationId + '/people/' + personId + '/game-profiles'
  const profilePath = basePath + (profileEditor ? '/' + profileEditor.id : '')
  const accountPath = basePath + '/' + selected?.id + '/external-accounts' + (accountEditor ? '/' + accountEditor.id : '')
  const context = (profile: GameProfile) => catalogue?.games.find((game) => game.key === profile.game_key && game.regions.some((region) => region.key === profile.region_key))?.platforms.find((platform) => platform.key === profile.platform_key)

  return <section aria-label={label('profiles')} aria-busy={loading} className="grid min-w-0 gap-4">
    <h4 className="text-lg font-semibold">{label('profiles')}</h4>
    <p className="text-sm text-slate-400">{label('manual')}</p>
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {mutation.feedback}
    {loading && <AsyncState variant="loading" title={t('people.loading')} />}
    {canWrite && (catalogue?.games.length ?? 0) > 0 && <Button disabled={disabled} onClick={() => { setProfileEditor(null); setAccountEditor(undefined) }}>{label('addProfile')}</Button>}
    {profileEditor !== undefined && <ProfileEditor key={profileEditor?.version ?? 'new'} profile={profileEditor} catalogue={catalogue} disabled={disabled} errors={mutation.errors(profilePath)} onCancel={() => setProfileEditor(undefined)} onSubmit={(event, values) => mutation.propose(event, label('saveProfile'), profilePath, { ...values, ...(profileEditor ? { version: profileEditor.version } : {}) }, profileEditor !== null)} />}
    {profiles?.data.length === 0 && <p>{label('profilesEmpty')}</p>}
    {profiles?.data.map((profile) => <article key={profile.id} className="grid min-w-0 gap-3 rounded-xl border border-white/10 p-4">
      <p className="break-words font-semibold">{catalogue?.games.find((game) => game.key === profile.game_key)?.name ?? profile.game_key} · {context(profile)?.name ?? profile.platform_key} · {profile.region_key} · {label(profile.status ?? 'active')}</p>
      <p className="whitespace-pre-wrap break-words">{label('rank')}: {profile.rank ?? label('none')} · {label('game_role')}: {profile.game_role ?? label('none')}</p>
      <Observation observedAt={profile.observed_at} freshness={profile.freshness} />
      <p>{label('accounts')}: {profile.external_accounts_count ?? 0}</p>
      {!context(profile) && <p className="text-sm text-amber-200">{label('withdrawn')}</p>}
      <div className="flex flex-wrap gap-3">
        <Button disabled={disabled} onClick={() => { setSelected(profile); setAccountPage(1); setAccountEditor(undefined); setProfileEditor(undefined) }}>{label('showAccounts')} · {profile.game_key} / {profile.platform_key} / {profile.region_key}</Button>
        {canWrite && <Button disabled={disabled} onClick={() => setProfileEditor(profile)}>{label('editProfile')} · {profile.game_key} / {profile.platform_key} / {profile.region_key}</Button>}
      </div>
    </article>)}
    {profiles && <Pagination currentPage={profiles.meta.current_page} totalPages={profiles.meta.last_page} disabled={disabled} onPageChange={(page) => { setProfilePage(page); setSelected(null); setAccountEditor(undefined); setProfileEditor(undefined) }} />}
    {selected && <section aria-label={label('accounts')} className="grid min-w-0 gap-3 rounded-xl border border-white/10 p-4">
      <h5 className="font-semibold">{label('accounts')} · {selected.game_key} / {selected.platform_key} / {selected.region_key}</h5>
      {canWrite && selected.status === 'active' && (context(selected)?.providers.length ?? 0) > 0 && <Button disabled={disabled} onClick={() => setAccountEditor(null)}>{label('addAccount')}</Button>}
      {accountEditor !== undefined && <AccountEditor key={accountEditor?.version ?? 'new'} account={accountEditor} profile={selected} catalogue={catalogue} disabled={disabled} errors={mutation.errors(accountPath)} onCancel={() => setAccountEditor(undefined)} onSubmit={(event, values) => mutation.propose(event, label('saveAccount'), accountPath, { ...values, ...(accountEditor ? { version: accountEditor.version } : {}) }, accountEditor !== null)} />}
      {accounts?.data.length === 0 && <p>{label('accountsEmpty')}</p>}
      {accounts?.data.map((account) => <article key={account.id} className="grid min-w-0 gap-2 rounded-xl border border-white/10 p-4">
        <p className="break-words font-semibold">{account.provider_key} · {account.display_name ?? label('none')} · {label(account.status ?? 'active')}</p>
        <p className="break-all">{label('external_id')}: {account.external_id ?? label('none')}</p>
        <Observation observedAt={account.observed_at} freshness={account.freshness} />
        {canWrite && <Button disabled={disabled} onClick={() => setAccountEditor(account)}>{label('editAccount')} · {account.display_name ?? account.id}</Button>}
      </article>)}
      {accounts && <Pagination currentPage={accounts.meta.current_page} totalPages={accounts.meta.last_page} disabled={disabled} onPageChange={(page) => { setAccountEditor(undefined); setAccountPage(page) }} />}
    </section>}
  </section>
}

type EditorProps = { disabled: boolean; errors: Record<string, string[]>; onCancel: () => void; onSubmit: (event: React.FormEvent<HTMLFormElement>, values: Record<string, unknown>) => void }
function ProfileEditor({ profile, catalogue, disabled, errors, onCancel, onSubmit }: EditorProps & { profile: GameProfile | null; catalogue: Catalogue | null }) {
  const [gameKey, setGameKey] = useState(profile?.game_key ?? '')
  const [platformKey, setPlatformKey] = useState(profile?.platform_key ?? '')
  const game = catalogue?.games.find((item) => item.key === gameKey)
  const available = Boolean(game?.platforms.some((item) => item.key === platformKey) && game?.regions.some((item) => item.key === profile?.region_key))
  return <form noValidate aria-label={label('saveProfile')} className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); onSubmit(event, changedValues(event.currentTarget, profile, ['observed_at'])) }}>
    {profile ? <p className="break-words md:col-span-2">{profile.game_key} / {profile.platform_key} / {profile.region_key}</p> : <>
      <SelectField name="game_key" title={label('game')} value={gameKey} onChange={(value) => { setGameKey(value); setPlatformKey('') }} error={errors.game_key?.[0]} disabled={disabled}><option value="">{label('none')}</option>{catalogue?.games.map((item) => <option key={item.key} value={item.key}>{item.name}</option>)}</SelectField>
      <SelectField name="platform_key" title={label('platform')} value={platformKey} onChange={setPlatformKey} error={errors.platform_key?.[0]} disabled={disabled}><option value="">{label('none')}</option>{game?.platforms.map((item) => <option key={item.key} value={item.key}>{item.name}</option>)}</SelectField>
      <SelectField key={gameKey} name="region_key" title={label('region')} error={errors.region_key?.[0]} disabled={disabled}><option value="">{label('none')}</option>{game?.regions.map((item) => <option key={item.key} value={item.key}>{item.name}</option>)}</SelectField>
    </>}
    {(!profile || available) ? <>
      <FormField name="rank" label={label('rank')} defaultValue={profile?.rank ?? ''} maxLength={80} disabled={disabled} error={errors.rank?.[0]} />
      <FormField name="game_role" label={label('game_role')} defaultValue={profile?.game_role ?? ''} maxLength={80} disabled={disabled} error={errors.game_role?.[0]} />
      <FormField name="observed_at" label={label('observed_at')} type="datetime-local" step="1" defaultValue={localDate(profile?.observed_at)} disabled={disabled} error={errors.observed_at?.[0]} hint={label('observationHint')} />
    </> : <p>{label('withdrawn')}</p>}
    <RecordStatus status={profile?.status} disabled={disabled} archivedOnly={Boolean(profile && !available)} error={errors.status?.[0]} />
    <EditorButtons disabled={disabled} onCancel={onCancel} title={label('saveProfile')} />
  </form>
}

function AccountEditor({ account, profile, catalogue, disabled, errors, onCancel, onSubmit }: EditorProps & { account: ExternalGameAccount | null; profile: GameProfile; catalogue: Catalogue | null }) {
  const game = catalogue?.games.find((item) => item.key === profile.game_key)
  const providers = game?.platforms.find((item) => item.key === profile.platform_key)?.providers ?? []
  const available = profile.status === 'active' && game?.regions.some((item) => item.key === profile.region_key) && (!account || providers.some((provider) => provider.key === account.provider_key))
  return <form noValidate aria-label={label('saveAccount')} className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); onSubmit(event, changedValues(event.currentTarget, account, ['observed_at'])) }}>
    {account ? <p>{account.provider_key}</p> : <SelectField name="provider_key" title={label('provider')} disabled={disabled} error={errors.provider_key?.[0]}><option value="">{label('none')}</option>{providers.map((provider) => <option key={provider.key} value={provider.key}>{provider.name}</option>)}</SelectField>}
    {available ? <>
      <FormField name="external_id" label={label('external_id')} defaultValue={account?.external_id ?? ''} readOnly={Boolean(account?.external_id)} disabled={disabled} maxLength={191} error={errors.external_id?.[0]} hint={label('idHint')} />
      <FormField name="display_name" label={label('display_name')} defaultValue={account?.display_name ?? ''} disabled={disabled} maxLength={120} error={errors.display_name?.[0]} />
      <FormField name="observed_at" label={label('observed_at')} type="datetime-local" step="1" defaultValue={localDate(account?.observed_at)} disabled={disabled} error={errors.observed_at?.[0]} hint={label('observationHint')} />
    </> : <p>{label('withdrawn')}</p>}
    <RecordStatus status={account?.status} disabled={disabled} archivedOnly={!available} error={errors.status?.[0]} />
    <EditorButtons disabled={disabled} onCancel={onCancel} title={label('saveAccount')} />
  </form>
}
function RecordStatus({ status, disabled, archivedOnly, error }: { status?: string; disabled: boolean; archivedOnly: boolean; error?: string }) {
  return <SelectField name="status" title={label('recordStatus')} defaultValue={archivedOnly ? 'archived' : status ?? 'active'} disabled={disabled} error={error}>
    {!archivedOnly && <option value="active">{label('active')}</option>}<option value="archived">{label('archived')}</option>
  </SelectField>
}
function EditorButtons({ disabled, title, onCancel }: { disabled: boolean; title: string; onCancel: () => void }) {
  return <div className="flex flex-wrap gap-3 md:col-span-2"><Button type="submit" disabled={disabled}>{title}</Button><Button disabled={disabled} onClick={onCancel}>{t('tenant.cancel')}</Button></div>
}
function Observation({ observedAt, freshness }: { observedAt?: string | null; freshness?: string }) {
  return <p className="text-sm text-slate-400">{label('freshness.' + (freshness ?? 'unknown'))} · {observedAt ? new Date(observedAt).toLocaleString('fr') : label('none')}</p>
}
