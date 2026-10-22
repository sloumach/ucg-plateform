import { t, type TranslationKey } from '../i18n/fr'
export type SessionActivity = { id: string; version: string; label: string; starts_at: string; ends_at: string; timezone: string }
export type SessionExercise = { title: string; instructions: string | null; minutes: number }
export type AcademySession = { id: string; cycle_id: string; title: string; exercises: SessionExercise[]; report: string | null; status: 'planned' | 'completed' | 'cancelled'; version: string; responsible_enrollment_id: string; responsible_label: string | null; schedule_state: 'current' | 'changed' | 'unavailable'; activity: SessionActivity | null; starts_at: string | null; ends_at: string | null; attendance_count: number }
export type AcademyAttendance = { id: string; enrollment_id: string; person_label: string | null; status: 'present' | 'absent' | 'excused' | 'late'; late_minutes: number | null; version: string }
export type SessionRevision = { id: string; kind: 'session' | 'attendance'; resource_id: string; actor_user_id: number; recorded_at: string }
export const sessionText = (key: string) => t(('sessions.' + key) as TranslationKey)
export const sessionsPath = (org: string, cohort: string, cycle: string) => 'tenants/' + org + '/academy/cohorts/' + cohort + '/cycles/' + cycle + '/sessions'
export function sessionWindow(start: string, end: string) {
  const from = new Date(start + 'T00:00:00Z'), until = new Date(end + 'T00:00:00Z')
  until.setUTCDate(until.getUTCDate() + 1)
  const bound = new Date(from); bound.setUTCDate(bound.getUTCDate() + 31)
  return { from_date: start, to_date: (until < bound ? until : bound).toISOString().slice(0, 10) }
}
