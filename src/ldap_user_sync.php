<?php

/**
 * Class LdapUserSync
 * Handles LDAP User Import into Joomla
 */
class LdapUserSync
{
    /**
     * Import LDAP Users into Joomla
     * 
     * @return array
     */
    public function importLdapUsers(): array
    {
        // Check PHP version compatibility
        if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
            ldap_set_option(null, LDAP_OPT_NETWORK_TIMEOUT, 5);
        }

        // Fetch LDAP configuration (replace with your own configuration)
        $ldapConfig = $this->getLdapConfig();
        
        $ldapServerUrl = $this->decryptValue($ldapConfig['server_url']);
        $ldapConn = $this->connectToLdapServer($ldapServerUrl);

        if (!$ldapConn) {
            return $this->generateResponse(false, 'LDAP_CONNECTION_FAILED');
        }

        // Decrypt and prepare credentials
        $bindDn = $this->decryptValue($ldapConfig['service_account_dn']);
        $bindPassword = $this->decryptValue($ldapConfig['service_account_password']);
        $searchBase = $this->decryptValue($ldapConfig['search_base']);
        $searchFilter = $ldapConfig['search_filter'];

        // Set LDAP options
        ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

        // Bind to the LDAP server
        if (!@ldap_bind($ldapConn, $bindDn, $bindPassword)) {
            return $this->generateResponse(false, 'LDAP_BIND_FAILED');
        }

        // Define attributes to retrieve
        $attributes = [
            'samaccountname', 
            'userprincipalname', 
            'givenname', 
            'cn', 
            'mail', 
            'sn', 
            'memberof', 
            'distinguishedname'
        ];
        
        $ldapEntries = $this->getLdapEntries($ldapConn, $searchBase, $searchFilter, $attributes);

        if (empty($ldapEntries)) {
            return $this->generateResponse(false, 'NO_USERS_FOUND');
        }

        // Import LDAP users into Joomla
        $importResult = $this->importUsersIntoJoomla($ldapEntries);

        ldap_unbind($ldapConn);

        return $importResult;
    }

    /**
     * Connects to the LDAP server
     * 
     * @param string $serverUrl
     * @return resource|false
     */
    private function connectToLdapServer(string $serverUrl)
    {
        $connection = ldap_connect($serverUrl);

        if (!$connection) {
            error_log("Failed to connect to LDAP server: $serverUrl");
            return false;
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

        return $connection;
    }

    /**
     * Fetches LDAP entries with pagination support
     * 
     * @param resource $ldapConn
     * @param string $baseDn
     * @param string $filter
     * @param array $attributes
     * @return array
     */
    private function getLdapEntries($ldapConn, string $baseDn, string $filter, array $attributes): array
    {
        $pageSize = 500;
        $cookie = '';
        $entries = [];

        do {
            $controls = [
                [
                    'oid' => LDAP_CONTROL_PAGEDRESULTS,
                    'iscritical' => false,
                    'value' => ['size' => $pageSize, 'cookie' => $cookie]
                ]
            ];

            $searchResult = ldap_search($ldapConn, $baseDn, $filter, $attributes);

            if ($searchResult === false) {
                error_log("LDAP search failed.");
                break;
            }

            $resultEntries = ldap_get_entries($ldapConn, $searchResult);

            foreach ($resultEntries as $entry) {
                if (isset($entry['dn'])) {
                    $entries[] = $entry;
                }
            }

            ldap_free_result($searchResult);
        } while ($cookie !== '');

        return $entries;
    }

    /**
     * Import LDAP users into Joomla
     * 
     * @param array $ldapEntries
     * @return array
     */
    private function importUsersIntoJoomla(array $ldapEntries): array
    {
        $created = 0;
        $updated = 0;
        $failed = 0;

        foreach ($ldapEntries as $ldapUser) {
            $username = trim($ldapUser['samaccountname'][0] ?? '');
            $email = trim($ldapUser['mail'][0] ?? '');
            $fullName = trim($ldapUser['cn'][0] ?? '');

            if (empty($username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                continue;
            }

            $joomlaUser = JUser::getInstance($username);

            if ($joomlaUser->id) {
                // Update existing user
                $joomlaUser->name = $fullName;
                $joomlaUser->email = $email;

                if (!$joomlaUser->save()) {
                    $failed++;
                } else {
                    $updated++;
                }
            } else {
                // Create new Joomla user
                $userData = [
                    'name' => $fullName,
                    'username' => $username,
                    'email' => $email,
                    'password' => JUserHelper::genRandomPassword(),
                    'groups' => [2],
                    'block' => 0
                ];

                $newUser = new JUser;
                $newUser->bind($userData);

                if ($newUser->save()) {
                    $created++;
                } else {
                    $failed++;
                }
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed
        ];
    }

    /**
     * Generate response array
     * 
     * @param bool $status
     * @param string $message
     * @return array
     */
    private function generateResponse(bool $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message
        ];
    }

    /**
     * Decrypt value (placeholder for your decryption function)
     * 
     * @param string $value
     * @return string
     */
    private function decryptValue(string $value): string
    {
        // Replace with your decryption logic
        return base64_decode($value);
    }

    /**
     * Get LDAP configuration (replace with your own configuration retrieval logic)
     * 
     * @return array
     */
    private function getLdapConfig(): array
    {
        return [
            'server_url' => base64_encode('ldap://your-ldap-server.com'),
            'service_account_dn' => base64_encode('cn=admin,dc=example,dc=com'),
            'service_account_password' => base64_encode('your-password'),
            'search_base' => base64_encode('dc=example,dc=com'),
            'search_filter' => '(objectClass=person)'
        ];
    }
}
