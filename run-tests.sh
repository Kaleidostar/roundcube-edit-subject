#!/usr/bin/env bash
# Baut die Testumgebung, wartet per Healthcheck auf die Dienste, führt alle
# Tests aus (PHPUnit unter PHP 8.4 und 8.1, Integrations- und Browsertests)
# und räumt danach mit "docker compose down -v" auf. Exit-Code 0 nur, wenn
# alles grün ist und nichts übrig bleibt.
#
#   ./run-tests.sh                          Roundcube 1.7.6
#   RC_TAG=1.6.21-apache ./run-tests.sh     Roundcube 1.6.21
#   MESSAGES_CACHE=1 ./run-tests.sh         mit messages_cache
#   ./run-tests.sh -k quota                 weitere Argumente gehen an pytest
#
# Bewusst ohne "set -e": das Aufräumen muss auch nach Fehlern laufen.
set -u
cd "$(dirname "$0")" || exit 1

export RC_TAG="${RC_TAG:-1.7.6-apache}"
export MESSAGES_CACHE="${MESSAGES_CACHE:-0}"
case "$RC_TAG" in
    1.7.6-apache)
        RC_STAGE='rc176'
        ;;
    1.6.21-apache)
        RC_STAGE='rc1621'
        ;;
    *)
        echo "Unterstützt sind die im Dockerfile digest-gepinnten RC_TAG-Werte 1.7.6-apache und 1.6.21-apache." >&2
        exit 1
        ;;
esac
export RC_STAGE
project="es-test-$(printf '%s-%s' "$RC_TAG" "$MESSAGES_CACHE" | tr -c 'a-z0-9' '-')-$$"
compose=(docker compose --project-name "$project" --file tests/docker/compose.yml)
status=0

cleanup() {
    echo
    echo "== Aufräumen (docker compose down -v)"
    local down_output down_rc left_c left_v left_c_rc left_v_rc count_c count_v
    down_output=$("${compose[@]}" --profile unit --profile runner down -v --remove-orphans 2>&1)
    down_rc=$?
    if [ "$down_rc" -ne 0 ]; then
        printf '%s\n' "$down_output" >&2
        echo "   FEHLER: docker compose down ist fehlgeschlagen" >&2
        status=1
    fi

    # Docker's status is captured before counting. A failed query is unknown,
    # never zero; wc must not turn an empty error response into success.
    left_c=$(docker ps -aq --filter "label=com.docker.compose.project=$project")
    left_c_rc=$?
    left_v=$(docker volume ls -q --filter "label=com.docker.compose.project=$project")
    left_v_rc=$?
    count_c=0
    count_v=0
    [ -z "$left_c" ] || count_c=$(printf '%s\n' "$left_c" | wc -l)
    [ -z "$left_v" ] || count_v=$(printf '%s\n' "$left_v" | wc -l)

    if [ "$left_c_rc" -ne 0 ] || [ "$left_v_rc" -ne 0 ]; then
        echo "   FEHLER: verbliebene Container/Volumes konnten nicht abgefragt werden" >&2
        status=1
    else
        echo "   verbliebene Container: $count_c, Volumes: $count_v"
        if [ "$count_c" -ne 0 ] || [ "$count_v" -ne 0 ]; then
            # was genau übrig ist, damit ein roter Lauf sich erklären lässt
            docker ps -a --filter "label=com.docker.compose.project=$project" --format '   Rest: Container {{.Names}} ({{.Status}})'
            docker volume ls --filter "label=com.docker.compose.project=$project" --format '   Rest: Volume {{.Name}}'
            status=1
        fi
    fi
    if [ "$status" -eq 0 ]; then
        echo "== Ergebnis: alle Tests grün (Roundcube $RC_TAG, messages_cache=$MESSAGES_CACHE)"
    else
        echo "== Ergebnis: FEHLGESCHLAGEN (Roundcube $RC_TAG, messages_cache=$MESSAGES_CACHE)"
    fi
}
trap 'cleanup; exit "$status"' EXIT
trap 'status=130; exit' INT TERM

step() { echo; echo "== $*"; }

step "Images bauen (Roundcube $RC_TAG)"
if ! "${compose[@]}" --profile unit --profile runner build --quiet; then
    status=1
    exit
fi

step "Syntaxprüfung und Unit-Tests unter PHP 8.4"
"${compose[@]}" run --rm --no-deps phpunit || status=1
step "Syntaxprüfung und Unit-Tests unter PHP 8.1"
"${compose[@]}" run --rm --no-deps phpunit81 || status=1

step "Dovecot und Roundcube starten, auf Healthchecks warten"
if ! "${compose[@]}" up --detach --wait --wait-timeout 180 \
        dovecot dovecot-nouidplus dovecot-quota roundcube; then
    "${compose[@]}" logs --no-color --tail 80
    status=1
    exit
fi

step "Integrations- und Browsertests (Roundcube $RC_TAG, messages_cache=$MESSAGES_CACHE)"
if ! "${compose[@]}" run --rm runner python3 -m pytest "$@"; then
    status=1
    echo "-- letzte Zeilen von Roundcube und Dovecot:"
    "${compose[@]}" logs --no-color --tail 40 roundcube dovecot
fi

exit
