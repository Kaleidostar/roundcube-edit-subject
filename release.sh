#!/usr/bin/env bash
# Baut das Release-ZIP dist/edit_subject-<version>.zip.
#
# Inhalt ist das Plugin-Verzeichnis edit_subject/ aus dem letzten Commit
# (git archive), ohne Tests, Docker-Dateien und Repo-Verwaltung. Die Liste
# unten ist eine Positivliste: was nicht darin steht, kommt nicht hinein.
#
# Das ZIP hängt nur vom Commit ab: git archive schreibt die Einträge in der
# Reihenfolge des Baums, mit der Zeit des Commits und ohne Dateirechte des
# Rechners. Die Zeitzone ist auf UTC festgelegt, gespeichert wird ohne
# Kompression. Dateisystem, umask, Zeitzone und zlib-Version des bauenden
# Rechners gehen damit nicht in die Prüfsumme ein.
#
# Geprüft wird vor dem Bauen, dass unter der Positivliste nur gewöhnliche
# Dateien liegen, und nach dem Bauen, dass jede Datei im ZIP Byte für Byte
# dem Stand im Commit entspricht. Ersatz-Objekte (refs/replace) sind
# abgeschaltet: Das ZIP nennt die ID des Commits, also trägt es dessen Inhalt.
#
#   ./release.sh            Stand von HEAD, Version aus "git describe"
#   ./release.sh 1.0.2      der Tag 1.0.2; er muss auf HEAD zeigen
set -u
cd "$(dirname "$0")" || exit 1

export GIT_NO_REPLACE_OBJECTS=1

files=(
    edit_subject.php
    edit_subject.js
    composer.json
    config.inc.php.dist
    CHANGELOG.md
    LICENSE
    README.md
    lib
    localization
    skins
)

fail() {
    echo "$*" >&2
    exit 1
}

if [ $# -ge 1 ]; then
    version="$1"
    [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "Die Version muss die Form X.Y.Z haben: $version"
    tagged="$(git rev-parse --verify --quiet "refs/tags/$version^{commit}")" \
        || fail "Den Tag $version gibt es nicht. Erst taggen, dann bauen."
    [ "$tagged" = "$(git rev-parse --verify HEAD)" ] \
        || fail "Der Tag $version zeigt nicht auf HEAD. Gebaut wird immer HEAD; erst den Tag auschecken."
else
    version="$(git describe --tags --always 2>/dev/null)"
    [ -n "$version" ] || fail "Keine Version: weder angegeben noch per git describe ermittelbar."
fi

# Ein symbolischer Link käme als Link ins ZIP, und Roundcube 1.7 liefert über
# static.php das Ziel eines Links im Plugin-Verzeichnis aus.
odd="$(git ls-tree -r HEAD -- "${files[@]}" | awk '$1 != "100644" && $1 != "100755" { print $1, $4 }')"
[ -z "$odd" ] || fail "Unter der Positivliste liegt etwas anderes als gewöhnliche Dateien: $odd"

if [ -n "$(git status --porcelain -- "${files[@]}")" ]; then
    echo "Hinweis: nicht committete Änderungen an Plugin-Dateien sind NICHT im ZIP." >&2
fi

mkdir -p dist
zip_file="dist/edit_subject-$version.zip"
rm -f "$zip_file"

if ! TZ=UTC git archive --format=zip -0 --prefix=edit_subject/ --output="$zip_file" HEAD -- "${files[@]}"; then
    rm -f "$zip_file"
    fail "Release-ZIP konnte nicht gebaut werden."
fi

# Im ZIP muss genau stehen, was der Commit unter der Positivliste führt:
# dieselben Namen ...
expected="$(git ls-tree -r --name-only HEAD -- "${files[@]}" | sed 's|^|edit_subject/|' | LC_ALL=C sort)"
actual="$(unzip -Z1 "$zip_file" | grep -v '/$' | LC_ALL=C sort)"

if [ -z "$expected" ] || [ "$expected" != "$actual" ]; then
    rm -f "$zip_file"
    fail "Der Inhalt des ZIP weicht vom Commit ab, ZIP verworfen."
fi

# ... und derselbe Inhalt. Filter und Attribute aus der Konfiguration des
# bauenden Rechners könnten ihn beim Archivieren verändern.
while IFS= read -r f; do
    if ! cmp -s <(unzip -p "$zip_file" "edit_subject/$f") <(git cat-file blob "HEAD:$f"); then
        rm -f "$zip_file"
        fail "Im ZIP steht $f anders als im Commit, ZIP verworfen."
    fi
done < <(git ls-tree -r --name-only HEAD -- "${files[@]}")

echo "$zip_file:"
unzip -l "$zip_file"
sha256sum "$zip_file"
