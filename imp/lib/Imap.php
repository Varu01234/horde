<?php
/**
 * Copyright 2008-2013 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @category  Horde
 * @copyright 2008-2013 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */

/**
 * Provides common functions for interaction with IMAP/POP3 servers via the
 * Horde_Imap_Client package.
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2008-2013 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 *
 * @property-read boolean $changed  If true, this object has changed.
 * @property-read Horde_Imap_Client_Base $ob  The client object for the base
 *                                            connection.
 */
class IMP_Imap implements Serializable
{
    /* Access constants. */
    const ACCESS_FOLDERS = 1;
    const ACCESS_SEARCH = 2;
    const ACCESS_FLAGS = 3;
    const ACCESS_UNSEEN = 4;
    const ACCESS_TRASH = 5;

    /**
     * Server configuration file.
     *
     * @var array
     */
    static protected $_config;

    /**
     * Has this object changed?
     *
     * @var boolean
     */
    protected $_changed = false;

    /**
     * Have we logged into server yet?
     *
     * @var boolean
     */
    protected $_login = false;

    /**
     * Default namespace.
     *
     * @var array
     */
    protected $_nsdefault = null;

    /**
     * The Horde_Imap_Client object.
     *
     * @var Horde_Imap_Client
     */
    protected $_ob = null;

    /**
     * Temporary data cache (destroyed at end of request).
     *
     * @var array
     */
    protected $_temp = array();

    /**
     */
    public function __get($key)
    {
        switch ($key) {
        case 'changed':
            return $this->_changed || ($this->_ob && $this->_ob->changed);

        case 'ob':
            return $this->_ob;
        }
    }

    /**
     * Determine if this is a connection to an IMAP server.
     *
     * @param IMP_Mailbox $mbox  Specifically check this mailbox. Otherwise,
     *                           checks the base IMAP objecct.
     *
     * @return boolean  True if connected to IMAP server, false if connected
     *                  to a POP3 server.
     */
    public function isImap($mbox = null)
    {
        return !$this->_ob || ($this->_ob instanceof Horde_Imap_Client_Socket);
    }

    /**
     * Create a new Horde_Imap_Client object.
     *
     * @param string $username  The username to authenticate with.
     * @param string $password  The password to authenticate with.
     * @param string $key       Create a new object using this server key.
     *
     * @return Horde_Imap_Client_Base  Client object.
     * @throws IMP_Imap_Exception
     */
    public function createImapObject($username, $password, $key)
    {
        global $prefs;

        if (!is_null($this->_ob)) {
            return $this->_ob;
        }

        if (($server = $this->loadServerConfig($key)) === false) {
            $error = new IMP_Imap_Exception('Could not load server configuration.');
            Horde::log($error);
            throw $error;
        }

        $protocol = isset($server['protocol'])
            ? strtolower($server['protocol'])
            : 'imap';

        $imap_config = array(
            'capability_ignore' => empty($server['capability_ignore']) ? array() : $server['capability_ignore'],
            'comparator' => empty($server['comparator']) ? false : $server['comparator'],
            'debug' => isset($server['debug']) ? $server['debug'] : null,
            'debug_literal' => !empty($server['debug_raw']),
            'encryptKey' => array(__CLASS__, 'getEncryptKey'),
            'hostspec' => isset($server['hostspec']) ? $server['hostspec'] : null,
            'id' => empty($server['id']) ? false : $server['id'],
            'lang' => empty($server['lang']) ? false : $server['lang'],
            'password' => $password,
            'port' => isset($server['port']) ? $server['port'] : null,
            'secure' => isset($server['secure']) ? $server['secure'] : false,
            'timeout' => empty($server['timeout']) ? null : $server['timeout'],
            'username' => $username,
        );

        /* Initialize caching. */
        $imap_config['cache'] = $this->loadCacheConfig(isset($server['cache']) ? $server['cache'] : null);

        try {
            $ob = ($protocol == 'imap')
                ? new Horde_Imap_Client_Socket($imap_config)
                : new Horde_Imap_Client_Socket_Pop3($imap_config);
        } catch (Horde_Imap_Client_Exception $e) {
            $error = new IMP_Imap_Exception($e);
            Horde::log($error);
            throw $error;
        }

        $this->_ob = $ob;

        if ($protocol == 'pop') {
            /* Turn some options off if we are working with POP3. */
            $prefs->setValue('save_sent_mail', false);
            $prefs->setLocked('save_sent_mail', true);
            $prefs->setLocked('sent_mail_folder', true);
            $prefs->setLocked('drafts_folder', true);
            $prefs->setLocked('trash_folder', true);
        }

        return $ob;
    }

    /**
     * Prepare the config parameters necessary to use IMAP caching.
     *
     * @param mixed $config  Either true (enable default cache), or a list of
     *                       cache config parameters (enable default cache and
     *                       pass these parameters to IMAP object).
     *
     * @return array  The configuration array.
     */
    public function loadCacheConfig($config)
    {
        $ob = empty($config)
            ? null
            : $GLOBALS['injector']->getInstance('Horde_Cache');

        if (!$ob) {
            $ob = new Horde_Cache(
                new Horde_Cache_Storage_Mock(),
                array('compress' => true)
            );
        }

        if (!is_array($config)) {
            $config = array();
        }

        return array(
            'cacheob' => $ob,
            'lifetime' => empty($config['lifetime']) ? false : $config['lifetime'],
            'slicesize' => empty($config['slicesize']) ? false : $config['slicesize'],
        );
    }

    /**
     * Update the list of mailboxes to ignore when caching FETCH data in the
     * IMAP client object.
     */
    public function updateFetchIgnore()
    {
        if ($this->isImap()) {
            $special = IMP_Mailbox::getSpecialMailboxes();
            $cache = $this->_ob->getParam('cache');
            $cache['fetch_ignore'] = array_filter(array(
                strval($special[IMP_Mailbox::SPECIAL_SPAM]),
                strval($special[IMP_Mailbox::SPECIAL_TRASH])
            ));
            $this->_ob->setParam('cache', $cache);
        }
    }

    /**
     * Checks access rights for a server.
     *
     * @param integer $right  Access right.
     *
     * @return boolean  Does the mailbox have the access right?
     */
    public function access($right)
    {
        switch ($right) {
        case self::ACCESS_FOLDERS:
        case self::ACCESS_TRASH:
            return (!empty($GLOBALS['conf']['user']['allow_folders']) &&
                    $this->isImap());

        case self::ACCESS_FLAGS:
        case self::ACCESS_SEARCH:
        case self::ACCESS_UNSEEN:
            return $this->isImap();
        }

        return false;
    }

    /**
     * Get the namespace list.
     *
     * @return array  See Horde_Imap_Client_Base#getNamespaces().
     */
    public function getNamespaceList()
    {
        try {
            return $this->getNamespaces($GLOBALS['session']->get('imp', 'imap_namespace', Horde_Session::TYPE_ARRAY));
        } catch (Horde_Imap_Client_Exception $e) {
            return array();
        }
    }

    /**
     * Get namespace info for a full mailbox path.
     *
     * @param string $mailbox    The mailbox path.
     * @param boolean $personal  If true, will return empty namespace only
     *                           if it is a personal namespace.
     *
     * @return mixed  The namespace info for the mailbox path or null if the
     *                path doesn't exist.
     */
    public function getNamespace($mailbox = null, $personal = false)
    {
        if (!$this->isImap($mailbox)) {
            return null;
        }

        $ns = $this->getNamespaceList();

        if (is_null($mailbox)) {
            reset($ns);
            $mailbox = key($ns);
        }

        foreach ($ns as $key => $val) {
            $mbox = $mailbox . $val['delimiter'];
            if (strlen($key) && (strpos($mbox, $key) === 0)) {
                return $val;
            }
        }

        return (isset($ns['']) && (!$personal || ($val['type'] == Horde_Imap_Client::NS_PERSONAL)))
            ? $ns['']
            : null;
    }

    /**
     * Get the default personal namespace.
     *
     * @return mixed  The default personal namespace info.
     */
    public function defaultNamespace()
    {
        if (!$this->isImap()) {
            return null;
        }

        if ($this->_login && !isset($this->_nsdefault)) {
            foreach ($this->getNamespaceList() as $val) {
                if ($val['type'] == Horde_Imap_Client::NS_PERSONAL) {
                    $this->_nsdefault = $val;
                    $this->_changed = true;
                    break;
                }
            }
        }

        return $this->_nsdefault;
    }

    /**
     * Return the cache ID for this mailbox.
     *
     * @param string $mailbox  The mailbox name (UTF-8).
     * @param array $addl      Local IMP metadata to add to the cache ID.
     *
     * @return string  The cache ID.
     */
    public function getCacheId($mailbox, array $addl = array())
    {
        return $this->getSyncToken($mailbox) .
            (empty($addl) ? '' : ('|' . implode('|', $addl)));
    }

    /**
     * Parses the cache ID for this mailbox.
     *
     * @param string $id  Cache ID generated by getCacheId().
     *
     * @return array  Two element array:
     *   - date: (integer) Date information (day of year), if embedded in
     *           cache ID.
     *   - token: (string) Mailbox sync token.
     */
    public function parseCacheId($id)
    {
        $out = array('date' => null);

        if ((($pos = strrpos($id, '|')) !== false) &&
            (substr($id, $pos + 1, 1) == 'D')) {
            $out['date'] = substr($id, $pos + 2);
        }

        $out['token'] = (($pos = strpos($id, '|')) === false)
            ? $id
            : substr($id, 0, $pos);

        return $out;
    }

    /**
     * All other calls to this class are routed to the underlying
     * Horde_Imap_Client_Base object.
     *
     * @param string $method  Method name.
     * @param array $params   Method Parameters.
     *
     * @return mixed  The return from the requested method.
     * @throws BadMethodCallException
     * @throws IMP_Imap_Exception
     */
    public function __call($method, $params)
    {
        if (!$this->_ob) {
            /* Fallback for these methods. */
            switch ($method) {
            case 'getIdsOb':
                $ob = new Horde_Imap_Client_Ids();
                call_user_func_array(array($ob, 'add'), $params);
                return $ob;

            default:
                $error = true;
                break;
            }
        } else {
            $error = !method_exists($this->_ob, $method);
        }

        if ($error) {
            if ($GLOBALS['registry']->getAuth()) {
                throw new Horde_Exception_AuthenticationFailure('', Horde_Auth::REASON_SESSION);
            }
            throw new BadMethodCallException(sprintf('%s: Invalid method call "%s".', __CLASS__, $method));
        }

        switch ($method) {
        case 'append':
        case 'createMailbox':
        case 'deleteMailbox':
        case 'expunge':
        case 'fetch':
        case 'getACL':
        case 'getMetadata':
        case 'getMyACLRights':
        case 'getQuota':
        case 'getQuotaRoot':
        case 'getSyncToken':
        case 'openMailbox':
        case 'setMetadata':
        case 'setQuota':
        case 'status':
        case 'statusMultiple':
        case 'store':
        case 'subscribeMailbox':
        case 'sync':
        case 'thread':
            // Horde_Imap_Client_Mailbox: these calls all have the mailbox as
            // their first parameter.
            $params[0] = IMP_Mailbox::getImapMboxOb($params[0]);
            break;

        case 'copy':
        case 'renameMailbox':
            // Horde_Imap_Client_Mailbox: these calls all have the mailbox as
            // their first two parameters.
            $params[0] = IMP_Mailbox::getImapMboxOb($params[0]);
            $params[1] = IMP_Mailbox::getImapMboxOb($params[1]);
            break;

        case 'search':
            $params = call_user_func_array(array($this, '_search'), $params);
            break;
        }

        try {
            $result = call_user_func_array(array($this->_ob, $method), $params);
        } catch (Horde_Imap_Client_Exception $e) {
            $error = new IMP_Imap_Exception($e);
            Horde::log($error);

            $auth_e = $error->authException(false);
            throw is_null($auth_e)
                ? $error
                : $auth_e;
        }

        /* Special handling for various methods. */
        switch ($method) {
        case 'createMailbox':
        case 'renameMailbox':
            // Mailbox is first parameter.
            IMP_Mailbox::get($params[0])->expire();
            break;

        case 'login':
            if (!$this->_login) {
                /* Check for POP3 UIDL support. */
                if (!$this->isImap() &&
                    !$this->queryCapability('UIDL')) {
                    $error = new IMP_Imap_Exception('The POP3 server does not support the REQUIRED UIDL capability.');
                    Horde::log($error);
                    throw $error;
                }

                $this->_changed = $this->_login = true;
            }
            break;

        case 'setACL':
            IMP_Mailbox::get($params[0])->expire(IMP_Mailbox::CACHE_ACL);
            break;
        }

        return $result;
    }

    /**
     * Prepares an IMAP search query.  Needed because certain configuration
     * parameters may need to be dynamically altered before passed to the
     * Imap_Client object.
     *
     * @param string $mailbox                        The mailbox to search.
     * @param Horde_Imap_Client_Search_Query $query  The search query object.
     * @param array $opts                            Additional options.
     *
     * @return array  Parameters to use in the search() call.
     */
    protected function _search($mailbox, $query = null, array $opts = array())
    {
        $mailbox = IMP_Mailbox::get($mailbox);

        if (!empty($opts['sort']) && $mailbox->access_sort) {
            /* If doing a from/to search, use display sorting if possible.
             * Although there is a fallback to a PHP-based display sort, for
             * performance reasons only do a display sort if it is supported
             * on the server. */
            if (($pos = array_search(Horde_Imap_Client::SORT_FROM, $opts['sort'])) !== false) {
                $opts['sort'][$pos] = Horde_Imap_Client::SORT_DISPLAYFROM_FALLBACK;
            }

            if (($pos = array_search(Horde_Imap_Client::SORT_TO, $opts['sort'])) !== false) {
                $opts['sort'][$pos] = Horde_Imap_Client::SORT_DISPLAYTO_FALLBACK;
            }
        }

        if (!is_null($query)) {
            $query->charset('UTF-8', false);
        }

        return array($mailbox->imap_mbox_ob, $query, $opts);
    }

    /* Static methods. */

    /**
     * Loads the IMP server configuration from backends.php.
     *
     * @param string $server  Returns this labeled entry only.
     *
     * @return mixed  If $server is set return this entry; else, return the
     *                entire servers array. Returns false on error.
     */
    static public function loadServerConfig($server = null)
    {
        if (isset(self::$_config)) {
            $servers = self::$_config;
        } else {
            try {
                $servers = Horde::loadConfiguration('backends.php', 'servers', 'imp');
                if (is_null($servers)) {
                    return false;
                }
            } catch (Horde_Exception $e) {
                Horde::log($e, 'ERR');
                return false;
            }

            foreach (array_keys($servers) as $key) {
                if (!empty($servers[$key]['disabled'])) {
                    unset($servers[$key]);
                }
            }
            self::$_config = $servers;
        }

        if (is_null($server)) {
            return $servers;
        }

        /* Check for the existence of the server in the config file. */
        if (empty($servers[$server]) || !is_array($servers[$server])) {
            $entry = sprintf('Invalid server key "%s" from client [%s]', $server, $_SERVER['REMOTE_ADDR']);
            Horde::log($entry, 'ERR');
            return false;
        }

        return $servers[$server];
    }

    /* Callback functions used in Horde_Imap_Client_Base. */

    static public function getEncryptKey()
    {
        return $GLOBALS['injector']->getInstance('Horde_Secret')->getKey();
    }

    /* Serializable methods. */

    /**
     */
    public function serialize()
    {
        return serialize(array(
            $this->_ob,
            $this->_nsdefault,
            $this->_login
        ));
    }

    /**
     */
    public function unserialize($data)
    {
        list(
            $this->_ob,
            $this->_nsdefault,
            $this->_login
        ) = unserialize($data);
    }

}
