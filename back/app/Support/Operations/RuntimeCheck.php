<?php

namespace App\Support\Operations;

final readonly class RuntimeCheck
{
    /** @param array<string, mixed> $observations */
    public function __construct(
        public ProbeState $state,
        public string $code,
        public array $observations = [],
    ) {}

    /** @return array{state: string, code: string, observations: array<string, mixed>} */
    public function toArray(): array
    {
        return ['state' => $this->state->value, 'code' => $this->code, 'observations' => $this->observations];
    }
}
