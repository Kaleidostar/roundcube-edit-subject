# Sicherheit

## Unterstützte Versionen

| Version | Sicherheitskorrekturen |
|---|---|
| 1.0.x | ja |

## Sicherheitslücke melden

Bitte Sicherheitslücken **nicht** als öffentliches Issue melden, sondern
vertraulich über die private Meldefunktion von GitHub: im Repository unter
**Security → Report a vulnerability**
([Anleitung](https://docs.github.com/de/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)).

Hilfreich sind:

- Version des Plugins, Roundcube-Version, PHP-Version und IMAP-Server
- Schritte zum Nachstellen
- die Auswirkung, zum Beispiel Zugriff auf fremde Mails oder Ordner,
  Header-Injection oder eine Anfrage, die ohne gültiges Request-Token durchgeht

Als Sicherheitsproblem gilt hier auch jeder Weg, auf dem das Plugin eine Mail
verliert oder eine Mail verändert, die nicht bearbeitet werden sollte.

Die Meldung wird vertraulich behandelt. Die Korrektur erscheint als neue
Version mit Eintrag in [CHANGELOG.md](CHANGELOG.md) und, wo nötig, als
Security Advisory im Repository.
