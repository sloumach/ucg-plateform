<?php

return [
    // Initial server-owned references; TEAM-001 will supply the public catalogue port.
    'freshness_days' => 30,
    // Application context zones, not official publisher shards or server identifiers.
    'regions' => [
        'global' => ['name' => 'Global'],
        'eu' => ['name' => 'Europe'],
        'na' => ['name' => 'Amérique du Nord'],
        'apac' => ['name' => 'Asie-Pacifique'],
    ],
    'platforms' => [
        'pc' => ['name' => 'PC'],
        'playstation' => ['name' => 'PlayStation'],
        'xbox' => ['name' => 'Xbox'],
        'switch' => ['name' => 'Nintendo Switch'],
    ],
    'providers' => [
        'riot' => ['name' => 'Riot Games'],
        'steam' => ['name' => 'Steam'],
        'epic' => ['name' => 'Epic Games'],
        'playstation' => ['name' => 'PlayStation Network'],
        'xbox' => ['name' => 'Xbox Network'],
        'nintendo' => ['name' => 'Nintendo'],
    ],
    'games' => [
        'league-of-legends' => ['name' => 'League of Legends', 'platforms' => ['pc' => ['riot']]],
        'valorant' => ['name' => 'VALORANT', 'platforms' => ['pc' => ['riot'], 'playstation' => ['riot'], 'xbox' => ['riot']]],
        'counter-strike-2' => ['name' => 'Counter-Strike 2', 'platforms' => ['pc' => ['steam']]],
        'rocket-league' => ['name' => 'Rocket League', 'platforms' => [
            'pc' => ['epic', 'steam'], 'playstation' => ['epic', 'playstation'],
            'xbox' => ['epic', 'xbox'], 'switch' => ['epic', 'nintendo'],
        ]],
    ],
    'enabled_games' => ['league-of-legends', 'valorant', 'counter-strike-2', 'rocket-league'],
    // An explicit UUID => list<game key> entry overrides the defaults for that organization.
    'tenant_enabled_games' => [],
];
