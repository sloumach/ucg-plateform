<?php

return [
    'identity_immutable' => 'L’identité d’un rôle ou accès technique ne peut pas être modifiée.',
    'audit_immutable' => 'Le journal d’accès plateforme et support ne peut pas être modifié.',
    'bootstrap_closed' => 'L’initialisation plateforme est déjà effectuée ou incompatible avec les comptes existants.',
    'account_required' => 'Le compte doit être vérifié et disposer d’un MFA confirmé pour recevoir un rôle plateforme actif.',
    'last_superadmin' => 'Le dernier superadmin actif ne peut pas être révoqué ou rétrogradé.',
    'forbidden' => 'Cet accès plateforme exige un rôle opérateur explicite et un MFA confirmé.',
    'backup_forbidden' => 'Un compte technique ne peut pas consulter une sauvegarde métier complète.',
    'role_saved' => 'Le rôle plateforme et son motif ont été enregistrés.',
    'access_opened' => 'L’accès support temporaire a été ouvert et audité.',
    'access_revoked' => 'L’accès support a été révoqué et audité.',
    'reason' => 'Indiquez un motif d’incident entre 10 et 500 caractères, sans secret.',
    'duration' => 'La durée de l’accès support doit être comprise entre 1 et 30 minutes.',
    'actions' => 'Choisissez une ou deux actions de diagnostic autorisées, sans doublon.',
    'confirm' => 'Confirmez explicitement l’ouverture de cet accès exceptionnel.',
    'reserved' => 'Le tenant, l’opérateur, la période et les permissions sont déterminés par le serveur.',
    'limit' => 'Le nombre maximal d’accès support actifs pour ce tenant et cet opérateur est atteint.',
    'invalid' => 'L’accès support demandé est invalide.',
];
