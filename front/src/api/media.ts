import { t, type TranslationKey } from '../i18n/fr'
export const mediaKinds = ['stream', 'vod', 'clip'] as const
export const mediaStatuses = ['scheduled', 'live', 'ended', 'unavailable'] as const
export const mediaVisibilities = ['private', 'organization', 'public'] as const
export type MediaItem = {
  id: string; team_id: string; team_label: string | null; title: string; kind: typeof mediaKinds[number]; provider: 'twitch' | 'youtube'
  url: string; status: typeof mediaStatuses[number]; status_source: 'manual'; status_observed_at: string
  is_scrim: boolean; visibility: typeof mediaVisibilities[number]; effective_visibility: typeof mediaVisibilities[number]
  publish_at: string | null; archived_at: string | null; version: string
}
export function mediaText(key: string) { return t(('media.' + key) as TranslationKey) }
export function safeMediaUrl(input: string): string | null {
  try {
    const url = new URL(input)
    return url.protocol === 'https:' && !url.username && !url.password && !url.port && !url.hash
      && ['www.youtube.com', 'www.twitch.tv', 'clips.twitch.tv'].includes(url.hostname) ? url.href : null
  } catch { return null }
}
