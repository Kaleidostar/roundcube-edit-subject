/**
 * Edit subject plugin script
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) 2026 Kaleidostar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * @licend  The above is the entire license notice
 * for the JavaScript code in this file.
 */

window.rcmail && rcmail.addEventListener('init', function () {
    var command = 'plugin.edit_subject';

    rcmail.register_command(command, function () {
        rcmail.edit_subject_open();
    }, false);

    if (rcmail.env.action == 'show' || rcmail.env.action == 'preview') {
        rcmail.enable_command(command, !!rcmail.env.uid);
    } else if (rcmail.message_list) {
        // enabled for exactly one selected message, like "View source"
        rcmail.env.message_commands.push(command);
        rcmail.enable_command(command, rcmail.message_list.get_single_selection() != null);

        // select the edited message again after a list reload (threads)
        rcmail.addEventListener('listupdate', function () {
            rcmail.edit_subject_select_pending();
        });
    }

    rcmail.addEventListener('plugin.edit_subject_loaded', function (data) {
        rcmail.edit_subject_dialog(data);
    });
    rcmail.addEventListener('plugin.edit_subject_done', function (data) {
        rcmail.edit_subject_done(data);
    });

    // Other Roundcube windows of the same origin (e.g. the list behind a
    // message opened in its own window) learn about replaced messages.
    // Links with target="_blank" open without window.opener in current
    // browsers, so the opener cannot always be reached directly.
    if (window.BroadcastChannel) {
        rcmail.edit_subject_channel = new BroadcastChannel('roundcube.edit_subject');
        rcmail.edit_subject_channel.onmessage = function (e) {
            rcmail.edit_subject_replaced(e.data);
        };
    }
});

/**
 * Tell other windows that a message was replaced
 */
rcmail.edit_subject_notify = function (data) {
    var msg = { user: this.env.user_id, old_uid: data.old_uid, uid: data.uid, mbox: data.mbox },
        opener;

    if (this.edit_subject_channel) {
        this.edit_subject_channel.postMessage(msg);
    } else if ((opener = this.opener(true)) && opener.edit_subject_replaced) {
        opener.edit_subject_replaced(msg);
    }
};

/**
 * Ask the server for the current (decoded) subject, then open the dialog
 */
rcmail.edit_subject_open = function () {
    var uid = this.get_single_uid();

    if (!uid) {
        return;
    }

    // One request at a time. The answer is only used if it belongs to this
    // message and the user is still in the folder he started from.
    this.edit_subject_state = { id: uid, mbox: this.get_message_mailbox(uid), view: this.env.mailbox };

    this.http_post('plugin.edit_subject_load', this.selection_post_data({ _uid: uid }),
        this.set_busy(true, 'edit_subject.loading'));
};

/**
 * The dialog with the subject field
 */
rcmail.edit_subject_dialog = function (data) {
    var ref = this,
        state = this.edit_subject_state;

    // The list ID is "<uid>" or, in results from several folders,
    // "<uid>-<folder>". An answer for another message is not the one this
    // window waits for.
    if (!data || !state || String(data.uid) !== String(state.id).split('-')[0] || data.mbox !== state.mbox) {
        return;
    }

    this.edit_subject_state = null;

    // no dialog after an error, or if the user moved on to another folder
    // while the answer was on its way
    if (data.status != 'ok' || this.env.mailbox !== state.view) {
        return;
    }

    // Everything the dialog saves comes from the answer it was opened with
    var mail = { id: state.id, uid: data.uid, mbox: data.mbox, original: data.subject };

    var input = $('<input>').attr({
            type: 'text',
            id: 'edit-subject-input',
            'class': 'form-control',
            'data-submit': 'true',
            autocomplete: 'off',
            spellcheck: 'true',
            dir: 'auto',
            'aria-describedby': 'edit-subject-notice'
        }).val(data.subject),
        content = $('<div class="edit-subject-dialog">').append(
            $('<label>').attr('for', 'edit-subject-input').text(this.get_label('newsubject', 'edit_subject')),
            input,
            $('<p class="hint">').attr('id', 'edit-subject-notice').text(this.get_label('replacenotice', 'edit_subject'))
        );

    var save = function () {
        var value = input.val();

        // unchanged: close the dialog, nothing happens on the server
        if (value === mail.original) {
            return true;
        }

        if (ref.edit_subject_length(value) > ref.env.edit_subject_max_length) {
            ref.display_message(ref.get_label('errortoolong', 'edit_subject')
                .replace('$max', ref.env.edit_subject_max_length), 'error');
            input.trigger('focus');
            return false;
        }

        var post = ref.selection_post_data({ _uid: mail.id });

        // UID and folder as the server named them when the dialog was
        // opened, not the folder shown now; the server changes the message
        // only if its subject still is the one the dialog started with
        post._uid = mail.uid;
        post._mbox = mail.mbox;
        post._expected = mail.original;
        post._subject = value;

        // flat list: the server sends the new list row
        if (ref.message_list && ref.env.action != 'show' && ref.env.action != 'preview' && !ref.env.threading) {
            post._list = 1;
        }

        ref.edit_subject_save_state = {
            uid: mail.uid,
            mbox: mail.mbox,
            view: ref.env.mailbox,
            search: ref.env.search_request || null,
            multi: !!ref.env.multifolder_listing,
            action: ref.env.action,
            open_uid: ref.env.uid || null
        };

        ref.http_post('plugin.edit_subject', post, ref.set_busy(true, 'edit_subject.saving'));

        return true;
    };

    this.simple_dialog(content, 'edit_subject.dialogtitle', save, {
        button: 'save',
        open: function () {
            input.trigger('focus').trigger('select');
        }
    });
};

/**
 * Number of characters (code points) after the same clean-up as on the server
 */
rcmail.edit_subject_length = function (value) {
    // trim(), not /^\s+|\s+$/: that expression is tried again at every
    // position of a white space run and blocks the page for seconds
    value = String(value).replace(/[\u0000-\u001F\u007F-\u009F]/g, ' ').trim();

    return Array.from(value).length;
};

/**
 * Server response of the save action
 */
rcmail.edit_subject_done = function (data) {
    var refresh = ['errorbothexist', 'erroruncertain', 'errorunknown', 'errorchanged', 'errorappendunknown', 'errorlost', 'errornotfound',
            'errorcopygone', 'errorcopychanged'],
        state = this.edit_subject_save_state,
        here = !!data && this.edit_subject_shows(data.mbox),
        current = false;

    this.edit_subject_save_state = null;

    // The answer may arrive late. Folder, search and view generation must all
    // still be the ones from which this save was sent. A UID alone would fit
    // another search result or a newly selected message as well.
    if (data && state) {
        current = String(data.old_uid) === String(state.uid) && data.mbox === state.mbox
            && this.env.mailbox === state.view && (this.env.search_request || null) === state.search
            && !!this.env.multifolder_listing === state.multi && this.env.action === state.action
            && (this.env.uid || null) === state.open_uid;
    }

    if (!data || data.status != 'ok') {
        // the folder may look different now: show it
        if (here && refresh.indexOf(data.code) >= 0 && this.message_list) {
            this.refresh_list();
        }
        return;
    }

    this.edit_subject_notify(data);

    if (this.env.action == 'show' || this.env.action == 'preview') {
        if (!current) {
            return;
        }

        // show the new version, replace() keeps the old UID out of the history
        location.replace(this.url('show', {
            _uid: data.uid,
            _mbox: data.mbox,
            _extwin: this.env.extwin ? 1 : null,
            _search: this.env.search_request || null
        }));
        return;
    }

    if (here && !current && this.message_list) {
        // The server may already have sent a row for the old list context.
        // Reload without a pending selection instead of touching the new one.
        this.refresh_list();
        return;
    }

    if (current) {
        this.edit_subject_replace_row(this.edit_subject_list_id(data.old_uid, data.mbox), data);
    }
};

/**
 * Whether the list of this window shows the given folder (results from
 * several folders show all of them, their rows carry the folder in the ID)
 */
rcmail.edit_subject_shows = function (mbox) {
    return !!this.env.multifolder_listing || mbox === this.env.mailbox;
};

/**
 * Remember a message that is to be selected after the list of the folder
 * shown now has been reloaded
 */
rcmail.edit_subject_remember = function (id) {
    this.edit_subject_pending = {
        id: id,
        mbox: this.env.mailbox,
        multi: !!this.env.multifolder_listing,
        search: this.env.search_request || null,
        action: this.env.action
    };
};

/**
 * ID of a message in the list: the UID, in results from several folders
 * followed by "-" and the folder
 */
rcmail.edit_subject_list_id = function (uid, mbox) {
    return this.env.multifolder_listing ? uid + '-' + mbox : String(uid);
};

/**
 * Whether a list holds a row with this ID (list.rows is a plain object,
 * names like "__proto__" are no rows)
 */
rcmail.edit_subject_has_row = function (list, id) {
    return Object.prototype.hasOwnProperty.call(list.rows, id);
};

/**
 * Put the new message at the place of the old one in the message list
 */
rcmail.edit_subject_replace_row = function (old_id, data) {
    var list = this.message_list,
        new_id = data.list_uid || data.uid;

    if (!list) {
        return;
    }

    var old_row = this.edit_subject_has_row(list, old_id) ? list.rows[old_id] : null,
        new_row = data.list_uid && this.edit_subject_has_row(list, new_id) ? list.rows[new_id] : null,
        selected = old_row ? list.in_selection(old_id) : false;

    if (old_row && new_row && old_row.obj.parentNode) {
        // the server added the new row at the end of the list, move it
        old_row.obj.parentNode.insertBefore(new_row.obj, old_row.obj);
        list.remove_row(old_id);
        $(old_row.obj).remove();
        delete this.env.messages[old_id];

        if (selected) {
            list.select(new_id);
        }
        return;
    }

    // thread view or row not found: reload the list and select the message
    this.edit_subject_remember(new_id);
    this.refresh_list();
};

/**
 * A message was replaced in another window: reload the list if it shows
 * the old message, so that no orphaned entry remains
 */
rcmail.edit_subject_replaced = function (data) {
    var list = this.message_list,
        is_uid = function (v) {
            return (typeof v == 'number' || typeof v == 'string') && /^[1-9][0-9]{0,9}$/.test(String(v));
        },
        old_id;

    // The channel is open to every page of this origin: take nothing but
    // the expected types, and only for the user of this window
    if (!list || !data || typeof data != 'object' || !is_uid(data.old_uid) || !is_uid(data.uid)
        || typeof data.mbox != 'string' || typeof data.user != 'string' || data.user !== this.env.user_id
        || (!this.env.multifolder_listing && data.mbox !== this.env.mailbox)
    ) {
        return;
    }

    old_id = this.edit_subject_list_id(data.old_uid, data.mbox);

    if (this.edit_subject_has_row(list, old_id)) {
        if (list.in_selection(old_id)) {
            this.edit_subject_remember(this.edit_subject_list_id(data.uid, data.mbox));
        }
        this.refresh_list();
    }
};

/**
 * Select the edited message after the list was reloaded
 */
rcmail.edit_subject_select_pending = function () {
    var list = this.message_list,
        pending = this.edit_subject_pending,
        id, row, parent;

    if (!pending || !list) {
        return;
    }

    this.edit_subject_pending = null;
    id = pending.id;

    // only in the list it was remembered for: in another folder or search
    // the same UID is another message, and selecting it would mark it as read
    if (pending.mbox !== this.env.mailbox || pending.multi !== !!this.env.multifolder_listing
        || (pending.search || null) !== (this.env.search_request || null) || pending.action !== this.env.action
        || !this.edit_subject_has_row(list, id) || !(row = list.rows[id])
    ) {
        return;
    }

    // open collapsed threads around the message
    while (row && row.parent_uid && this.edit_subject_has_row(list, row.parent_uid) && (parent = list.rows[row.parent_uid])) {
        if (!parent.expanded) {
            list.expand(parent);
        }
        row = parent;
    }

    list.select(id);
};
