import { useState, type FormEvent } from 'react'
import { type ActiveOrganization } from '../../api/tenancy'
import { academyPath, type Cohort } from '../../api/academy'
import { t } from '../../i18n/fr'
import { Button } from '../Button'
import { FormField } from '../FormField'
import { Pagination } from '../data/Pagination'
import { Alert } from '../feedback/Alert'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { useAcademyPage } from './useAcademyPage'
import { AcademyCohortDetail } from './AcademyCohortDetail'

type Props = { active: ActiveOrganization; disabled: boolean; onInvalid: () => void }
export function AcademyPanel(props: Props) {
  const { active, disabled, onInvalid } = props
  const org = active.context?.organization_id
  const allowed = active.context?.permissions.includes('academy.read') ?? false
  const manage = active.context?.permissions.includes('academy.manage') ?? false
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [refresh, setRefresh] = useState(0)
  const [selected, setSelected] = useState<Cohort | null>(null), [editor, setEditor] = useState<Cohort | 'new' | null>(null)
  const path = org ? academyPath(org) : ''
  const read = useAcademyPage<Cohort>(active, open && allowed && org ? path : null, page, refresh, onInvalid)
  const mutation = usePeopleMutation<Cohort>(active, () => { setEditor(null); setSelected(null); setRefresh((value) => value + 1) }, onInvalid)
  if (!org || !allowed) return null
  const blocked = disabled || read.loading || mutation.blocked
  function save(event: FormEvent<HTMLFormElement>) {
    const data = new FormData(event.currentTarget), editing = editor !== 'new' ? editor : null
    mutation.propose(event, t('academy.saveCohort'), path + (editing ? '/' + editing.id : ''), { name: String(data.get('name')), ...(editing ? { version: editing.version } : {}) }, !!editing)
  }
  return <section aria-label={t('academy.title')} className="grid min-w-0 gap-4 rounded-xl border border-slate-700 p-4">
    <Button disabled={disabled || mutation.blocked} onClick={() => { setOpen((value) => !value); setEditor(null); setSelected(null) }}>{open ? t('academy.close') : t('academy.title')}</Button>
    {open && <>
      <p className="text-sm text-slate-400">{t('academy.hint')}</p>
      {read.loading && <p role="status">{t('academy.loading')}</p>}
      {read.error && <><Alert tone="error" title={read.error.message} requestId={read.error.requestId} /><Button onClick={() => setRefresh((value) => value + 1)}>{t('academy.retry')}</Button></>}
      {mutation.feedback}
      {manage && <Button disabled={blocked} onClick={() => { setEditor('new'); setSelected(null) }}>{t('academy.createCohort')}</Button>}
      {read.result?.data.length === 0 && <p>{t('academy.empty')}</p>}
      <ul className="grid min-w-0 gap-3 sm:grid-cols-2">{read.result?.data.map((cohort) => <li key={cohort.id} className="grid min-w-0 gap-2 rounded-xl border border-slate-700 p-3 [overflow-wrap:anywhere]">
        <h3 className="font-bold">{cohort.name}</h3><p>{t(cohort.status === 'active' ? 'academy.active' : 'academy.archived')}</p>
        <Button disabled={blocked} onClick={() => { setSelected(cohort); setEditor(null) }}>{t('academy.details')} {cohort.name}</Button>
        {manage && cohort.status === 'active' && <>
          <Button disabled={blocked} onClick={() => { setEditor(cohort); setSelected(null) }}>{t('academy.edit')} {cohort.name}</Button>
          <form onSubmit={(event) => mutation.propose(event, t('academy.archive'), path + '/' + cohort.id, { version: cohort.version, status: 'archived' }, true)}><Button type="submit" disabled={blocked}>{t('academy.archive')} {cohort.name}</Button></form>
        </>}
      </li>)}</ul>
      {read.result && <Pagination currentPage={read.result.meta.current_page} totalPages={read.result.meta.last_page} disabled={blocked} onPageChange={(next) => { setPage(next); setSelected(null); setEditor(null) }} />}
      {editor && <form key={editor === 'new' ? 'new' : editor.id + editor.version} aria-label={t('academy.saveCohort')} className="grid min-w-0 gap-3" onSubmit={save}>
        <FormField label={t('academy.name')} name="name" required maxLength={120} disabled={blocked} defaultValue={editor === 'new' ? '' : editor.name} error={mutation.errors(path + (editor === 'new' ? '' : '/' + editor.id)).name?.[0]} />
        <div className="flex flex-wrap gap-2"><Button type="submit" disabled={blocked}>{t('academy.saveCohort')}</Button><Button disabled={blocked} onClick={() => setEditor(null)}>{t('academy.cancel')}</Button></div>
      </form>}
      {selected && <AcademyCohortDetail key={selected.id + selected.version} {...props} disabled={blocked} cohort={selected} />}
    </>}
  </section>
}
