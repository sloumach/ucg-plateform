import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { composerFindings, npmFindings, reviewAudits } from '../../security-audit.mjs'

const now = new Date('2026-10-31T12:00:00.000Z')
const cleanComposer = () => ({ advisories: [], abandoned: [] })
const cleanNpm = () => ({ auditReportVersion: 2, vulnerabilities: {}, metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 0, critical: 0, total: 0 } } })
const register = (findings = []) => ({ version: 1, findings })
function vulnerableNpm(severity = 'high') {
  return { ...cleanNpm(), vulnerabilities: { fixture: { name: 'fixture', severity, range: '<2', via: [{ url: 'https://example.test/advisory' }, 'parent'] } }, metadata: { vulnerabilities: { ...cleanNpm().metadata.vulnerabilities, total: 1, [severity]: 1 } } }
}
function record(changes = {}) { return { id: 'npm:fixture', owner: 'Fixture security maintainer', status: 'open', first_seen_at: '2026-10-20T12:00:00.000Z', ...changes } }

test('clean native reports and an empty register pass without fabricated vulnerabilities', () => {
  const result = reviewAudits(cleanComposer(), cleanNpm(), register(), now)
  assert.equal(result.passed, true)
  assert.deepEqual(result.findings, [])
  assert.deepEqual(result.closure_required, [])
})

test('new advisories block delivery and require an owner and durable first detection date', () => {
  const result = reviewAudits(cleanComposer(), vulnerableNpm(), register(), now)
  assert.equal(result.passed, false)
  assert.equal(result.findings[0].tracking, 'untracked')
  assert.equal(result.findings[0].owner, null)
  assert.equal(result.findings[0].first_seen_at, null)
  assert.equal(result.findings[0].deadlines_provisional, true)
  assert.equal(result.findings[0].assessment_due_at, '2026-11-01T12:00:00.000Z')
  assert.equal(result.findings[0].correction_due_at, '2026-11-07T12:00:00.000Z')
})

test('tracked findings stay blocking and their deadlines never restart on later scans', () => {
  const input = register([record()])
  const first = reviewAudits(cleanComposer(), vulnerableNpm(), input, now)
  const later = reviewAudits(cleanComposer(), vulnerableNpm(), input, new Date('2026-11-01T12:00:00.000Z'))
  assert.equal(first.passed, false)
  assert.equal(first.findings[0].assessment_due_at, later.findings[0].assessment_due_at)
  assert.equal(first.findings[0].correction_due_at, '2026-10-27T12:00:00.000Z')
  assert.equal(first.findings[0].assessment_overdue, true)
  assert.equal(first.findings[0].correction_overdue, true)
  assert.equal(first.findings[0].deadlines_provisional, false)
  assert.deepEqual(input, register([record()]))
})

test('every severity uses its own assessment and correction boundary including Composer medium', () => {
  for (const [severity, assess, fix] of [['critical', 1, 24], ['high', 24, 168], ['moderate', 168, 720], ['low', 720, 2160], ['info', 720, 2160]]) {
    const start = new Date(record().first_seen_at)
    const row = reviewAudits(cleanComposer(), vulnerableNpm(severity), register([record()]), start).findings[0]
    assert.equal(Date.parse(row.assessment_due_at) - start.getTime(), assess * 3600000)
    assert.equal(Date.parse(row.correction_due_at) - start.getTime(), fix * 3600000)
    assert.equal(reviewAudits(cleanComposer(), vulnerableNpm(severity), register([record()]), new Date(row.correction_due_at)).findings[0].correction_overdue, true)
  }
  const composer = { advisories: { 'fixture/package': [{ advisoryId: 'PKSA-fixture', severity: 'medium', link: 'https://example.test/advisory', affectedVersions: '<2' }] }, abandoned: { 'fixture/old': null } }
  assert.deepEqual(composerFindings(composer).map((row) => [row.id, row.severity]), [['composer:fixture/package:PKSA-fixture', 'moderate'], ['composer:fixture/old:abandoned', 'high']])
})

test('assessment evidence stops the assessment alarm but never waives correction', () => {
  const result = reviewAudits(cleanComposer(), vulnerableNpm(), register([record({ assessed_at: '2026-10-21T12:00:00.000Z', assessment: 'Fixture runtime exposure reviewed' })]), now)
  assert.equal(result.findings[0].assessment_overdue, false)
  assert.equal(result.findings[0].correction_overdue, true)
  assert.equal(result.passed, false)
})

test('disappearance requires documented closure and reappearance reopens the finding', () => {
  assert.deepEqual(reviewAudits(cleanComposer(), cleanNpm(), register([record()]), now).closure_required, ['npm:fixture'])
  const closed = register([record({ status: 'resolved', resolved_at: now.toISOString(), evidence: 'Fixture patched lock and passing audit' })])
  assert.equal(reviewAudits(cleanComposer(), cleanNpm(), closed, now).passed, true)
  assert.equal(reviewAudits(cleanComposer(), vulnerableNpm(), closed, now).findings[0].tracking, 'reopened')
})

test('network errors, missing data, ignored advisories, inconsistent totals and unknown severity fail closed', () => {
  for (const input of [{}, { error: 'network' }, { ...cleanComposer(), ignored: { fixture: [{}] } }, { advisories: { fixture: [null] }, abandoned: [] }, { advisories: { fixture: [] }, abandoned: [] }]) {
    assert.throws(() => composerFindings(input))
  }
  for (const input of [{}, { error: 'network' }, { ...vulnerableNpm(), metadata: { vulnerabilities: { total: 0 } } }, { ...cleanNpm(), metadata: { vulnerabilities: { ...cleanNpm().metadata.vulnerabilities, critical: 1 } } }, vulnerableNpm('unknown')]) assert.throws(() => npmFindings(input))
})

test('tracking rejects missing owners, duplicate IDs, unproved closure and invalid or future dates', () => {
  for (const records of [[record({ owner: '' })], [record(), record()], [record({ status: 'resolved' })], [record({ first_seen_at: '2026-02-30T12:00:00.000Z' })], [record({ first_seen_at: '2027-01-01T00:00:00.000Z' })], [record({ assessed_at: '2026-10-21T12:00:00.000Z' })]]) {
    assert.throws(() => reviewAudits(cleanComposer(), cleanNpm(), register(records), now))
  }
})

test('CLI persists red evidence and exits nonzero even when an input report is truncated', () => {
  const directory = mkdtempSync(join(tmpdir(), 'ucg-security-audit-'))
  try {
    const paths = ['composer.json', 'npm.json', 'register.json', 'result.json'].map((name) => join(directory, name))
    writeFileSync(paths[0], JSON.stringify(cleanComposer()))
    writeFileSync(paths[1], '{')
    writeFileSync(paths[2], JSON.stringify(register()))
    const result = spawnSync(process.execPath, [fileURLToPath(new URL('../../security-audit.mjs', import.meta.url)), ...paths], { encoding: 'utf8', windowsHide: true })
    assert.equal(result.status, 2)
    assert.equal(JSON.parse(readFileSync(paths[3], 'utf8')).passed, false)
    writeFileSync(paths[1], JSON.stringify(vulnerableNpm()))
    assert.equal(spawnSync(process.execPath, [fileURLToPath(new URL('../../security-audit.mjs', import.meta.url)), ...paths], { windowsHide: true }).status, 1)
    writeFileSync(paths[1], JSON.stringify(cleanNpm()))
    assert.equal(spawnSync(process.execPath, [fileURLToPath(new URL('../../security-audit.mjs', import.meta.url)), ...paths], { windowsHide: true }).status, 0)
  } finally {
    assert.equal(dirname(directory), resolve(tmpdir()))
    assert.match(basename(directory), /^ucg-security-audit-[A-Za-z0-9]+$/)
    rmSync(directory, { recursive: true, force: true })
  }
})
