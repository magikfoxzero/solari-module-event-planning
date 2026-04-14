<?php

namespace Tests;

use NewSolari\Identity\Models\IdentityUser;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function authenticateAs(IdentityUser $user, ?string $partitionId = null)
    {
        $headers = [
            'x-api-key' => $user->username,
            'x-secret-key' => 'password',
            'X-Test-User-Id' => $user->record_id,
        ];

        if ($partitionId) {
            $headers['X-Partition-ID'] = $partitionId;
        }

        return $this->withHeaders($headers)
            ->withoutMiddleware('auth.api')
            ->withMiddleware(\NewSolari\Core\Security\TestAuthenticationMiddleware::class);
    }
}
