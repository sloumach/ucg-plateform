import { t, type TranslationKey } from '../i18n/fr'
import type { Page } from './tenancy'
export const availabilityStatuses = ['available', 'unavailable', 'uncertain'] as const
export type Availability = {
  id: string; person_id: string; person_label: string | null; status: typeof availabilityStatuses[number]; comment?: string | null
  starts_at: string; ends_at: string; origin_timezone: string; starts_at_origin: string; ends_at_origin: string
  cancelled_at: string | null; version: string; number: number; can_edit: boolean; can_cancel: boolean; can_history: boolean
}
export type AvailabilityRevision = Pick<Availability, 'id' | 'status' | 'comment' | 'starts_at' | 'ends_at' | 'origin_timezone' | 'cancelled_at' | 'version' | 'number'> & {
  action: 'created' | 'updated' | 'cancelled'; created_at: string
}
export type AvailabilityPage = Page<Availability> & { availability: {
  viewer_person_id: string | null; viewer_timezone: string; display_timezone: string; from_utc: string; to_utc: string; can_self: boolean; can_manage: boolean
} }
export function availabilityText(key: string) { return t(('availability.' + key) as TranslationKey) }
