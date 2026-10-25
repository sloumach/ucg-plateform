export const smokeProfile = Object.freeze({ name: 'smoke', samples: 100, warmup: 5, exports: 3, targetP95Ms: 500 })

export function loadProfile(arguments_) {
  if (arguments_.length > 1 || arguments_.some((value) => value !== '--profile=smoke')) {
    throw new Error('Only --profile=smoke is supported; external URLs, databases and custom volumes are refused.')
  }
  return smokeProfile
}

export function percentile(values, fraction) {
  if (!values.length || values.some((value) => !Number.isFinite(value) || value < 0)
    || !Number.isFinite(fraction) || fraction <= 0 || fraction > 1) {
    throw new Error('A percentile requires finite non-negative samples and a fraction in (0, 1].')
  }
  const sorted = [...values].sort((left, right) => left - right)
  return sorted[Math.ceil(sorted.length * fraction) - 1]
}

export function summarize(samples) {
  if (!samples.length) throw new Error('An empty measurement phase cannot pass.')
  const successful = samples.filter((sample) => sample.ok)
  const timings = samples.map((sample) => sample.ms)
  return {
    requests: samples.length, successful: successful.length, errors: samples.length - successful.length,
    p50Ms: percentile(timings, 0.5), p95Ms: percentile(timings, 0.95), maxMs: Math.max(...timings),
    statusCounts: Object.fromEntries([...new Set(samples.map((sample) => String(sample.status)))].map((status) => [status, samples.filter((sample) => String(sample.status) === status).length])),
    queryCounts: Object.fromEntries([25, 100].map((size) => {
      const counts = successful.filter((sample) => sample.pageSize === size).map((sample) => sample.queries)
      const rows = successful.filter((sample) => sample.pageSize === size)
      return [size, counts.length ? { samples: counts.length, min: Math.min(...counts), max: Math.max(...counts), p95Ms: percentile(rows.map((sample) => sample.ms), 0.95) } : null]
    })),
    diagnostics: Object.fromEntries(['sqlMs', 'bootstrapMs', 'handlerMs'].map((key) => {
      const values = successful.map((sample) => sample[key]).filter(Number.isFinite)
      return [key, values.length ? { samples: values.length, p50: percentile(values, 0.5), p95: percentile(values, 0.95) } : null]
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
