# Mitwirken

Fehlerberichte, Vorschläge und Pull Requests sind willkommen. Diese Datei
beschreibt, wie das Projekt aufgebaut ist, wie man es testet und wie ein
Release entsteht.

## Grundregel

Mails sind für Nutzer nicht wiederbeschaffbar. Jede Änderung folgt deshalb der
Regel des Plugins: lieber abbrechen oder ein Duplikat hinterlassen, als eine
Mail zu verlieren oder eine Mail zu verändern, die nicht bearbeitet werden
sollte. Die Reihenfolge der Schritte in `lib/edit_subject_engine.php` trägt
diese Sicherheit. Wer sie ändert, belegt mit einem Test, dass auch der
Fehlerfall sicher bleibt.

## Aufbau

Das Repository ist zugleich das Plugin-Verzeichnis. `release.sh` packt nur die
Dateien des Plugins in das Release-ZIP.

| Pfad | Inhalt |
|---|---|
| `edit_subject.php`, `edit_subject.js` | Plugin-Klasse und Code im Browser |
| `lib/edit_subject_header.php` | Kopf der Mail neu schreiben, reine Funktionen |
| `lib/edit_subject_engine.php` | Ablauf auf dem Server |
| `lib/edit_subject_storage.php` | Anbindung an `rcube_imap` |
| `localization/` | Texte auf Deutsch und Englisch |
| `skins/elastic/` | Symbol im Mehr-Menü |
| `tests/` | Unit-, Integrations- und Browsertests mit Docker-Umgebung |
| `docs/` | Showcase und Screenshots |

## Tests

Auf dem Rechner werden nur Docker und Docker Compose gebraucht.

```bash
make test         # Roundcube 1.7.6
make test-16      # Roundcube 1.6.21
make test-cache   # Roundcube 1.7.6 mit messages_cache
make test-all     # alle drei Läufe
./run-tests.sh -k quota   # weitere Argumente gehen an pytest
```

`run-tests.sh` baut die Images, startet die Dienste und wartet per Healthcheck
auf sie. Danach führt es alle Tests aus und räumt mit `docker compose down -v`
auf. Bei Fehlern endet es mit einem Exit-Code ungleich 0. Beim ersten Lauf lädt
Docker die Basis-Images und baut vier Test-Images: Roundcube, Dovecot, PHPUnit und den
Runner mit Chromium. Das dauert je nach Leitung einige Minuten.

| Stufe | Werkzeug | Inhalt |
|---|---|---|
| Syntax und Unit-Tests | PHPUnit unter PHP 8.4 und 8.1 | Header-Funktion, Ablauf gegen simulierten Speicher, Auswertung der FETCH-Antworten |
| Integration | pytest, requests, imaplib | Ablauf über Roundcubes HTTP-Schnittstelle gegen Dovecot 2.4.5 |
| Browser | Playwright, Chromium | Menü, Dialog, Liste, Mailansicht, eigenes Fenster, Suche, Threads |

Die Testumgebung besteht aus drei Dovecot-Instanzen: dem Hauptserver mit
ACL-Ordnern, einem Server ohne UIDPLUS und einem mit 256 KiB Quota. Dazu kommt
Roundcube mit einem Testkit-Plugin, das Speicherfehler simuliert. Den Zustand
auf dem Server prüfen die Tests nie über Roundcube, sondern über eine eigene
IMAP-Verbindung.

Derselbe Roundcube-Container ist unter mehreren Hostnamen erreichbar, jeder
wählt ein Szenario (`tests/docker/roundcube/config/edit_subject_tests.php`):
`rc` ist der Normalfall, `rc-sim` der simulierte Speicher, `rc-noacl` ohne
ACL-Fähigkeit, `rc-nouidplus` und `rc-quota` die beiden anderen Server,
`rc-keep` mit `edit_subject_keep_original`, `rc-small` mit der Grenze `'1K'`,
`rc-badsize` mit einer Grenze, die keine Größe ist (`'1.5M'`), `rc-forcecaps` mit
`imap_force_caps`. Das Testkit tut nichts, solange die Umgebungsvariable
`EDIT_SUBJECT_TESTKIT=1` fehlt: Das Plugin registriert dann nichts, und der
Speichertreiber `estest` liest keine Steuerdatei und verhält sich wie
`rcube_imap`. Die Container hängen an einem internen Netz; sie erreichen nur
einander.

Auf GitHub laufen dieselben drei Läufe bei jedem Push auf `main` und bei jedem
Pull Request (Workflow `Tests`).

### Testfälle

Die Tests tragen Nummern aus dieser Liste, etwa `test case 18` im Kommentar.
Dazu kommen Tests ohne Nummer, zum Beispiel für die Rechteprüfung vorab, vom
Server verworfene Flags und die Temp-Datei. Die Fälle 28 bis 40 stammen aus
dem Sicherheitsaudit vom 22.11.2026: Jeder davon scheiterte gegen Version
1.0.0. Die Fälle 41 bis 48 stammen aus dem zweiten Audit vom 22./23.11.2026:
Jeder davon scheiterte gegen Version 1.0.1. Die Fälle 49 bis 52 gehören zu den
Änderungen nach 1.0.2 und zum dritten Audit vom 23.11.2026; ihre Tests
scheiterten gegen den Arbeitsstand vor dessen Korrekturen. Die zugehörigen
Unit-Tests stehen
in `tests/unit/StorageTest.php` und am Ende von `EngineTest.php` und
`HeaderTest.php`.

| Nr. | Stufe | Geprüft wird |
|---|---|---|
| 1 | Unit | ASCII-Betreff durch ASCII-Betreff ersetzen |
| 2 | Unit | Umlaute, Emoji und 200 Zeichen: dekodiert gleich der Eingabe, Kodierregeln eingehalten |
| 3 | Unit | Original mit gefaltetem, mehrzeiligem, kodiertem Betreff |
| 4 | Unit | Original ohne `Subject`-Feld |
| 5 | Unit | Original mit zwei `Subject`-Feldern |
| 6 | Unit | Eingabe mit CR/LF und angehängtem `Bcc:` ergibt keine zusätzliche Kopfzeile |
| 7 | Unit | Leerer Betreff; Betreff über der Längengrenze |
| 8 | Unit | `X-Original-Subject` wird einmal gesetzt und bleibt bei weiteren Bearbeitungen stehen |
| 9 | Integration | Mail mit Anhängen: Körper bytegenau, übrige Kopfzeilen gleich und in derselben Reihenfolge |
| 10 | Integration | Signierte Mail (`multipart/signed`): Körper bytegenau, Signatur weiter gültig |
| 11 | Integration | Flags und Schlüsselwörter bleiben (`\Seen`, `\Flagged`, `\Answered`, `$Forwarded`, `$label1`); Ungelesenes bleibt ungelesen |
| 12 | Integration | INTERNALDATE bleibt (Mail aus dem Jahr 2019) |
| 13 | Integration | Nachbarn mit `\Deleted` bleiben, die Zahl der Mails stimmt, die alte UID ist weg |
| 14 | Integration | Mail mit 25 MB im `memory_limit` des Images |
| 15 | Integration | Ordner mit Umlaut im Namen und Unterordner |
| 16 | Integration | Dieselbe Mail zweimal hintereinander |
| 17 | Integration | Unveränderter Betreff: die UID bleibt |
| 18 | Integration | APPEND scheitert (Quota): Meldung, Original unverändert, keine Reste im Ordner, keine Temp-Dateien |
| 19 | Integration | Server ohne UIDPLUS: Abbruch mit Meldung, nichts verändert |
| 20 | Integration | Löschen des Originals scheitert: die Kopie wird zurückgenommen (simulierter Speicher); scheitert auch das, bleiben beide Fassungen (echte ACL) |
| 21 | Integration | Anfrage ohne gültiges Token abgelehnt, GET ändert nichts, ungültige UID und fremder Ordner ergeben saubere Meldungen |
| 22 | Browser | Menüeintrag sichtbar und nur bei genau einer ausgewählten Mail aktiv |
| 23 | Browser | Aus der Liste: Dialog vorbelegt, neuer Betreff in Liste und Vorschau, die Mail bleibt ausgewählt |
| 24 | Browser | Aus der geöffneten Mail und im eigenen Fenster: die Ansicht zeigt die neue Fassung |
| 25 | Browser | Aktive Suche, ordnerübergreifende Suche und Thread-Ansicht |
| 26 | Browser | Beschriftungen auf Deutsch und Englisch |
| 27 | Browser | Keine Fehler in der Browser-Konsole, kein 404 für Dateien des Plugins, keine neuen Einträge im Fehlerlog |
| 28 | Integration | `imap_force_caps`: Nachbarn mit `\Deleted` bleiben auch bei der zweiten Bearbeitung einer Sitzung; eine Mail, die selbst `\Deleted` trägt, wird ersetzt und geht nicht verloren |
| 29 | Integration | UIDPLUS fällt während einer Sitzung weg: Abbruch mit Meldung, nichts verändert |
| 30 | Integration | Schlüsselwörter, die wie FETCH-Felder heißen (`RFC822.SIZE`, `UID`, `FLAGS`, `NIL`): Mail bleibt bearbeitbar, Schlüsselwörter bleiben |
| 31 | Integration | Betreff aus dem Dialog passt nicht mehr, fehlt oder gehört zu einer Mail mit gleicher UID in einem anderen Ordner: Abbruch, nichts verändert |
| 32 | Integration | Cookie `_uid`: Anfrage abgelehnt, weder die genannte noch die per Cookie gemeinte Mail verändert |
| 33 | Integration | POST ohne AJAX-Kennung mit gültigem Token ändert nichts (HTTP 400); POST ohne Token ergibt 403; UID mit angehängtem Leerraum ist ungültig |
| 34 | Integration | Kopfbereich mit 150 000 Zeilen oder über 4 MiB: saubere Meldung beim Laden und beim Speichern statt HTTP 500 |
| 35 | Integration | Neuer Betreff mit sehr langem Leerraum in der Mitte: saubere Meldung statt HTTP 500 |
| 36 | Integration | Zwei gleichzeitige Speicheranfragen für dieselbe Mail: eine gewinnt, es bleibt genau eine Fassung |
| 37 | Integration | Antwort auf APPEND bleibt aus: eigene Meldung, Original unverändert, nichts gelöscht |
| 38 | Integration | Zwei `Subject`-Felder: Der Dialog bekommt das letzte, das auch die Liste zeigt; `X-Original-Subject` hält dieses fest |
| 39 | Browser | Antwort auf die Ladeanfrage kommt nach einem Ordnerwechsel: kein Dialog, keine Mail verändert; nach einem Wechsel der Auswahl bleibt der Dialog an seine Mail gebunden |
| 40 | Browser | Gefälschte Nachrichten im Kanal zwischen den Fenstern bleiben ohne Wirkung; die Längenprüfung blockiert die Seite nicht |
| 41 | Unit, Integration | Rückbau nach gescheitertem Löschen: Räumt ein anderes Programm das markierte Original weg, bleibt die Kopie als einzige Fassung; bleibt die Löschmarkierung stehen, bleiben beide |
| 42 | Unit, Integration | Sehr langer Betreff der gespeicherten Mail: Laden und Speichern ohne HTTP 500, dekodiert werden höchstens 8192 Byte, `X-Original-Subject` bleibt vollständig |
| 43 | Unit, Integration | Roher 8-Bit-Betreff: Der Dialog zeigt, was die Liste zeigt; ASCII wird kodiert, wo roher Text anders gelesen würde (UTF-7, UTF-16) |
| 44 | Browser | Antwort auf das Speichern kommt nach einem Ordnerwechsel: Liste des anderen Ordners unverändert, dort keine Mail ausgewählt oder als gelesen markiert |
| 45 | Unit | Sperrdatei: Link, Link ins Leere und FIFO an ihrem Namen verändern nichts und halten nichts an; Rechte 0600; eine liegen gebliebene Datei stört nicht; eine ausgetauschte Temp-Datei wird nicht gesendet |
| 46 | Unit, Integration | UID ohne bestätigte UIDVALIDITY (Hook eines anderen Plugins spricht IMAP): Der Rückbau löscht nur, was den geschriebenen Kopf trägt; eine nie gesehene Kopie gilt nicht als entfernt |
| 47 | Unit | Kopie verschwindet vor dem Löschen des Originals: Das Original bleibt |
| 48 | Unit | Antworten des Servers: Literal und nicht verstandene Zeile ergeben „unbekannt“; Flags nur als Atome; `BAD` beim APPEND lässt den Ausgang offen; Kopf nach Verbindungsabriss; Entmarkieren nur im selben Ordner |
| 49 | Unit, Integration | Gesamtgröße der Mail: Grenze vor jedem Abruf, Angabe mit Einheit (`'1K'`, `'64M'`); was keine Größe ist, ergibt den Standard und einen Logeintrag |
| 50 | Unit, Integration | Ein anderes Programm löscht oder verschiebt das Original nach dem APPEND, oder eine zweite Bearbeitung ersetzt es zuerst: Dessen Änderung bleibt, die Kopie wird zurückgenommen, keine Erfolgsmeldung, kein Duplikat |
| 51 | Unit | Kopie mit fremden Flags (`\Deleted`) oder falschem Körper (SHA-256): zurückgenommen; die Meldung passt zum Zustand des Originals; ist das Original fort und die Kopie unbekannt, heißt es „unbekannt“ |
| 52 | Unit | Server sendet mehr Körper als angekündigt: höchstens ein Byte mehr in der Temp-Datei, Abbruch; `flock` ohne Wirkung ergibt ein Problem mit der Temp-Datei samt Logeintrag, keine Meldung über eine laufende Bearbeitung |

### Mit laufender Umgebung entwickeln

Die Umgebung kann laufen bleiben, während man am Plugin arbeitet. Das
Repository ist per Bind-Mount eingehängt, Änderungen wirken sofort.

```bash
export RC_TAG=1.7.6-apache MESSAGES_CACHE=0
docker compose -p es-dev -f tests/docker/compose.yml up -d --wait dovecot dovecot-nouidplus dovecot-quota roundcube
docker compose -p es-dev -f tests/docker/compose.yml run --rm --no-deps runner python3 -m pytest integration/test_20_failures.py -q
docker compose -p es-dev -f tests/docker/compose.yml --profile unit --profile runner down -v
```

### IMAP-Verkehr mitschneiden

In der laufenden Umgebung schaltet dieser Befehl das IMAP-Protokoll von
Roundcube ein:

```bash
docker compose -p es-dev -f tests/docker/compose.yml exec -T roundcube \
  sh -c 'printf "%s\n" "\$config[\"imap_debug\"] = true;" >> /var/www/html/config/config.inc.php'
```

Das Protokoll landet in `/var/roundcube/logs/imap.log`. Eigene Dateien in
diesem Verzeichnis nur als `www-data` anlegen (`exec -u www-data`). Gehört eine
Logdatei root, kann Roundcube nicht hineinschreiben. Die PHP-Warnungen im
`errors.log` lassen dann die Tests scheitern.

### Versionen und Fallen

Die Basis-Images werden per Digest bezogen. `tests/docker/phpunit/composer.lock`
fixiert die PHPUnit-Abhängigkeiten über die Commit-Referenz jedes Pakets; eine
Inhalts-Prüfsumme (`shasum`) trägt Composer für Archive von GitHub nicht ein,
das Feld ist bei allen Paketen leer. `tests/docker/runner/requirements.txt`
fixiert Python einschließlich aller Paket-Hashes. Die Datei wird bei Bedarf mit
`pip-compile --generate-hashes` aus `requirements.in` erneuert. Auch die Archive
von Chromium und FFmpeg werden beim Download per SHA-256 geprüft. APT verwendet
den Debian-Snapshot vom 23.11.2026 (`debian.sources`). Dependabot überwacht die
Docker-, Composer-, Python- und GitHub-Actions-Manifeste.
`test_00_environment.py` prüft die laufende Roundcube-Version, PHP nur auf die
Hauptversion 8. Gemessen am 23.11.2026:

| Teil | Versionen |
|---|---|
| Roundcube-Images `1.7.6-apache` und `1.6.21-apache` | PHP 8.4.28, PCRE 10.44, `pcre.jit=1` |
| PHPUnit-Images | PHP 8.4.28 mit PCRE 10.44 und PHP 8.1.34 mit PCRE 10.39, beide PHPUnit 10.5.67 |
| IMAP-Server | Dovecot 2.4.5 |
| Runner | Python 3.12.16, pytest 9.0.3, requests 2.33.0, Playwright 1.64.1 mit Chromium 156.0.8078.4 |

Ohne PCRE-JIT über HTTP messen: Apache liest eine neue Datei unter
`conf.d` mit `apachectl graceful` ein, ohne den Container neu zu starten.
Rückgängig macht es das Löschen der Datei und ein weiteres `graceful`.

```bash
docker compose -p es-dev -f tests/docker/compose.yml exec -T roundcube \
  sh -c 'printf "pcre.jit=0\n" > /usr/local/etc/php/conf.d/zz-pcre-nojit.ini && apachectl graceful'
```

`requests` legt Cookies für einen Hostnamen ohne Punkt wie `rc` unter
`rc.local` ab. Ein von Hand mit `domain="rc"` gesetztes Cookie wird deshalb
nicht gesendet. Wer einen Test mit eigenen Cookies schreibt, prüft den
gesendeten Kopf (`response.request.headers["Cookie"]`).

## Stil

- Code und Kommentare auf Englisch, im Stil der Roundcube-Kern-Plugins
  (`markasjunk`, `archive`, `zipdownload`).
- Texte für Nutzer immer in `localization/de_DE.inc` und `localization/en_US.inc`.
- Dokumentation auf Deutsch.
- Commit-Nachrichten auf Deutsch: kurze Betreffzeile, im Text der Grund.
- Tests nicht abschwächen, um sie grün zu bekommen. Verlangt ein Test etwas
  Falsches, die Änderung im Pull Request begründen.

## Showcase

`docs/showcase.html` baut `edit_subject_header::sanitize()` und
`edit_subject_header::subject_field()` in JavaScript nach und zeigt eine
mitgeschnittene IMAP-Befehlsfolge. Wer `lib/edit_subject_header.php` oder den
Ablauf in `lib/edit_subject_engine.php` ändert, zieht die Seite nach.

## Pull Requests

- ein Thema pro Pull Request
- `make test-all` grün
- Eintrag in `CHANGELOG.md` unter „Unveröffentlicht“

## Release

1. In `CHANGELOG.md` den Abschnitt „Unveröffentlicht“ in die neue Version
   umbenennen, das Datum setzen und die Links am Ende ergänzen.
2. Committen, einen Tag ohne „v“ setzen und beides pushen:

   ```bash
   git tag -a 1.1.0 -m "edit_subject 1.1.0"
   git push origin main 1.1.0
   ```

3. Der Workflow `Release` führt die Tests aus und baut ZIP und `.sha256` dreimal
   bytegleich aus dem getaggten Commit. Ein separater, nur mit `contents: read`,
   `id-token: write` und `attestations: write` ausgestatteter Job vergleicht den
   SHA-256 und erzeugt mit `actions/attest` die SLSA-Build-Provenance. Erst danach
   veröffentlicht ein letzter Job nach erneutem Bytevergleich genau diese Dateien
   mit dem Abschnitt aus `CHANGELOG.md` als Text. Ein Fehler vor der Attestierung
   oder dabei verhindert die Veröffentlichung.

Lokal baut `./release.sh 1.1.0` dasselbe ZIP nach `dist/`, Byte für Byte:
Es hängt nur vom Commit ab (gemessen am 22.11.2026 mit Git 2.43.0 über vier
Kombinationen aus Zeitzone, umask und Dateisystem). Der Tag muss dafür auf
HEAD zeigen. Die `.sha256`-Datei des Releases lässt sich so nachrechnen.

`release.sh` baut nur aus gewöhnlichen Dateien: Liegt unter der Positivliste
ein symbolischer Link, bricht es ab. Nach dem Bauen vergleicht es jede Datei
im ZIP Byte für Byte mit dem Commit, und es arbeitet ohne Ersatz-Objekte
(`refs/replace`).
