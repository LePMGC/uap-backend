<?php

namespace App\Modules\Core\UserManagement\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, Notifiable, HasRoles;

    /**
     * Fields that can be mass-assigned.
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'phone_number',
        'password',
        'is_blocked',
        'must_change_password'
    ];

    /**
     * Fields hidden from API responses.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Automatic casting for Laravel 11.
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     * This is where we inject Permissions for the Frontend.
     */
    public function getJWTCustomClaims()
    {
        return [
            'username'     => $this->username,
            'email'        => $this->email,
            'phone_number' => $this->phone_number,
            // Dynamically get roles and permissions from Spatie
            'roles'        => $this->getRoleNames()[0] ?? null, // Assuming single role for simplicity
            'permissions'  => $this->getAllPermissions()->pluck('name'),
        ];
    }
}