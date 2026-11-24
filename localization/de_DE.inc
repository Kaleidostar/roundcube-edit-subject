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
$labels['editsubject'] = 'Betreff bearbeiten';
$labels['dialogtitle'] = 'Betreff bearbeiten';
$labels['newsubject'] = 'Betreff';
$labels['replacenotice'] = 'Die Mail wird auf dem Server durch eine geänderte Fassung ersetzt.';
$labels['loading'] = 'Betreff wird geladen...';
$labels['saving'] = 'Betreff wird gespeichert...';

$messages = [];
$messages['subjectchanged'] = 'Betreff geändert.';
$messages['errorinvaliduid'] = 'Ungültige Nachrichten-ID. Es wurde nichts geändert.';
$messages['errorinvalidfolder'] = 'Der Ordner existiert nicht oder ist nicht zugänglich. Es wurde nichts geändert.';
$messages['errornotfound'] = 'Die Mail wurde nicht gefunden. Möglicherweise wurde sie verschoben oder gelöscht.';
$messages['errorinvalidsubject'] = 'Der Betreff enthält ungültige Zeichen. Es wurde nichts geändert.';
$messages['errortoolong'] = 'Der Betreff ist zu lang (höchstens $max Zeichen). Es wurde nichts geändert.';
$messages['errorheadertoolarge'] = 'Der Kopfbereich dieser Mail ist zu groß zum Bearbeiten. Es wurde nichts geändert.';
$messages['errornouidplus'] = 'Der Mailserver unterstützt die IMAP-Erweiterung UIDPLUS nicht. Ohne sie lässt sich der Betreff nicht sicher ändern. Es wurde nichts geändert.';
$messages['errornorights'] = 'In diesem Ordner fehlt die Berechtigung, Mails abzulegen und zu löschen (fehlende Rechte: $rights). Es wurde nichts geändert.';
$messages['errortempfile'] = 'Der Betreff konnte wegen eines Problems mit einer temporären Datei nicht geändert werden. Die Mail ist unverändert.';
$messages['errorfetch'] = 'Die Mail konnte nicht vollständig gelesen werden. Sie ist unverändert.';
$messages['errorappend'] = 'Die geänderte Fassung konnte nicht auf dem Server gespeichert werden ($detail). Die ursprüngliche Mail ist unverändert.';
$messages['erroroverquota'] = 'Das Postfach ist voll, die geänderte Fassung konnte nicht gespeichert werden. Die ursprüngliche Mail ist unverändert.';
$messages['errorverify'] = 'Der Server hat die geänderte Fassung fehlerhaft gespeichert, sie wurde deshalb wieder entfernt. Die ursprüngliche Mail ist unverändert.';
$messages['errordelete'] = 'Die ursprüngliche Mail ließ sich nicht entfernen, die Änderung wurde deshalb zurückgenommen. Die Mail ist unverändert.';
$messages['errorbothexist'] = 'Die Änderung ließ sich nicht abschließen und nicht zurücknehmen. Im Ordner liegen jetzt beide Fassungen der Mail.';
$messages['erroruncertain'] = 'Das Ergebnis ließ sich nicht prüfen. Bitte im Ordner nachsehen: Die geänderte Fassung wurde behalten, die ursprüngliche Mail ist möglicherweise ebenfalls noch vorhanden.';
$messages['errorunknown'] = 'Das Ergebnis ließ sich nicht prüfen. Bitte im Ordner nachsehen, welche Fassungen der Mail noch vorhanden sind.';
$messages['errorchanged'] = 'Die Mail auf dem Server ist nicht mehr die, die im Dialog stand: Sie wurde inzwischen geändert, verschoben oder gelöscht. Es wurde nichts geändert.';
$messages['errormessagetoolarge'] = 'Die Mail überschreitet das konfigurierte Größenlimit von $max Byte. Es wurde nichts geändert.';
$messages['errorbusy'] = 'Eine andere Betreffänderung läuft für dieses Konto bereits. Bitte später erneut versuchen. Es wurde nichts geändert.';
$messages['errorreadonly'] = 'Der Ordner ist nur zum Lesen geöffnet. Es wurde nichts geändert.';
$messages['errorappendunknown'] = 'Der Server hat das Speichern der geänderten Fassung nicht bestätigt. Die ursprüngliche Mail ist unverändert. Bitte im Ordner nachsehen, ob die geänderte Fassung zusätzlich angelegt wurde.';
$messages['errorlost'] = 'Nach dem Ersetzen ist die Mail im Ordner nicht mehr zu finden, weder die ursprüngliche noch die geänderte Fassung.';
$messages['errorcopygone'] = 'Die geänderte Fassung wurde von anderer Seite entfernt oder verschoben, bevor sie das Original ersetzen konnte. Die ursprüngliche Mail ist unverändert.';
$messages['errorcopychanged'] = 'Die geänderte Fassung wurde von anderer Seite verändert, bevor sie das Original ersetzen konnte, und deshalb wieder entfernt. Die ursprüngliche Mail ist unverändert.';
