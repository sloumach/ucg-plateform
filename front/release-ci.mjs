import { mkdirSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const requiredWorkflows = ['backend-quality.yml', 'frontend-quality.yml', 'dependency-security.yml']
const positive = (value) => Number.isSafeInteger(value) && value > 0

function validateScope(repository, revision, branch) {
  if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repository) || !/^[a-f0-9]{40}$/.test(revision)
    || !/^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$/.test(branch)) throw new Error('Invalid CI source scope.')
}

export function selectQualityEvidence(repository, revision, branch, workflows) {
  validateScope(repository, revision, branch)
  const runs = requiredWorkflows.map((workflow) => {
    if (!Array.isArray(workflows[workflow])) throw new Error('Required quality workflow is missing.')
    const matching = workflows[workflow].filter((run) => run.head_sha === revision && run.head_branch === branch && run.event === 'push'
      && run.head_repository?.full_name === repository)
    if (matching.some((run) => !positive(run.id) || !positive(run.run_number) || !positive(run.run_attempt))) throw new Error('Malformed quality workflow run.')
    matching.sort((a, b) => b.run_number - a.run_number || b.run_attempt - a.run_attempt)
    const latest = matching[0]
    if (!latest || latest.status !== 'completed' || latest.conclusion !== 'success') {
      throw new Error('Latest backend, frontend and dependency security push workflows must succeed on this exact commit and branch.')
    }
    return { workflow, id: latest.id, attempt: latest.run_attempt }
  })
  return validateQualityEvidence({ schemaVersion: 1, repository, sourceRevision: revision, branch, runs }, revision)
}

export function validateQualityEvidence(evidence, revision) {
  if (!evidence || evidence.schemaVersion !== 1 || evidence.sourceRevision !== revision || !Array.isArray(evidence.runs)
    || evidence.runs.length !== requiredWorkflows.length) throw new Error('Invalid CI run references.')
  validateScope(evidence.repository, evidence.sourceRevision, evidence.branch)
  const workflows = new Set()
  const ids = new Set()
  const runs = evidence.runs.map((run) => {
    if (!requiredWorkflows.includes(run.workflow) || workflows.has(run.workflow) || ids.has(run.id) || !positive(run.id) || !positive(run.attempt)) {
      throw new Error('Invalid or duplicated CI run reference.')
    }
    workflows.add(run.workflow)
    ids.add(run.id)
    return { workflow: run.workflow, id: run.id, attempt: run.attempt }
  })
  return { schemaVersion: 1, repository: evidence.repository, sourceRevision: revision, branch: evidence.branch, runs }
}

async function collect() {
  if (process.argv.length !== 2 || process.env.GITHUB_ACTIONS !== 'true' || !process.env.GITHUB_TOKEN) throw new Error('Use this collector only inside the release workflow.')
  const repository = process.env.GITHUB_REPOSITORY
  const revision = process.env.GITHUB_SHA
  const branch = process.env.GITHUB_REF_NAME
  validateScope(repository, revision, branch)
  const workflows = {}
  for (const workflow of requiredWorkflows) {
    const url = new URL(`https://api.github.com/repos/${repository}/actions/workflows/${workflow}/runs`)
    url.search = new URLSearchParams({ head_sha: revision, branch, event: 'push', per_page: '100' }).toString()
    const response = await fetch(url, { redirect: 'error', signal: AbortSignal.timeout(30000), headers: {
      Authorization: `Bearer ${process.env.GITHUB_TOKEN}`, Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28',
    } })
    if (!response.ok) throw new Error('Unable to read required quality workflow runs.')
    const body = await response.text()
    if (body.length > 4 * 1024 * 1024) throw new Error('CI evidence response exceeds its limit.')
    workflows[workflow] = JSON.parse(body).workflow_runs
  }
  const evidence = selectQualityEvidence(repository, revision, branch, workflows)
  mkdirSync('front/reports', { recursive: true })
  writeFileSync('front/reports/release-ci.json', JSON.stringify(evidence, null, 2), { flag: 'wx', mode: 0o600 })
  console.log('Backend, frontend and dependency security runs verified for the exact candidate commit.')
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try { await collect() }
  catch { console.error('Release CI qualification refused; no candidate published.'); process.exitCode = 1 }
}
