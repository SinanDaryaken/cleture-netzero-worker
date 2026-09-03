<?php

namespace App\Models;

use Database\Factories\OrganizationUserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password'])]
class OrganizationUser extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<OrganizationUserFactory> */
    use CanResetPassword, HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    public function getConnectionName(): ?string
    {
        return (string) config('identity.connection');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'auth_version' => 'integer',
        ];
    }
}
