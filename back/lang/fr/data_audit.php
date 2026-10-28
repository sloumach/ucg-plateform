<?php

return [
    'invalid_manifest' => 'Le manifeste ou son périmètre est invalide. Vérifiez le format, les références et les chemins locaux autorisés.',
    'invalid_bundle' => 'Le fichier est absent, trop volumineux, modifié ou inaccessible dans le dossier déclaré.',
    'failed' => 'L’audit n’a pas pu aboutir. Aucun import n’a été lancé.',
    'issues' => [
        'OWNER_UNRESOLVED' => 'Le responsable de ce fichier reste à identifier.',
        'PURPOSE_UNRESOLVED' => 'L’usage prévu de ces données reste à préciser.',
        'RETENTION_UNRESOLVED' => 'La règle de conservation reste à référencer.',
        'FILE_UNAVAILABLE_OR_UNSAFE' => 'Le fichier ne peut pas être lu dans le périmètre autorisé.',
        'BUNDLE_LIMIT' => 'La limite globale du lot est dépassée ; le rapport est incomplet.',
        'FILE_LIMIT' => 'Le fichier dépasse la limite de l’import Personnes.',
        'IDENTICAL_FILE_CONTENT' => 'Un autre fichier du lot possède exactement le même contenu.',
        'FORMAT_REQUIRES_DEDICATED_AUDIT' => 'Ce format ou cette catégorie exige un audit métier dédié.',
        'CSV_FORMAT_INVALID' => 'Le CSV ne respecte pas le format pris en charge.',
        'MAPPING_INVALID' => 'La correspondance des colonnes ou les valeurs par défaut est invalide.',
        'UNMAPPED_COLUMN' => 'Une colonne n’est ni associée à un champ ni explicitement écartée.',
        'IGNORED_COLUMN_REQUIRES_REVIEW' => 'Une colonne écartée exige une revue ; aucune donnée n’est supprimée.',
        'COLUMN_COUNT' => 'Le nombre de cellules ne correspond pas à l’en-tête.',
        'INVALID_FIELD' => 'Un champ ne respecte pas les règles de la fiche Personne.',
        'SPREADSHEET_FORMULA_RISK' => 'Une cellule ressemble à une formule de tableur et nécessite une revue.',
        'DUPLICATE_PSEUDONYM' => 'Ce pseudonyme est déjà présent dans les fichiers de cette organisation.',
        'POSSIBLE_SHARED_IDENTITY' => 'Des coordonnées ou un nom sont partagés ; vérifiez le rapprochement sans fusion automatique.',
    ],
];
