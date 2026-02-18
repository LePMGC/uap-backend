<?php

namespace App\Modules\Connectors\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class LdapAuthConnector implements AuthConnectorInterface
{
    public function authenticate(string $username, string $password): bool
    {
        $host   = Config::get('auth.ldap.host');
        $port   = Config::get('auth.ldap.port', 389);
        $domain = Config::get('auth.ldap.domain'); // e.g., @telecom.internal

        // 1. Establish connection
        $ldapConn = ldap_connect($host, $port);
        
        if (!$ldapConn) {
            Log::error("Could not connect to LDAP host: $host");
            return false;
        }

        // Standard options for Active Directory
        ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

        // 2. Attempt Bind (The actual authentication)
        // Telecom ADs often require username@domain format
        $ldapUser = str_contains($username, '@') ? $username : $username . $domain;

        try {
            $bind = @ldap_bind($ldapConn, $ldapUser, $password);
            
            if ($bind) {
                return true;
            }
        } catch (\Exception $e) {
            Log::error("LDAP Bind Error: " . $e->getMessage());
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