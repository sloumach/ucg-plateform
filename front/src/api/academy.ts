import { t, type TranslationKey } from '../i18n/fr'

export type Cohort = { id: string; name: string; status: 'active' | 'archived'; version: string }
export type WeeklySlot = { day: number; starts_at: string; ends_at: string }
export type AcademyCycle = Cohort & { cohort_id: string; starts_on: string; ends_on: string; timezone: string; timetable: WeeklySlot[] }
export type Enrollment = { id: string; person_id: string; person_label: string | null; cycle_id: string; role: 'student' | 'mentor'; starts_on: string; ends_on: string; status: 'active' | 'withdrawn' | 'completed'; version: string }
export type AcademyRevision = { id: string; kind: 'cohort' | 'cycle' | 'enrollment'; resource_id: string; actor_user_id: number; recorded_at: string }
export const academyPath = (org: string, cohort?: string) => 'tenants/' + org + '/academy/cohorts' + (cohort ? '/' + cohort : '')

export const academyDay = (day: number) => t(('academy.day.' + day) as TranslationKey)
