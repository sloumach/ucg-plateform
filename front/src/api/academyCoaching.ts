import { t, type TranslationKey } from '../i18n/fr'
export type AcademyObjective = { id: string; title: string; description: string | null; due_on: string; status: 'active' | 'completed' | 'cancelled'; scope: 'individual' | 'collective'; version?: string; cycle_id?: string; target_enrollment_id?: string | null; responsible_enrollment_id?: string; target_label?: string | null; responsible_label?: string | null }
export type AcademyFeedback = { id: string; content: string; created_at: string; version?: string; status?: 'active' | 'withdrawn'; audience?: 'shared' | 'internal'; justification?: string; author_user_id?: number }
export type CoachingRevision = { id: string; kind: 'objective' | 'feedback'; resource_id: string; actor_user_id: number; recorded_at: string }
export const coachingText = (key: string) => t(('coaching.' + key) as TranslationKey)
export const objectivesPath = (org: string, cohort: string, cycle: string) => 'tenants/' + org + '/academy/cohorts/' + cohort + '/cycles/' + cycle + '/objectives'
