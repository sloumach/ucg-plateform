<?php

namespace App\Modules\Tenancy\Domain\Contracts;

interface ClientActivationDelivery
{
    /** Returns a preview link only for local development without a delivery transport. */
    public function send(string $email, string $name, string $organization, string $url): ?string;
}
