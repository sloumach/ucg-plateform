import { useState } from 'react'
import type { ActiveOrganization } from '../../api/tenancy'
import type { MatchGame, MatchGameOutcome, MatchGamePage, MatchRecord, MatchScorecard } from '../../api/matches'
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
export function MatchScores({ active, item, root, management, disabled, onInvalid, onSaved, onClose }: Props) {
  const [page, setPage] = useState(1), [outcome, setOutcome] = useState<MatchGameOutcome>('played')
  const path = root + '/' + item.id + '/games', rulesPath = root + '/' + item.id + '/score-rules'
  const { list, loading, error } = usePartnershipList<MatchGame, MatchGamePage>(path + '?page=' + page + '&per_page=10' + (management ? '&management=1' : ''), active, true, onInvalid)
  const mutation = usePeopleMutation<MatchScorecard>(active, onSaved, onInvalid)
  const blocked = disabled || mutation.blocked, scorecard = list?.scorecard, rules = scorecard?.rules
  const shared = item.visibility !== 'private', permissions = active.context!.permissions
  const writable = permissions.includes('sports.write') && (!shared || permissions.includes('sports.publish')) && ['in_progress', 'awaiting_validation'].includes(item.status)
  const editable = writable && page === 1 && !loading && !error && !!scorecard
  const version = scorecard?.match_version
  const scoreErrors = mutation.errors(path), ruleErrors = mutation.errors(rulesPath)
  return <section aria-label={text('scores.title')} className="grid min-w-0 gap-3 rounded-xl border border-cyan-400/30 p-3">
    <h3 className="font-bold [overflow-wrap:anywhere]">{text('scores.title')} · {item.title}</h3>
    <p className="text-sm text-amber-200">{text('scores.hint')}</p>
    <Button disabled={blocked} onClick={onClose}>{text('scores.close')}</Button>
    {mutation.feedback}
    {loading && <p role="status">{text('loading')}</p>}
    {error && <Alert tone="error" title={error.message} requestId={error.requestId} />}
    {scorecard && !rules && <p>{text('scores.noRules')}</p>}
    {rules && <p>{text('scores.rules')} : {text('format.' + rules.format)} · {rules.game_limit} · {text(rules.allow_draw ? 'scores.drawAllowed' : 'scores.drawDenied')}</p>}
    {scorecard?.series && <div className="grid min-w-0 gap-2" aria-label={text('scores.summary')}>
      <p>{text(scorecard.series.complete ? 'scores.complete' : 'scores.pending')} · {scorecard.series.played} {text('scores.counted')}</p>
      {scorecard.series.draw && <p>{text('scores.seriesDraw')}</p>}
      <ol>{scorecard.series.standings.map((entry) => <li key={entry.participant_id} className="[overflow-wrap:anywhere]">{item.participants.find((p) => p.id === entry.participant_id)?.label} : {entry.score}{scorecard.series!.winner_ids.includes(entry.participant_id) && !scorecard.series!.draw ? ' · ' + text('scores.winner') : ''}</li>)}</ol>
    </div>}
    {editable && !rules && <form aria-label={text('scores.configure')} className="grid min-w-0 gap-3" onSubmit={(event) => {
      const values = new FormData(event.currentTarget)
      mutation.propose(event, text('scores.configure'), rulesPath, { version, game_limit: Number(values.get('game_limit')), allow_draw: values.has('allow_draw'), reason: String(values.get('reason') ?? '').trim(), ...(shared ? { confirm_sharing: values.has('confirm_sharing') } : {}) })
    }}>
      <p className="text-sm text-amber-200">{text('scores.rulesHint')}</p>
      <FormField name="game_limit" label={text('scores.gameLimit')} type="number" min={1} max={100} step={1} required disabled={blocked}
        defaultValue={item.format === 'bo1' ? 1 : item.format === 'bo3' ? 3 : item.format === 'bo5' ? 5 : 1} error={ruleErrors.game_limit?.[0]} />
      <FormField name="allow_draw" label={text('scores.allowDraw')} type="checkbox" disabled={blocked} error={ruleErrors.allow_draw?.[0]} />
      <FormField name="reason" label={text('scores.reason')} minLength={10} maxLength={1000} required disabled={blocked} error={ruleErrors.reason?.[0]} />
      {shared && <FormField name="confirm_sharing" label={text('scores.sharing')} type="checkbox" required disabled={blocked} error={ruleErrors.confirm_sharing?.[0]} />}
      <Button type="submit" disabled={blocked}>{text('scores.configure')}</Button>
    </form>}
    {editable && rules && !scorecard?.series?.complete && (list?.meta.total ?? 0) < 100 && <form aria-label={text('scores.add')} className="grid min-w-0 gap-3" onSubmit={(event) => {
      const values = new FormData(event.currentTarget)
      mutation.propose(event, text('scores.add'), path, { version, outcome, map: String(values.get('map') ?? '').trim() || null, mode: String(values.get('mode') ?? '').trim() || null,
        game_patch: String(values.get('game_patch') ?? '').trim(), reason: String(values.get('reason') ?? '').trim(),
        results: item.participants.map((p) => ({ participant_id: p.id, score: outcome === 'remake' ? null : values.get('score_' + p.id) === '' ? null : Number(values.get('score_' + p.id)) })),
        ...(shared ? { confirm_sharing: values.has('confirm_sharing') } : {}) })
    }}>
      <h4 className="font-bold">{text('scores.add')}</h4>
      <SelectField name="outcome" title={text('scores.outcome')} value={outcome} required disabled={blocked} onChange={(value) => setOutcome(value as MatchGameOutcome)} error={scoreErrors.outcome?.[0]}>
        {(['played', ...(rules.allow_draw ? ['draw'] : []), 'forfeit', 'abandon', 'remake'] as MatchGameOutcome[]).map((value) => <option key={value} value={value}>{text('scores.outcome.' + value)}</option>)}
      </SelectField>
      <FormField name="map" label={text('scores.map')} maxLength={160} disabled={blocked} error={scoreErrors.map?.[0]} />
      <FormField name="mode" label={text('scores.mode')} maxLength={160} disabled={blocked} error={scoreErrors.mode?.[0]} />
      <FormField name="game_patch" label={text('scores.patch')} maxLength={80} required disabled={blocked} error={scoreErrors.game_patch?.[0]} />
      {outcome === 'remake' ? <p>{text('scores.remakeHint')}</p> : <>
        <p className="text-sm text-slate-400">{text('scores.pointsHint')}</p>
        {item.participants.map((p, index) => <FormField key={p.id} name={'score_' + p.id} label={text('scores.score') + ' · ' + p.label} type="number" min={0} max={1000000} step={1} required disabled={blocked} error={scoreErrors['results.' + index + '.score']?.[0]} />)}
      </>}
      {scoreErrors.results?.[0] && <Alert tone="error" title={scoreErrors.results[0]} />}
      <FormField name="reason" label={text('scores.reason')} minLength={10} maxLength={1000} required disabled={blocked} error={scoreErrors.reason?.[0]} />
      {shared && <FormField name="confirm_sharing" label={text('scores.sharing')} type="checkbox" required disabled={blocked} error={scoreErrors.confirm_sharing?.[0]} />}
      <Button type="submit" disabled={blocked}>{text('scores.save')}</Button>
    </form>}
    {list?.data.length === 0 && <p>{text('scores.empty')}</p>}
    <ol className="grid min-w-0 gap-3">{list?.data.map((game) => <li key={game.id} className="grid min-w-0 gap-2 rounded-lg border border-slate-700 p-3">
      <h4 className="font-bold">#{game.number} · {text('scores.outcome.' + game.outcome)}</h4>
      <p className="[overflow-wrap:anywhere]">{game.map} · {game.mode} · {game.game_patch}</p>
      <p className="text-sm">{calendarDate(game.occurred_at, active.context!.timezone)} · {text('actor')} #{game.actor_user_id}</p>
      <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{game.reason}</p>
      <ul>{game.results.map((r) => <li key={r.participant_id} className="[overflow-wrap:anywhere]">{r.label} : {r.score ?? '—'}</li>)}</ul>
    </li>)}</ol>
    {list && <Pagination currentPage={list.meta.current_page} totalPages={list.meta.last_page} disabled={blocked || loading} onPageChange={setPage} />}
  </section>
}
