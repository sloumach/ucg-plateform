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
import { WorkspaceEditor } from '../layout/WorkspaceEditor'

export function ScopedTeamsPanel({ active, disabled, onInvalid }: { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [selected, setSelected] = useState<string | null>(null)
  const root = 'tenants/' + active.context!.organization_id
  const { list, loading, error } = usePartnershipList<{ id: string; label: string }>(root + '/my-team-scopes?per_page=10&page=' + page, active, open, onInvalid)
  const detail = useAgreementRead<Team>(teamsPath(active.context!.organization_id, selected ?? ''), active, open && selected !== null, onInvalid)
  const team = selected && !detail.loading && !detail.error && detail.data?.id === selected ? detail.data : null
  return <section aria-label={t('roles.myTeams')} className="ui-panel grid min-w-0 gap-3 p-4">
    <Button disabled={disabled} aria-expanded={open} onClick={() => { setOpen((value) => !value); setSelected(null) }}>{t('roles.myTeams')}</Button>
    {open && <>
      {(loading || detail.loading) && <p role="status">{t('roles.loading')}</p>}
      {(error || detail.error) && <Alert tone="error" title={(error || detail.error)!.message} />}
      {team ? <WorkspaceEditor title={team.name}>
        <article className="ui-card grid min-w-0 gap-3 p-4">
          <p className="text-sm text-muted">{team.game_name} · {team.season}</p>
          {team.public_description && <p className="whitespace-pre-wrap">{team.public_description}</p>}
          <Button onClick={() => setSelected(null)}>{t('workspace.backToList')}</Button>
        </article>
      </WorkspaceEditor> : <>
        {list?.data.length === 0 && <p className="ui-state">{t('roles.noTeams')}</p>}
        <ul className="grid min-w-0 gap-2">{list?.data.map((entry) => <li key={entry.id} className="ui-card p-3"><Button disabled={disabled || loading} onClick={() => setSelected(entry.id)}>{entry.label}</Button></li>)}</ul>
        {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={(next) => { setPage(next); setSelected(null) }} />}
      </>}
    </>}
  </section>
}
