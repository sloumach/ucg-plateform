<?php

return [
    'queued' => 'La demande d’export a été enregistrée.',
    'disabled' => 'La génération des exports est désactivée ou la file persistante est indisponible.',
    'limit' => 'La limite d’export est atteinte. Réduisez le volume ou attendez l’expiration des demandes en cours.',
    'expired' => 'Cet export a expiré. Créez une nouvelle demande.',
    'unavailable' => 'Cet export n’est pas disponible. Actualisez son état ou créez une nouvelle demande.',
    'stale' => 'La source a changé depuis la génération. Créez un nouvel export.',
    'input' => 'Les paramètres d’export ne correspondent pas à la demande initiale.',
    'dispatch_failed' => 'La demande n’a pas pu être mise en file. Créez une nouvelle demande.',
    'cleanup_done' => 'Le lot de fichiers expirés a été nettoyé.',
    'cleanup_failed' => 'Certains fichiers expirés n’ont pas pu être nettoyés. Consultez les journaux.',
];
