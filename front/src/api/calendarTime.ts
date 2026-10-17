/** Resolve a wall clock in its IANA zone; never interpret it in the browser's own zone. */
export function calendarInstants(value: string, timezone: string): string[] {
  const local = value.length === 16 ? value + ':00' : value
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(local)) return []
  const nominal = Date.parse(local + 'Z')
  if (!Number.isFinite(nominal) || new Date(nominal).toISOString().slice(0, 19) !== local) return []
  try {
    const formatter = new Intl.DateTimeFormat('en-GB', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' })
    const wall = (instant: number) => {
      const parts = Object.fromEntries(formatter.formatToParts(new Date(instant)).map((part) => [part.type, part.value]))
      return parts.year.padStart(4, '0') + '-' + parts.month + '-' + parts.day + 'T' + parts.hour + ':' + parts.minute + ':' + parts.second
    }
    // Probe offsets on both sides of nearby transitions, then verify every candidate by round-trip.
    const offsets = new Set([-48,-24,0,24,48].map((hours) => {
      const probe = nominal + hours * 3600000
      return Date.parse(wall(probe) + 'Z') - probe
    }))
    return [...offsets].map((offset) => nominal - offset).filter((instant) => wall(instant) === local).sort((a, b) => a - b)
      .flatMap((instant) => {
        const minutes = (nominal - instant) / 60000
        if (!Number.isInteger(minutes)) return []
        const absolute = Math.abs(minutes), offset = (minutes >= 0 ? '+' : '-') + String(Math.floor(absolute / 60)).padStart(2, '0') + ':' + String(absolute % 60).padStart(2, '0')
        return [local + offset]
      })
  } catch (failure) { if (failure instanceof RangeError) return []; throw failure }
}
export function defaultCalendarWindow(now = new Date()) {
  const start = new Date(now.getTime())
  const from = start.getFullYear() + '-' + String(start.getMonth() + 1).padStart(2, '0') + '-' + String(start.getDate()).padStart(2, '0')
  start.setDate(start.getDate() + 30)
  const to = start.getFullYear() + '-' + String(start.getMonth() + 1).padStart(2, '0') + '-' + String(start.getDate()).padStart(2, '0')
  return { from, to }
}
