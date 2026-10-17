import { describe, expect, it } from 'vitest'
import { calendarInstants, defaultCalendarWindow } from './calendarTime'
import { safeCalendarUrl } from './calendar'
describe('heures du calendrier', () => {
  it('résout les heures dans le fuseau choisi et non celui du navigateur', () => {
    expect(calendarInstants('2026-07-01T10:00', 'Europe/Paris')).toEqual(['2026-07-01T10:00:00+02:00'])
    expect(calendarInstants('2026-11-01T10:00', 'Europe/Paris')).toEqual(['2026-11-01T10:00:00+01:00'])
    expect(calendarInstants('2026-10-18T10:00', 'Asia/Kolkata')).toEqual(['2026-10-18T10:00:00+05:30'])
  })
  it('refuse les heures inexistantes et laisse les deux occurrences d’une heure répétée', () => {
    expect(calendarInstants('2026-03-29T02:30', 'Europe/Paris')).toEqual([])
    expect(calendarInstants('2026-10-25T02:30', 'Europe/Paris')).toEqual(['2026-10-25T02:30:00+02:00','2026-10-25T02:30:00+01:00'])
    expect(calendarInstants('2011-12-30T10:00', 'Pacific/Apia')).toEqual([])
  })
  it('gère le décalage de trente minutes et les passages de date', () => {
    expect(calendarInstants('2026-04-05T01:45', 'Australia/Lord_Howe')).toEqual(['2026-04-05T01:45:00+11:00','2026-04-05T01:45:00+10:30'])
    expect(calendarInstants('2026-01-01T00:15', 'Pacific/Kiritimati')).toEqual(['2026-01-01T00:15:00+14:00'])
  })
  it('rejette fuseaux et dates invalides sans normalisation silencieuse', () => {
    expect(calendarInstants('2026-02-30T10:00', 'UTC')).toEqual([])
    expect(calendarInstants('2026-01-01T10:00', 'Invalid/Zone')).toEqual([])
    expect(calendarInstants('', 'UTC')).toEqual([])
  })
  it('propose trente jours sans confondre fin de mois et durée fixe en heures', () => {
    expect(defaultCalendarWindow(new Date(2026, 9, 17, 12))).toEqual({ from: '2026-10-17', to: '2026-11-16' })
  })
  it('empêche un lien actif ou authentifié injecté dans les données et conserve HTTPS', () => {
    expect(safeCalendarUrl('javascript:alert(1)')).toBeNull()
    expect(safeCalendarUrl('https://u:p@example.test/')).toBeNull()
    expect(safeCalendarUrl('https://example.test:8443/')).toBeNull()
    expect(safeCalendarUrl('https://meeting.example.test/room?token=abc')).toBe('https://meeting.example.test/room?token=abc')
  })
})
