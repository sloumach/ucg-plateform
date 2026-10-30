<?php

namespace App\Architecture\Database;

final class TableOwnership
{
    /** @var array<string, string> Closed exceptions; every other table is tenant-owned. */
    public const GLOBAL_TABLES = [
        'retention_guard_definitions' => 'Infrastructure : code SQL de migration et empreintes des gardes, aucune donnée métier.',
        'metric_definitions' => 'Performance : définitions globales, aucune valeur privée tenant.',
        'metric_revisions' => 'Performance : versions et audit des définitions globales.',
        'metric_catalogue_state' => 'Performance : version du dictionnaire commun.',
        'game_catalogue_entries' => 'Teams : références communes sans données métier tenant.',
        'game_catalogue_state' => 'Teams : version du catalogue commun.',
        'game_catalogue_events' => 'Teams : audit append-only du catalogue commun, aucun contenu tenant.',
        'users' => 'Identity : compte de connexion partagé, pas une personne métier.',
        'password_reset_tokens' => 'Identity : récupération du compte global.',
        'identity_security_events' => 'Identity : audit append-only des événements de sécurité du compte global.',
        'identity_audit_events' => 'Identity : consultations et refus globaux minimaux, aucun contenu métier tenant.',
        'platform_roles' => 'Identity : rôles opérateur explicites, aucun accès tenant implicite.',
        'platform_role_events' => 'Identity : journal append-only des attributions plateforme par outillage opérateur.',
        'personal_access_tokens' => 'Identity : authentification Sanctum ; aucun droit tenant implicite.',
        'sessions' => 'Infrastructure : sessions du compte ; sélection tenant non autorisante.',
        'cache' => 'Infrastructure : transport partagé ; clés tenant isolées par TEN-005.',
        'cache_locks' => 'Infrastructure : verrous partagés ; clés tenant isolées par TEN-005.',
        'jobs' => 'Infrastructure : transport partagé ; contexte des jobs contrôlé par TEN-006.',
        'job_batches' => 'Infrastructure : suivi technique des traitements.',
        'failed_jobs' => 'Infrastructure : incidents techniques, accès opérateur uniquement.',
        'operations_alerts' => 'Infrastructure : états techniques sans identité ni contenu tenant, accès serveur.',
        'operations_alert_events' => 'Infrastructure : transitions techniques et références UUID d’incident, accès serveur.',
        'migrations' => 'Infrastructure : historique du schéma.',
        'organizations' => 'Tenancy : racine du tenant, identifiée par id ; visibilité autorisée seulement.',
    ];

    public static function isGlobal(string $table): bool
    {
        return array_key_exists($table, self::GLOBAL_TABLES);
    }
}
