import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { reviewRetentionFixture } from './retention-review.mjs'

function record(values = {}) {
  return { id: 'fixture-person', organizationId: 'fixture-alpha', status: 'former', closedOn: '2026-09-01',
    currentUseNeeded: false, openObligation: false, ageGroup: 'adult', sharedAccount: false, copiesReviewed: true, ...values }
}
function policy(values = {}) {
  return { organizationId: 'fixture-alpha', state: 'reviewed_fixture', reference: 'fixture-policy-example', days: 30, fixtureOnly: true, ...values }
}
function review(value = record(), rules = policy(), asOf = '2026-10-27') {
  return reviewRetentionFixture('fixture-alpha', value, rules, asOf)
}

test('a missing or draft rule requests review without inventing a deadline', () => {
  const result = review(record(), policy({ state: 'missing', reference: null, days: null }))
  assert.equal(result.outcome, 'review_required')
  assert.deepEqual(result.reasons, ['policy_review_required', 'duration_missing'])
  assert.equal(result.reviewOn, null)
  assert.equal(review(record(), policy({ state: 'draft' })).outcome, 'review_required')
  assert.equal(review(record(), policy({ reference: null })).outcome, 'review_required')
})

test('candidate, trial and former statuses alone never start a retention clock', () => {
  for (const status of ['candidate', 'trial', 'former']) {
    const result = review(record({ status, closedOn: null }))
    assert.deepEqual(result.reasons, ['closure_event_missing'])
    assert.equal(result.reviewOn, null)
  }
})

test('current members, unavailable members and substitutes remain current uses to reassess', () => {
  for (const status of ['active', 'unavailable', 'substitute']) {
    const result = review(record({ status, closedOn: null, currentUseNeeded: true }))
    assert.equal(result.outcome, 'keep_for_current_use')
    assert.equal(result.reviewOn, null)
    assert.equal(result.deletionAuthorized, false)
  }
  assert.deepEqual(review(record({ status: 'active', currentUseNeeded: true })).reasons, ['closure_conflicts_with_status'])
})

test('a current status alone does not justify retention and a remaining use needs review after departure', () => {
  assert.deepEqual(review(record({ status: 'active', closedOn: null })).reasons, ['current_need_review_required'])
  assert.deepEqual(review(record({ currentUseNeeded: true })).reasons, ['remaining_use_review_required'])
})

test('a fictional deadline is explicit, UTC-based and never grants deletion', () => {
  const input = record()
  const rules = policy()
  const before = structuredClone({ input, rules })
  assert.equal(review(input, rules, '2026-09-30').outcome, 'keep_until_review_date')
  const result = review(input, rules, '2026-10-01')
  assert.equal(result.outcome, 'review_due')
  assert.equal(result.reviewOn, '2026-10-01')
  assert.equal(result.deletionAuthorized, false)
  assert.deepEqual({ input, rules }, before)
  assert.equal(review(record({ closedOn: '2024-02-28' }), policy({ days: 1 }), '2024-02-29').reviewOn, '2024-02-29')
})

test('open obligations, age uncertainty, shared accounts and copies each require scoped review', () => {
  const cases = [
    [{ openObligation: true }, 'obligation_scope_review_required'],
    [{ ageGroup: 'minor' }, 'age_or_guardian_review_required'],
    [{ ageGroup: 'unknown' }, 'age_or_guardian_review_required'],
    [{ sharedAccount: true }, 'global_account_protected'],
    [{ copiesReviewed: false }, 'copies_review_required'],
  ]
  for (const [changes, reason] of cases) {
    const result = review(record(changes))
    assert.equal(result.outcome, 'review_required')
    assert.deepEqual(result.reasons, [reason])
    assert.equal(result.reviewOn, null)
    assert.deepEqual(result.protectedResources, ['global_accounts', 'other_organizations'])
  }
  assert.deepEqual(review(record({ sharedAccount: true, copiesReviewed: false })).reasons,
    ['global_account_protected', 'copies_review_required'])
})

test('foreign organizations, production-like policies and private extra fields are refused', () => {
  assert.throws(() => review(null), /closed fictional/)
  assert.throws(() => review([], policy()), /closed fictional/)
  assert.throws(() => review(record(), { ...policy(), execute: true }), /closed fictional/)
  assert.throws(() => review(record({ organizationId: 'fixture-beta' })), /matching fictional/)
  assert.throws(() => review(record(), policy({ organizationId: 'fixture-beta' })), /matching fictional/)
  assert.throws(() => reviewRetentionFixture('real-tenant', record(), policy(), '2026-10-27'), /matching fictional/)
  for (const change of [{ fixtureOnly: false }, { state: 'approved' }, { reference: 'real-policy' }, { days: 0 }, { days: -1 }, { days: 1.5 }, { days: 36501 }]) {
    assert.throws(() => review(record(), policy(change)), /matching fictional/)
  }
  for (const change of [{ first_name: 'private' }, { updated_at: '2026-01-01' }, { ageGroup: 'unreviewed' }, { copiesReviewed: 'true' }, { id: 'real-person' }]) {
    assert.throws(() => review(record(change)))
  }
})

test('invalid calendar dates and future closure events are refused without using the current clock', () => {
  for (const value of ['2026-02-30', '2026-2-01', 'not-a-date', '', null, '2026-10-27T00:00:00Z']) {
    assert.throws(() => review(record(), policy(), value), /calendar date/)
  }
  assert.throws(() => review(record({ closedOn: '2026-02-30' })), /calendar date/)
  assert.throws(() => review(record({ closedOn: '2026-10-28' })), /future closure/)
})

test('the fixed simulation prints fictional decisions and refuses any external input', () => {
  const script = fileURLToPath(new URL('./retention-run.mjs', import.meta.url))
  const result = spawnSync(process.execPath, [script], { encoding: 'utf8', windowsHide: true })
  assert.equal(result.status, 0)
  const report = JSON.parse(result.stdout)
  assert.deepEqual(report.counts, { keep_for_current_use: 3, review_required: 7, review_due: 1 })
  assert.equal(report.applicationPolicyLoaded, false)
  assert.equal(report.fixtureDaysAreNotUcgPolicy, true)
  assert.equal(report.deletionSupported, false)
  assert.equal(report.results.find((row) => row.id === 'fixture-departure').fixtureDurationDays, 30)
  assert.equal(report.results.every((row) => row.deletionAuthorized === false), true)
  for (const argument of ['--execute', '--database=existing', '--policy=real.json', 'https://example.com']) {
    const refused = spawnSync(process.execPath, [script, argument], { encoding: 'utf8', windowsHide: true })
    assert.notEqual(refused.status, 0)
    assert.equal(refused.stdout, '')
    assert.match(refused.stderr, /accepts no arguments/)
  }
})
