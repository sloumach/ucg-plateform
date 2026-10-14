import { t, type TranslationKey } from '../i18n/fr'
import { contractsPath } from './contractRecords'

export type ContractDocument = {
  id: string; reference: string; kind: 'original' | 'amendment'; title: string
  original_document_id: string | null; effective_on: string | null; versions_count: number
  created_by: number; created_at: string; publication_status: 'private'
  signature_evidence_status: 'not_declared' | 'declared_external'
  signed_version: { id: string; number: number; sha256: string; signed_on: string; recorded_by: number; recorded_at: string } | null
}
export type ContractDocumentVersion = {
  id: string; number: number; purpose: 'draft' | 'signed_copy'; filename: string; sha256: string; bytes: number
  created_by: number; created_at: string; scan_status: 'pending' | 'queued' | 'processing' | 'clean' | 'rejected' | 'failed' | 'stale'; download_available: boolean; scan_id?: string | null; scan_reason?: string | null; scan_finished_at?: string | null; publication_status: 'private'
}
export const signedContractStates = ['signed', 'active', 'ended', 'terminated']
export function documentsPath(org: string, contract: string, document?: string) {
  return contractsPath(org, contract) + '/documents' + (document ? '/' + document : '')
}
export function documentText(key: string) { return t(('contractDocuments.' + key) as TranslationKey) }

export type ContractDocumentScan = {
  id: string; attempt: number; status: 'queued' | 'processing' | 'clean' | 'rejected' | 'failed'; reason: string | null
  policy: string; engine: string | null; requested_by: number; requested_at: string; started_at: string | null; finished_at: string | null; invalidated_at: string | null
}
