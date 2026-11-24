<?php

/**
 * Roundcube configuration for the edit_subject test environment.
 *
 * Included by the official image from /var/roundcube/config. The runner
 * reaches the same Roundcube container under several host names; each name
 * selects one test scenario. Nothing in here belongs on a real server.
 */

// Logs and temp files go to volumes the test runner can read.
$config['log_driver'] = 'file';
$config['log_dir'] = '/var/roundcube/logs/';
$config['temp_dir'] = '/var/roundcube/temp/';

$config['messages_cache'] = getenv('EDIT_SUBJECT_MESSAGES_CACHE') === '1';

// Keep the defaults of the plugin unless a scenario below changes them.
$config['edit_subject_keep_original'] = false;

$es_test_host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));

switch ($es_test_host) {
    // Storage driver from the edit_subject_testkit plugin that can simulate
    // refused deletions, no-op expunges and failing appends.
    case 'rc-sim':
        $config['storage_driver'] = 'estest';
        break;

    // Same server as "rc", but Roundcube ignores the ACL capability, so the
    // plugin cannot check the rights in advance and the server-side ACL
    // decides when the operations are executed.
    case 'rc-noacl':
        $config['imap_disabled_caps'] = ['ACL'];
        break;

    // Server without UIDPLUS in the CAPABILITY list.
    case 'rc-nouidplus':
        $config['imap_host'] = 'dovecot-nouidplus:31143';
        break;

    // Server with a 256 KiB quota for every account.
    case 'rc-quota':
        $config['imap_host'] = 'dovecot-quota:31143';
        break;

    // Plugin option edit_subject_keep_original enabled.
    case 'rc-keep':
        $config['edit_subject_keep_original'] = true;
        break;

    // Small complete-message limit for the HTTP-level refusal test, written
    // with a unit as Roundcube's own size options take it (1024 bytes).
    case 'rc-small':
        $config['edit_subject_max_message_size'] = '1K';
        break;

    // A limit that is no size: the default applies, the error log says so.
    case 'rc-badsize':
        $config['edit_subject_max_message_size'] = '1.5M';
        break;

    // Roundcube empties the capability list of the connection after every
    // login (option for IMAP proxies). Its session still remembers UIDPLUS;
    // unless the plugin asks the connection, Roundcube expunges with a plain
    // EXPUNGE from the second edit of a session on (measured with 1.6.21 and
    // 1.7.6). imap_debug must stay off here: it makes Roundcube load the
    // list for its ID command and hides the effect.
    case 'rc-forcecaps':
        $config['imap_force_caps'] = true;
        break;
}

unset($es_test_host);
