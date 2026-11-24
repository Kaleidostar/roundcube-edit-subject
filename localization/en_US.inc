<?php

/*
 +-----------------------------------------------------------------------+
 | Localization file of the Roundcube Webmail Edit Subject plugin        |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version.                                                    |
 +-----------------------------------------------------------------------+
*/

$labels = [];
$labels['editsubject'] = 'Edit subject';
$labels['dialogtitle'] = 'Edit subject';
$labels['newsubject'] = 'Subject';
$labels['replacenotice'] = 'The message is replaced on the server by a modified copy.';
$labels['loading'] = 'Loading subject...';
$labels['saving'] = 'Saving subject...';

$messages = [];
$messages['subjectchanged'] = 'Subject changed.';
$messages['errorinvaliduid'] = 'Invalid message ID. Nothing was changed.';
$messages['errorinvalidfolder'] = 'The folder does not exist or is not accessible. Nothing was changed.';
$messages['errornotfound'] = 'The message was not found. It may have been moved or deleted.';
$messages['errorinvalidsubject'] = 'The subject contains invalid characters. Nothing was changed.';
$messages['errortoolong'] = 'The subject is too long (at most $max characters). Nothing was changed.';
$messages['errorheadertoolarge'] = 'The header of this message is too large to be edited. Nothing was changed.';
$messages['errornouidplus'] = 'The mail server does not support the IMAP extension UIDPLUS. Without it the subject cannot be changed safely. Nothing was changed.';
$messages['errornorights'] = 'You are not allowed to store and delete messages in this folder (missing rights: $rights). Nothing was changed.';
$messages['errortempfile'] = 'The subject could not be changed because of a problem with a temporary file. The message is unchanged.';
$messages['errorfetch'] = 'The message could not be read completely. It is unchanged.';
$messages['errorappend'] = 'The changed message could not be stored on the server ($detail). The original message is unchanged.';
$messages['erroroverquota'] = 'The mailbox is full, the changed message could not be stored. The original message is unchanged.';
$messages['errorverify'] = 'The server stored the changed message incorrectly, so it was removed again. The original message is unchanged.';
$messages['errordelete'] = 'The original message could not be removed, so the change was undone. The message is unchanged.';
$messages['errorbothexist'] = 'The change could not be completed and could not be undone. The folder now contains both versions of the message.';
$messages['erroruncertain'] = 'The result could not be checked. Please look into the folder: the changed message was kept, the original message may still be there as well.';
$messages['errorunknown'] = 'The result could not be checked. Please look into the folder to see which versions of the message are still present.';
$messages['errorchanged'] = 'The message on the server is no longer the one shown in the dialog: it has been changed, moved or deleted in the meantime. Nothing was changed.';
$messages['errormessagetoolarge'] = 'The message exceeds the configured size limit of $max bytes. Nothing was changed.';
$messages['errorbusy'] = 'Another subject edit is already running for this account. Please try again later. Nothing was changed.';
$messages['errorreadonly'] = 'The folder is opened read-only. Nothing was changed.';
$messages['errorappendunknown'] = 'The server did not confirm that the changed message was stored. The original message is unchanged. Please check the folder for an additional changed copy.';
$messages['errorlost'] = 'After the replacement the message can no longer be found in the folder, neither the original nor the changed one.';
$messages['errorcopygone'] = 'The changed message was removed or moved by someone else before it could replace the original. The original message is unchanged.';
$messages['errorcopychanged'] = 'The changed message was modified by someone else before it could replace the original, so it was removed again. The original message is unchanged.';
