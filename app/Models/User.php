<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Models\GeneratesUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * The platform's user account.
 *
 * `email_verified_at` is declared here because Larastan does not resolve the
 * `casts()` method, so it infers the column's database type (`string|null`)
 * instead of what the `datetime` cast actually produces at runtime
 * (`Carbon|null`). Assigning `now()` then reads as a type error.
 *
 * The annotation is the fix rather than the workaround. Assigning a pre-formatted
 * string would satisfy the analyser and quietly establish that cast columns take
 * strings, which is wrong at every other call site and would have to be unlearned
 * as the model gains the rest of its casts in Phase 1.
 *
 * @property Carbon|null $email_verified_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use GeneratesUuid, HasFactory, HasRoles, Notifiable;

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
        ];
    }
}
