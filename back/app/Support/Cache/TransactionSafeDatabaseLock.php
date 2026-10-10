<?php

namespace App\Support\Cache;

use Illuminate\Cache\DatabaseLock;

/** PostgreSQL duplicate-key errors abort transactions even when caught in PHP. */
final class TransactionSafeDatabaseLock extends DatabaseLock
{
    public function acquire(): bool
    {
        if ($this->connection->table($this->table)->insertOrIgnore([
            'key' => $this->name, 'owner' => $this->owner, 'expiration' => $this->expiresAt(),
        ]) === 1) {
            return true;
        }

        return $this->connection->table($this->table)->where('key', $this->name)
            ->where(fn ($query) => $query->where('owner', $this->owner)->orWhere('expiration', '<=', $this->currentTime()))
            ->update(['owner' => $this->owner, 'expiration' => $this->expiresAt()]) === 1;
    }
}
