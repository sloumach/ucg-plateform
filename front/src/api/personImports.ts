import { tenancyRequest, type Page } from './tenancy'
import type { ApiSuccessResponse } from './contracts'

export const importFields = ['pseudonym', 'language', 'timezone', 'status', 'biography_public', 'first_name', 'last_name', 'contact_email', 'contact_phone', 'biography_internal'] as const
export type ImportField = typeof importFields[number]
export type ImportRow = { id: string; line: number; status: string; values: Record<string, string | null>; errors: string[]; person_id: string | null }
export type PersonImport = {
  id: string; version: string; status: 'uploaded' | 'previewed' | 'queued' | 'processing' | 'completed' | 'failed'
  headers: string[]; mapping: Partial<Record<ImportField, number>>; defaults: { language?: string; timezone?: string; status?: string }
  row_count: number; counts: Record<string, number>; failure_code: string | null
  rows: ImportRow[]; pagination: { current_page: number; last_page: number; total: number }
}
export function personImportsPath(org: string, id?: string, suffix?: string) {
  return 'tenants/' + encodeURIComponent(org) + '/people-imports' + (id ? '/' + encodeURIComponent(id) : '') + (suffix ? '/' + suffix : '')
}
export function listPersonImports(org: string, page: number, revision?: string, signal?: AbortSignal) {
  return tenancyRequest<Page<PersonImport>>(personImportsPath(org) + '?page=' + page, { revision, signal })
}
export function getPersonImport(org: string, id: string, page: number, revision?: string, signal?: AbortSignal) {
  return tenancyRequest<ApiSuccessResponse<PersonImport>>(personImportsPath(org, id) + '?page=' + page, { revision, signal })
}
export function downloadPersonImportReport(org: string, id: string, revision?: string, signal?: AbortSignal) {
  return tenancyRequest<Blob>(personImportsPath(org, id, 'report'), { revision, signal, download: true })
}
