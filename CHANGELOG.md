# Changelog

Alle nennenswerten Änderungen an diesem Projekt stehen in dieser Datei.
Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionsnummern folgen [Semantic Versioning](https://semver.org/lang/de/).

## [Unveröffentlicht]

## [1.0.3] – 2026-11-24

Härtung nach 1.0.2 und Korrekturen aus dem dritten Sicherheitsaudit vom
23.11.2026. Das ist die erste veröffentlichte Version; 1.0.0 bis 1.0.2 waren
interne Stände und wurden nie veröffentlicht.

### Sicherheit

- Die Kopie wird mit exakt denselben Flags verifiziert, zusätzliche Flags –
  insbesondere `\Deleted` – führen zum Abbruch. Die Flag-Menge wird unmittelbar
  vor dem Löschen des Originals noch einmal geprüft; hat ein anderes Programm
  sie geändert, wird die Kopie zurückgenommen, mit eigener Meldung.
- Nach einer fehlgeschlagenen Prüfung der Kopie wird das Original erneut
  gelesen. Hat ein anderes Programm es inzwischen geändert, verschoben oder
  gelöscht, sagt die Meldung das, statt die Mail „unverändert“ zu nennen. Was
  das andere Programm getan hat, bleibt wie bisher bestehen: Die Kopie wird
  zurückgenommen, auch wenn sie danach die einzige Fassung wäre.
- Ist das Original fort und lässt sich die Kopie weder finden noch als
  entfernt bestätigen, meldet das Plugin einen unbekannten Ausgang, statt zu
  behaupten, die geänderte Fassung sei behalten.
- Ein Fehler von `flock()` führt nicht mehr zu ungeschütztem Weiterarbeiten.
  Das Plugin meldet dann ein Problem mit einer temporären Datei und nennt den
  Grund im Fehlerlog. Die Sperre gilt jetzt je Konto und Host für alle
  gleichzeitigen Bearbeitungen, wodurch auch der Temp-Verbrauch auf einen
  Vorgang begrenzt ist.
- Sendet der Server mehr Körper, als RFC822.SIZE ankündigt, gelangt höchstens
  ein Byte mehr in die Temp-Datei, und die Bearbeitung bricht ab. Bisher
  konnte ein IMAP-Server, den ein Nutzer bei freier Serverwahl am Login
  angibt, das Temp-Verzeichnis beliebig füllen.
- Der gespeicherte Körper wird per SHA-256 mit dem gesendeten Körper verglichen,
  nicht mehr nur über seine Länge. Die Prüfung verwendet weiterhin konstanten
  Speicher und dieselbe Temp-Datei.
- Mit `edit_subject_keep_original` konnte ein sehr langer Originalbetreff das
  PHP-Speicherlimit erreichen. Die Faltung arbeitet jetzt ohne Kandidatenarray
  mit konstantem Zusatzspeicher.
- Neue konfigurierbare Grenze `edit_subject_max_message_size` für die komplette
  Mail (Kopf und Körper), standardmäßig 64 MiB. Größere Mails werden vor dem
  Abruf abgelehnt. Angaben wie `'64M'` werden verstanden; was keine Größe ist,
  etwa `'1.5M'` oder `true`, ergibt den Standard und einen Logeintrag.
- Späte Speicherantworten werden auch an Suche, Ansicht und geöffneter Nachricht
  gebunden, nicht nur an den Ordner. Bei einem Kontextwechsel lädt der Client
  ohne vorgemerkte Auswahl neu, statt eine veraltete Zeilenoperation auszuführen.
- Die Testabhängigkeiten `requests` und `pytest` wurden auf 2.33.0 beziehungsweise
  9.0.3 aktualisiert. `requests` 2.33.0 enthält die Korrekturen zu
  CVE-2024-47081 (seit 2.32.4) und CVE-2026-25645. Für CVE-2025-71176 führt
  OSV `pytest` bis 9.0.2 als betroffen, eine behobene Version nennt es nicht.
  `requests` ignoriert Proxy-/Netrc-Umgebung in den internen Tests.
- Release-ZIP und Prüfsumme erhalten eine SLSA-Build-Provenance über Sigstore.
  Die README zeigt die Prüfung, gebunden an den Workflow `Release` und den Tag.

### Geändert

- Basis-Images sind per Digest festgelegt; Composer besitzt ein Lockfile,
  Python vollständige Versions- und Hash-Sperren, APT einen festen
  Debian-Snapshot. Dependabot überwacht alle diese Manifeste.
- Die Teststeuerung wertet Fehler beim Aufräumen und bei den Endabfragen als
  fehlgeschlagenen Lauf aus. Fehlerlogs und Browserfehler werden strenger
  kontrolliert; zusätzliche nicht deklarierte Fehler lassen Tests scheitern.
- Die deutsche Quota-Meldung, das separate Fenster und späte Antworten nach
  einer neuen Suche haben zusätzliche Server- beziehungsweise Browserprüfungen.

## [1.0.2] – 2026-11-23

Korrekturen aus dem zweiten Sicherheitsaudit vom 22./23.11.2026. Interner
Stand, nicht veröffentlicht.

### Sicherheit

- Scheiterte das Löschen des Originals und räumte ein anderes Programm den
  Ordner auf, bevor das Plugin seine Löschmarkierung zurückgenommen hatte,
  entfernte der Rückbau auch die Kopie; gemeldet wurde „Die Mail ist
  unverändert“. Das Plugin nimmt die Markierung jetzt zuerst zurück und prüft
  das Original danach. Ist es inzwischen verschwunden, bleibt die Kopie, und
  die Bearbeitung gilt als gelungen; trägt es die Markierung weiter, bleiben
  beide Fassungen.
- Der Betreff der gespeicherten Mail ging ungekürzt an Roundcubes Dekodierer,
  beim Öffnen des Dialogs wie beim Speichern. Ein präparierter Betreff belegte
  ohne PCRE-JIT einen PHP-Prozess bis zur Zeitgrenze (bei 500 KB keine Antwort
  nach 122 Sekunden) und endete mit JIT ab 512 KiB am Speicherlimit mit HTTP
  500. Dekodiert werden jetzt höchstens 8192 Byte.
- Der Rückbau entfernt die Kopie nur noch, wenn sie als die eigene erkannt
  ist: Der Server hat ihre UID für genau diesen Ordner genannt, oder sie
  trägt den geschriebenen Kopf. Vorher genügte die UID. War die Antwort auf
  APPEND nicht mehr lesbar, weil ein anderes Plugin im Hook `message_saved`
  mit dem Server gesprochen hatte, entfiel der Vergleich der UIDVALIDITY.
  Eine Kopie, die die Verbindung nie gesehen hat, gilt nicht mehr als
  entfernt, nur weil sie nicht gefunden wird.
- Unmittelbar vor dem Löschen des Originals wird die Kopie noch einmal
  gesucht. Hat sie inzwischen jemand entfernt oder verschoben, bleibt das
  Original stehen. Vorher lagen zwischen ihrer Prüfung und dem Löschen drei
  Befehle.
- Die Sperrdatei wird nicht mehr über einen symbolischen Link geöffnet und
  ihre Rechte nicht mehr über den Namen gesetzt. Wer im Temp-Verzeichnis
  schreiben durfte, konnte über einen Link an ihrem vorhersagbaren Namen die
  Rechte einer Datei des Webservers auf 0600 setzen, eine leere Datei anlegen
  lassen oder die Anfrage mit einem FIFO anhalten. Auch die Temp-Datei der
  Mail wird nur noch gesendet, wenn unter ihrem Namen noch die geschriebene
  Datei liegt.
- Im Browser: Kam die Antwort auf das Speichern, nachdem der Nutzer den
  Ordner gewechselt hatte, traf sie die Liste des anderen Ordners. Dort
  verschwand die Zeile der Mail mit der alten UID-Nummer aus der Anzeige,
  oder die Mail mit der neuen UID-Nummer wurde ausgewählt und dadurch als
  gelesen markiert. Die Liste wird jetzt nur angefasst, wenn sie den Ordner
  der bearbeiteten Mail zeigt.
- Antworten des Servers: Eine Antwort mit Literal wird nicht mehr zeilenweise
  gelesen. Eine nicht verstandene FETCH-Zeile heißt „unbekannt“, nicht mehr
  „Mail ist weg“. Flags werden nur als Atome nach RFC 3501 übernommen;
  Roundcube schreibt sie unmaskiert in den APPEND-Befehl. Ein `BAD` während
  APPEND gilt nicht mehr als „nicht gespeichert“. Ein Kopf, der nach einem
  Verbindungsabriss unvollständig ankam, wird verworfen. Das Zurücknehmen der
  Löschmarkierung prüft wie das Löschen, ob der Ordner noch derselbe ist.

### Geändert

- Neue Meldung: Die geänderte Fassung wurde von anderer Seite entfernt, bevor
  sie das Original ersetzen konnte.
- Reines ASCII wird als Encoded-Word geschrieben, wenn die Mail einen
  Zeichensatz nennt, mit dem Roundcube rohen Kopftext anders läse (UTF-7,
  UTF-16).
- Nach einem harten Abbruch von PHP bleibt keine Sperrdatei mehr liegen; eine
  alte wird beim Benutzen aufgefrischt, damit Roundcube sie nicht währenddessen
  wegräumt.
- `release.sh` lehnt alles außer gewöhnlichen Dateien ab (ein symbolischer
  Link käme als Link ins ZIP), vergleicht den Inhalt jeder Datei im ZIP mit
  dem Commit und arbeitet ohne Ersatz-Objekte (`refs/replace`).
- Testumgebung: Die Container hängen an einem internen Netz ohne Weg nach
  außen. Das Testkit bleibt ohne `EDIT_SUBJECT_TESTKIT=1` auch im
  Speichertreiber wirkungslos.
- README: Temp-Verzeichnis als Voraussetzung, Sperre je Konto und Host,
  freigegebene Ordner, Composer mit `--prefer-source`, Grenze von 8192 Byte.

### Behoben

- Der Dialog las rohen 8-Bit-Text im Betreff ohne den Zeichensatz der Mail.
  Bei einer Mail mit `charset=UTF-8` zeigte Roundcubes Liste „Grüße“, der
  Dialog „GrÃ¼ÃŸe“, und so wurde es gespeichert. Der Dialog liest den Betreff
  jetzt wie Roundcube.
- Ein Kopf, der sich nicht lesen ließ, wurde als „Mail nicht gefunden“
  gemeldet.

## [1.0.1] – 2026-11-22

Korrekturen aus dem Sicherheitsaudit vom 22.11.2026. Interner Stand, nicht
veröffentlicht.

### Sicherheit

- Roundcube sendete statt `UID EXPUNGE` ein einfaches `EXPUNGE`, wenn UIDPLUS
  nur in seinem Sitzungspuffer stand, nicht in der Fähigkeitsliste der
  Verbindung: mit der Option `imap_force_caps` ab der zweiten Bearbeitung
  einer Sitzung, sonst nach einer Änderung am Server. Dabei verschwanden
  alle als gelöscht markierten Mails des Ordners; trug die bearbeitete Mail
  selbst diese Markierung, auch ihre Kopie, bei gemeldetem Erfolg. Das Plugin
  fragt jetzt die Verbindung und meldet Erfolg nur, wenn die Kopie noch da
  ist.
- Geändert wird nur noch die Mail, deren Betreff im Dialog stand: Der Client
  schickt diesen Betreff mit, der Server vergleicht. Vorher konnte nach einem
  Ordnerwechsel während des Ladens die Mail mit derselben UID im anderen
  Ordner geändert werden. Der Dialog ist an seine Mail gebunden und öffnet
  sich nach einem Ordnerwechsel nicht mehr.
- Antworten auf FETCH werden nach ihrer Struktur gelesen statt mit regulären
  Ausdrücken. Schlüsselwörter wie `UID` oder `RFC822.SIZE` galten vorher als
  Felder; bei Servern mit anderer Feldreihenfolge als Dovecot konnte der
  Rückbau dadurch die Kopie entfernen, obwohl das Original schon gelöscht
  war.
- Die Kopie wird nur noch zurückgenommen, nachdem das Original unverändert
  wiedergefunden und seine Löschmarkierung zurückgenommen ist. Eine UID, die
  eine neue Mail nicht haben kann, oder eine andere UIDVALIDITY in der Antwort
  auf APPEND führt zum Abbruch ohne Löschen.
- Der neue Betreff wird vor jeder Verarbeitung auf 16 384 Byte Rohlänge
  begrenzt, der Leerraum in linearer Zeit entfernt. Vorher brauchte eine
  Anfrage ohne PCRE-JIT bis zu 122 Sekunden, mit JIT endete sie ab 1 000 001
  Leerzeichen mit HTTP 500.
- Anfragen mit einem Cookie `_uid` werden abgelehnt. Roundcube liest die UID
  auch aus Cookies und schrieb damit Ziel und Ordner der Anfrage um.
- Anfragen ohne AJAX-Kennung werden abgelehnt, bevor etwas geschieht (HTTP
  400). Eine UID mit angehängtem Zeilenumbruch gilt als ungültig.
- Der Kopfbereich wird mit einer Obergrenze abgerufen und darf höchstens
  20 000 Zeilen haben; die Grenzen gelten auch beim Öffnen des Dialogs.
  Vorher endeten Laden und Speichern bei sehr vielen kurzen Kopfzeilen am
  Speicherlimit (HTTP 500).
- Für eine Mail läuft auf einem Host nur eine Bearbeitung zugleich, und vor
  dem Löschen wird das Original erneut gelesen. Zwei gleichzeitige Anfragen
  hinterließen vorher zwei Fassungen.
- Im Browser: Nachrichten anderer Fenster werden auf ihre Typen geprüft, die
  Längenprüfung läuft in linearer Zeit (ein präparierter Betreff blockierte
  die Seite sekundenlang).

### Geändert

- Bei mehreren `Subject`-Feldern wird das letzte bearbeitet, weil Roundcube
  dieses anzeigt; vorher das erste. `X-Original-Subject` hält entsprechend
  das letzte fest.
- Die Speicheranfrage verlangt den Parameter `_expected` (Betreff beim Öffnen
  des Dialogs).
- Neue Meldungen: Mail inzwischen geändert, Mail wird gerade bearbeitet,
  Ordner nur lesbar, Speichern der Kopie nicht bestätigt, Mail nach dem
  Ersetzen nicht mehr auffindbar. Ein APPEND ohne Antwort des Servers wird
  nicht mehr als „nicht gespeichert“ gemeldet.
- Das Release-ZIP hängt nur noch vom Commit ab (unkomprimiert, feste
  Zeitzone, keine Dateirechte des bauenden Rechners) und lässt sich bytegleich
  nachbauen. `release.sh <version>` verlangt, dass der Tag auf HEAD zeigt.
- README: Installation per Git nur zum Entwickeln; `X-Original-Subject` ist
  kein Nachweis.

### Behoben

- Ordnernamen und Antworten des Servers können im Fehlerlog keine neuen
  Zeilen mehr beginnen.
- Ein POST ohne Token wird mit 403 beantwortet, nicht mehr mit 405.
- `run-tests.sh` nennt nach dem Aufräumen, was übrig geblieben ist.

## [1.0.0] – 2026-11-22

Interner Stand, nicht veröffentlicht.

### Hinzugefügt

- Eintrag „Betreff bearbeiten“ im Mehr-Menü der Mailliste, der geöffneten Mail
  und einer Mail im eigenen Fenster (Skin Elastic).
- Ersetzen auf dem Server in fester Reihenfolge: geänderte Fassung ablegen
  (APPEND), prüfen (Größe, Kopf, Betreff, Flags, Eingangsdatum), erst dann das
  Original per `UID EXPUNGE` löschen und nachprüfen. Scheitert das Löschen,
  wird die Kopie wieder entfernt.
- Abbruch vor jeder Änderung, wenn der Server kein UIDPLUS (RFC 4315) meldet
  oder Rechte (RFC 4314) fehlen.
- Neuer Betreff als UTF-8-Encoded-Words nach RFC 2047, gefaltet. Steuerzeichen
  in der Eingabe werden zu Leerzeichen, höchstens 500 Zeichen.
- Option `edit_subject_keep_original` für den ursprünglichen Betreff als
  `X-Original-Subject`.
- Nachführen von Liste, Suche, ordnerübergreifender Suche, Thread-Ansicht und
  offenen Fenstern.
- Texte auf Deutsch und Englisch.
- Testumgebung in Docker: Unit-Tests unter PHP 8.4 und 8.1, Integrations- und
  Browsertests gegen Roundcube 1.7.6 und 1.6.21 mit Dovecot 2.4.5.
- Release-ZIP über `release.sh`, interaktive Demo in `docs/showcase.html`.

[Unveröffentlicht]: https://github.com/Kaleidostar/roundcube-edit-subject/compare/1.0.3...HEAD
[1.0.3]: https://github.com/Kaleidostar/roundcube-edit-subject/releases/tag/1.0.3
