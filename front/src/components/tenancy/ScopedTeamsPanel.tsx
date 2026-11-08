import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { Team } from '../../api/teams'
import { teamsPath } from '../../api/teams'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { useAgreementRead } from '../partnerships/useAgreementRead'

export function ScopedTeamsPanel({ active, disabled, onInvalid }: { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [selected, setSelected] = useState<string | null>(null)
  const root = 'tenants/' + active.context!.organization_id
  const { list, loading, error } = usePartnershipList<{ id: string; label: string }>(root + '/my-team-scopes?per_page=10&page=' + page, active, open, onInvalid)
  const detail = useAgreementRead<Team>(teamsPath(active.context!.organization_id, selected ?? ''), active, open && selected !== null, onInvalid)
  return <section aria-label={t('roles.myTeams')} className="grid min-w-0 gap-3 rounded-xl border border-white/10 p-4">
    <Button disabled={disabled} onClick={() => { setOpen((value) => !value); setSelected(null) }}>{t('roles.myTeams')}</Button>
    {open && <>
      {(loading || detail.loading) && <p role="status">{t('roles.loading')}</p>}
      {(error || detail.error) && <Alert tone="error" title={(error || detail.error)!.message} />}
      {list?.data.length === 0 && <p>{t('roles.noTeams')}</p>}
      <ul>{list?.data.map((team) => <li key={team.id}><Button disabled={disabled || loading} onClick={() => setSelected(team.id)}>{team.label}</Button></li>)}</ul>
      {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={(next) => { setPage(next); setSelected(null) }} />}
      {selected && detail.data?.id === selected && !detail.loading && !detail.error && <article>
        <h3>{detail.data.name}</h3><p>{detail.data.game_name} · {detail.data.season}</p>
        <p>{detail.data.public_description}</p>
      </article>}
    </>}
  </section>
}
