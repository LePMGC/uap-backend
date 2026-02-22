<?php

namespace App\Modules\Connectors\Auth;

use Illuminate\Support\Facades\Config;
use App\Modules\Connectors\Services\UapLogger;

class LdapAuthConnector implements AuthConnectorInterface
{
    public function authenticate(string $username, string $password): bool
    {
        $host   = Config::get('auth.ldap.host');
        $port   = Config::get('auth.ldap.port', 389);
        $domain = Config::get('auth.ldap.domain');

        // 1. Establish Connection
        $ldapConn = @ldap_connect($host, $port);
        
        if (!$ldapConn) {
            UapLogger::error('Security', 'LDAP_CONNECT_FAILED', ['host' => $host]);
            return false;
        }

        // Standard LDAP options for Active Directory compatibility
        ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

        // 2. Prepare the Distinguished Name or User Principal Name
        // e.g., jdoe@enterprise.com
        $ldapUser = str_contains($username, '@') ? $username : $username . $domain;

        try {
            // 3. Attempt Bind (The actual password check)
            $bind = @ldap_bind($ldapConn, $ldapUser, $password);
            
            if ($bind) {
                UapLogger::info('Security', 'AUTH_SUCCESS_LDAP', ['user' => $username]);
                return true;
            }

            UapLogger::error('Security', 'AUTH_FAILED_LDAP', ['user' => $username, 'reason' => 'Invalid Credentials']);
        } catch (\Exception $e) {
            UapLogger::error('Security', 'LDAP_BIND_EXCEPTION', ['user' => $username, 'error' => $e->getMessage()]);
        } finally {
            @ldap_unbind($ldapConn);
        }

        return false;
    }

    public function getUserAttributes(string $username): array
    {
        // This is used for JIT provisioning to fill the 'name' and 'email' fields
        return [
            'username' => $username,
            'source'   => 'LDAP',
            // In a production environment, you would use ldap_search here to get:
            // 'name' => $ldapEntry['displayname'],
            // 'email' => $ldapEntry['mail'],
        ];
    }
}