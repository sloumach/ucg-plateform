import { useState } from 'react'
import type { ActiveOrganization, Page } from '../../api/tenancy'
import type { MatchChoice, MatchLineupRevision, MatchParticipant, MatchRecord } from '../../api/matches'
import { matchText as text } from '../../api/matches'
import { calendarDate } from '../../api/calendar'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SelectField } from '../people/peopleForms'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'

type Props = { active: ActiveOrganization; item: MatchRecord; participant: MatchParticipant; root: string; management: boolean;
  disabled: boolean; onInvalid: () => void; onSaved: () => void; onClose: () => void }

export function MatchLineups({ active, item, participant, root, management, disabled, onInvalid, onSaved, onClose }: Props) {
  const [page, setPage] = useState(1), [choicePage, setChoicePage] = useState(1), [assign, setAssign] = useState(false), [choice, setChoice] = useState<MatchChoice | null>(null)
  const path = root + '/' + item.id + '/participants/' + participant.id + '/lineups'
  const { list, loading, error } = usePartnershipList<MatchLineupRevision, Page<MatchLineupRevision>>(path + '?page=' + page + '&per_page=10' + (management ? '&management=1' : ''), active, true, onInvalid)
  const { list: choices, loading: choosing, error: choiceError } = usePartnershipList<MatchChoice, Page<MatchChoice>>(path + '/choices?page=' + choicePage + '&per_page=10', active, assign, onInvalid)
  const mutation = usePeopleMutation<MatchLineupRevision>(active, onSaved, onInvalid)
  const blocked = disabled || mutation.blocked, canWrite = active.context!.permissions.includes('sports.write')
  const editable = canWrite && ['proposed', 'confirmed', 'in_progress'].includes(item.status)
  const current = page === 1 ? list?.data[0] : undefined
  const entries = current?.snapshot.participants ?? []
  const zone = active.context!.timezone
  return <section aria-label={text('lineups')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('lineups')} · {participant.label}</h3>
    <p className="text-sm text-slate-400">{text('lineupHint')}</p>
    <Button disabled={blocked} onClick={onClose}>{text('closeLineups')}</Button>
    {mutation.feedback}
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {list?.data.length === 0 && <p>{text('noLineup')}</p>}
    {current && <p className="text-sm text-amber-200">{text('eligibility.' + current.assessment.status)} · {text('assessedAt')} : {calendarDate(current.assessment.effective_at, zone)}</p>}
    {editable && page === 1 && !loading && !error && <>
      {item.status !== 'in_progress' && <Button disabled={blocked || (item.visibility !== 'private' && !active.context!.permissions.includes('sports.publish'))} onClick={() => setAssign(!assign)}>{text('assignLineup')}</Button>}
      {item.visibility !== 'private' && !active.context!.permissions.includes('sports.publish') && <p className="text-sm text-amber-200">{text('lineupSharingPermission')}</p>}
      {assign && <form aria-label={text('assignLineup')} className="grid min-w-0 gap-3" onSubmit={(event) => {
        const form = new FormData(event.currentTarget)
        mutation.propose(event, text('assignLineup') + ' : ' + (choice?.label ?? ''), path, { version: item.version, lineup_id: choice?.id ?? '', reason: String(form.get('reason') ?? '').trim(), ...(item.visibility !== 'private' ? { confirm_sharing: form.has('confirm_sharing') } : {}) })
      }}>
        <SelectField name="lineup_id" title={text('finalizedLineup')} value={choice?.id ?? ''} required disabled={blocked || choosing} error={mutation.errors(path).lineup_id?.[0]}
          onChange={(id) => setChoice(choices?.data.find((row) => row.id === id) ?? null)}>
          <option value="">{text('select')}</option>
          {choice && !choices?.data.some((row) => row.id === choice.id) && <option value={choice.id}>{choice.label}</option>}
          {choices?.data.map((row) => <option key={row.id} value={row.id}>{row.label}</option>)}
        </SelectField>
        {choosing && <p role="status">{text('loadingChoices')}</p>}
        {choiceError && <Alert tone="error" title={choiceError.message} requestId={choiceError.requestId} />}
        {choices?.data.length === 0 && <p>{text('noFinalizedLineup')}</p>}
        {choices && <Pagination currentPage={choices.meta.current_page} totalPages={choices.meta.last_page} disabled={blocked || choosing} onPageChange={setChoicePage} />}
        <FormField name="reason" label={text('lineupReason')} minLength={10} maxLength={1000} required disabled={blocked} error={mutation.errors(path).reason?.[0]} />
        {item.visibility !== 'private' && <FormField name="confirm_sharing" label={text('lineupSharing')} type="checkbox" required disabled={blocked} error={mutation.errors(path).confirm_sharing?.[0]} />}
        <Button type="submit" disabled={blocked || !choice}>{text('saveLineup')}</Button>
      </form>}
      {current && entries.some((entry) => entry.slot_key === 'substitute') && <form aria-label={text('substituteLineup')} className="grid min-w-0 gap-3" onSubmit={(event) => {
        const form = new FormData(event.currentTarget)
        mutation.propose(event, text('substituteLineup'), path + '/substitutions', { version: item.version, out_person_id: String(form.get('out_person_id') ?? ''), in_person_id: String(form.get('in_person_id') ?? ''), reason: String(form.get('reason') ?? '').trim() })
      }}>
        <h4 className="font-bold">{text('substituteLineup')}</h4>
        <SelectField name="out_person_id" title={text('outPerson')} required disabled={blocked} error={mutation.errors(path + '/substitutions').out_person_id?.[0]}>
          {entries.filter((entry) => entry.slot_key === 'starter').map((entry) => <option key={entry.person_id} value={entry.person_id}>{entry.pseudonym}</option>)}
        </SelectField>
        <SelectField name="in_person_id" title={text('inPerson')} required disabled={blocked} error={mutation.errors(path + '/substitutions').in_person_id?.[0]}>
          {entries.filter((entry) => entry.slot_key === 'substitute').map((entry) => <option key={entry.person_id} value={entry.person_id}>{entry.pseudonym}</option>)}
        </SelectField>
        <FormField name="reason" label={text('lineupReason')} minLength={10} maxLength={1000} required disabled={blocked} error={mutation.errors(path + '/substitutions').reason?.[0]} />
        <Button type="submit" disabled={blocked}>{text('saveSubstitution')}</Button>
      </form>}
    </>}
    <ol className="grid min-w-0 gap-3">{list?.data.map((revision) => <li key={revision.id} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3">
      <h4 className="font-bold [overflow-wrap:anywhere]">#{revision.number} · {text('lineupAction.' + revision.action)} · {revision.snapshot.label}</h4>
      <p className="text-sm">{text('status.' + revision.phase)} · {calendarDate(revision.occurred_at, zone)} · {text('actor')} #{revision.actor_user_id}</p>
      <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{revision.reason}</p>
      <p className="text-sm text-amber-200">{text('eligibility.' + revision.assessment.status)}</p>
      <ul className="grid min-w-0 gap-1">{revision.snapshot.participants.map((entry) => <li key={entry.person_id} className="[overflow-wrap:anywhere]">{text('lineupSlot.' + entry.slot_key)} · {entry.pseudonym}</li>)}</ul>
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(next) => { setPage(next); setAssign(false) }} />}
  </section>
}
