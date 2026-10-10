export const frenchMessages = {
  'app.name': 'Ultra Cyber Game',
  'app.subtitle': 'Plateforme de gestion esports',
  'app.ticket': 'UCG-TEN-003',
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
  'tenant.title': 'Mes organisations',
  'tenant.header': 'Contexte d’organisation',
  'tenant.active': 'Organisation active : :name',
  'tenant.none': 'Aucune organisation sélectionnée.',
  'tenant.select': 'Ouvrir :name',
  'tenant.loading': 'Chargement des organisations…',
  'tenant.error': 'Impossible de charger cet espace.',
  'tenant.empty': 'Vous ne disposez actuellement d’aucune organisation accessible.',
  'tenant.refresh': 'Actualiser les accès',
  'tenant.role.owner': 'Propriétaire',
  'tenant.role.administrator': 'Administrateur',
  'tenant.role.member': 'Membre',
  'tenant.status.active': 'Active',
  'tenant.status.suspended': 'Suspendue',
  'tenant.status.revoked': 'Révoquée',
  'tenant.status.pending': 'En attente',
  'tenant.status.accepted': 'Acceptée',
  'tenant.status.declined': 'Refusée',
  'tenant.status.expired': 'Expirée',
  'tenant.inbox': 'Mes invitations',
  'tenant.noInvitations': 'Aucune invitation.',
  'tenant.accept': 'Accepter',
  'tenant.decline': 'Refuser',
  'tenant.invite': 'Inviter un membre',
  'tenant.email': 'E-mail de la personne invitée',
  'tenant.role': 'Rôle dans cette organisation',
  'tenant.start': 'Début de l’adhésion',
  'tenant.end': 'Fin de l’adhésion (facultative)',
  'tenant.dateHint': 'Dates dans le fuseau de votre navigateur ; début immédiat si vide.',
  'tenant.send': 'Envoyer l’invitation',
  'tenant.members': 'Adhésions',
  'tenant.membershipStatus': 'Statut de l’adhésion',
  'tenant.noMembers': 'Aucune adhésion ; le propriétaire conserve son accès de gouvernance.',
  'tenant.account': 'Compte :id',
  'tenant.update': 'Enregistrer l’adhésion',
  'tenant.manageInvitations': 'Invitations de l’organisation',
  'tenant.revoke': 'Révoquer',
  'tenant.leave': 'Quitter cette organisation',
  'tenant.confirmTitle': 'Confirmer l’action',
  'tenant.confirmMessage': ':action — organisation :name. Vérifiez la cible avant de continuer.',
  'tenant.confirm': 'Confirmer et continuer',
  'tenant.cancel': 'Annuler',
  'tenant.busy': 'Traitement…',
  'tenant.changed': 'Le contexte a changé. Les accès et les données ont été rechargés.',
  'tenant.until': 'Du :start au :end',
  'tenant.noEnd': 'sans date de fin',
  'tenant.expires': 'Invitation valable jusqu’au :date',
  'tenant.done': 'Opération enregistrée.',
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
