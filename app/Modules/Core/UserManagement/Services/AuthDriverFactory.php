<?php

namespace App\Modules\Core\UserManagement\Services;

use App\Modules\Connectors\Auth\AuthConnectorInterface;
use App\Modules\Connectors\Auth\LocalAuthConnector;
use App\Modules\Connectors\Auth\LdapAuthConnector;
use Illuminate\Support\Facades\Config;

class AuthDriverFactory
{
    public static function make(): AuthConnectorInterface
    {
        $mode = config('auth.uap_mode', 'local');
        \Log::info("AuthDriverFactory: Creating auth connector for mode '{$mode}'");

        return match ($mode) {
            'ldap'  => new LdapAuthConnector(),
            'local' => new LocalAuthConnector(),
            default => new LocalAuthConnector(),
        };
    }
}