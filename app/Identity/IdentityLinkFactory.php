<?php

namespace App\Identity;

use App\Models\OrganizationUser;
use InvalidArgumentException;

class IdentityLinkFactory
{
    public function emailVerification(
        OrganizationUser $organizationUser,
        #[\SensitiveParameter] string $token,
    ): string {
        return $this->baseUrl().'/'.ltrim((string) config('identity.paths.email_verification'), '/')
            .'/'.rawurlencode((string) $organizationUser->getKey())
            .'/'.rawurlencode($token);
    }

    public function passwordReset(
        OrganizationUser $organizationUser,
        #[\SensitiveParameter] string $token,
    ): string {
        $query = http_build_query([
            'email' => $organizationUser->email,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->baseUrl().'/'.ltrim((string) config('identity.paths.password_reset'), '/')
            .'/'.rawurlencode($token).'?'.$query;
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) config('identity.netzero_my_url'), '/');

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('The NetZero My URL configuration is invalid.');
        }

        return $baseUrl;
    }
}
