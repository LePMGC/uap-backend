<?php

namespace App\Modules\Core\UserManagement\Services;

use App\Modules\Connectors\Auth\LocalAuthConnector;
use App\Modules\Connectors\Auth\LdapAuthConnector;
use App\Models\SystemSetting;

class AuthOrchestrator
{
    public function authenticate(string $username, string $password): bool
    {
        $mode = SystemSetting::where('key', 'authentication_provider')->value('value');

        // 1. Log the attempt and the selected mode
        \App\Modules\Connectors\Services\UapLogger::info('Security', 'AUTH_ATTEMPT_INITIATED', [
            'username' => $username,
            'mode_selected' => ($username === 'admin_emergency') ? 'emergency_local' : $mode
        ]);

        if ($username === 'admin_emergency') {
            return (new LocalAuthConnector())->authenticate($username, $password);
        }

        return match ($mode) {
            'active_directory' => (new LdapAuthConnector())->authenticate($username, $password),
            'local'            => (new LocalAuthConnector())->authenticate($username, $password),
            default            => throw new \Exception("Invalid Auth Configuration"),
        };
    }
}