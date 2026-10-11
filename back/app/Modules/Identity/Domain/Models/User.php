<?php

namespace App\Modules\Identity\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property string|null $mfa_secret
 * @property list<string>|null $mfa_recovery_codes
 * @property CarbonImmutable|null $mfa_confirmed_at
 * @property string|null $mfa_version
 * @property int|null $mfa_last_used_step
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'mfa_version', 'mfa_last_used_step', 'mfa_confirmed_at'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'array',
            'mfa_confirmed_at' => 'immutable_datetime',
            'mfa_last_used_step' => 'integer',
        ];
    }

    public function hasMfa(): bool
    {
        return $this->mfa_confirmed_at !== null && $this->getRawOriginal('mfa_secret') !== null && $this->mfa_version !== null;
    }
}
