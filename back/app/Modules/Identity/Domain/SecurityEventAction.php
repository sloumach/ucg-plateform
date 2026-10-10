<?php

namespace App\Modules\Identity\Domain;

enum SecurityEventAction: string
{
    case LoginFailed = 'auth.login.failed';
    case LoginSucceeded = 'auth.login.succeeded';
    case LogoutSucceeded = 'auth.logout.succeeded';
    case PasswordResetRequested = 'auth.password_reset.requested';
    case PasswordResetSucceeded = 'auth.password_reset.succeeded';
    case EmailVerificationRequested = 'auth.email_verification.requested';
    case EmailVerified = 'auth.email_verified';
    case OtherSessionsRevoked = 'auth.sessions.revoked';
}
