export const smokeProfile = Object.freeze({ name: 'smoke', samples: 100, warmup: 5, exports: 3, targetP95Ms: 500 })
const profiles = Object.freeze({
  smoke: smokeProfile,
  profile: Object.freeze({ ...smokeProfile, name: 'profile' }),
  volume: Object.freeze({ ...smokeProfile, name: 'volume', samples: 3, httpProcesses: 4, readers: 99, accounts: 500, games: 100000, timeoutMs: 900000 }),
  pressure: Object.freeze({ ...smokeProfile, name: 'pressure', samples: 3, httpProcesses: 4, readers: 99, accounts: 500, games: 100000, imports: 3, importRows: 1000, timeoutMs: 900000 }),
  endurance: Object.freeze({ ...smokeProfile, name: 'endurance', samples: 15, intervalMs: 12000, rampMs: 50, noiseIntervalMs: 1000, readers: 99, accounts: 500, games: 100000, imports: 3, importRows: 1000, timeoutMs: 900000 }),
})

export function loadProfile(arguments_) {
  const name = arguments_.length === 0 ? 'smoke' : arguments_[0]?.replace(/^--profile=/, '')
  if (arguments_.length > 1 || (arguments_.length && !arguments_[0].startsWith('--profile=')) || !Object.hasOwn(profiles, name)) {
    throw new Error('Only fixed smoke, profile, volume, pressure and endurance profiles are supported; external URLs, databases and custom volumes are refused.')
  }
  return profiles[name]
}

export function serverConcurrency(samples) {
  const events = []
  for (const sample of samples.filter((value) => value.ok)) {
    if (!Number.isInteger(sample.workerPid) || sample.workerPid <= 0 || !Number.isFinite(sample.appStarted) || !Number.isFinite(sample.appEnded)
      || sample.appStarted <= 0 || sample.appEnded < sample.appStarted) throw new Error('Missing server concurrency instrumentation')
    events.push({ at: sample.appStarted, delta: 1 }, { at: sample.appEnded, delta: -1 })
  }
  events.sort((left, right) => left.at - right.at || left.delta - right.delta)
  let active = 0
  let peak = 0
  for (const event of events) { active += event.delta; peak = Math.max(peak, active) }
  return { peakMeasuredHandlers: peak, workerPids: [...new Set(samples.filter((value) => value.ok).map((value) => value.workerPid))].sort((a, b) => a - b) }
}

export function heavyWorkReads(samples, windows) {
  if (windows.some((value) => !Number.isFinite(value.started) || !Number.isFinite(value.ended) || value.started <= 0 || value.ended < value.started)) {
    throw new Error('Invalid measured heavy job window')
  }
  return samples.filter((sample) => sample.ok && windows.some((window) => sample.appStarted < window.ended && sample.appEnded > window.started))
}

export function percentile(values, fraction) {
  if (!values.length || values.some((value) => !Number.isFinite(value) || value < 0)
    || !Number.isFinite(fraction) || fraction <= 0 || fraction > 1) {
    throw new Error('A percentile requires finite non-negative samples and a fraction in (0, 1].')
  }
  const sorted = [...values].sort((left, right) => left - right)
  return sorted[Math.ceil(sorted.length * fraction) - 1]
}

export function backoffMilliseconds(value) {
  if (typeof value !== 'string' || !/^\d+$/.test(value) || Number(value) < 1 || Number(value) > 60) {
    throw new Error('Load backpressure must have a bounded Retry-After between 1 and 60 seconds.')
  }
  return Number(value) * 1000
}

export function measurementResource(profile, tenant, iteration) {
  if (!profile.games) return 'people'
  const resources = profile.imports && tenant === 'alpha' ? ['matches', 'games'] : ['people', 'matches', 'games']
  return resources[iteration % resources.length]
}

export function readTimingHeaders(headers) {
  return Object.fromEntries(['sql', 'bootstrap', 'handler', 'connection', 'firstQuery'].map((name) => {
    const header = `x-ucg-${name === 'sql' ? 'query' : name === 'firstQuery' ? 'first-query' : name}-ms`
    const raw = headers[header]
    if (typeof raw !== 'string' || raw.trim() === '' || !Number.isFinite(Number(raw)) || Number(raw) < 0) {
      throw new Error('Missing or invalid load timing instrumentation.')
    }
    return [`${name}Ms`, Number(raw)]
  }))
}

export function summarize(samples) {
  if (!samples.length) throw new Error('An empty measurement phase cannot pass.')
  const successful = samples.filter((sample) => sample.ok)
  const timings = samples.map((sample) => sample.ms)
  return {
    requests: samples.length, successful: successful.length, errors: samples.length - successful.length,
    p50Ms: percentile(timings, 0.5), p95Ms: percentile(timings, 0.95), maxMs: Math.max(...timings),
    statusCounts: Object.fromEntries([...new Set(samples.map((sample) => String(sample.status)))].map((status) => [status, samples.filter((sample) => String(sample.status) === status).length])),
    rateRefusals: Object.fromEntries(['quota', 'contention', 'unclassified'].map((reason) => [reason,
      samples.filter((sample) => sample.status === 429 && (['quota', 'contention'].includes(sample.rateRefusal) ? sample.rateRefusal : 'unclassified') === reason).length])),
    queryCounts: Object.fromEntries([25, 100].map((size) => {
      const counts = successful.filter((sample) => sample.pageSize === size).map((sample) => sample.queries)
      const rows = successful.filter((sample) => sample.pageSize === size)
      return [size, counts.length ? { samples: counts.length, min: Math.min(...counts), max: Math.max(...counts), p95Ms: percentile(rows.map((sample) => sample.ms), 0.95) } : null]
    })),
    diagnostics: Object.fromEntries(['sqlMs', 'connectionMs', 'firstQueryMs', 'bootstrapMs', 'handlerMs', 'outsideApplicationMs'].map((key) => {
      // This residual includes server admission, fixture guards, transport and client work.
      // SQL is already inside handlerMs; it must not be subtracted a second time.
      const values = successful.map((sample) => key === 'outsideApplicationMs'
        ? [sample.ms, sample.bootstrapMs, sample.handlerMs].every(Number.isFinite)
          ? Math.max(0, sample.ms - sample.bootstrapMs - sample.handlerMs) : null
        : sample[key]).filter(Number.isFinite)
      return [key, values.length ? { samples: values.length, p50: percentile(values, 0.5), p95: percentile(values, 0.95) } : null]
    })),
    servers: Object.fromEntries([...new Set(samples.map((sample) => sample.server).filter(Number.isInteger))].map((server) => {
      const rows = samples.filter((sample) => sample.server === server)
      const outstanding = rows.map((sample) => sample.outstandingOnServer).filter(Number.isInteger)
      return [server, {
        requests: rows.length, errors: rows.filter((sample) => !sample.ok).length,
        p95Ms: percentile(rows.map((sample) => sample.ms), 0.95),
        peakOutstandingAtDispatch: outstanding.length ? Math.max(...outstanding) : null,
      }]
    })),
    resources: Object.fromEntries([...new Set(samples.map((sample) => `${sample.resource ?? 'people'}:${sample.pageSize}`))].map((key) => {
      const rows = samples.filter((sample) => `${sample.resource ?? 'people'}:${sample.pageSize}` === key)
      const counts = rows.filter((sample) => sample.ok).map((sample) => sample.queries)
      return [key, { requests: rows.length, errors: rows.filter((sample) => !sample.ok).length, p95Ms: percentile(rows.map((sample) => sample.ms), 0.95), maxQueries: counts.length ? Math.max(...counts) : null }]
    })),
  }
}

export function validatePeople(payload, expectedIds, pageSize) {
  if (!Array.isArray(payload?.data) || payload.data.length !== Math.min(pageSize, expectedIds.size)
    || payload.meta?.total !== expectedIds.size
    || new Set(payload.data.map((row) => row.id)).size !== payload.data.length
    || payload.data.some((row) => !expectedIds.has(row.id))) {
    throw new Error('People response is incomplete, unpaginated or contains a foreign record.')
  }
}

export function assertLocalOrigin(origin) {
  const parsed = new URL(origin)
  if (parsed.protocol !== 'http:' || parsed.hostname !== '127.0.0.1' || !parsed.port
    || parsed.username || parsed.password || parsed.pathname !== '/' || parsed.search || parsed.hash) {
    throw new Error('Only a generated loopback HTTP origin is permitted.')
  }
  return parsed.origin
}
