import type { ActiveOrganization } from '../../api/tenancy'
import type { TranslationKey } from '../../i18n/fr'

type Tool = { id: string; label: TranslationKey; allowed: boolean }
type Section = { id: string; label: TranslationKey; tools: Tool[] }

export function organizationSections(context: ActiveOrganization['context']): Section[] {
  const overview: Section = { id: 'apercu', label: 'tenant.sections.overview', tools: [] }
  if (!context) return [overview]

  const { permissions } = context
  const has = (...keys: string[]) => keys.some((key) => permissions.includes(key))
  const sports = has('sports.read')
  const administration = has('administrative.read')
  const partnerships = has('partnerships.read')
  // Limited access is resolved by the existing panels within the organization.
  const sections: Section[] = [
    overview,
    { id: 'equipes', label: 'tenant.sections.teams', tools: [
      { id: 'liste', label: 'tenant.tools.teams', allowed: sports },
      { id: 'jeux', label: 'tenant.tools.games', allowed: sports },
      { id: 'adversaires', label: 'tenant.tools.opponents', allowed: sports },
      { id: 'equipes-autorisees', label: 'roles.myTeams', allowed: true },
    ] },
    { id: 'calendrier', label: 'tenant.sections.calendar', tools: [
      { id: 'activites', label: 'tenant.tools.activities', allowed: has('calendar.read') },
      { id: 'disponibilites', label: 'tenant.tools.availability', allowed: has('availability.self', 'availability.read') },
    ] },
    { id: 'matchs', label: 'tenant.sections.matches', tools: [
      { id: 'matchs', label: 'tenant.tools.matches', allowed: sports },
      { id: 'competitions', label: 'tenant.tools.competitions', allowed: sports && has('sports.competitions.read') },
    ] },
    { id: 'academie', label: 'tenant.sections.academy', tools: [
      { id: 'cohortes', label: 'tenant.tools.cohorts', allowed: has('academy.read') },
      { id: 'parcours', label: 'tenant.tools.learning', allowed: has('academy.self.read') },
    ] },
    { id: 'suivi', label: 'tenant.sections.reporting', tools: [
      { id: 'tableaux', label: 'tenant.tools.dashboards', allowed: permissions.some((key) => key.startsWith('reporting.')) },
      { id: 'statistiques', label: 'tenant.tools.statistics', allowed: sports && has('sports.statistics.read') },
      { id: 'recherche', label: 'tenant.tools.search', allowed: sports || administration },
    ] },
    { id: 'collaboration', label: 'tenant.sections.collaboration', tools: [
      { id: 'taches', label: 'tenant.tools.tasks', allowed: has('tasks.read') },
      { id: 'communications', label: 'tenant.tools.communications', allowed: has('communication.read') },
      { id: 'medias', label: 'tenant.tools.media', allowed: has('media.read') },
    ] },
    { id: 'personnes', label: 'tenant.sections.people', tools: [
      { id: 'fiches', label: 'tenant.sections.people', allowed: sports || administration || has('sports.write', 'administrative.write') },
    ] },
    { id: 'administration', label: 'tenant.sections.administration', tools: [
      { id: 'contrats', label: 'tenant.tools.contracts', allowed: administration },
      { id: 'partenariats', label: 'tenant.tools.partnerships', allowed: partnerships },
      { id: 'campagnes', label: 'tenant.tools.campaigns', allowed: partnerships },
    ] },
    { id: 'acces', label: 'tenant.sections.access', tools: [
      { id: 'membres', label: 'tenant.tools.members', allowed: has('memberships.manage') },
      { id: 'invitations', label: 'tenant.tools.invitations', allowed: has('invitations.manage') },
      { id: 'roles', label: 'tenant.tools.roles', allowed: has('roles.assign') },
      { id: 'affectations', label: has('roles.assign') ? 'tenant.tools.assignments' : 'roles.myAssignments', allowed: true },
      { id: 'acces-limites', label: 'roles.limitedTitle', allowed: true },
      { id: 'journal', label: 'tenant.tools.audit', allowed: has('audit.view') },
    ] },
  ]
  return sections.map((section) => ({
    ...section,
    tools: section.tools.filter((tool) => tool.allowed),
  })).filter((section) => section.id === 'apercu' || section.tools.length > 0)
}
