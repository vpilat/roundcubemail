<?php

/*
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Caching of IMAP folder contents (messages and index)                |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 | Author: Aleksander Machniak <alec@alec.pl>                            |
 +-----------------------------------------------------------------------+
*/

/**
 * Interface class for accessing Roundcube messages cache
 */
class rcube_imap_cache
{
    public const MODE_INDEX = 1;
    public const MODE_MESSAGE = 2;

    /**
     * Instance of rcube_imap
     *
     * @var rcube_imap
     */
    private $imap;

    /**
     * Instance of rcube_db
     *
     * @var rcube_db
     */
    private $db;

    /**
     * User ID
     *
     * @var int
     */
    private $userid;

    /**
     * Expiration time in seconds
     *
     * @var int
     */
    private $ttl;

    /**
     * Maximum cached message size
     *
     * @var int
     */
    private $threshold;

    /**
     * Internal (in-memory) cache
     *
     * @var array
     */
    private $icache = [];

    /** @var int */
    private $mode = 0;

    private $skip_deleted = false;
    private $index_table;
    private $thread_table;
    private $messages_table;

    /**
     * List of known flags. Thanks to this we can handle flag changes
     * with good performance. Bad thing is we need to know used flags.
     */
    public $flags = [
        1 => 'SEEN',            // RFC3501
        2 => 'DELETED',         // RFC3501
        4 => 'ANSWERED',        // RFC3501
        8 => 'FLAGGED',         // RFC3501
        16 => 'DRAFT',          // RFC3501
        32 => 'MDNSENT',        // RFC3503
        64 => 'FORWARDED',      // RFC5550
        128 => 'SUBMITPENDING', // RFC5550
        256 => 'SUBMITTED',     // RFC5550
        512 => 'JUNK',
        1024 => 'NONJUNK',
        2048 => 'LABEL1',
        4096 => 'LABEL2',
        8192 => 'LABEL3',
        16384 => 'LABEL4',
        32768 => 'LABEL5',
        65536 => 'HASATTACHMENT',
        131072 => 'HASNOATTACHMENT',
    ];

    /**
     * Object constructor.
     *
     * @param rcube_db   $db           DB handler
     * @param rcube_imap $imap         IMAP handler
     * @param int        $userid       User identifier
     * @param bool       $skip_deleted skip_deleted flag
     * @param int        $ttl          Expiration time of cache items
     * @param int        $threshold    Maximum cached message size
     */
    public function __construct($db, $imap, $userid, $skip_deleted, $ttl = 0, $threshold = 0)
    {
        // convert ttl string to seconds
        $ttl = get_offset_sec($ttl);
        if ($ttl > 2592000) {
            $ttl = 2592000;
        }

        $this->db = $db;
        $this->imap = $imap;
        $this->userid = $userid;
        $this->skip_deleted = $skip_deleted;
        $this->ttl = $ttl;
        $this->threshold = $threshold;

        // cache all possible information by default
        $this->mode = self::MODE_INDEX | self::MODE_MESSAGE;

        // database tables
        $this->index_table = $db->table_name('cache_index', true);
        $this->thread_table = $db->table_name('cache_thread', true);
        $this->messages_table = $db->table_name('cache_messages', true);
    }

    /**
     * Cleanup actions (on shutdown).
     */
    public function close()
    {
        $this->save_icache();
        $this->icache = [];
    }

    /**
     * Set cache mode
     *
     * @param int $mode Cache mode
     */
    public function set_mode($mode)
    {
        $this->mode = $mode;
    }

    /**
     * Return (sorted) messages index (UIDs).
     * If index doesn't exist or is invalid, will be updated.
     *
     * @param string $mailbox    Folder name
     * @param string $sort_field Sorting column
     * @param string $sort_order Sorting order (ASC|DESC)
     * @param bool   $existing   Skip index initialization if it doesn't exist in DB
     *
     * @return rcube_result_index|null Messages index
     */
    public function get_index($mailbox, $sort_field = null, $sort_order = null, $existing = false)
    {
        if (empty($this->icache[$mailbox])) {
            $this->icache[$mailbox] = [];
        }

        $sort_order = strtoupper($sort_order) == 'ASC' ? 'ASC' : 'DESC';

        // Seek in internal cache
        if (array_key_exists('index', $this->icache[$mailbox])) {
            // The index was fetched from database already, but not validated yet
            if (empty($this->icache[$mailbox]['index']['validated'])) {
                $index = $this->icache[$mailbox]['index'];
            }
            // We've got a valid index
            elseif ($sort_field == 'ANY' || $this->icache[$mailbox]['index']['sort_field'] == $sort_field) {
                $result = $this->icache[$mailbox]['index']['object'];
                if ($result->get_parameters('ORDER') != $sort_order) {
                    $result->revert();
                }

                return $result;
            }
        }

        // Get index from DB (if DB wasn't already queried)
        if (empty($index) && empty($this->icache[$mailbox]['index_queried'])) {
            $index = $this->get_index_row($mailbox);

            // set the flag that DB was already queried for index
            // this way we'll be able to skip one SELECT, when
            // get_index() is called more than once
            $this->icache[$mailbox]['index_queried'] = true;
        }

        $data = null;

        // @TODO: Think about skipping validation checks.
        // If we could check only every 10 minutes, we would be able to skip
        // expensive checks, mailbox selection or even IMAP connection, this would require
        // additional logic to force cache invalidation in some cases
        // and many rcube_imap changes to connect when needed

        // Entry exists, check cache status
        if (!empty($index)) {
            $exists = true;
            $modseq = $index['modseq'] ?? null;
            $isf = $index['sort_field'] ?? '';

            if ($sort_field == 'ANY') {
                $sort_field = $isf;
            }

            if ($sort_field != $isf) {
                $is_valid = false;
            } else {
                $is_valid = $this->validate($mailbox, $index, $exists);
            }

            if ($is_valid) {
                $data = $index['object'];
                // revert the order if needed
                if ($data->get_parameters('ORDER') != $sort_order) {
                    $data->revert();
                }
            }
        } else {
            if ($existing) {
                return null;
            }

            if ($sort_field == 'ANY') {
                $sort_field = '';
            }

            // Got it in internal cache, so the row already exist
            $exists = array_key_exists('index', $this->icache[$mailbox]);

            $modseq = null;
        }

        // Index not found, not valid or sort field changed, get index from IMAP server
        if ($data === null) {
            // Get mailbox data (UIDVALIDITY, counters, etc.) for status check
            $mbox_data = $this->imap->folder_data($mailbox);
            $data = $this->get_index_data($mailbox, $sort_field, $sort_order, $mbox_data);

            if (isset($mbox_data['HIGHESTMODSEQ'])) {
                $modseq = $mbox_data['HIGHESTMODSEQ'];
            }

            // insert/update
            $this->add_index_row($mailbox, $sort_field, $data, $mbox_data, $exists, $modseq);
        }

        $this->icache[$mailbox]['index'] = [
            'validated' => true,
            'object' => $data,
            'sort_field' => $sort_field,
            'modseq' => $modseq,
        ];

        return $data;
    }

    /**
     * Return messages thread.
     * If threaded index doesn't exist or is invalid, will be updated.
     *
     * @param string $mailbox Folder name
     *
     * @return rcube_result_thread Messages threaded index
     */
    public function get_thread($mailbox)
    {
        if (empty($this->icache[$mailbox])) {
            $this->icache[$mailbox] = [];
        }

        // Seek in internal cache
        if (array_key_exists('thread', $this->icache[$mailbox])) {
            return $this->icache[$mailbox]['thread']['object'];
        }

        $index = null;

        // Get thread from DB (if DB wasn't already queried)
        if (empty($this->icache[$mailbox]['thread_queried'])) {
            $index = $this->get_thread_row($mailbox);

            // set the flag that DB was already queried for thread
            // this way we'll be able to skip one SELECT, when
            // get_thread() is called more than once or after clear()
            $this->icache[$mailbox]['thread_queried'] = true;
        }

        // Entry exist, check cache status
        if (!empty($index)) {
            $exists = true;
            $is_valid = $this->validate($mailbox, $index, $exists);

            if (!$is_valid) {
                $index = null;
            }
        }

        // Index not found or not valid, get index from IMAP server
        if ($index === null) {
            // Get mailbox data (UIDVALIDITY, counters, etc.) for status check
            $mbox_data = $this->imap->folder_data($mailbox);
            // Get THREADS result
            $index['object'] = $this->get_thread_data($mailbox, $mbox_data);

            // insert/update
            $this->add_thread_row($mailbox, $index['object'], $mbox_data, !empty($exists));
        }

        $this->icache[$mailbox]['thread'] = $index;

        return $index['object'];
    }

    /**
     * Returns list of messages (headers). See rcube_imap::fetch_headers().
     *
     * @param string $mailbox Folder name
     * @param array  $msgs    Message UIDs
     *
     * @return array The list of messages (rcube_message_header) indexed by UID
     */
    public function get_messages($mailbox, $msgs = [])
    {
        $result = [];

        if (empty($msgs)) {
            return $result;
        }

        if ($this->mode & self::MODE_MESSAGE) {
            // Fetch messages from cache
            $sql_result = $this->db->query(
                'SELECT `uid`, `data`, `flags`'
                . " FROM {$this->messages_table}"
                . ' WHERE `user_id` = ?'
                    . ' AND `mailbox` = ?'
                    . ' AND `uid` IN (' . $this->db->array2list($msgs, 'integer') . ')',
                $this->userid, $mailbox);

            $msgs = array_flip($msgs);

            while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                $uid = intval($sql_arr['uid']);
                $result[$uid] = $this->build_message($sql_arr);

                if (!empty($result[$uid])) {
                    // save memory, we don't need message body here (?)
                    $result[$uid]->body = null;

                    unset($msgs[$uid]);
                }
            }

            $this->db->reset();

            $msgs = array_flip($msgs);
        }

        // Fetch not found messages from IMAP server
        if (!empty($msgs)) {
            $messages = $this->imap->fetch_headers($mailbox, $msgs, false, true);

            // Insert to DB and add to result list
            if (!empty($messages)) {
                foreach ($messages as $msg) {
                    // @phpstan-ignore-next-line
                    if ($this->mode & self::MODE_MESSAGE) {
                        $this->add_message($mailbox, $msg, !array_key_exists($msg->uid, $result));
                    }

                    $result[$msg->uid] = $msg;
                }
            }
        }

        return $result;
    }

    /**
     * Returns message data.
     *
     * @param string $mailbox Folder name
     * @param int    $uid     Message UID
     * @param bool   $update  If message doesn't exists in cache it will be fetched
     *                        from IMAP server
     * @param bool   $cache   Enables internal cache usage
     *
     * @return rcube_message_header Message data
     */
    public function get_message($mailbox, $uid, $update = true, $cache = true)
    {
        // Check internal cache
        if (!empty($this->icache['__message'])
            && $this->icache['__message']['mailbox'] == $mailbox
            && $this->icache['__message']['object']->uid == $uid
        ) {
            return $this->icache['__message']['object'];
        }

        $message = null;
        $found = false;

        if ($this->mode & self::MODE_MESSAGE) {
            $sql_result = $this->db->query(
                'SELECT `flags`, `data`'
                . " FROM {$this->messages_table}"
                . ' WHERE `user_id` = ?'
                    . ' AND `mailbox` = ?'
                    . ' AND `uid` = ?',
                $this->userid, $mailbox, (int) $uid);

            if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                $message = $this->build_message($sql_arr);
                $found = true;
            }
        }

        // Get the message from IMAP server
        if (empty($message) && $update) {
            $message = $this->imap->get_message_headers($uid, $mailbox, true);
            // cache will be updated in close(), see below
        }

        if (!($this->mode & self::MODE_MESSAGE)) {
            return $message;
        }

        // Save the message in internal cache, will be written to DB in close()
        // Common scenario: user opens unseen message
        // - get message (SELECT)
        // - set message headers/structure (INSERT or UPDATE)
        // - set \Seen flag (UPDATE)
        // This way we can skip one UPDATE
        if (!empty($message) && $cache) {
            // Save current message from internal cache
            $this->save_icache();

            $this->icache['__message'] = [
                'object' => $message,
                'mailbox' => $mailbox,
                'exists' => $found,
                'md5sum' => md5(serialize($message)),
            ];
        }

        return $message;
    }

    /**
     * Saves the message in cache.
     *
     * @param string                $mailbox Folder name
     * @param ?rcube_message_header $message Message data
     * @param bool                  $force   Skips message in-cache existence check
     */
    public function add_message($mailbox, $message, $force = false)
    {
        if (!is_object($message) || empty($message->uid)) {
            return;
        }

        if (!($this->mode & self::MODE_MESSAGE)) {
            return;
        }

        $flags = 0;
        $msg = clone $message;

        if (!empty($message->flags)) {
            foreach ($this->flags as $idx => $flag) {
                if (!empty($message->flags[$flag])) {
                    $flags += $idx;
                }
            }
        }

        $msg->flags = [];

        $msg = $this->db->encode($msg, true);
        $expires = $this->db->param($this->ttl ? $this->db->now($this->ttl) : 'NULL', rcube_db::TYPE_SQL);

        $this->db->insert_or_update(
            $this->messages_table,
            ['user_id' => $this->userid, 'mailbox' => $mailbox, 'uid' => (int) $message->uid],
            ['flags', 'expires', 'data'],
            [$flags, $expires, $msg]
        );
    }

    /**
     * Sets the flag for specified message.
     *
     * @param string $mailbox Folder name
     * @param ?array $uids    Message UIDs or null to change flag
     *                        of all messages in a folder
     * @param string $flag    The name of the flag
     * @param bool   $enabled Flag state
     */
    public function change_flag($mailbox, $uids, $flag, $enabled = false)
    {
        if (empty($uids)) {
            return;
        }

        if (!($this->mode & self::MODE_MESSAGE)) {
            return;
        }

        $flag = strtoupper($flag);
        $idx = (int) array_search($flag, $this->flags);

        if (!$idx) {
            return;
        }

        $uids = (array) $uids;

        // Internal cache update
        if (
            isset($this->icache['__message'])
            && ($message = $this->icache['__message'])
            && $message['mailbox'] === $mailbox
            && in_array($message['object']->uid, $uids)
        ) {
            $message['object']->flags[$flag] = $enabled;

            if (count($uids) == 1) {
                return;
            }
        }

        $this->db->query(
            "UPDATE {$this->messages_table}"
            . ' SET `expires` = ' . ($this->ttl ? $this->db->now($this->ttl) : 'NULL')
            . ', `flags` = `flags` ' . ($enabled ? "+ {$idx}" : "- {$idx}")
            . ' WHERE `user_id` = ?'
                . ' AND `mailbox` = ?'
                // @phpstan-ignore-next-line
                . (count($uids) > 0 ? ' AND `uid` IN (' . $this->db->array2list($uids, 'integer') . ')' : '')
                . " AND (`flags` & {$idx}) = " . ($enabled ? '0' : $idx),
            $this->userid, $mailbox
        );
    }

    /**
     * Removes message(s) from cache.
     *
     * @param string $mailbox Folder name
     * @param ?array $uids    Message UIDs, NULL removes all messages
     */
    public function remove_message($mailbox = null, $uids = null)
    {
        if (!($this->mode & self::MODE_MESSAGE)) {
            return;
        }

        if (!strlen($mailbox)) {
            $this->db->query(
                "DELETE FROM {$this->messages_table}"
                . ' WHERE `user_id` = ?',
                $this->userid);
        } else {
            // Remove the message from internal cache
            if (
                !empty($uids)
                && isset($this->icache['__message'])
                && ($message = $this->icache['__message'])
                && $message['mailbox'] === $mailbox
                && in_array($message['object']->uid, (array) $uids)
            ) {
                $this->icache['__message'] = null;
            }

            $this->db->query(
                "DELETE FROM {$this->messages_table}"
                . ' WHERE `user_id` = ?'
                    . ' AND `mailbox` = ?'
                    . ($uids !== null ? ' AND `uid` IN (' . $this->db->array2list((array) $uids, 'integer') . ')' : ''),
                $this->userid, $mailbox
            );
        }
    }

    /**
     * Clears index cache.
     *
     * @param string $mailbox Folder name
     * @param bool   $remove  Enable to remove the DB row
     */
    public function remove_index($mailbox = null, $remove = false)
    {
        if (!($this->mode & self::MODE_INDEX)) {
            return;
        }

        // The index should be only removed from database when
        // UIDVALIDITY was detected or the mailbox is empty
        // otherwise use 'valid' flag to not loose HIGHESTMODSEQ value
        if ($remove) {
            $this->db->query(
                "DELETE FROM {$this->index_table}"
                . ' WHERE `user_id` = ?'
                    . (strlen($mailbox) ? ' AND `mailbox` = ' . $this->db->quote($mailbox) : ''),
                $this->userid
            );
        } else {
            $this->db->query(
                "UPDATE {$this->index_table}"
                . ' SET `valid` = 0'
                . ' WHERE `user_id` = ?'
                    . (strlen($mailbox) ? ' AND `mailbox` = ' . $this->db->quote($mailbox) : ''),
                $this->userid
            );
        }

        if (strlen($mailbox)) {
            unset($this->icache[$mailbox]['index']);
            // Index removed, set flag to skip SELECT query in get_index()
            $this->icache[$mailbox]['index_queried'] = true;
        } else {
            $this->icache = [];
        }
    }

    /**
     * Clears thread cache.
     *
     * @param string $mailbox Folder name
     */
    public function remove_thread($mailbox = null)
    {
        if (!($this->mode & self::MODE_INDEX)) {
            return;
        }

        $this->db->query(
            "DELETE FROM {$this->thread_table}"
            . ' WHERE `user_id` = ?'
                . (strlen($mailbox) ? ' AND `mailbox` = ' . $this->db->quote($mailbox) : ''),
            $this->userid
        );

        if (strlen($mailbox)) {
            unset($this->icache[$mailbox]['thread']);
            // Thread data removed, set flag to skip SELECT query in get_thread()
            $this->icache[$mailbox]['thread_queried'] = true;
        } else {
            $this->icache = [];
        }
    }

    /**
     * Clears the cache.
     *
     * @param string $mailbox Folder name
     * @param array  $uids    Message UIDs, NULL removes all messages in a folder
     */
    public function clear($mailbox = null, $uids = null)
    {
        $this->remove_index($mailbox, true);
        $this->remove_thread($mailbox);
        $this->remove_message($mailbox, $uids);
    }

    /**
     * Delete expired cache entries
     */
    public static function gc()
    {
        $rcube = rcube::get_instance();
        $db = $rcube->get_dbh();
        $now = $db->now();

        $db->query('DELETE FROM ' . $db->table_name('cache_messages', true)
              . " WHERE `expires` < {$now}");

        $db->query('DELETE FROM ' . $db->table_name('cache_index', true)
              . " WHERE `expires` < {$now}");

        $db->query('DELETE FROM ' . $db->table_name('cache_thread', true)
              . " WHERE `expires` < {$now}");
    }

    /**
     * Fetches index data from database
     */
    private function get_index_row($mailbox)
    {
        if (!($this->mode & self::MODE_INDEX)) {
            return;
        }

        // Get index from DB
        $sql_result = $this->db->query(
            'SELECT `data`, `valid`'
            . " FROM {$this->index_table}"
            . ' WHERE `user_id` = ?'
                . ' AND `mailbox` = ?',
            $this->userid, $mailbox
        );

        if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            $data = explode('@', $sql_arr['data']);
            $index = $this->db->decode($data[0], true);
            unset($data[0]);

            if (empty($index)) {
                $index = new rcube_result_index($mailbox);
            }

            return [
                'valid' => $sql_arr['valid'],
                'object' => $index,
                'sort_field' => $data[1],
                'deleted' => $data[2],
                'validity' => $data[3],
                'uidnext' => $data[4],
                'modseq' => $data[5],
            ];
        }
    }

    /**
     * Fetches thread data from database
     */
    private function get_thread_row($mailbox)
    {
        if (!($this->mode & self::MODE_INDEX)) {
            return;
        }

        // Get thread from DB
        $sql_result = $this->db->query(
            'SELECT `data`'
            . " FROM {$this->thread_table}"
            . ' WHERE `user_id` = ?'
                . ' AND `mailbox` = ?',
            $this->userid, $mailbox);

        if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            $data = explode('@', $sql_arr['data']);
            $thread = $this->db->decode($data[0], true);
            unset($data[0]);

            if (empty($thread)) {
                $thread = new rcube_result_thread($mailbox);
            }

            return [
                'object' => $thread,
                'deleted' => $data[1],
                'validity' => $data[2],
                'uidnext' => $data[3],
            ];
        }
    }

    /**
     * Saves index data into database
     */
    private function add_index_row($mailbox, $sort_field, $data, $mbox_data = [], $exists = false, $modseq = null)
    {
        if (!($this->mode & self::MODE_INDEX)) {
            return;
        }

        $data = [
            $this->db->encode($data, true),
            $sort_field,
            (int) $this->skip_deleted,
            (int) $mbox_data['UIDVALIDITY'],
            (int) $mbox_data['UIDNEXT'],
            $modseq ?: ($mbox_data['HIGHESTMODSEQ'] ?? ''),
        ];

        $data = implode('@', $data);
        $expires = $this->db->param($this->ttl ? $this->db->now($this->ttl) : 'NULL', rcube_db::TYPE_SQL);

        $this->db->insert_or_update(
            $this->index_table,
            ['user_id' => $this->userid, 'mailbox' => $mailbox],
            ['valid', 'expires', 'data'],
            [1, $expires, $data]
        );
    }

    /**
     * Saves thread data into database
     */
    private function add_thread_row($mailbox, $data, $mbox_data = [], $exists = false)
    {
        if (!($this->mode & self::MODE_INDEX)) {
            return;
        }

        $data = [
            $this->db->encode($data, true),
            (int) $this->skip_deleted,
            (int) $mbox_data['UIDVALIDITY'],
            (int) $mbox_data['UIDNEXT'],
        ];

        $data = implode('@', $data);
        $expires = $this->db->param($this->ttl ? $this->db->now($this->ttl) : 'NULL', rcube_db::TYPE_SQL);

        $this->db->insert_or_update(
            $this->thread_table,
            ['user_id' => $this->userid, 'mailbox' => $mailbox],
            ['expires', 'data'],
            [$expires, $data]
        );
    }

    /**
     * Checks index/thread validity
     */
    private function validate($mailbox, $index, &$exists = true)
    {
        $object = $index['object'];
        $is_thread = is_a($object, 'rcube_result_thread');

        // sanity check
        if (empty($object)) {
            return false;
        }

        $index['validated'] = true;

        // Get mailbox data (UIDVALIDITY, counters, etc.) for status check
        $mbox_data = $this->imap->folder_data($mailbox);

        // @TODO: Think about skipping validation checks.
        // If we could check only every 10 minutes, we would be able to skip
        // expensive checks, mailbox selection or even IMAP connection, this would require
        // additional logic to force cache invalidation in some cases
        // and many rcube_imap changes to connect when needed

        // Check UIDVALIDITY
        if (empty($index['validity']) || $index['validity'] != $mbox_data['UIDVALIDITY']) {
            $this->clear($mailbox);
            $exists = false;
            return false;
        }

        // Folder is empty but cache isn't
        if (empty($mbox_data['EXISTS'])) {
            if (!$object->is_empty()) {
                $this->clear($mailbox);
                $exists = false;
                return false;
            }
        }
        // Folder is not empty but cache is
        elseif ($object->is_empty()) {
            unset($this->icache[$mailbox][$is_thread ? 'thread' : 'index']);
            return false;
        }

        // Validation flag
        if (!$is_thread && empty($index['valid'])) {
            unset($this->icache[$mailbox]['index']);
            return false;
        }

        // Index was created with different skip_deleted setting
        if ($this->skip_deleted != $index['deleted']) {
            return false;
        }

        // Check HIGHESTMODSEQ
        if (!empty($index['modseq']) && !empty($mbox_data['HIGHESTMODSEQ'])
            && $index['modseq'] == $mbox_data['HIGHESTMODSEQ']
        ) {
            return true;
        }

        // Check UIDNEXT
        if ($index['uidnext'] != $mbox_data['UIDNEXT']) {
            unset($this->icache[$mailbox][$is_thread ? 'thread' : 'index']);
            return false;
        }

        // @TODO: find better validity check for threaded index
        if ($is_thread) {
            // check messages number...
            if (!$this->skip_deleted && $mbox_data['EXISTS'] != $object->count_messages()) {
                return false;
            }

            return true;
        }

        // The rest of checks, more expensive
        if (!empty($this->skip_deleted)) {
            // compare counts if available
            if (!empty($mbox_data['UNDELETED'])
                && $mbox_data['UNDELETED']->count() != $object->count()
            ) {
                return false;
            }

            // compare UID sets
            if (!empty($mbox_data['UNDELETED'])) {
                $uids_new = $mbox_data['UNDELETED']->get();
                $uids_old = $object->get();

                if (count($uids_new) != count($uids_old)) {
                    return false;
                }

                sort($uids_new, \SORT_NUMERIC);
                sort($uids_old, \SORT_NUMERIC);

                if ($uids_old != $uids_new) {
                    return false;
                }
            } elseif ($object->is_empty()) {
                // We have to run ALL UNDELETED search anyway for this case, so we can
                // return early to skip the following search command.
                return false;
            } else {
                // get all undeleted messages excluding cached UIDs
                $existing = rcube_imap_generic::compressMessageSet($object->get());
                $ids = $this->imap->search_once($mailbox, "ALL UNDELETED NOT UID {$existing}");

                if (!$ids->is_empty()) {
                    return false;
                }
            }
        } else {
            // check messages number...
            if ($mbox_data['EXISTS'] != $object->count()) {
                return false;
            }
            // ... and max UID
            if ($object->max() != $this->imap->id2uid($mbox_data['EXISTS'], $mailbox)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Synchronizes the mailbox.
     *
     * @param string $mailbox Folder name
     */
    public function synchronize($mailbox)
    {
        // RFC4549: Synchronization Operations for Disconnected IMAP4 Clients
        // RFC4551: IMAP Extension for Conditional STORE Operation
        //          or Quick Flag Changes Resynchronization
        // RFC5162: IMAP Extensions for Quick Mailbox Resynchronization

        // @TODO: synchronize with other methods?
        $qresync = $this->imap->get_capability('QRESYNC');
        $condstore = $qresync ? true : $this->imap->get_capability('CONDSTORE');

        if (!$qresync && !$condstore) {
            return;
        }

        // Get stored index
        $index = $this->get_index_row($mailbox);

        // database is empty
        if (empty($index)) {
            // set the flag that DB was already queried for index
            // this way we'll be able to skip one SELECT in get_index()
            $this->icache[$mailbox]['index_queried'] = true;
            return;
        }

        $this->icache[$mailbox]['index'] = $index;

        // no last HIGHESTMODSEQ value
        if (empty($index['modseq'])) {
            return;
        }

        if (!$this->imap->check_connection()) {
            return;
        }

        // Enable QRESYNC
        $res = $this->imap->conn->enable($qresync ? 'QRESYNC' : 'CONDSTORE');
        if ($res === false) {
            return;
        }

        // Close mailbox if already selected to get most recent data
        if ($this->imap->conn->selected == $mailbox) {
            $this->imap->conn->close();
        }

        // Get mailbox data (UIDVALIDITY, HIGHESTMODSEQ, counters, etc.)
        $mbox_data = $this->imap->folder_data($mailbox);

        if (empty($mbox_data)) {
            return;
        }

        // Check UIDVALIDITY
        if ($index['validity'] != $mbox_data['UIDVALIDITY']) {
            $this->clear($mailbox);
            return;
        }

        // QRESYNC not supported on specified mailbox
        if (!empty($mbox_data['NOMODSEQ']) || empty($mbox_data['HIGHESTMODSEQ'])) {
            return;
        }

        // Nothing new
        if ($mbox_data['HIGHESTMODSEQ'] == $index['modseq']) {
            return;
        }

        $uids = [];
        $removed = [];

        // Get known UIDs
        if ($this->mode & self::MODE_MESSAGE) {
            $sql_result = $this->db->query(
                'SELECT `uid`'
                . " FROM {$this->messages_table}"
                . ' WHERE `user_id` = ?'
                    . ' AND `mailbox` = ?',
                $this->userid, $mailbox
            );

            while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                $uids[] = $sql_arr['uid'];
            }
        }

        // Synchronize messages data
        if (!empty($uids)) {
            // Get modified flags and vanished messages
            // UID FETCH 1:* (FLAGS) (CHANGEDSINCE 0123456789 VANISHED)
            $result = $this->imap->conn->fetch($mailbox, $uids, true, ['FLAGS'], $index['modseq'], $qresync);

            if (!empty($result)) {
                foreach ($result as $msg) {
                    $uid = $msg->uid;
                    // Remove deleted message
                    if ($this->skip_deleted && !empty($msg->flags['DELETED'])) {
                        $removed[] = $uid;
                        // Invalidate index
                        $index['valid'] = false;
                        continue;
                    }

                    $flags = 0;
                    if (!empty($msg->flags)) {
                        foreach ($this->flags as $idx => $flag) {
                            if (!empty($msg->flags[$flag])) {
                                $flags += $idx;
                            }
                        }
                    }

                    $this->db->query(
                        "UPDATE {$this->messages_table}"
                        . ' SET `flags` = ?, `expires` = ' . ($this->ttl ? $this->db->now($this->ttl) : 'NULL')
                        . ' WHERE `user_id` = ?'
                            . ' AND `mailbox` = ?'
                            . ' AND `uid` = ?'
                            . ' AND `flags` <> ?',
                        $flags, $this->userid, $mailbox, $uid, $flags
                    );
                }
            }

            // VANISHED found?
            if ($qresync) {
                $mbox_data = $this->imap->folder_data($mailbox);

                // Removed messages found
                $uids = !empty($mbox_data['VANISHED']) ? rcube_imap_generic::uncompressMessageSet($mbox_data['VANISHED']) : null;
                if (!empty($uids)) {
                    $removed = array_merge($removed, $uids);
                    // Invalidate index
                    $index['valid'] = false;
                }
            }

            // remove messages from database
            if (!empty($removed)) {
                $this->remove_message($mailbox, $removed);
            }
        }

        $sort_field = $index['sort_field'];
        $sort_order = $index['object']->get_parameters('ORDER');
        $exists = true;

        // Validate index
        if (!$this->validate($mailbox, $index, $exists)) {
            // Invalidate (remove) thread index
            // if $exists=false it was already removed in validate()
            if ($exists) {
                $this->remove_thread($mailbox);
            }

            // Update index
            $data = $this->get_index_data($mailbox, $sort_field, $sort_order, $mbox_data);
        } else {
            $data = $index['object'];
        }

        // update index and/or HIGHESTMODSEQ value
        $this->add_index_row($mailbox, $sort_field, $data, $mbox_data, $exists);

        // update internal cache for get_index()
        $this->icache[$mailbox]['index']['object'] = $data;
    }

    /**
     * Converts cache row into message object.
     *
     * @param array $sql_arr Message row data
     *
     * @return rcube_message_header Message object
     */
    private function build_message($sql_arr)
    {
        $message = $this->db->decode($sql_arr['data'], true);

        if ($message) {
            $message->flags = [];
            foreach ($this->flags as $idx => $flag) {
                if (($sql_arr['flags'] & $idx) == $idx) {
                    $message->flags[$flag] = true;
                }
            }
        }

        return $message;
    }

    /**
     * Saves message stored in internal cache
     */
    private function save_icache()
    {
        // Save current message from internal cache
        if (!empty($this->icache['__message'])) {
            $message = $this->icache['__message'];

            // clean up some object's data
            $this->message_object_prepare($message['object']);

            // calculate current md5 sum
            $md5sum = md5(serialize($message['object']));

            if ($message['md5sum'] != $md5sum) {
                $this->add_message($message['mailbox'], $message['object'], !$message['exists']);
            }

            $this->icache['__message']['md5sum'] = $md5sum;
        }
    }

    /**
     * Prepares message object to be stored in database.
     *
     * @param rcube_message_header|rcube_message_part $msg
     */
    private function message_object_prepare(&$msg, &$size = 0)
    {
        // Remove body too big
        if (isset($msg->body)) {
            $length = strlen($msg->body);

            if (!empty($msg->body_modified) || $size + $length > $this->threshold * 1024) {
                $msg->body = null;
            } else {
                $size += $length;
            }
        }

        // Fix mimetype which might be broken by some code when message is displayed
        // Another solution would be to use object's copy in rcube_message class
        // to prevent related issues, however I'm not sure which is better
        if (!empty($msg->mimetype)) {
            [$msg->ctype_primary, $msg->ctype_secondary] = explode('/', $msg->mimetype);
        }

        $msg->replaces = null;

        if (is_object($msg->structure)) {
            $this->message_object_prepare($msg->structure, $size);
        }

        if (!empty($msg->parts) && is_array($msg->parts)) {
            foreach ($msg->parts as $part) {
                $this->message_object_prepare($part, $size);
            }
        }
    }

    /**
     * Fetches index data from IMAP server
     */
    private function get_index_data($mailbox, $sort_field, $sort_order, $mbox_data = [])
    {
        if (empty($mbox_data)) {
            $mbox_data = $this->imap->folder_data($mailbox);
        }

        if (!empty($mbox_data['EXISTS'])) {
            // fetch sorted sequence numbers
            $index = $this->imap->index_direct($mailbox, $sort_field, $sort_order);
        } else {
            $index = new rcube_result_index($mailbox, '* SORT');
        }

        return $index;
    }

    /**
     * Fetches thread data from IMAP server
     */
    private function get_thread_data($mailbox, $mbox_data = [])
    {
        if (empty($mbox_data)) {
            $mbox_data = $this->imap->folder_data($mailbox);
        }

        if (!empty($mbox_data['EXISTS'])) {
            // get all threads (default sort order)
            return $this->imap->threads_direct($mailbox);
        }

        return new rcube_result_thread($mailbox, '* THREAD');
    }
}
