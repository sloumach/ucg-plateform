import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const deadlines = { critical: [1, 24], high: [24, 168], moderate: [168, 720], low: [720, 2160] }
function requireValue(condition, message) { if (!condition) throw new Error(message) }
function object(value) { return value !== null && typeof value === 'object' && !Array.isArray(value) }
function nonempty(value) { return typeof value === 'string' && value.trim().length > 0 }
function timestamp(value) {
  requireValue(typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value)
    && Number.isFinite(Date.parse(value)) && new Date(value).toISOString() === value, 'Invalid UTC timestamp')
  return Date.parse(value)
}
function finding(id, ecosystem, name, severity, source, affected) {
  severity = severity === 'medium' ? 'moderate' : severity === 'info' ? 'low' : severity
  requireValue(Object.hasOwn(deadlines, severity), 'Unknown advisory severity')
  requireValue(nonempty(name) && nonempty(id) && nonempty(source) && nonempty(affected), 'Incomplete advisory')
  return { id, ecosystem, package: name, severity, source, affected }
}

export function composerFindings(report) {
  requireValue(object(report) && !report.error && (object(report.advisories) || Array.isArray(report.advisories) && report.advisories.length === 0)
    && (object(report.abandoned) || Array.isArray(report.abandoned) && report.abandoned.length === 0), 'Incomplete Composer audit')
  // Ignored advisories must never turn this independent report green.
  requireValue(report.ignored === undefined || (object(report.ignored) || Array.isArray(report.ignored)) && Object.keys(report.ignored).length === 0, 'Ignored Composer advisories are not accepted')
  const rows = []
  for (const [name, advisories] of Object.entries(report.advisories)) {
    requireValue((object(advisories) || Array.isArray(advisories)) && Object.keys(advisories).length > 0, 'Invalid Composer advisory list')
    for (const advisory of Object.values(advisories)) {
      requireValue(object(advisory) && nonempty(advisory.advisoryId), 'Invalid Composer advisory')
      rows.push(finding(`composer:${name}:${advisory.advisoryId}`, 'composer', name, advisory.severity, advisory.link, advisory.affectedVersions))
    }
  }
  for (const [name, replacement] of Object.entries(report.abandoned)) {
    requireValue(replacement === null || nonempty(replacement), 'Invalid abandoned package')
    rows.push(finding(`composer:${name}:abandoned`, 'composer', name, 'high', 'composer-abandoned', replacement ?? 'no replacement declared'))
  }
  return rows
}

export function npmFindings(report) {
  requireValue(object(report) && report.auditReportVersion === 2 && !report.error && object(report.vulnerabilities)
    && object(report.metadata?.vulnerabilities), 'Incomplete npm audit')
  const rows = Object.entries(report.vulnerabilities).map(([name, advisory]) => {
    requireValue(object(advisory) && advisory.name === name && Array.isArray(advisory.via) && advisory.via.length > 0, 'Invalid npm advisory')
    const sources = advisory.via.map((source) => {
      requireValue(nonempty(source) || object(source) && nonempty(source.url), 'Invalid npm advisory source')
      return typeof source === 'string' ? `dependency:${source}` : source.url
    })
    return finding(`npm:${name}`, 'npm', name, advisory.severity, [...new Set(sources)].sort().join(', '), advisory.range)
  })
  requireValue(report.metadata.vulnerabilities.total === rows.length, 'Inconsistent npm audit totals')
  for (const severity of ['info', 'low', 'moderate', 'high', 'critical']) {
    const count = report.metadata.vulnerabilities[severity]
    requireValue(Number.isSafeInteger(count) && count >= 0
      && count === Object.values(report.vulnerabilities).filter((row) => row.severity === severity).length, 'Inconsistent npm severity totals')
  }
  return rows
}

export function reviewAudits(composer, npm, register, now = new Date()) {
  const instant = timestamp(now.toISOString())
  requireValue(object(register) && register.version === 1 && Array.isArray(register.findings), 'Invalid security register')
  const records = new Map()
  for (const record of register.findings) {
    requireValue(object(record) && nonempty(record.id) && !records.has(record.id) && nonempty(record.owner)
      && ['open', 'resolved'].includes(record.status), 'Invalid or duplicate tracking record')
    const firstSeen = timestamp(record.first_seen_at)
    requireValue(firstSeen <= instant, 'Tracking date is in the future')
    if (record.assessed_at !== undefined) {
      requireValue(timestamp(record.assessed_at) >= firstSeen && timestamp(record.assessed_at) <= instant && nonempty(record.assessment), 'Invalid assessment evidence')
    }
    if (record.status === 'resolved') {
      requireValue(timestamp(record.resolved_at) >= firstSeen && timestamp(record.resolved_at) <= instant && nonempty(record.evidence), 'Invalid resolution evidence')
    }
    records.set(record.id, record)
  }
  const seen = new Set()
  const findings = [...composerFindings(composer), ...npmFindings(npm)].map((row) => {
    requireValue(!seen.has(row.id), 'Duplicate audit finding')
    seen.add(row.id)
    const record = records.get(row.id)
    const firstSeen = record ? timestamp(record.first_seen_at) : instant
    const [assessmentHours, correctionHours] = deadlines[row.severity]
    const assessmentDue = firstSeen + assessmentHours * 3600000
    const correctionDue = firstSeen + correctionHours * 3600000
    return { ...row, owner: record?.owner ?? null, first_seen_at: record?.first_seen_at ?? null,
      tracking: !record ? 'untracked' : record.status === 'resolved' ? 'reopened' : 'open',
      deadlines_provisional: !record, assessment_due_at: new Date(assessmentDue).toISOString(), correction_due_at: new Date(correctionDue).toISOString(),
      assessment_overdue: !record?.assessed_at && instant >= assessmentDue, correction_overdue: instant >= correctionDue }
  })
  const missing = [...records.values()].filter((record) => record.status === 'open' && !seen.has(record.id)).map((record) => record.id)
  return { version: 1, generated_at: now.toISOString(), passed: findings.length === 0 && missing.length === 0,
    findings, closure_required: missing }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const [, , composerPath, npmPath, registerPath, outputPath] = process.argv
  if (!composerPath || !npmPath || !registerPath || !outputPath || process.argv.length !== 6) {
    process.stderr.write('Usage: node security-audit.mjs COMPOSER_JSON NPM_JSON REGISTER_JSON OUTPUT_JSON\n')
    process.exitCode = 2
  } else {
    let result
    try {
      const read = (path) => JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''))
      result = reviewAudits(read(composerPath), read(npmPath), read(registerPath))
      process.exitCode = result.passed ? 0 : 1
    } catch {
      result = { version: 1, generated_at: new Date().toISOString(), passed: false, error: 'Audit or tracking input missing, malformed or inconsistent; inspect native reports.' }
      process.exitCode = 2
    }
    writeFileSync(outputPath, JSON.stringify(result, null, 2) + '\n')
    process.stdout.write(JSON.stringify(result, null, 2) + '\n')
  }
}
