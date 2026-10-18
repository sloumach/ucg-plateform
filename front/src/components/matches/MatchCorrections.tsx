import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import { matchText as text, type MatchRecord, type MatchCorrection, type MatchCorrectionPage, type MatchGame, type MatchGamePage, type MatchGameOutcome } from '../../api/matches'
import { calendarDate } from '../../api/calendar'
import { usePartnershipList } from '../partnerships/usePartnershipList'
import { usePeopleMutation } from '../people/usePeopleMutation'
import { SelectField } from '../people/peopleForms'
import { FormField } from '../FormField'
import { Button } from '../Button'
import { Alert } from '../feedback/Alert'
import { Pagination } from '../data/Pagination'

type Props = { active: ActiveOrganization; item: MatchRecord; root: string; management: boolean; disabled: boolean; onInvalid: () => void; onClose: () => void; onSaved: () => void }
export function MatchCorrections({ active, item, root, management, disabled, onInvalid, onClose, onSaved }: Props) {
  const [page, setPage] = useState(1), [refresh, setRefresh] = useState(0), [editing, setEditing] = useState(false)
  const [key] = useState(() => crypto.randomUUID())
  const path = root + '/' + item.id + '/corrections', params = '&per_page=5' + (management ? '&management=1' : '')
  const { list, loading, error } = usePartnershipList<MatchCorrection, MatchCorrectionPage>(path + '?page=' + page + params, active, true, onInvalid, refresh)
  const { list: card, loading: scoresLoading, error: scoresError } = usePartnershipList<MatchGame, MatchGamePage>(root + '/' + item.id + '/games?page=1&per_page=100' + (management ? '&management=1' : ''), active, editing, onInvalid, refresh)
  const mutation = usePeopleMutation<MatchCorrection>(active, onSaved, onInvalid)
  const blocked = disabled || mutation.blocked, state = list?.corrections
  const ready = page === 1 && !loading && !error && !!state?.can_correct && !state.pending
  const editorReady = ready && !scoresLoading && !scoresError && card && !card.scorecard.recalculation_pending && card.scorecard.match_version === state?.match_version && (card.scorecard.score_revision ?? null) === state?.score_revision
  return <section aria-label={text('corrections.title')} className="grid min-w-0 gap-3 rounded-xl border border-amber-400/30 p-3">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('corrections.title')} · {item.title}</h3>
    <p className="text-sm text-slate-400">{text('corrections.hint')}</p>
    <div className="flex flex-wrap gap-3"><Button disabled={blocked} onClick={onClose}>{text('corrections.close')}</Button><Button disabled={blocked || loading} onClick={() => { setEditing(false); setRefresh((n) => n + 1) }}>{text('corrections.refresh')}</Button></div>
    {mutation.feedback}
    {loading && <p role="status">{text('loading')}</p>}{error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {state?.pending && <p role="status" className="text-amber-200">{text('corrections.pending')}</p>}
    {ready && <Button disabled={blocked} onClick={() => setEditing(!editing)}>{text('corrections.create')}</Button>}
    {editing && scoresLoading && <p role="status">{text('loading')}</p>}{editing && scoresError && <Alert tone="error" title={scoresError.message} requestId={scoresError.requestId} />}
    {editing && editorReady && card.data.length > 0 && <form aria-label={text('corrections.save')} className="grid min-w-0 gap-3" onSubmit={(event) => {
      const values = new FormData(event.currentTarget)
      mutation.propose(event, text('corrections.save'), path, { version: state!.match_version, score_revision: state!.score_revision, idempotency_key: key,
        reason: String(values.get('reason') ?? '').trim(), confirm_correction: values.has('confirm_correction'),
        ...(item.visibility !== 'private' ? { confirm_sharing: values.has('confirm_sharing') } : {}),
        games: card.data.filter((game) => values.has('select_' + game.id)).map((game) => ({ game_id: game.id, outcome: String(values.get('outcome_' + game.id)), results: game.results.map((r) => ({ participant_id: r.participant_id,
          score: values.get('outcome_' + game.id) === 'remake' || values.get('score_' + game.id + '_' + r.participant_id) === '' ? null : Number(values.get('score_' + game.id + '_' + r.participant_id)) })) })) })
    }}>
      <p className="text-sm text-amber-200">{text('corrections.editHint')}</p>
      {card.data.filter((game) => game.outcome !== 'remake').map((game) => <CorrectionGame key={game.id} game={game} disabled={blocked} />)}
      {mutation.errors(path).games?.[0] && <Alert tone="error" title={mutation.errors(path).games[0]} />}
      <FormField name="reason" label={text('corrections.reason')} minLength={10} maxLength={1000} required disabled={blocked} error={mutation.errors(path).reason?.[0]} />
      <FormField name="confirm_correction" label={text('corrections.confirm')} type="checkbox" required disabled={blocked} error={mutation.errors(path).confirm_correction?.[0]} />
      {item.visibility !== 'private' && <FormField name="confirm_sharing" label={text('corrections.sharing')} type="checkbox" required disabled={blocked} error={mutation.errors(path).confirm_sharing?.[0]} />}
      <Button type="submit" disabled={blocked}>{text('corrections.save')}</Button>
    </form>}
    {list?.data.length === 0 && <p>{text('corrections.empty')}</p>}
    <ol className="grid min-w-0 gap-3">{list?.data.map((entry) => <li key={entry.id} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3">
      <h4 className="font-bold">#{entry.number} · {text('corrections.state.' + entry.state)}</h4>
      <p className="text-sm">{text('actor')} #{entry.actor_user_id} · {calendarDate(entry.occurred_at, active.context!.timezone)}</p>
      <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{entry.reason}</p>
      {(['before', 'after'] as const).map((side) => <div key={side} className="grid min-w-0 gap-1"><h5 className="font-bold">{text('corrections.' + side)}</h5>{entry[side].map((game) => <div key={game.game_id} className="grid min-w-0 gap-1">
        <p>#{game.number} · {text('scores.outcome.' + game.outcome)}</p><ul>{game.results.map((r) => <li key={r.participant_id} className="[overflow-wrap:anywhere]">{r.label} : {r.score ?? '—'}</li>)}</ul>
      </div>)}</div>)}
      {entry.state === 'pending' && state?.can_correct && <form aria-label={text('corrections.retry')} onSubmit={(event) => mutation.propose(event, text('corrections.retry'), path + '/' + entry.id + '/retries', {})}><Button type="submit" disabled={blocked}>{text('corrections.retry')}</Button></form>}
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={(next) => { setPage(next); setEditing(false) }} />}
  </section>
}
function CorrectionGame({ game, disabled }: { game: MatchGame; disabled: boolean }) {
  const [selected, setSelected] = useState(false), [outcome, setOutcome] = useState<MatchGameOutcome>(game.outcome)
  return <fieldset className="grid min-w-0 gap-3 rounded-xl border border-slate-700 p-3">
    <legend className="[overflow-wrap:anywhere]">#{game.number} · {game.map ?? game.mode}</legend>
    <FormField name={'select_' + game.id} label={text('corrections.select') + ' #' + game.number} type="checkbox" checked={selected} onChange={(e) => setSelected(e.target.checked)} disabled={disabled} />
    {selected && <><SelectField name={'outcome_' + game.id} title={text('scores.outcome') + ' #' + game.number} value={outcome} onChange={(value) => setOutcome(value as MatchGameOutcome)} disabled={disabled}>
      {(['played', 'draw', 'forfeit', 'abandon'] as MatchGameOutcome[]).map((value) => <option key={value} value={value}>{text('scores.outcome.' + value)}</option>)}
    </SelectField>{outcome !== 'remake' && game.results.map((r) => <FormField key={r.participant_id} name={'score_' + game.id + '_' + r.participant_id} label={text('scores.score') + ' · ' + r.label + ' #' + game.number} type="number" defaultValue={r.score ?? ''} required min={0} max={1000000} step={1} disabled={disabled} />)}</>}
  </fieldset>
}
