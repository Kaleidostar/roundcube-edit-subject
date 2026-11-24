<?php

/**
 * Edit subject
 *
 * Lets users change the subject of an existing message. IMAP messages are
 * immutable, so the message is replaced on the server: a modified copy is
 * stored in the same folder (APPEND) and verified, only then the original
 * is deleted. Requires the IMAP extension UIDPLUS (RFC 4315).
 *
 * Configuration (see config.inc.php.dist):
 *   edit_subject_keep_original   - keep the first subject as X-Original-Subject
 *   edit_subject_max_message_size - maximum complete message size in bytes
 *
 * Copyright (C) 2026 Kaleidostar
 *
 * @license GNU GPLv3+
 * @author  Kaleidostar
 */

require_once __DIR__ . '/lib/edit_subject_header.php';
require_once __DIR__ . '/lib/edit_subject_engine.php';
require_once __DIR__ . '/lib/edit_subject_storage.php';

class edit_subject extends rcube_plugin
{
    public $task = 'mail';

    /** @var rcmail */
    private $rc;

    /** Client callback of the current action */
    private $callback = 'plugin.edit_subject_done';

    /**
     * Plugin initialization
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();

        $this->load_config();

        $this->register_action('plugin.edit_subject', [$this, 'action_save']);
        $this->register_action('plugin.edit_subject_load', [$this, 'action_load']);

        if ($this->rc->action == '' || $this->rc->action == 'show' || $this->rc->action == 'preview') {
            $this->add_texts('localization/', true);
            $this->include_script('edit_subject.js');
            $this->include_stylesheet($this->local_skin_path() . '/edit_subject.css');

            $this->add_button([
                'command' => 'plugin.edit_subject',
                'type' => 'link-menuitem',
                'label' => 'edit_subject.editsubject',
                'class' => 'edit-subject disabled',
                'classact' => 'edit-subject active',
            ], 'messagemenu');

            $this->rc->output->set_env('edit_subject_max_length', edit_subject_header::MAX_LENGTH);
        }
    }

    /**
     * Return the current (decoded) subject of a message for the dialog
     */
    public function action_load()
    {
        $this->callback = 'plugin.edit_subject_loaded';
        $this->add_texts('localization/');
        $this->check_request();

        [$uid, $mbox] = $this->message_id();

        $result = $this->engine()->load($mbox, $uid);

        if ($result['status'] != edit_subject_engine::STATUS_OK) {
            $vars = [];

            if ($result['code'] == edit_subject_engine::ERR_MESSAGE_TOO_LARGE) {
                $vars['max'] = (int) $result['detail'];
            }

            $this->reply_error($result['code'], $uid, $mbox, 400, $vars);
        }

        $this->rc->output->command('plugin.edit_subject_loaded', [
            'status' => edit_subject_engine::STATUS_OK,
            'uid' => $uid,
            'mbox' => $mbox,
            'subject' => $result['subject'],
        ]);
        $this->rc->output->send();
    }

    /**
     * Replace the subject of a message
     */
    public function action_save()
    {
        $this->add_texts('localization/');
        $this->check_request();

        [$uid, $mbox] = $this->message_id();

        // raw input: rcube_utils would strip tags and drop invalid characters
        $subject = $_POST['_subject'] ?? null;

        if (!is_string($subject)) {
            $this->reply_error(edit_subject_engine::ERR_INVALID_SUBJECT, $uid, $mbox);
        }

        // The subject the dialog was opened with. It binds the request to
        // the message the user looked at: a UID alone would also fit another
        // message, in another folder or after someone else replaced this one.
        $expected = $_POST['_expected'] ?? null;

        if (!is_string($expected)) {
            $this->reply_error('invalidrequest', $uid, $mbox);
        }

        $keep = (bool) $this->rc->config->get('edit_subject_keep_original', false);
        $result = $this->engine()->run($mbox, $uid, $subject, $keep, $expected);

        $data = [
            'status' => $result['status'],
            'code' => $result['code'],
            'uid' => $result['uid'],
            'old_uid' => $uid,
            'mbox' => $mbox,
            'subject' => $result['subject'],
        ];

        if ($result['status'] == edit_subject_engine::STATUS_OK) {
            $this->after_success($result['uid'], $mbox, $data);
            $this->rc->output->show_message('edit_subject.subjectchanged', 'confirmation');
        } elseif ($result['status'] == edit_subject_engine::STATUS_ERROR) {
            $this->show_error($result);
        }

        $this->rc->output->command('plugin.edit_subject_done', $data);
        $this->rc->output->send();
    }

    /**
     * Update the session state and the client list after a replacement
     */
    private function after_success($new_uid, $mbox, &$data)
    {
        $storage = $this->rc->storage;

        // the saved search result still holds the old UID
        if (rcube_utils::get_input_string('_search', rcube_utils::INPUT_POST) && $storage->get_search_set()) {
            $_SESSION['search'] = $storage->refresh_search();
        }

        // new list row for the flat message list, the client puts it at the
        // position of the old one (threads are reloaded by the client)
        if (!empty($_POST['_list'])) {
            $header = $storage->get_message_headers($new_uid, $mbox, true);

            if ($header) {
                rcmail_action_mail_index::js_message_list([$header], false);
                // in multi-folder results the list ID has the folder appended
                $data['list_uid'] = $header->uid;
            }
        }
    }

    private function show_error($result)
    {
        $code = $result['code'];
        $vars = [];

        if ($code == edit_subject_engine::ERR_TOO_LONG) {
            $vars['max'] = edit_subject_header::MAX_LENGTH;
        } elseif ($code == edit_subject_engine::ERR_MESSAGE_TOO_LARGE) {
            $vars['max'] = (int) $result['detail'];
        } elseif ($code == edit_subject_engine::ERR_APPEND) {
            if ($this->rc->storage->get_response_code() == rcube_storage::OVERQUOTA) {
                $code = 'erroroverquota';
            }
            $vars['detail'] = $result['detail'] ?: '-';
        } elseif ($code == edit_subject_engine::ERR_NO_RIGHTS) {
            $vars['rights'] = $result['detail'];
        }

        $this->rc->output->show_message('edit_subject.' . $code, 'error', $vars, true, 15);
    }

    /**
     * Stop unless this is a request of the plugin's own client code: POST,
     * valid request token, AJAX, and no "_uid" cookie.
     *
     * Roundcube's own check (rcube::check_request) lets GET requests and
     * POST requests without data pass, so nothing here is redundant.
     */
    private function check_request()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->reply_error('invalidrequest', null, null, 405);
        }

        $token = rcube_utils::request_header('X-Roundcube-Request');

        if (!is_string($token) || $token === '') {
            $token = rcube_utils::get_input_string('_token', rcube_utils::INPUT_POST);
        }

        if (!is_string($token) || $token === '' || !hash_equals((string) $this->rc->get_request_token(), $token)) {
            $this->reply_error('invalidrequest', null, null, 403);
        }

        // Roundcube's mail task reads _uid from cookies as well (the cookie
        // wins) and rewrites _uid and _mbox of the POST data with it. A
        // cookie must never choose the message. And only the plugin's own
        // client code (AJAX) is served, there is no page to render.
        if (isset($_COOKIE['_uid']) || empty($this->rc->output->ajax_call)) {
            $this->reply_error('invalidrequest', null, null, 400);
        }
    }

    /**
     * Validated UID and folder of the request
     *
     * In multi-folder search results the client sends "<uid>-<folder>",
     * Roundcube's mail action handler splits that into _uid and _mbox.
     */
    private function message_id()
    {
        $uid = rcube_utils::get_input_string('_uid', rcube_utils::INPUT_POST);
        $mbox = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST, true);

        // "D": no line feed after the digits; the cast must not change the
        // value (32 bit PHP saturates above 2147483647)
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $uid) || (string) (int) $uid !== $uid || (int) $uid > 4294967295) {
            $this->reply_error('errorinvaliduid');
        }

        if (!strlen($mbox) || !$this->rc->storage->folder_exists($mbox)) {
            $this->reply_error('errorinvalidfolder', (int) $uid);
        }

        return [(int) $uid, $mbox];
    }

    private function engine()
    {
        $storage = new edit_subject_storage($this->rc->get_storage());
        $logger = function ($message) {
            rcube::raise_error([
                'code' => 600,
                'file' => __FILE__,
                'line' => __LINE__,
                // folder names and server answers must not start new log lines
                'message' => 'edit_subject: ' . preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $message),
            ], true, false);
        };

        // the lock of a message is per mail account
        $scope = ($this->rc->user->ID ?? '') . '@' . ($_SESSION['storage_host'] ?? '');

        // bytes or "64M"; anything else must not silently become another
        // limit ((int) "64M" would be 64 bytes, (int) 1e20 no limit at all)
        $configured = $this->rc->config->get('edit_subject_max_message_size');
        $max_size = $configured === null ? edit_subject_engine::MAX_MESSAGE_SIZE : edit_subject_engine::size_limit($configured);

        if ($max_size === null) {
            $logger('edit_subject_max_message_size ' . var_export($configured, true)
                . ' is no size, using the default of ' . edit_subject_engine::MAX_MESSAGE_SIZE . ' bytes');
            $max_size = edit_subject_engine::MAX_MESSAGE_SIZE;
        }

        return new edit_subject_engine($storage, $this->rc->config->get('temp_dir'),
            RCUBE_TEMP_FILE_PREFIX . 'edit_subject', $logger, $scope, $max_size);
    }

    /**
     * Send an error response and stop
     *
     * @param string      $code Error code (localization key)
     * @param int|null    $uid  UID of the message, if known
     * @param string|null $mbox Folder of the message, if known
     * @param int        $http HTTP status for requests that are not AJAX
     * @param array|null $vars Variables for the localized message
     */
    private function reply_error($code, $uid = null, $mbox = null, $http = 400, $vars = null)
    {
        $label = $code == 'invalidrequest' ? 'invalidrequest' : 'edit_subject.' . $code;

        // A plain page request does not come from the plugin's client code;
        // there is no page to render, just refuse it.
        if (empty($this->rc->output->ajax_call)) {
            http_response_code($http);
            if ($http == 405) {
                header('Allow: POST');
            }
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            echo $this->rc->gettext($label);
            exit;
        }

        $this->rc->output->show_message($label, 'error', $vars, true, 15);
        $this->rc->output->command($this->callback, [
            'status' => edit_subject_engine::STATUS_ERROR,
            'code' => $code,
            'uid' => $uid,
            'old_uid' => $uid,
            'mbox' => $mbox,
            'subject' => null,
        ]);
        $this->rc->output->send();
    }
}

