import { ApiClientError } from '../../api/errors'
import { t, type TranslationKey } from '../../i18n/fr'

export const personStatuses = ['candidate', 'trial', 'active', 'unavailable', 'substitute', 'former'] as const
export const personSportsFields = ['pseudonym', 'language', 'timezone', 'status', 'biography_public'] as const
export const personAdminFields = ['user_id', 'first_name', 'last_name', 'contact_email', 'contact_phone', 'biography_internal'] as const
export function label(name: string) { return t(('people.' + name) as TranslationKey) }
export function apiError(error: unknown) { return error instanceof ApiClientError ? error : new ApiClientError(t('people.error')) }
export function shouldReloadContext(error: ApiClientError) {
  return [401, 403, 404].includes(error.status ?? 0) || error.code === 'TENANT_CONTEXT_CHANGED'
}

export function isoDate(value: string) {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toISOString().replace('.000Z', '+00:00')
}
export function localDate(value?: string | null) {
  if (!value) return ''
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 19)
}

// Compare against the rendered baseline, so untouched private and observation fields stay omitted.
export function changedValues(form: HTMLFormElement, before: Record<string, unknown> | null, dateFields: string[] = []) {
  const result: Record<string, unknown> = {}
  for (const [name, entry] of new FormData(form)) {
    const value = String(entry)
    const original = before?.[name] ?? ''
    const baseline = dateFields.includes(name) ? localDate(String(original)) : String(original)
    // datetime-local omits zero seconds in its value, even with step=1.
    const unchanged = dateFields.includes(name) ? isoDate(value) === isoDate(baseline) : value === baseline
    if (!before || !unchanged) result[name] = dateFields.includes(name) ? isoDate(value) : name === 'user_id' ? (value ? Number(value) : null) : (value || null)
  }
  return result
}
