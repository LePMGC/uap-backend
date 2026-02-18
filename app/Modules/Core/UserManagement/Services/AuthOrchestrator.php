<?php

namespace App\Modules\Core\UserManagement\Services;

use App\Modules\Connectors\Auth\LocalAuthConnector;
use App\Modules\Connectors\Auth\LdapAuthConnector;
use App\Models\SystemSetting;

class AuthOrchestrator
{
    public function authenticate(string $username, string $password): bool
    {
        // 1. Fetch the Global Admin Setting
        $mode = SystemSetting::where('key', 'authentication_provider')->value('value');

        // 2. Exception: Always allow a specific "super_admin" to use Local
        // This is the "Break-Glass" account for telecom emergencies.
        if ($username === 'admin_emergency') {
            return (new LocalAuthConnector())->authenticate($username, $password);
        }

        // 3. Execute based on Admin's Global Toggle
        return match ($mode) {
            'active_directory' => (new LdapAuthConnector())->authenticate($username, $password),
            'local'            => (new LocalAuthConnector())->authenticate($username, $password),
            default            => throw new \Exception("Invalid Auth Configuration"),
        };
    }
}