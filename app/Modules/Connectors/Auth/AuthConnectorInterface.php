<?php

namespace App\Modules\Connectors\Auth;

interface AuthConnectorInterface
{
    /**
     * Attempt to verify the user credentials.
     */
    public function authenticate(string $username, string $password): bool;

    /**
     * Fetch user details from the source (Email, Full Name, etc.) 
     * useful for syncing with the local users table.
     */
    public function getUserAttributes(string $username): array;
}