<?php

namespace App\Modules\Connectors\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use App\Modules\Connectors\Services\UapLogger;

class LdapAuthConnector implements AuthConnectorInterface
{
    public function authenticate(string $username, string $password): bool
    {
        $host = Config::get('auth.ldap.host');
        
        // 1. Connection Logging
        $ldapConn = ldap_connect($host, Config::get('auth.ldap.port', 389));
        
        if (!$ldapConn) {
            UapLogger::error('Security', 'LDAP_CONNECT_FAILED', [
                'host' => $host,
                'user' => $username
            ], 'CRITICAL');
            return false;
        }

        // 2. Bind (Authentication) Logging
        $domain = Config::get('auth.ldap.domain');
        $ldapUser = str_contains($username, '@') ? $username : $username . $domain;

        try {
            $bind = @ldap_bind($ldapConn, $ldapUser, $password);
            
            if ($bind) {
                UapLogger::info('Security', 'AUTH_SUCCESS_LDAP', ['user' => $username]);
                return true;
            }

            UapLogger::error('Security', 'AUTH_FAILED_LDAP', [
                'user' => $username,
                'reason' => 'Invalid Credentials'
            ], 'WARNING');

        } catch (\Exception $e) {
            UapLogger::error('Security', 'LDAP_BIND_EXCEPTION', [
                'user' => $username,
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    public function getUserAttributes(string $username): array
    {
        // In a real implementation, you would use ldap_search here 
        // to get the user's Display Name and Email to sync to Laravel.
        return [
            'username' => $username,
            'source'   => 'Active Directory'
        ];
    }
}