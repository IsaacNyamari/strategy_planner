#!/usr/bin/env bash
#
# Root-owned helper behind the "Deploy" button in the app.
# Installed to /usr/local/bin/strategy-planner-deploy by deploy/install-web-deploy.sh.
# The web server (www-data) may run ONLY this file, via sudo, with these commands:
#
#   strategy-planner-deploy run <requested-by>   start ./deploy.sh in the background
#   strategy-planner-deploy status               print live + latest GitHub commit (JSON)
#
# It never takes code or paths from the web request.
#
set -Eeuo pipefail

APP_DIR="/var/www/strategy_planner"
LOG="$APP_DIR/storage/logs/deploy.log"
STATE="$APP_DIR/storage/app/deploy-state.json"
LOCK="/run/strategy-planner-deploy.lock"
BRANCH="main"

json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | tr -d '\000-\037'; }

write_state() { # write_state status started finished exit commit by
    mkdir -p "$(dirname "$STATE")"
    printf '{"status":"%s","started_at":"%s","finished_at":"%s","exit_code":%s,"commit":"%s","requested_by":"%s"}\n' \
        "$1" "$2" "$3" "${4:-null}" "$(json_escape "$5")" "$(json_escape "$6")" > "$STATE.tmp"
    mv "$STATE.tmp" "$STATE"
    chown www-data:www-data "$STATE" 2>/dev/null || true
    chmod 644 "$STATE"
}

case "${1:-}" in
    status)
        cd "$APP_DIR"
        current="$(git log -1 --format='%h|%s|%cI' 2>/dev/null || echo '||')"
        latest="$(timeout 15 git ls-remote origin "refs/heads/$BRANCH" 2>/dev/null | cut -c1-7 || true)"
        IFS='|' read -r c_hash c_msg c_date <<< "$current"
        printf '{"current":{"hash":"%s","message":"%s","date":"%s"},"latest":"%s"}\n' \
            "$(json_escape "$c_hash")" "$(json_escape "$c_msg")" "$(json_escape "$c_date")" "$(json_escape "$latest")"
        ;;

    run)
        # Who asked (for the log only); keep it to safe characters.
        by="$(printf '%s' "${2:-unknown}" | tr -cd 'A-Za-z0-9@._+ -' | cut -c1-80)"

        if ! flock -n "$LOCK" true \
            || systemctl list-units --no-legend --state=active,activating 'strategy-planner-deploy-*' 2>/dev/null | grep -q .; then
            echo "A deployment is already running." >&2
            exit 3
        fi

        started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        write_state running "$started" "" null "" "$by"
        printf 'Deployment requested by %s at %s\n\n' "$by" "$started" > "$LOG"
        chown www-data:www-data "$LOG" 2>/dev/null || true

        # Hand the job to systemd as its own unit, fully detached from the web
        # server (deploy.sh reloads PHP-FPM, which must not take the job with it).
        # systemd jobs start with a nearly empty environment; give it the basics.
        systemd-run --quiet --collect --unit="strategy-planner-deploy-$(date +%s)" \
            --setenv=HOME=/root \
            --setenv=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
            --setenv=COMPOSER_ALLOW_SUPERUSER=1 \
            "$(readlink -f "$0")" _worker "$by" "$started"
        echo "started"
        ;;

    _worker) # internal: started by systemd-run above, as root
        by="${2:-unknown}"
        started="${3:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
        exec 9>"$LOCK"
        flock 9
        set +e
        cd "$APP_DIR"
        ./deploy.sh "$BRANCH" >> "$LOG" 2>&1
        code=$?
        commit="$(git log -1 --format='%h %s' 2>/dev/null)"
        write_state "$([ $code -eq 0 ] && echo success || echo failed)" "$started" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$code" "$commit" "$by"
        chown www-data:www-data "$LOG" 2>/dev/null || true
        exit "$code"
        ;;

    *)
        echo "usage: $0 run <requested-by> | status" >&2
        exit 2
        ;;
esac
