<?php

namespace App\Modules\Media\Domain;

use Illuminate\Database\Query\Builder;

/** Current audience only; never persisted as an authorization snapshot. */
final readonly class CommunicationAudience
{
    /** @param list<string> $teams
     * @param  list<string>  $games
     * @param  list<string>  $roles
     * @param  array<string, Builder>  $contexts
     */
    public function __construct(public array $teams, public array $games, public array $roles, public array $contexts = []) {}

    public function includes(string $type, ?string $id): bool
    {
        return match ($type) {
            'organization' => true,
            'team' => in_array($id, $this->teams, true),
            'game' => in_array($id, $this->games, true),
            'role' => in_array($id, $this->roles, true),
            default => false,
        };
    }
}
