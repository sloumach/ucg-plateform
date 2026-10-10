export const frenchMessages = {
  'app.name': 'Ultra Cyber Game',
  'app.subtitle': 'Plateforme de gestion esports',
  'app.ticket': 'UCG-ARC-006',
  'foundation.eyebrow': 'Fondation technique',
  'foundation.title': 'Le socle UCG est prêt pour les premiers parcours métier.',
  'foundation.description':
    'React et Laravel communiquent par une API versionnée, sécurisée par Sanctum. Cette page valide le premier parcours authentifié de la plateforme.',
  'foundation.technologiesLabel': 'Technologies installées',
  'system.loading': 'Vérification de l’API…',
  'system.operational': ':name :version opérationnelle',
  'system.unavailableTitle': 'API indisponible',
  'system.unavailableMessage':
    'Le statut de la plateforme ne peut pas être vérifié pour le moment.',
  'auth.loading': 'Vérification de la session…',
  'auth.active': 'Session active',
  'auth.welcome': 'Bienvenue, :name',
  'auth.sessionDescription':
    'Le parcours SPA → Sanctum → session Laravel est opérationnel.',
  'auth.secureArea': 'Espace sécurisé',
  'auth.loginTitle': 'Connexion',
  'auth.loginDescription':
    'Utilisez un compte créé dans la base locale pour valider le parcours.',
  'auth.emailLabel': 'Adresse e-mail',
  'auth.passwordLabel': 'Mot de passe',
  'auth.submit': 'Se connecter',
  'auth.submitting': 'Connexion…',
  'auth.logout': 'Se déconnecter',
  'auth.loggingOut': 'Déconnexion…',
  'auth.loginSucceeded': 'Connexion réussie.',
  'auth.logoutSucceeded': 'Déconnexion effectuée.',
  'auth.loginUnavailable': 'Le service de connexion est indisponible.',
  'auth.logoutFailed': 'La déconnexion a échoué.',
  'auth.sessionUnavailableTitle': 'Session non vérifiée',
  'auth.sessionUnavailableMessage':
    'Impossible de vérifier la session. Vous pouvez réessayer de vous connecter.',
  'errors.requestReference': 'Référence de support : :requestId',
  'notifications.regionLabel': 'Notifications',
  'notifications.close': 'Fermer la notification',
  'pagination.label': 'Pagination',
  'pagination.previous': 'Page précédente',
  'pagination.next': 'Page suivante',
  'pagination.page': 'Page :page',
  'pagination.currentPage': 'Page :page, page actuelle',
  'common.retry': 'Réessayer',
} as const

export type TranslationKey = keyof typeof frenchMessages

type TranslationValues = Record<string, string | number>

export function translate(
  key: TranslationKey,
  values: TranslationValues = {},
): string {
  let message: string = frenchMessages[key]

  for (const [placeholder, value] of Object.entries(values)) {
    message = message.replaceAll(`:${placeholder}`, String(value))
  }

  return message
}

export const t = translate
