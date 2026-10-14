import { t, type TranslationKey } from '../i18n/fr'

export const contractTypes = ['player', 'coach', 'manager', 'analyst', 'contributor', 'sponsor'] as const
export const contractStatuses = ['draft', 'in_review', 'approved', 'sent', 'signed', 'active', 'ended', 'terminated', 'archived'] as const
export const validationTargets = ['approved', 'signed', 'active', 'ended', 'terminated']
export type ContractStatus = typeof contractStatuses[number]
export type ContractParty = { id: string; kind: 'person' | 'external'; person_id: string | null; name: string; position: number }
export type ContractObligation = { id: string; title: string; due_on: string | null; position: number; status: 'pending' | 'completed' | 'waived'; version: string }
export type ContractRecord = {
  id: string; reference: string; type: typeof contractTypes[number]; title: string; responsible_person_id: string; responsible_name: string
  starts_on: string | null; ends_on: string | null; status: ContractStatus; version: string; prepared_by: number
  approved_by: number | null; approved_at: string | null; signed_on: string | null; signature_reference: string | null
  signature_recorded_at: string | null; terminated_on: string | null; signature_evidence_status: 'not_declared' | 'declared_external'
  in_force: boolean; sporting_eligibility: 'not_assessed'; publication_status: 'private'; parties_count: number
  obligations_count: number; pending_obligations_count: number; available_transitions: ContractStatus[]
  parties?: ContractParty[]; obligations?: ContractObligation[]
}
export type ContractEvent = { id: string; from_status: ContractStatus | null; to_status: ContractStatus; contract_version: string; actor_user_id: number; occurred_at: string }
export type ContractPerson = { id: string; label: string }
export function contractText(key: string) { return t(('contractRecords.' + key) as TranslationKey) }
export function contractsPath(org: string, id?: string) { return 'tenants/' + org + '/contracts' + (id ? '/' + id : '') }
