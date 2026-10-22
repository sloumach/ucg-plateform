import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { coachingText as text, type AcademyObjective } from '../../api/academyCoaching'
import { Button } from '../Button'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { useAcademyPage } from './useAcademyPage'
import { AcademyFeedbackPanel } from './AcademyFeedbackPanel'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function AcademyPersonalPanel({ active, disabled, onInvalid }: Props) {
  const org = active.context?.organization_id, allowed = active.context?.permissions.includes('academy.self.read') ?? false
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [selectedId, setSelectedId] = useState<string | null>(null)
  const base = 'tenants/' + org + '/academy/my-objectives'
  const read = useAcademyPage<AcademyObjective>(active, open && org && allowed ? base : null, page, refresh, onInvalid)
  const selected = read.result?.data.find((item) => item.id === selectedId)
  if (!org || !allowed) return null
  return <section aria-label={text('personal')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <Button disabled={disabled} onClick={() => { setOpen((value) => !value); setSelectedId(null) }}>{open ? text('closePersonal') : text('personal')}</Button>
    {open && <><p>{text('personalHint')}</p>{read.loading && <p role="status">{text('loading')}</p>}{read.error && <><Alert tone="error" title={read.error.message} requestId={read.error.requestId} /><Button onClick={() => setRefresh((value) => value + 1)}>{text('retry')}</Button></>}
      {read.result?.data.length === 0 && <p>{text('empty')}</p>}
      <ul className="grid min-w-0 gap-3">{read.result?.data.map((item) => <li key={item.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3 [overflow-wrap:anywhere]">
        <h4 className="font-bold">{item.title}</h4><p>{text(item.scope)} · {text(item.status)} · {text('deadline')} : {item.due_on}</p>{item.description && <p className="whitespace-pre-wrap">{item.description}</p>}
        <Button disabled={disabled || read.loading} onClick={() => setSelectedId(item.id)}>{text('feedback')} {item.title}</Button>
      </li>)}</ul>
      {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={disabled || read.loading} onPageChange={(next) => { setPage(next); setSelectedId(null) }} />}
      {selected && <AcademyFeedbackPanel key={selected.id} active={active} base={base + '/' + selected.id + '/feedback'} title={selected.title} mode="self" writable={false} disabled={disabled} onInvalid={onInvalid} />}
    </>}
  </section>
}
