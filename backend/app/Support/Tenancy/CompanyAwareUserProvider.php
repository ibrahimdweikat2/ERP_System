<?php

namespace App\Support\Tenancy;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves the signed-in user before any company is known: the session holds
 * only the user id, and the user's company decides the context afterwards.
 */
class CompanyAwareUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        return $this->unscoped(fn () => parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        return $this->unscoped(fn () => parent::retrieveByToken($identifier, $token));
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
    {
        $this->unscoped(fn () => parent::updateRememberToken($user, $token));
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        return $this->unscoped(fn () => parent::retrieveByCredentials($credentials));
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false)
    {
        $this->unscoped(fn () => parent::rehashPasswordIfRequired($user, $credentials, $force));
    }

    private function unscoped(callable $callback): mixed
    {
        return app(CompanyContext::class)->bypass($callback);
    }
}
