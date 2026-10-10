<?php

return [
    'login' => [
        'email' => [
            'required' => 'L’adresse e-mail est obligatoire.',
            'invalid' => 'L’adresse e-mail doit être valide.',
        ],
        'password' => [
            'required' => 'Le mot de passe est obligatoire.',
        ],
    ],
    'notifications' => [
        'logout_succeeded' => 'Déconnexion effectuée.',
        'password_link_requested' => 'Si un compte correspond à cette adresse, un lien de réinitialisation a été envoyé.',
        'password_reset_succeeded' => 'Le mot de passe a été réinitialisé. Les anciennes sessions ont été révoquées.',
        'verification_sent' => 'Si nécessaire, un nouveau lien de vérification a été envoyé.',
        'email_verified' => 'L’adresse e-mail a été vérifiée.',
        'sessions_revoked' => 'Les autres sessions ont été révoquées.',
    ],
    'password_reset' => [
        'token' => [
            'required' => 'Le jeton de réinitialisation est obligatoire.',
            'invalid' => 'Le lien de réinitialisation est invalide ou expiré.',
        ],
        'password' => [
            'required' => 'Le nouveau mot de passe est obligatoire.',
            'confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ],
    ],
    'sessions' => [
        'password_invalid' => 'Le mot de passe actuel est incorrect.',
    ],
    'errors' => [
        'security_audit_immutable' => 'Le journal de sécurité ne peut pas être modifié.',
    ],
    'mail' => [
        'password_reset' => [
            'subject' => 'Réinitialisation de votre mot de passe',
            'introduction' => 'Une demande de réinitialisation a été reçue pour votre compte.',
            'action' => 'Choisir un nouveau mot de passe',
            'expiration' => 'Ce lien expire dans :minutes minutes.',
            'ignore' => 'Si vous n’êtes pas à l’origine de cette demande, aucune action n’est requise.',
        ],
        'verification' => [
            'subject' => 'Vérification de votre adresse e-mail',
            'introduction' => 'Vérifiez votre adresse e-mail pour sécuriser votre compte.',
            'action' => 'Vérifier mon adresse e-mail',
            'ignore' => 'Si vous n’avez pas créé ce compte, aucune action n’est requise.',
        ],
    ],
];
