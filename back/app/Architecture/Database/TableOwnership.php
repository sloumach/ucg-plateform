<?php

namespace App\Architecture\Database;

final class TableOwnership
{
    /** @var array<string, string> Closed exceptions; every other table is tenant-owned. */
    public const GLOBAL_TABLES = [
        'users' => 'Identity : compte de connexion partagé, pas une personne métier.',
        'password_reset_tokens' => 'Identity : récupération du compte global.',
        'personal_access_tokens' => 'Identity : authentification Sanctum ; aucun droit tenant implicite.',
        'sessions' => 'Infrastructure : sessions du compte ; sélection tenant non autorisante.',
        'cache' => 'Infrastructure : transport partagé ; clés tenant isolées par TEN-005.',
        'cache_locks' => 'Infrastructure : verrous partagés ; clés tenant isolées par TEN-005.',
        'jobs' => 'Infrastructure : transport partagé ; contexte des jobs contrôlé par TEN-006.',
        'job_batches' => 'Infrastructure : suivi technique des traitements.',
        'failed_jobs' => 'Infrastructure : incidents techniques, accès opérateur uniquement.',
        'migrations' => 'Infrastructure : historique du schéma.',
        'organizations' => 'Tenancy : racine du tenant, identifiée par id ; visibilité autorisée seulement.',
    ];

    public static function isGlobal(string $table): bool
    {
        return array_key_exists($table, self::GLOBAL_TABLES);
    }
}
