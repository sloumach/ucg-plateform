import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { appendFileSync, readFileSync } from 'node:fs'
import { createCandidate, verifyCandidate } from './release.mjs'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const args = process.argv.slice(2)
try {
  if ((args.length === 2 || (args.length === 3 && args[2] === '--ci-evidence')) && args[0] === 'build' && /^--profile=(local|staging|production)$/.test(args[1])) {
    const profile = args[1].slice('--profile='.length)
    const qualityEvidence = args[2] ? JSON.parse(readFileSync(resolve(root, 'front/reports/release-ci.json'), 'utf8')) : null
    const directory = createCandidate({ root, profile, qualityEvidence })
    if (args[2] && process.env.GITHUB_ACTIONS === 'true' && process.env.GITHUB_OUTPUT) {
      if (/[\r\n]/.test(directory)) throw new Error('Invalid candidate output path.')
      appendFileSync(process.env.GITHUB_OUTPUT, `directory=${directory}\n`)
    }
    console.log(JSON.stringify({ candidate: directory, profile, deploymentAuthorized: false, runtimeDependenciesIncluded: false }))
  } else if (args.length === 4 && args[0] === 'verify') {
    console.log(JSON.stringify(verifyCandidate(args[1], args[2], args[3])))
  } else {
    throw new Error('Use build --profile=local|staging|production, or verify DIRECTORY COMMIT PROFILE.')
  }
} catch (error) {
  console.error(error instanceof SyntaxError ? 'Invalid candidate JSON.' : error.code ? 'Release filesystem/process operation failed.' : error.message)
  process.exitCode = 1
}
