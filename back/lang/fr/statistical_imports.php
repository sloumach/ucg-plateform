<?php

return [
    'csv' => 'CSV UTF-8 attendu : 1 Mio et 100 lignes de données maximum, séparateur virgule ou point-virgule.',
    'schema' => 'Les en-têtes doivent reprendre les champs des observations, sans colonne inconnue. Les identifiants et versions doivent être explicites.',
    'saved' => 'Prévisualisation privée enregistrée. Aucune observation créée.',
    'reference' => 'Référence introuvable, inaccessible ou incompatible dans cette organisation.',
    'duplicate' => 'Ligne identique déjà présente dans ce fichier.',
    'columns' => 'Le nombre de cellules ne correspond pas aux en-têtes.',
    'stale' => 'La métrique ou une référence a changé : préparez un nouveau fichier à jour.',
    'version' => 'Cet import a changé. Actualisez son suivi avant de continuer.',
    'empty' => 'Aucune ligne valide à importer. Préparez un fichier corrigé.',
    'rejected' => 'Confirmez explicitement que les lignes invalides seront ignorées.',
    'disabled' => 'Le traitement des CSV statistiques doit être activé par un opérateur après recette.',
    'queued' => 'Import confirmé. Consultez le suivi pour connaître les observations effectivement créées.',
    'dispatch_failed' => 'La mise en file a échoué. L’import est conservé ; utilisez la reprise après rétablissement du service.',
];
