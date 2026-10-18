import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchRecord, MatchResult, MatchResultEvent, MatchResultPage, MatchResultOutcome } from '../../api/matches'
import { matchText as text } from '../../api/matches'
import { calendarDate } from '../../api/calendar'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SelectField } from '../people/peopleForms'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'

type Props = { active: ActiveOrganization; item: MatchRecord; root: string; management: boolean; disabled: boolean;
  onInvalid: () => void; onSaved: () => void; onClose: () => void }
export function MatchResults({ active, item, root, management, disabled, onInvalid, onSaved, onClose }: Props) {
  const [page, setPage] = useState(1), [outcome, setOutcome] = useState<MatchResultOutcome>(item.status === 'confirmed' ? 'forfeit' : 'played')
  const path = root + '/' + item.id + '/results'
  const { list, loading, error } = usePartnershipList<MatchResultEvent, MatchResultPage>(path + '?page=' + page + '&per_page=10' + (management ? '&management=1' : ''), active, true, onInvalid)
  const mutation = usePeopleMutation<MatchResult>(active, onSaved, onInvalid)
  const blocked = disabled || mutation.blocked, result = list?.result, shared = item.visibility !== 'private'
  const actions = page === 1 && !loading && !error ? result?.allowed_actions ?? [] : []
  const errors = mutation.errors(path)
  function common(values: FormData) {
    return { version: result?.match_version, reason: String(values.get('reason') ?? '').trim(), ...(shared ? { confirm_sharing: values.has('confirm_sharing') } : {}) }
  }
  return <section aria-label={text('results.title')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('results.title')} · {item.title}</h3>
    <p className="text-sm text-slate-400">{text('results.hint')}</p>
    <Button disabled={blocked} onClick={onClose}>{text('results.close')}</Button>
    {mutation.feedback}
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {result && <p>{text('status.' + result.status)}</p>}
    {result?.submission && <section aria-label={text('results.current')} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3">
      <h4 className="font-bold">{text('results.current')} · {text('results.outcome.' + result.submission.outcome)}</h4>
      <p>{text('results.proposer')} #{result.submission.actor_user_id} · {calendarDate(result.submission.occurred_at, active.context!.timezone)}</p>
      <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{result.submission.proof_reference}</p>
      <ul>{result.submission.snapshot?.participants.map((p) => <li key={p.participant_id} className="[overflow-wrap:anywhere]">{p.label}
        {result.submission!.outcome === 'played' ? ' : ' + (result.submission!.snapshot?.series?.standings.find((r) => r.participant_id === p.participant_id)?.score ?? '—') : ''}
        {result.submission!.snapshot?.winner_ids.includes(p.participant_id) ? ' · ' + text('results.selectedWinner') : ''}</li>)}</ul>
      {result.current?.action === 'submit' && !actions.includes('approve') && <p className="text-sm text-amber-200">{text('results.independentHint')}</p>}
    </section>}
    {actions.includes('submit') && <form aria-label={text('results.submit')} className="grid min-w-0 gap-3" onSubmit={(event) => {
      const values = new FormData(event.currentTarget)
      mutation.propose(event, text('results.submit'), path, { ...common(values), outcome, proof_reference: String(values.get('proof_reference') ?? '').trim(), confirm_proof: values.has('confirm_proof'),
        ...(outcome === 'played' ? {} : { winner_participant_id: String(values.get('winner_participant_id') ?? '') }) })
    }}>
      <SelectField name="outcome" title={text('results.outcome')} value={outcome} onChange={(value) => setOutcome(value as MatchResultOutcome)} required disabled={blocked} error={errors.outcome?.[0]}>
        {(['played', 'forfeit', 'abandon'] as MatchResultOutcome[]).map((value) => <option key={value} value={value} disabled={value === 'played' && result?.status === 'confirmed'}>{text('results.outcome.' + value)}</option>)}
      </SelectField>
      <p className="text-sm text-amber-200">{text(outcome === 'played' ? 'results.playedHint' : 'results.awardedHint')}</p>
      {outcome !== 'played' && <SelectField name="winner_participant_id" title={text('results.winner')} required disabled={blocked} error={errors.winner_participant_id?.[0]}>
        <option value="">{text('select')}</option>{item.participants.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
      </SelectField>}
      <FormField name="proof_reference" label={text('results.proof')} minLength={10} maxLength={2000} required disabled={blocked} error={errors.proof_reference?.[0]} hint={text('results.proofHint')} />
      <FormField name="confirm_proof" label={text('results.confirmProof')} type="checkbox" required disabled={blocked} error={errors.confirm_proof?.[0]} />
      <FormField name="reason" label={text('results.reason')} minLength={10} maxLength={1000} required disabled={blocked} error={errors.reason?.[0]} />
      {shared && <FormField name="confirm_sharing" label={text('results.sharing')} type="checkbox" required disabled={blocked} error={errors.confirm_sharing?.[0]} />}
      <Button type="submit" disabled={blocked}>{text('results.submit')}</Button>
    </form>}
    {(['approve', 'dispute'] as const).filter((action) => actions.includes(action)).map((action) => <form key={action} aria-label={text('results.' + action)} className="grid min-w-0 gap-3 rounded-lg border border-slate-700 p-3" onSubmit={(event) => {
      const values = new FormData(event.currentTarget)
      mutation.propose(event, text('results.' + action), path + (action === 'approve' ? '/approvals' : '/disputes'), { ...common(values), submission_id: result?.submission?.id,
        ...(action === 'approve' ? { confirm_evidence: values.has('confirm_evidence') } : {}) })
    }}>
      <h4 className="font-bold">{text('results.' + action)}</h4>
      {action === 'approve' && <FormField name="confirm_evidence" label={text('results.confirmEvidence')} type="checkbox" required disabled={blocked} error={mutation.errors(path + '/approvals').confirm_evidence?.[0]} />}
      <FormField name="reason" label={text('results.reason')} minLength={10} maxLength={1000} required disabled={blocked} error={mutation.errors(path + (action === 'approve' ? '/approvals' : '/disputes')).reason?.[0]} />
      {shared && <FormField name="confirm_sharing" label={text('results.sharing')} type="checkbox" required disabled={blocked} />}
      <Button type="submit" disabled={blocked}>{text('results.' + action)}</Button>
    </form>)}
    {list?.data.length === 0 && <p>{text('results.empty')}</p>}
    <ol className="grid min-w-0 gap-3">{list?.data.map((event) => <li key={event.id} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3">
      <h4 className="font-bold">#{event.number} · {text('results.action.' + event.action)}</h4>
      <p>{text('status.' + event.from_status)} → {text('status.' + event.to_status)}</p>
      <p className="text-sm">{text('actor')} #{event.actor_user_id} · {calendarDate(event.occurred_at, active.context!.timezone)}</p>
      <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{event.reason}</p>
      {event.proof_reference && <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{text('results.proof')} : {event.proof_reference}</p>}
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={setPage} />}
  </section>
}
