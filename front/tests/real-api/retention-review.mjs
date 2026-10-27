const organizations = ['fixture-alpha', 'fixture-beta']
const currentStatuses = ['active', 'unavailable', 'substitute']
const closedStatuses = ['candidate', 'trial', 'former']
const maximumFixtureDays = 36500
const utcDayMilliseconds = 86400000

function shape(value, keys) {
  if (value === null || typeof value !== 'object' || Array.isArray(value)
    || Object.keys(value).length !== keys.length || keys.some((key) => !Object.hasOwn(value, key))) {
    throw new Error('Only the closed fictional retention schema is supported.')
  }
}

function date(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    throw new Error('An explicit valid UTC calendar date is required.')
  }
  const milliseconds = Date.parse(`${value}T00:00:00.000Z`)
  if (!Number.isFinite(milliseconds) || new Date(milliseconds).toISOString().slice(0, 10) !== value) {
    throw new Error('An explicit valid UTC calendar date is required.')
  }
  return milliseconds
}

// Pure fixture laboratory: no database, files, application configuration or deletion adapter.
export function reviewRetentionFixture(organizationId, record, policy, asOf) {
  shape(record, ['id', 'organizationId', 'status', 'closedOn', 'currentUseNeeded', 'openObligation', 'ageGroup', 'sharedAccount', 'copiesReviewed'])
  shape(policy, ['organizationId', 'state', 'reference', 'days', 'fixtureOnly'])
  if (!organizations.includes(organizationId) || record.organizationId !== organizationId || policy.organizationId !== organizationId
    || typeof record.id !== 'string' || !/^fixture-[a-z0-9-]{1,64}$/.test(record.id)
    || ![...currentStatuses, ...closedStatuses].includes(record.status)
    || !['adult', 'minor', 'unknown'].includes(record.ageGroup)
    || [record.currentUseNeeded, record.openObligation, record.sharedAccount, record.copiesReviewed].some((value) => typeof value !== 'boolean')
    || policy.fixtureOnly !== true || !['missing', 'draft', 'reviewed_fixture'].includes(policy.state)
    || (policy.reference !== null && (typeof policy.reference !== 'string' || !/^fixture-policy-[a-z0-9-]{1,64}$/.test(policy.reference)))
    || (policy.days !== null && (!Number.isInteger(policy.days) || policy.days < 1 || policy.days > maximumFixtureDays))) {
    throw new Error('Only matching fictional organizations, records and policies are supported.')
  }
  const now = date(asOf)
  const closedAt = record.closedOn === null ? null : date(record.closedOn)
  if (closedAt !== null && closedAt > now) throw new Error('A future closure is not a completed event.')

  const reasons = []
  if (policy.state !== 'reviewed_fixture' || policy.reference === null) reasons.push('policy_review_required')
  if (policy.days === null) reasons.push('duration_missing')
  if (closedStatuses.includes(record.status) && closedAt === null) reasons.push('closure_event_missing')
  if (currentStatuses.includes(record.status) && closedAt !== null) reasons.push('closure_conflicts_with_status')
  if (currentStatuses.includes(record.status) && !record.currentUseNeeded) reasons.push('current_need_review_required')
  if (closedStatuses.includes(record.status) && record.currentUseNeeded) reasons.push('remaining_use_review_required')
  if (record.openObligation) reasons.push('obligation_scope_review_required')
  if (record.ageGroup !== 'adult') reasons.push('age_or_guardian_review_required')
  if (record.sharedAccount) reasons.push('global_account_protected')
  if (!record.copiesReviewed) reasons.push('copies_review_required')

  let outcome = 'review_required'
  let reviewOn = null
  if (reasons.length === 0) {
    if (currentStatuses.includes(record.status)) {
      outcome = 'keep_for_current_use'
      reasons.push('current_use_to_reassess')
    } else {
      // UTC day arithmetic is deliberately limited to fictional, day-based policies.
      reviewOn = new Date(closedAt + policy.days * utcDayMilliseconds).toISOString().slice(0, 10)
      outcome = date(reviewOn) <= now ? 'review_due' : 'keep_until_review_date'
      reasons.push(outcome === 'review_due' ? 'deadline_reached_for_review' : 'deadline_not_reached')
    }
  }
  return { id: record.id, organizationId, policyReference: policy.reference, fixtureDurationDays: policy.days, outcome, reasons, reviewOn,
    deletionAuthorized: false, protectedResources: ['global_accounts', 'other_organizations'] }
}
