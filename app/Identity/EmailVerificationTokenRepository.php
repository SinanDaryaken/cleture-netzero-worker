<?php

namespace App\Identity;

use App\Models\OrganizationUser;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

class EmailVerificationTokenRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Hasher $hasher,
    ) {}

    public function create(OrganizationUser $organizationUser): string
    {
        $token = Str::random(64);
        $createdAt = now();

        $this->database->connection((string) config('identity.connection'))
            ->table((string) config('identity.email_verification.table'))
            ->updateOrInsert(
                ['organization_user_id' => $organizationUser->getKey()],
                [
                    'token' => $this->hasher->make($token),
                    'created_at' => $createdAt,
                    'expires_at' => $createdAt->copy()->addMinutes(
                        (int) config('identity.email_verification.expire'),
                    ),
                ],
            );

        return $token;
    }
}
