<?php

return [
    'errors' => [
        'identity_immutable' => 'L’identifiant, le slug et le propriétaire de l’organisation ne peuvent pas être modifiés par cette opération.',
        'audit_immutable' => 'Le journal de cycle de vie ne peut pas être modifié ou supprimé.',
        'slug_taken' => 'Ce slug est déjà utilisé par une organisation.',
        'invalid_account' => 'Un compte existant avec une adresse e-mail vérifiée est requis.',
        'invalid_transition' => 'Ce changement de statut n’est pas autorisé.',
        'inactive' => 'Cette organisation n’est pas active. Les modifications ordinaires sont désactivées.',
        'pilot_owner' => 'Le pilote UCG existe déjà avec un autre propriétaire. Aucun changement n’a été effectué.',
    ],
    'validation' => [
        'required' => 'Ce champ est obligatoire.',
        'string' => 'Ce champ doit être du texte.',
        'max' => 'Ce champ ne peut pas dépasser :max caractères.',
        'choice' => 'La valeur sélectionnée n’est pas autorisée.',
        'integer' => 'Ce champ doit être un nombre entier.',
        'between' => 'Ce champ doit être compris entre :min et :max.',
        'settings' => 'Les paramètres doivent contenir uniquement les options autorisées.',
        'format' => 'Le format de ce champ est invalide.',
        'timezone' => 'Sélectionnez un fuseau horaire IANA valide.',
        'uuid' => 'L’identifiant de corrélation est invalide.',
        'immutable' => 'Ce champ ne peut pas être modifié par cette opération.',
    ],
    'notifications' => ['updated' => 'Les paramètres de l’organisation ont été enregistrés.'],
    'console' => [
        'provisioned' => 'Organisation créée : :id.',
        'transitioned' => 'Statut modifié : :status.',
        'invalid' => 'Les options fournies sont invalides : :fields.',
    ],
];
