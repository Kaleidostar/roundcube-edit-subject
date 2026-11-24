#!/bin/sh
# Runs inside the phpunit containers: syntax check of every PHP file of the
# plugin with the container's PHP version, then the unit tests.
set -u

cd /src || exit 1
echo "== $(php -r 'echo "PHP ", PHP_VERSION;')"

lint_failed=0
for f in edit_subject.php lib/*.php localization/*.inc config.inc.php.dist; do
    [ -e "$f" ] || continue
    if ! out=$(php -l "$f" 2>&1); then
        echo "$out"
        lint_failed=1
    fi
done
if [ "$lint_failed" -ne 0 ]; then
    echo "Syntaxprüfung fehlgeschlagen"
    exit 1
fi
echo "Syntaxprüfung: ok"

exec phpunit --configuration tests/unit/phpunit.xml --do-not-cache-result "$@"
