<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

/**
 * Eloquent user provider that upgrades a legacy WordPress password hash to
 * Bagisto's native bcrypt format on the first successful login.
 *
 * The rehash is performed inside validateCredentials() (rather than relying on
 * the guard's rehashPasswordIfRequired) because the headless JWT login path
 * authenticates via Guard::once(), which validates credentials but never
 * triggers the guard-level rehash. Doing it here covers every login path
 * (session, JWT and admin) since they all funnel through validateCredentials().
 */
class WordPressEloquentUserProvider extends EloquentUserProvider
{
    /**
     * Validate a user against the given credentials and transparently upgrade
     * any legacy hash once the plaintext password has been verified.
     */
    public function validateCredentials(UserContract $user, #[\SensitiveParameter] array $credentials)
    {
        if (! parent::validateCredentials($user, $credentials)) {
            return false;
        }

        $plain = $credentials['password'] ?? null;

        if (! is_null($plain) && $this->hasher->needsRehash($user->getAuthPassword())) {
            $user->forceFill([
                $user->getAuthPasswordName() => $this->hasher->make($plain),
            ])->save();
        }

        return true;
    }
}
