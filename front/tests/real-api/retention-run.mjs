import { reviewRetentionFixture } from './retention-review.mjs'

if (process.argv.length !== 2) {
  throw new Error('This simulation accepts no arguments, URLs, files or databases.')
}

const asOf = '2026-10-27'
const base = { id: 'fixture-active', organizationId: 'fixture-alpha', status: 'active', closedOn: null,
  currentUseNeeded: true, openObligation: false, ageGroup: 'adult', sharedAccount: false, copiesReviewed: true }
const policy = { organizationId: 'fixture-alpha', state: 'reviewed_fixture', reference: 'fixture-policy-example', days: 30, fixtureOnly: true }
const scenarios = [
  { record: base, policy },
  { record: { ...base, id: 'fixture-missing-policy', status: 'former', closedOn: '2026-09-01', currentUseNeeded: false },
    policy: { ...policy, state: 'missing', reference: null, days: null } },
  { record: { ...base, id: 'fixture-candidate', status: 'candidate', currentUseNeeded: false }, policy },
  { record: { ...base, id: 'fixture-unavailable', status: 'unavailable' }, policy },
  { record: { ...base, id: 'fixture-former-no-event', status: 'former', currentUseNeeded: false }, policy },
  { record: { ...base, id: 'fixture-departure', status: 'former', closedOn: '2026-09-01', currentUseNeeded: false }, policy },
  { record: { ...base, id: 'fixture-open-obligation', status: 'former', closedOn: '2026-09-01', currentUseNeeded: false, openObligation: true }, policy },
  { record: { ...base, id: 'fixture-minor', ageGroup: 'minor' }, policy },
  { record: { ...base, id: 'fixture-shared-account', sharedAccount: true }, policy },
  { record: { ...base, id: 'fixture-copies', copiesReviewed: false }, policy },
  { record: { ...base, id: 'fixture-beta', organizationId: 'fixture-beta' }, policy: { ...policy, organizationId: 'fixture-beta' } },
]

const results = scenarios.map(({ record, policy }) => reviewRetentionFixture(record.organizationId, record, policy, asOf))
console.log(JSON.stringify({ schemaVersion: 1, ticket: 'UCG-OPS-007', mode: 'fictional_simulation', asOf,
  scope: 'person_record_examples_only', applicationPolicyLoaded: false,
  fixtureDaysAreNotUcgPolicy: true, deletionSupported: false,
  counts: Object.fromEntries([...new Set(results.map((row) => row.outcome))].map((outcome) => [outcome, results.filter((row) => row.outcome === outcome).length])),
  results }, null, 2))
