<?php

/**
 * Test helpers for the edit_subject plugin.
 *
 * NEVER install this on a real server. It is mounted into the Roundcube
 * container of the test environment only and lets the tests simulate
 * storage failures that a healthy IMAP server does not produce on demand.
 *
 * Without the environment variable EDIT_SUBJECT_TESTKIT=1 (set by the
 * compose file of the tests) nothing in here does anything: the plugin
 * registers nothing, and the storage driver reads no control file and
 * behaves like rcube_imap.
 *
 * Control: the runner writes /var/roundcube/testctl/<user>.json with
 *   refuse_delete_uids  delete_message() returns false and does nothing
 *   noop_expunge_uids   expunge_message() returns true and does nothing
 *                       (what Dovecot answers without the "e" right)
 *   abort_hook_uids     the message_delete hook (Roundcube 1.7) aborts
 *   corrupt_append      one byte is appended to the file before APPEND
 *   lose_append_answer  the message is stored, but save_message() returns
 *                       false as after a lost connection
 *   disable_uidplus     UIDPLUS is taken from the capabilities of the
 *                       connection (imap_disabled_caps) from now on; the
 *                       session keeps what it has cached
 *   vanish_before_unflag_uids  right before \Deleted is taken from one of
 *                       these messages it is expunged, as the EXPUNGE of
 *                       another client would do it while it is flagged
 *   noop_in_saved_hook  a message_saved hook sends NOOP, so the answer of
 *                       the APPEND is no longer the last one Roundcube has
 *   expunge_after_append_uids  right after the APPEND these messages are
 *                       flagged \Deleted and expunged, as another client
 *                       deleting them would do it (the APPEND answer stays)
 *   move_after_append_uids  right after the APPEND these messages are moved
 *                       to the folder move_to (default "Archiv")
 * Observation: every APPEND from a file is recorded in
 *   /var/roundcube/testctl/<user>.observed.json
 */
class edit_subject_testkit extends rcube_plugin
{
    public function init()
    {
        if (getenv('EDIT_SUBJECT_TESTKIT') !== '1') {
            return;
        }

        $rcmail = rcmail::get_instance();

        // the storage object reads this when it connects, later in the request
        if (edit_subject_testkit_ctl::get('disable_uidplus', false)) {
            $rcmail->config->set('imap_disabled_caps', ['UIDPLUS']);
        }

        // Lets the tests bind their results to the versions actually running.
        if (!headers_sent()) {
            header('X-Edit-Subject-Testkit: rc=' . RCMAIL_VERSION
                . '; php=' . PHP_VERSION
                . '; messages_cache=' . ($rcmail->config->get('messages_cache') ? 1 : 0)
                . '; storage=' . $rcmail->config->get('storage_driver', 'imap')
                . '; imap_host=' . implode(',', (array) $rcmail->config->get('imap_host'))
                . '; keep_original=' . ($rcmail->config->get('edit_subject_keep_original') ? 1 : 0)
                . '; disabled_caps=' . implode(',', (array) $rcmail->config->get('imap_disabled_caps'))
                . '; force_caps=' . ($rcmail->config->get('imap_force_caps') ? 1 : 0)
                . '; imap_debug=' . ($rcmail->config->get('imap_debug') ? 1 : 0)
                . '; memory_limit=' . ini_get('memory_limit'));
        }

        $this->add_hook('message_delete', [$this, 'message_delete']);
        $this->add_hook('message_saved', [$this, 'message_saved']);

        // record the memory peak of every edit request (test case 14)
        if ($rcmail->action == 'plugin.edit_subject') {
            register_shutdown_function(function () {
                edit_subject_testkit_ctl::observe([
                    'memory_peak' => memory_get_peak_usage(true),
                    'memory_limit' => ini_get('memory_limit'),
                ]);
            });
        }
    }

    /**
     * Roundcube 1.7: another plugin may abort a deletion via this hook.
     */
    public function message_delete($args)
    {
        $uids = edit_subject_testkit_ctl::uid_list($args['uids'] ?? '');

        if (array_intersect($uids, edit_subject_testkit_ctl::get('abort_hook_uids', []))) {
            $args['abort'] = true;
        }

        return $args;
    }

    /**
     * Another plugin may talk to the server after a message was stored.
     */
    public function message_saved($args)
    {
        if (edit_subject_testkit_ctl::get('noop_in_saved_hook', false)) {
            rcmail::get_instance()->get_storage()->conn->execute('NOOP');
        }

        return $args;
    }
}

/**
 * Access to the control and observation files of the current user.
 */
class edit_subject_testkit_ctl
{
    const DIR = '/var/roundcube/testctl';

    protected static function base()
    {
        $user = (string) ($_SESSION['username'] ?? '');

        return self::DIR . '/' . preg_replace('/[^a-z0-9@._-]/i', '_', $user);
    }

    public static function enabled()
    {
        return getenv('EDIT_SUBJECT_TESTKIT') === '1';
    }

    public static function get($name, $default = null)
    {
        if (!self::enabled()) {
            return $default;
        }

        $file = self::base() . '.json';
        clearstatcache(true, $file);

        if (!is_readable($file)) {
            return $default;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && array_key_exists($name, $data) ? $data[$name] : $default;
    }

    public static function observe($entry)
    {
        if (!self::enabled()) {
            return;
        }

        $file = self::base() . '.observed.json';
        $list = is_readable($file) ? json_decode((string) file_get_contents($file), true) : [];
        $list = is_array($list) ? $list : [];
        $list[] = $entry;
        file_put_contents($file, json_encode($list), LOCK_EX);
    }

    public static function uid_list($uids)
    {
        if (is_array($uids)) {
            $uids = implode(',', $uids);
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $uids))));
    }
}

/**
 * Storage driver "estest" (config: storage_driver = 'estest').
 */
class rcube_estest extends rcube_imap
{
    public function save_message($folder, &$message, $headers = '', $is_file = false, $flags = [], $date = null, $binary = false)
    {
        if ($is_file && is_string($message) && is_file($message)) {
            clearstatcache(true, $message);
            edit_subject_testkit_ctl::observe([
                'dir' => dirname(realpath($message)),
                'basename' => basename($message),
                'perms' => sprintf('%04o', fileperms($message) & 0777),
                'size' => filesize($message),
                'headers_param' => $headers !== '' && $headers !== null,
                'flags' => array_values((array) $flags),
                'date' => $date,
            ]);

            if (edit_subject_testkit_ctl::get('corrupt_append', false)) {
                file_put_contents($message, 'X', FILE_APPEND);
            }
        }

        $result = parent::save_message($folder, $message, $headers, $is_file, $flags, $date, $binary);

        if ($result) {
            // another client acts on the original once the copy has arrived;
            // the answer of the APPEND stays the last one Roundcube has
            $answer = $this->conn->result;
            $gone = edit_subject_testkit_ctl::uid_list(edit_subject_testkit_ctl::get('expunge_after_append_uids', []));
            $moved = edit_subject_testkit_ctl::uid_list(edit_subject_testkit_ctl::get('move_after_append_uids', []));

            if ($gone) {
                $this->conn->flag($folder, implode(',', $gone), 'DELETED');
                $this->conn->expunge($folder, implode(',', $gone));
            }

            if ($moved) {
                $this->conn->move(implode(',', $moved), $folder, (string) edit_subject_testkit_ctl::get('move_to', 'Archiv'));
            }

            $this->conn->result = $answer;
        }

        if ($result && edit_subject_testkit_ctl::get('lose_append_answer', false)) {
            return false;
        }

        return $result;
    }

    public function delete_message($uids, $folder = null)
    {
        $list = edit_subject_testkit_ctl::uid_list($uids);

        if (array_intersect($list, edit_subject_testkit_ctl::get('refuse_delete_uids', []))) {
            return false;
        }

        return parent::delete_message($uids, $folder);
    }

    public function expunge_message($uids, $folder = null, $clear_cache = true)
    {
        $list = edit_subject_testkit_ctl::uid_list($uids);

        if (array_intersect($list, edit_subject_testkit_ctl::get('noop_expunge_uids', []))) {
            return true;
        }

        return parent::expunge_message($uids, $folder, $clear_cache);
    }

    public function unset_flag($uids, $flag, $folder = null)
    {
        $list = edit_subject_testkit_ctl::uid_list($uids);

        if (strtoupper((string) $flag) == 'DELETED'
            && array_intersect($list, edit_subject_testkit_ctl::get('vanish_before_unflag_uids', []))
        ) {
            // what the EXPUNGE of another client does to a flagged message
            $this->conn->expunge($folder, implode(',', $list));
        }

        return parent::unset_flag($uids, $flag, $folder);
    }
}
