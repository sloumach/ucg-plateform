<?php

return [
    'saved' => 'Correction enregistrée. Le recalcul est en attente ; une nouvelle preuve et une validation indépendante seront nécessaires.',
    'retry' => 'La demande de recalcul a été remise en file.',
    'pending' => 'Un recalcul est en attente. Actualisez son état avant de poursuivre.',
    'revision' => 'La version des scores a changé. Rechargez les scores avant de proposer une correction ou un résultat.',
    'key' => 'Cette clé de correction est déjà associée à une autre demande.',
    'confirm' => 'Confirmez la correction et la remise en validation du résultat.',
    'invalid' => 'La correction doit concerner des parties existantes, tous leurs participants et une série cohérente ; un résultat complet ne peut pas devenir incomplet.',
    'unchanged' => 'Cette correction ne change aucun score ni résultat de partie.',
    'limit' => 'La limite de corrections pour ce match est atteinte.',
    'async' => 'Configurez une file persistante et son worker pour recalculer les scores en arrière-plan.',
    'phase' => 'Ce match ne permet pas de corriger ses parties dans son état actuel.',
];
