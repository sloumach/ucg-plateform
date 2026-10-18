import type { Page } from './tenancy'
import { t, type TranslationKey } from '../i18n/fr'
export const matchFormats = ['bo1', 'bo3', 'bo5', 'lobby', 'free_for_all'] as const
export const matchStatuses = ['proposed', 'confirmed', 'in_progress', 'awaiting_validation', 'validated', 'disputed', 'cancelled', 'forfeited'] as const
export type MatchStatus = typeof matchStatuses[number]
export type MatchParticipantKind = 'person' | 'team' | 'opponent_snapshot'
export type MatchSelection = { kind: MatchParticipantKind; source_id: string; label: string }
export type MatchParticipant = MatchSelection & { id: string; reference_version: string; slot: number }
export type MatchRecord = { id: string; title: string; kind: 'scrim' | 'official'; format: typeof matchFormats[number]; status: MatchStatus;
  game_id: string; game_key: string; game_name: string; version: string; visibility: 'private' | 'team' | 'organization';
  starts_at: string; ends_at: string; starts_at_origin: string; ends_at_origin: string; origin_timezone: string;
  responsible_person_id: string; responsible_label: string; participants: MatchParticipant[]; editable: boolean; available_transitions: MatchStatus[] }
export type MatchChoice = { id: string; label: string }
export type MatchStatusEvent = { id: string; from_status: MatchStatus | null; to_status: MatchStatus; reason: string | null; match_version: string; occurred_at: string }
export type MatchPage = Page<MatchRecord>
export type MatchLineupEntry = { person_id: string; pseudonym: string; slot_key: 'starter' | 'substitute'; position: number }
export type MatchLineupRevision = { id: string; participant_id: string; match_version: string; number: number; action: 'assign' | 'substitute'; phase: MatchStatus;
  reason: string; actor_user_id: number; occurred_at: string;
  snapshot: { id: string; label: string; team_name: string; ruleset_id: string | null; ruleset_version: string | null; eligibility_report_id: string | null; participants: MatchLineupEntry[] };
  assessment: { status: 'eligible' | 'unassessed'; effective_at: string; evaluated_at: string } }
export function matchText(key: string) { return t(('matches.' + key) as TranslationKey) }
