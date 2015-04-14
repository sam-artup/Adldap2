<?php

namespace adLDAP\classes;

use adLDAP\Exceptions\adLDAPException;
use adLDAP\Objects\AccountControl;
use adLDAP\Objects\User;
use adLDAP\adLDAP;

/**
 * Ldap User management
 *
 * PHP LDAP CLASS FOR MANIPULATING ACTIVE DIRECTORY
 * Version 5.0.0
 *
 * PHP Version 5 with SSL and LDAP support
 *
 * Written by Scott Barnett, Richard Hyland
 *   email: scott@wiggumworld.com, adldap@richardhyland.com
 *   http://github.com/adldap/adLDAP
 *
 * Copyright (c) 2006-2014 Scott Barnett, Richard Hyland
 *
 * We'd appreciate any improvements or additions to be submitted back
 * to benefit the entire community :)
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * @category ToolsAndUtilities
 * @package adLDAP
 * @subpackage Users
 * @author Scott Barnett, Richard Hyland
 * @copyright (c) 2006-2014 Scott Barnett, Richard Hyland
 * @license http://www.gnu.org/licenses/old-licenses/lgpl-2.1.html LGPLv2.1
 * @version 5.0.0
 * @link http://github.com/adldap/adLDAP
 *
 * Class adLDAPUsers
 * @package adLDAP\classes
 */
class adLDAPUsers extends adLDAPBase
{
    /**
     * The default query fields to use
     * when requesting user information.
     *
     * @var array
     */
    public $defaultQueryFields = array(
        "samaccountname",
        "mail",
        "memberof",
        "department",
        "displayname",
        "telephonenumber",
        "primarygroupid",
        "objectsid"
    );

    /**
     * Validate a user's login credentials
     *
     * @param string $username The users AD username
     * @param string $password The users AD password
     * @param bool $preventRebind
     * @return bool
     */
    public function authenticate($username, $password, $preventRebind = false)
    {
        return $this->adldap->authenticate($username, $password, $preventRebind);
    }

    /**
     * Create a user.
     *
     * If you specify a password here, this can only be performed over SSL.
     *
     * @param array $attributes The attributes to set to the user account
     * @return bool|string
     * @throws adLDAPException
     */
    public function create(array $attributes)
    {
        $user = new User($attributes);

        if ($user->getAttribute('password') && ! $this->connection->canChangePasswords())
        {
            throw new adLDAPException('SSL must be configured on your webserver and enabled in the class to set passwords.');
        }

        // Translate the schema
        $add = $this->adldap->adldap_schema($user->toSchema());
        
        // Additional stuff only used for adding accounts
        $add["cn"][0] = $attributes["display_name"];
        $add["samaccountname"][0] = $attributes["username"];
        $add["objectclass"][0] = "top";
        $add["objectclass"][1] = "person";
        $add["objectclass"][2] = "organizationalPerson";
        $add["objectclass"][3] = "user";

        // Set the account control attribute
        $control_options = array("NORMAL_ACCOUNT");

        if ( ! $attributes["enabled"]) $control_options[] = "ACCOUNTDISABLE";

        $add["userAccountControl"][0] = $this->accountControl($control_options);
        
        // Determine the container
        $attributes["container"] = array_reverse($attributes["container"]);

        $container = "OU=" . implode(", OU=",$attributes["container"]);

        $dn = "CN=" . $add["cn"][0] . ", " . $container . "," . $this->adldap->getBaseDn();

        // Add the entry
        $result = $this->connection->add($dn, $add);

        if ($result != true) return false;

        return true;
    }

    /**
     * Account control options.
     *
     * @param array $options The options to convert to int
     * @return int
     */
    protected function accountControl($options)
    {
        $accountControl = new AccountControl($options);

        $accountControl->setValueIfAttributeExists('SCRIPT', 1);

        $accountControl->setValueIfAttributeExists('ACCOUNTDISABLE', 2);

        $accountControl->setValueIfAttributeExists('HOMEDIR_REQUIRED', 8);

        $accountControl->setValueIfAttributeExists('LOCKOUT', 16);

        $accountControl->setValueIfAttributeExists('PASSWD_NOTREQD', 32);

        //PASSWD_CANT_CHANGE Note You cannot assign this permission by directly modifying the UserAccountControl attribute.
        //For information about how to set the permission programmatically, see the "Property flag descriptions" section.
        $accountControl->setValueIfAttributeExists('ENCRYPTED_TEXT_PWD_ALLOWED', 128);

        $accountControl->setValueIfAttributeExists('TEMP_DUPLICATE_ACCOUNT', 256);

        $accountControl->setValueIfAttributeExists('NORMAL_ACCOUNT', 512);

        $accountControl->setValueIfAttributeExists('INTERDOMAIN_TRUST_ACCOUNT', 2048);

        $accountControl->setValueIfAttributeExists('WORKSTATION_TRUST_ACCOUNT', 4096);

        $accountControl->setValueIfAttributeExists('SERVER_TRUST_ACCOUNT', 8192);

        $accountControl->setValueIfAttributeExists('DONT_EXPIRE_PASSWORD', 65536);

        $accountControl->setValueIfAttributeExists('MNS_LOGON_ACCOUNT', 131072);

        $accountControl->setValueIfAttributeExists('SMARTCARD_REQUIRED', 262144);

        $accountControl->setValueIfAttributeExists('TRUSTED_FOR_DELEGATION', 524288);

        $accountControl->setValueIfAttributeExists('NOT_DELEGATED', 1048576);

        $accountControl->setValueIfAttributeExists('USE_DES_KEY_ONLY', 2097152);

        $accountControl->setValueIfAttributeExists('DONT_REQ_PREAUTH', 4194304);

        $accountControl->setValueIfAttributeExists('PASSWORD_EXPIRED', 8388608);

        $accountControl->setValueIfAttributeExists('TRUSTED_TO_AUTH_FOR_DELEGATION', 16777216);

        return intval($accountControl->getAttribute('value'));
    }

    /**
     * Delete a user account
     *
     * @param string $username The username to delete
     * @param bool $isGUID
     * @return bool
     */
    public function delete($username, $isGUID = false)
    {
        $userinfo = $this->info($username, array("*"), $isGUID);

        $dn = $userinfo[0]['distinguishedname'][0];

        $result = $this->adldap->folder()->delete($dn);

        if ($result != true) return false;

        return true;
    }

    /**
     * Retrieves the groups that the specified user is apart of
     *
     * @param string $username The username of the user to query
     * @param null $recursive Recursive list of groups
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return array|bool
     */
    public function groups($username, $recursive = NULL, $isGUID = false)
    {
        if ($username === NULL) return false;

        // Use the default option if they haven't set it
        if ($recursive === NULL) $recursive = $this->adldap->getRecursiveGroups();

        if ( ! $this->adldap->getLdapBind()) return false;
        
        // Search the directory for their information
        $info = @$this->info($username, array("memberof", "primarygroupid"), $isGUID);

        // Presuming the entry returned is our guy (unique usernames)
        $groups = $this->adldap->utilities()->niceNames($info[0]["memberof"]);

        if ($recursive === true)
        {
            foreach ($groups as $id => $groupName)
            {
                $extraGroups = $this->adldap->group()->recursiveGroups($groupName);

                $groups = array_merge($groups, $extraGroups);
            }
        }

        return $groups;
    }

    /**
     * Find information about the users. Returned in a raw array format from AD
     *
     * @param string $username The username to query
     * @param array $fields Array of parameters to query
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return array|bool
     */
    public function info($username, array $fields = array(), $isGUID = false)
    {
        $this->adldap->utilities()->validateNotNull('Username', $username);

        $this->adldap->utilities()->validateLdapIsBound();

        // Make sure we assign the default fields if none are given
        if (count($fields) === 0) $fields = $this->defaultQueryFields;

        if ($isGUID === true)
        {
            $username = $this->adldap->utilities()->strGuidToHex($username);

            $filter = "objectguid=" . $username;
        } else if (strpos($username, "@"))
        {
             $filter = "userPrincipalName=" . $username;
        } else
        {
             $filter = "samaccountname=" . $username;
        }

        $filter = "(&(objectCategory=person)({$filter}))";

        if ( ! in_array("objectsid", $fields))
        {
            $fields[] = "objectsid";
        }

        $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

        $entries = $this->connection->getEntries($results);

        if (isset($entries[0]))
        {
            if ($entries[0]['count'] >= 1)
            {
                if (in_array("memberof", $fields))
                {
                    // AD does not return the primary group in the ldap query, we may need to fudge it
                    if ($this->adldap->getRealPrimaryGroup() && isset($entries[0]["primarygroupid"][0]) && isset($entries[0]["objectsid"][0]))
                    {
                        $entries[0]["memberof"][] = $this->adldap->group()->getPrimaryGroup($entries[0]["primarygroupid"][0], $entries[0]["objectsid"][0]);
                    } else
                    {
                        $entries[0]["memberof"][] = "CN=Domain Users,CN=Users," . $this->adldap->getBaseDn();
                    }

                    if ( ! isset($entries[0]["memberof"]["count"]))
                    {
                        $entries[0]["memberof"]["count"] = 0;
                    }

                    $entries[0]["memberof"]["count"]++;
                }
            }

            return $entries;
        }

        return false;
    }

    /**
     * Find information about the users. Returned in a raw array format from AD
     *
     * @param string $username The username to query
     * @param array $fields Array of parameters to query
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return \adLDAP\collections\adLDAPUserCollection|bool
     */
    public function infoCollection($username, array $fields = array(), $isGUID = false)
    {
        if ($username === NULL) return false;

        if ( ! $this->adldap->getLdapBind()) return false;
        
        $info = $this->info($username, $fields, $isGUID);
        
        if ($info !== false)
        {
            return new \adLDAP\collections\adLDAPUserCollection($info, $this->adldap);
        }

        return false;
    }

    /**
     * Determine if the specified user is in the specified group
     *
     * @param string $username The username to query
     * @param string $group The name of the group to check against
     * @param null $recursive Check groups recursively
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return bool
     */
    public function inGroup($username, $group, $recursive = NULL, $isGUID = false)
    {
        if ($username === NULL) return false;

        if ($group === NULL) return false;

        if ( ! $this->adldap->getLdapBind()) return false;

        // Use the default option if they haven't set it
        if ($recursive === NULL) $recursive = $this->adldap->getRecursiveGroups();
        
        // Get a list of the groups
        $groups = $this->groups($username, $recursive, $isGUID);
        
        // Return true if the specified group is in the group list
        if (in_array($group, $groups)) return true;

        return false;
    }

    /**
     * Determine a user's password expiry date
     *
     * @param $username
     * @param bool $isGUID
     * @return array|bool|string
     * @throws adLDAPException
     * @requires bcmod http://php.net/manual/en/function.bcmod.php
     */
    public function passwordExpiry($username, $isGUID = false)
    {
        if ($username === NULL) return "Missing compulsory field [username]";

        if ( ! $this->adldap->getLdapBind()) return false;

        if ( ! function_exists('bcmod'))
        {
            $message = "Missing function support [bcmod] http://php.net/manual/en/function.bcmod.php";

            throw new adLDAPException($message);
        }

        $userInfo = $this->info($username, array("pwdlastset", "useraccountcontrol"), $isGUID);

        $pwdLastSet = $userInfo[0]['pwdlastset'][0];

        $status = array();

        if ($userInfo[0]['useraccountcontrol'][0] == '66048')
        {
            return "Does not expire";
        }

        if ($pwdLastSet === '0')
        {
            return "Password has expired";
        }

        // Password expiry in AD can be calculated from TWO values:
        //   - User's own pwdLastSet attribute: stores the last time the password was changed
        //   - Domain's maxPwdAge attribute: how long passwords last in the domain
        //
        // Although Microsoft chose to use a different base and unit for time measurements.
        // This function will convert them to Unix timestamps
        $filter = 'objectclass=*';

        $results = $this->connection->read($this->adldap->getBaseDn(), $filter, array('maxPwdAge'));

        if ( ! $results) return false;

        $info = $this->connection->getEntries($results);

        $maxPwdAge = $info[0]['maxpwdage'][0];

        // See MSDN: http://msdn.microsoft.com/en-us/library/ms974598.aspx
        //
        // pwdLastSet contains the number of 100 nanosecond intervals since January 1, 1601 (UTC),
        // stored in a 64 bit integer.
        //
        // The number of seconds between this date and Unix epoch is 11644473600.
        //
        // maxPwdAge is stored as a large integer that represents the number of 100 nanosecond
        // intervals from the time the password was set before the password expires.
        //
        // We also need to scale this to seconds but also this value is a _negative_ quantity!
        //
        // If the low 32 bits of maxPwdAge are equal to 0 passwords do not expire
        //
        // Unfortunately the maths involved are too big for PHP integers, so I've had to require
        // BCMath functions to work with arbitrary precision numbers.
        if (bcmod($maxPwdAge, 4294967296) === '0')
        {
            return "Domain does not expire passwords";
        }

        // Add maxpwdage and pwdlastset and we get password expiration time in Microsoft's
        // time units.  Because maxpwd age is negative we need to subtract it.
        $pwdExpire = bcsub($pwdLastSet, $maxPwdAge);

        // Convert MS's time to Unix time
        $status['expiryts'] = bcsub(bcdiv($pwdExpire, '10000000'), '11644473600');
        $status['expiryformat'] = date('Y-m-d H:i:s', bcsub(bcdiv($pwdExpire, '10000000'), '11644473600'));

        return $status;
    }

    /**
     * Modify a user
     *
     * @param string $username The username to query
     * @param array $attributes The attributes to modify.  Note if you set the enabled attribute you must not specify any other attributes
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return bool|string
     * @throws adLDAPException
     */
    public function modify($username, $attributes, $isGUID = false)
    {
        $user = new User($attributes);

        if ($username === NULL) throw new adLDAPException("Missing compulsory field [username]");

        if ($user->getAttribute('password') && ! $this->connection->canChangePasswords())
        {
            throw new adLDAPException('SSL/TLS must be configured on your webserver and enabled in the class to set passwords.');
        }

        // Find the dn of the user
        $userDn = $this->dn($username, $isGUID);

        if ($userDn === false) return false;
        
        // Translate the update to the LDAP schema                
        $mod = $this->adldap->adldap_schema($user->toSchema());

        $enabled = $user->getAttribute('enabled');

        // Check to see if this is an enabled status update
        if ( ! $mod && ! $enabled) return false;

        if ($enabled)
        {
            $controlOptions = array("NORMAL_ACCOUNT");
        } else
        {
            $controlOptions = array("NORMAL_ACCOUNT", "ACCOUNTDISABLE");
        }

        $mod["userAccountControl"][0] = $this->accountControl($controlOptions);

        // Do the update
        $result = $this->connection->modify($userDn, $mod);

        if ($result === false) return false;

        return true;
    }

    /**
     * Disable a user account
     *
     * @param string $username The username to disable
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return bool|string
     * @throws adLDAPException
     */
    public function disable($username, $isGUID = false)
    {
        if ($username === NULL) return "Missing compulsory field [username]";

        $attributes = array("enabled" => 0);

        $result = $this->modify($username, $attributes, $isGUID);

        if ($result == false) return false;

        return true;
    }

    /**
     * Enable a user account
     *
     * @param string $username The username to enable
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return bool|string
     * @throws \adLDAP\adLDAPException
     */
    public function enable($username, $isGUID = false)
    {
        if ($username === NULL) return "Missing compulsory field [username]";

        $attributes = array("enabled" => 1);

        $result = $this->modify($username, $attributes, $isGUID);

        if ($result == false) return false;
        
        return true;
    }

    /**
     * Set the password of a user - This must be performed over SSL
     *
     * @param string $username The username to modify
     * @param string $password The new password
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return bool
     * @throws \adLDAP\adLDAPException
     */
    public function password($username, $password, $isGUID = false)
    {
        if ($username === NULL) return false;

        if ($password === NULL) return false;

        if ( ! $this->adldap->getLdapBind()) return false;

        if ( ! $this->adldap->getUseSSL() && ! $this->adldap->getUseTLS())
        {
            $message = 'SSL must be configured on your webserver and enabled in the class to set passwords.';

            throw new adLDAPException($message);
        }
        
        $userDn = $this->dn($username, $isGUID);

        if ($userDn === false) return false;
                
        $add = array();

        $add["unicodePwd"][0] = $this->encodePassword($password);

        $result = $this->connection->modReplace($userDn, $add);

        if ($result === false)
        {
            $err = $this->connection->errNo();

            if ($err)
            {
                $error = $this->connection->err2Str($err);

                $msg = 'Error ' . $err . ': ' . $error . '.';

                if($err == 53)
                {
                    $msg .= ' Your password might not match the password policy.';
                }

                throw new adLDAPException($msg);
            }
            else
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Encode a password for transmission over LDAP
     *
     * @param string $password The password to encode
     * @return string
     */
    public function encodePassword($password)
    {
        $password = "\"" . $password . "\"";

        $encoded = "";

        for ($i = 0; $i < strlen($password); $i++) $encoded .= "{$password{$i}}\000";

        return $encoded;
    }

    /**
     * Retrieve the user's distinguished name based on their username
     *
     * @param string $username The username
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return string|bool
     */
    public function dn($username, $isGUID = false)
    {
        $user = $this->info($username, array("cn"), $isGUID);

        if ($user[0]["dn"] === NULL) return false;

        $userDn = $user[0]["dn"];

        return $userDn;
    }

    /**
     * Return a list of all users in AD
     *
     * @param bool $includeDescription Return a description of the user
     * @param string $search Search parameter
     * @param bool $sorted Sort the user accounts
     * @return array|bool
     */
    public function all($includeDescription = false, $search = "*", $sorted = true)
    {
        if ( ! $this->adldap->getLdapBind()) return false;
        
        // Perform the search and grab all their details
        $filter = "(&(objectClass=user)(samaccounttype=" . adLDAP::ADLDAP_NORMAL_ACCOUNT .")(objectCategory=person)(cn=" . $search . "))";

        $fields = array("samaccountname","displayname");

        $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

        $entries = $this->connection->getEntries($results);

        $usersArray = array();

        for ($i = 0; $i < $entries["count"]; $i++)
        {
            if ($includeDescription && strlen($entries[$i]["displayname"][0])>0)
            {
                $usersArray[$entries[$i]["samaccountname"][0]] = $entries[$i]["displayname"][0];
            } elseif ($includeDescription)
            {
                $usersArray[$entries[$i]["samaccountname"][0]] = $entries[$i]["samaccountname"][0];
            } else
            {
                array_push($usersArray, $entries[$i]["samaccountname"][0]);
            }
        }

        if ($sorted) asort($usersArray);

        return $usersArray;
    }

    /**
     * Converts a username (samAccountName) to a GUID
     *
     * @param string $username The username to query
     * @return bool|string
     */
    public function usernameToGuid($username)
    {
        if ( ! $this->adldap->getLdapBind()) return false;

        if ($username === null) return "Missing compulsory field [username]";
        
        $filter = "samaccountname=" . $username;

        $fields = array("objectGUID");

        $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

        $numEntries = $this->connection->countEntries($results);

        if ($numEntries > 0)
        {
            $entry = $this->connection->getFirstEntry($results);

            $guid = $this->connection->getValuesLen($entry, 'objectGUID');

            $strGUID = $this->adldap->utilities()->binaryToText($guid[0]);

            return $strGUID; 
        }

        return false; 
    }

    /**
     * Return a list of all users in AD that have a specific value in a field
     *
     * @param bool $includeDescription Return a description of the user
     * @param bool $searchField Field to search search for
     * @param bool $searchFilter Value to search for in the specified field
     * @param bool $sorted Sort the user accounts
     * @return array|bool
     */
    public function find($includeDescription = false, $searchField = false, $searchFilter = false, $sorted = true)
    {
        if ( ! $this->adldap->getLdapBind()) return false;
          
        // Perform the search and grab all their details
        $searchParams = "";

        if ($searchField)
        {
            $searchParams = "(" . $searchField . "=" . $searchFilter . ")";
        }

        $filter = "(&(objectClass=user)(samaccounttype=" . adLDAP::ADLDAP_NORMAL_ACCOUNT .")(objectCategory=person)" . $searchParams . ")";

        $fields = array("samaccountname","displayname");

        $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

        $entries = $this->connection->getEntries($results);

        $usersArray = array();

        for ($i = 0; $i < $entries["count"]; $i++)
        {
            if ($includeDescription && strlen($entries[$i]["displayname"][0]) > 0)
            {
                $usersArray[$entries[$i]["samaccountname"][0]] = $entries[$i]["displayname"][0];
            }
            else if ($includeDescription)
            {
                $usersArray[$entries[$i]["samaccountname"][0]] = $entries[$i]["samaccountname"][0];
            }
            else
            {
                array_push($usersArray, $entries[$i]["samaccountname"][0]);
            }
        }

        if ($sorted) asort($usersArray);

        return ($usersArray);
    }

    /**
     * Move a user account to a different OU.
     *
     * When specifying containers, it accepts containers in 1. parent 2. child order
     *
     * @param string $username The username to move
     * @param string $container The container or containers to move the user to
     * @return bool|string
     */
    public function move($username, $container)
    {
        if ( ! $this->adldap->getLdapBind()) return false;

        if ($username === null) return "Missing compulsory field [username]";

        if ($container === null) return "Missing compulsory field [container]";

        if ( ! is_array($container)) return "Container must be an array";
        
        $userInfo = $this->info($username, array("*"));

        $dn = $userInfo[0]['distinguishedname'][0];

        $newRDn = "cn=" . $username;

        $container = array_reverse($container);

        $newContainer = "ou=" . implode(",ou=",$container);

        $newBaseDn = strtolower($newContainer) . "," . $this->adldap->getBaseDn();

        $result = $this->connection->rename($dn, $newRDn, $newBaseDn, true);

        if ($result !== true) return false;

        return true;
    }

    /**
     * Get the last logon time of any user as a Unix timestamp
     *
     * @param string $username
     * @return long|bool|string
     */
    public function getLastLogon($username)
    {
        if ( ! $this->adldap->getLdapBind()) return false;

        if ($username === null) return "Missing compulsory field [username]";

        $userInfo = $this->info($username, array("lastLogonTimestamp"));

        $lastLogon = adLDAPUtils::convertWindowsTimeToUnixTime($userInfo[0]['lastLogonTimestamp'][0]);

        return $lastLogon;
    }
}