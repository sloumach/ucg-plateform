import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { campaignText as text, campaignDate, type SponsorshipDeliverable, type SponsorshipDashboard } from '../../api/sponsorshipCampaigns'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePartnershipList } from './usePartnershipList'

type Props = { active: ActiveOrganization; disabled: boolean; refresh?: number; onInvalid: () => void }
export function SponsorshipDashboardPanel({ active, disabled, refresh = 0, onInvalid }: Props) {
  const [page, setPage] = useState(1), [localRefresh, setLocalRefresh] = useState(0)
  const allowed = active.context?.permissions.includes('partnerships.read') ?? false
  const { list, loading, error } = usePartnershipList<SponsorshipDeliverable, SponsorshipDashboard>('tenants/' + active.context?.organization_id + '/sponsorship-dashboard?per_page=10&page=' + page, active, allowed, onInvalid, refresh + localRefresh)
  if (!allowed) return null
  return <section aria-label={text('dashboard')} className="grid min-w-0 gap-3 rounded-2xl border border-amber-400/30 p-4">
    <h3 className="text-xl font-bold">{text('dashboard')}</h3>
    <p className="text-sm text-slate-400">{text('dashboardHint')}</p>
    <Button disabled={disabled || loading} onClick={() => setLocalRefresh((n) => n + 1)}>{text('refresh')}</Button>
    {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list && <>
      <dl className="grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-4">{(['promised', 'overdue', 'delivered', 'validated'] as const).map((key) => <div key={key} className="min-w-0 rounded-xl border border-slate-700 p-3">
        <dt className="[overflow-wrap:anywhere]">{text('summary.' + key)}</dt><dd className={'text-2xl font-bold ' + (key === 'overdue' ? 'text-rose-200' : 'text-cyan-200')}>{list.summary?.[key] ?? 0}</dd>
      </div>)}</dl>
      <h4 className="font-semibold">{text('overdueList')}</h4>
      {list.data.length === 0 && <p>{text('noOverdue')}</p>}
      <ul className="grid min-w-0 gap-2">{list.data.map((record) => <li key={record.id} className="grid min-w-0 gap-1 rounded-xl border border-rose-400/30 p-3">
        <p className="font-bold [overflow-wrap:anywhere]">{record.title}</p>
        <p className="[overflow-wrap:anywhere]">{record.sponsor_name} · {record.agreement_reference} · {record.campaign_title}</p>
        <p>{text('due_at')} : {campaignDate(record.due_at, active.context!.timezone)}</p>
        <p className="[overflow-wrap:anywhere]">{text('responsible')} : {record.responsible_label ?? text('unavailable')}</p>
      </li>)}</ul>
      <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={disabled || loading} onPageChange={setPage} />
    </>}
  </section>
}
