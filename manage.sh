#!/usr/bin/env bash
#
# InvoGrid — administration.
#
#   sudo ./manage.sh help
#
# One place for the jobs that are awkward or risky without a proper flow:
# migrations, account and credential resets, backups, the queue, health checks
# and config. Day-to-day work — reviewing and submitting documents — is done in
# the web interface and deliberately has no command here.
#
# Anything that needs the database goes through bin/console.php so it uses the
# application's own models, prepared statements and guard rails. Changing a role
# with the database client would walk straight past the rule that stops you
# stranding the site with no administrator. Anything that needs root — services,
# ownership, backups, cron — is done here.
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$APP_DIR/.env"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/invogrid}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"
REPO_URL="${REPO_URL:-https://github.com/maeterlinckle/invogrid.git}"

QUIET=no
ASSUME_YES=no

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[36m'
else
    C_RESET=""; C_BOLD=""; C_DIM=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""
fi

say()  { [ "$QUIET" = yes ] || printf '%s\n' "$*"; }
step() { [ "$QUIET" = yes ] || printf '\n%s==>%s %s%s%s\n' "$C_BLUE" "$C_RESET" "$C_BOLD" "$*" "$C_RESET"; }
ok()   { [ "$QUIET" = yes ] || printf '  %s[ ok ]%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
info() { [ "$QUIET" = yes ] || printf '  %s[ .. ]%s %s\n' "$C_DIM" "$C_RESET" "$*"; }
warn() { printf '  %s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
die()  { printf '\n%sError:%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }

# Is there a systemd unit by this name?
#
# The output is captured and tested, never piped into `grep -q`. This script
# runs under `set -o pipefail`, where grep exiting on its first match SIGPIPEs
# the producer and the pipeline then reports failure — so a match reads as a
# miss. Inherited from the sibling projects, where it cost an install.
unit_exists() { # unit_exists NAME
    have systemctl || return 1

    local units
    units="$(systemctl list-unit-files --no-legend "$1.service" 2>/dev/null || true)"

    [ -n "$units" ]
}

usage() {
    cat <<'USAGE'
InvoGrid — administration

  sudo ./manage.sh <command> [arguments]

Checking
  status                      services, versions, disk and the pipeline at a glance
  doctor                      full check of PHP, config, storage, database and integrations
  health                      call the site's own /health endpoint
  stats                       document and queue counts
  test                        run the three verification harnesses
  logs [-f] [-n LINES]        the application log

Users
  users                       list every account
  create-admin                create the first administrator (interactive)
  reset-password USERNAME     set a new password; they must change it on sign-in
  unlock [USERNAME]           clear sign-in lockouts (all accounts if none given)
  activate USERNAME           re-enable an account
  deactivate USERNAME         disable one
  set-role USERNAME ROLE      viewer, reviewer or admin

Application
  settings                    which settings are configured
  set-setting KEY             set one, reading the value from stdin
  config KEY [VALUE]          read or change a value in .env
  migrate [--status]          apply pending database migrations
  db-grant                    re-apply the database grant (fixes a migration
                              that stops with "command denied")
  reset-database              empty the database and rebuild the schema
                              (asks twice; ignores --yes)
  reset-storage               delete every downloaded PDF and rendered page
                              (asks twice; ignores --yes)

Pipeline
  queue [--status]            run one pass of the queue worker, or just look
  refresh [--sync]            refresh the Clear Books cache now
  sync-invoices [--status]    fetch the bills and credit notes Clear Books holds
  retry ID                    put one failed document back to its failed stage

Server
  backup [DIR]                dump the database and archive the PDFs and logo
  restore DUMP [FILES]        restore from a backup
  update [SOURCE_DIR]         copy in a new version and migrate
                              (no SOURCE_DIR: pull from the project repository)
  permissions                 re-apply ownership and file modes
  package [FILE]              build a distributable archive of this install
  cron-install                the queue worker, the Clear Books refresh and the
                              invoice sync
  cron-remove                 remove them again
  restart                     restart the web server and PHP-FPM

Options
  --quiet                     only print warnings and errors (for cron)
  --yes                       do not ask for confirmation
USAGE
}

# ---------------------------------------------------------------------------
# Environment
# ---------------------------------------------------------------------------
require_root() {
    [ "$(id -u)" -eq 0 ] || die "This needs root:  sudo $0 $*"
}

env_get() { # env_get KEY
    local key="$1" line value
    [ -r "$ENV_FILE" ] || return 0

    line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | tail -1 || true)"
    [ -n "$line" ] || return 0

    value="${line#*=}"
    value="${value#"${value%%[![:space:]]*}"}"          # trim leading space
    value="${value%"${value##*[![:space:]]}"}"          # trim trailing space

    case "$value" in
        \"*\") value="${value%\"}"; value="${value#\"}" ;;
        \'*\') value="${value%\'}"; value="${value#\'}" ;;
        *)     value="${value%% #*}" ;;                 # strip an inline comment
    esac

    printf '%s' "$value"
}

env_set() { # env_set KEY VALUE
    local key="$1" value="$2" backup tmp

    [ -f "$ENV_FILE" ] || die "No .env at $ENV_FILE."

    backup="$ENV_FILE.$(date +%Y%m%d-%H%M%S).bak"
    cp -p "$ENV_FILE" "$backup"
    chmod 600 "$backup"

    if grep -qE "^[[:space:]]*${key}=" "$ENV_FILE"; then
        # Written through a temp file so the original mode and owner survive.
        tmp="$(mktemp)"
        awk -v k="$key" -v v="$value" '
            $0 ~ "^[[:space:]]*" k "=" { print k "=" v; next }
            { print }
        ' "$ENV_FILE" > "$tmp"
        cat "$tmp" > "$ENV_FILE"
        rm -f "$tmp"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi

    ok "$key set (previous .env kept as $(basename "$backup"))"
}

detect_web_user() {
    local candidate
    for candidate in www-data apache http nginx; do
        id -u "$candidate" >/dev/null 2>&1 && { printf '%s' "$candidate"; return 0; }
    done
    printf 'root'
}

PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || die "PHP is not on the PATH."

WEB_USER="$(detect_web_user)"
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || printf '%s' "$WEB_USER")"
DB_CLIENT="$(command -v mariadb || command -v mysql || true)"
DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"

WEB_READ_CHECKED=no

run_as_web() {
    if [ "$(id -u)" -ne 0 ]; then
        "$@"
    elif have runuser; then
        runuser -u "$WEB_USER" -- "$@"
    elif have sudo; then
        sudo -u "$WEB_USER" -- "$@"
    else
        local quoted="" arg
        for arg in "$@"; do quoted+=" $(printf '%q' "$arg")"; done
        su -s /bin/sh -c "$quoted" "$WEB_USER"
    fi
}

# PHP runs as the web user, so the web user has to be able to read the tree. It
# cannot if the application sits somewhere only root can traverse — a checkout
# under /root being the usual way that happens. Say so once, plainly, instead of
# letting PHP fail to open src/bootstrap.php over and over.
assert_web_can_read() {
    [ "$WEB_READ_CHECKED" = yes ] && return 0
    WEB_READ_CHECKED=yes

    [ "$(id -u)" -eq 0 ] || return 0
    id -u "$WEB_USER" >/dev/null 2>&1 || return 0

    if run_as_web test -r "$APP_DIR/src/bootstrap.php" 2>/dev/null; then
        return 0
    fi

    say "" >&2
    printf '%sThe web server user cannot read this installation.%s\n' "$C_BOLD" "$C_RESET" >&2
    say "" >&2
    warn "$WEB_USER cannot read $APP_DIR/src/bootstrap.php"
    say "" >&2
    say "  PHP runs as $WEB_USER, so it needs to read the application files." >&2
    say "  A directory only root can enter — anything under /root, typically —" >&2
    say "  will always fail this way." >&2
    say "" >&2
    say "  Fix the ownership and modes:" >&2
    say "" >&2
    say "      sudo $APP_DIR/manage.sh permissions" >&2
    say "" >&2
    say "  If the application really does live under /root, move it somewhere" >&2
    say "  the web server can reach, such as /var/www/invogrid." >&2
    say "" >&2

    exit 1
}

console() {
    assert_web_can_read
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" bin/console.php "$@" )
}

app_script() { # app_script <relative-script-path> [args...]
    assert_web_can_read
    local script="$1"; shift
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" "$script" "$@" )
}

confirm() {
    local question="$1" answer
    [ "$ASSUME_YES" = yes ] && return 0
    read -r -p "  $question [y/N]: " answer || true
    [ "${answer,,}" = y ] || [ "${answer,,}" = yes ]
}

web_service() {
    local candidate
    for candidate in apache2 httpd nginx; do
        if unit_exists "$candidate"; then
            printf '%s' "$candidate"; return 0
        fi
    done
    printf ''
}

db_service() {
    local candidate
    for candidate in mariadb mysqld mysql; do
        if unit_exists "$candidate"; then
            printf '%s' "$candidate"; return 0
        fi
    done
    printf ''
}

# A defaults file for the application's own database credentials, so a password
# never appears in the process list — and is removed however the script ends.
#
# An EXIT trap rather than a per-function RETURN trap. A RETURN trap only fires
# when the function returns normally, and `set -e` does not return from a
# function — it exits the shell. So the one case the cleanup exists for, a
# command failing part-way, is the one case a RETURN trap does not cover: a
# failed backup would leave the password in /tmp.
DB_CNF_FILES=()

db_cnf_cleanup() {
    local f
    for f in ${DB_CNF_FILES+"${DB_CNF_FILES[@]}"}; do
        [ -n "$f" ] && rm -f "$f"
    done
    DB_CNF_FILES=()
}

trap db_cnf_cleanup EXIT

db_client_cnf() {
    local cnf; cnf="$(mktemp)"; chmod 600 "$cnf"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
        "$(env_get DB_USERNAME)" "$(env_get DB_PASSWORD)" \
        "$(env_get DB_HOST)" "$(env_get DB_PORT)" > "$cnf"
    DB_CNF_FILES+=("$cnf")
    printf '%s' "$cnf"
}

# ---------------------------------------------------------------------------
# Checking
# ---------------------------------------------------------------------------
cmd_status() {
    step "InvoGrid at $APP_DIR"

    local version="unknown"
    if have git && [ -d "$APP_DIR/.git" ]; then
        version="$(cd "$APP_DIR" && git rev-parse --short HEAD 2>/dev/null || echo unknown)"
    fi

    say "  Version      $version"
    say "  PHP          $("$PHP_BIN" -r 'echo PHP_VERSION;')"
    say "  Web user     $WEB_USER:$WEB_GROUP"
    say "  URL          $(env_get APP_URL)"

    local svc; svc="$(web_service)"
    if [ -n "$svc" ] && have systemctl; then
        say "  Web server   $svc — $(systemctl is-active "$svc" 2>/dev/null || echo unknown)"
    fi

    local dbsvc; dbsvc="$(db_service)"
    if [ -n "$dbsvc" ] && have systemctl; then
        say "  Database     $dbsvc — $(systemctl is-active "$dbsvc" 2>/dev/null || echo unknown)"
    fi

    # poppler is the one local dependency without which nothing works at all,
    # so it belongs in the glance rather than only in doctor.
    if have pdftoppm; then
        say "  pdftoppm     $(pdftoppm -v 2>&1 | head -1)"
    else
        warn "pdftoppm is NOT installed — no document can be read"
    fi

    say "  Disk         $(df -h "$APP_DIR" | awk 'NR==2 {print $4 " free of " $2}')"

    if [ -d "$APP_DIR/storage" ]; then
        say "  Storage      $(du -sh "$APP_DIR/storage" 2>/dev/null | cut -f1) used"
    fi

    say ""
    console stats || true

    local cron=/etc/cron.d/invogrid
    say ""
    if [ -f "$cron" ]; then
        ok "Cron installed ($cron)"
    else
        warn "No cron entries. The queue will not move on its own: $0 cron-install"
    fi
}

cmd_doctor() { console doctor; }
cmd_stats()  { console stats; }
cmd_users()  { console user:list; }

cmd_health() {
    local url; url="$(env_get APP_URL)"
    [ -n "$url" ] || die "APP_URL is not set in .env."

    have curl || die "curl is not installed."

    local body status
    body="$(curl -fsS --max-time 10 "$url/health" 2>&1)" && status=ok || status=failed

    if [ "$status" = ok ]; then
        ok "$url/health — $body"
    else
        die "$url/health did not answer: $body"
    fi
}

# The three harnesses that ship with the application. Worth one command,
# because "is this install actually sound?" is a question with an answer.
cmd_test() {
    local url; url="$(env_get APP_URL)"
    local failed=0

    step "Smoke tests"
    app_script tests/smoke.php || failed=1

    step "Pipeline audit"
    app_script tests/pipeline.php || failed=1

    if [ -n "$url" ]; then
        step "Permission sweep against $url"
        say "  Creates three throwaway accounts, requests every route as each,"
        say "  and removes them again. Nothing is submitted."
        app_script tests/permissions.php "$url" || failed=1
    else
        warn "APP_URL is not set, so the permission sweep was skipped."
    fi

    [ "$failed" -eq 0 ] || die "Something above failed."
    ok "All three passed"
}

cmd_logs() {
    local log="$APP_DIR/storage/logs/app.log"
    [ -f "$log" ] || die "No log at $log yet."

    local follow=no lines=100
    while [ $# -gt 0 ]; do
        case "$1" in
            -f|--follow) follow=yes ;;
            -n) shift; lines="${1:-100}" ;;
            -n*) lines="${1#-n}" ;;
        esac
        shift || true
    done

    if [ "$follow" = yes ]; then
        tail -n "$lines" -f "$log"
    else
        tail -n "$lines" "$log"
    fi
}

# ---------------------------------------------------------------------------
# Users
# ---------------------------------------------------------------------------
# Every one of these goes through bin/console.php, so the rules hold: a username
# never changes, an account is deactivated rather than deleted, and the last
# active administrator cannot be demoted or switched off. Those live in the
# model, which is why they hold here as well as on the web.
cmd_create_admin() {
    assert_web_can_read
    ( cd "$APP_DIR" && run_as_web "$PHP_BIN" bin/create-admin.php "$@" )
}

cmd_reset_password() {
    [ -n "${1:-}" ] || die "Which account? Usage: $0 reset-password USERNAME"
    console user:password "$1"
}

cmd_unlock()     { console user:unlock "${1:-}"; }
cmd_activate()   { [ -n "${1:-}" ] || die "Which account? Usage: $0 activate USERNAME";   console user:activate "$1"; }
cmd_deactivate() { [ -n "${1:-}" ] || die "Which account? Usage: $0 deactivate USERNAME"; console user:deactivate "$1"; }

cmd_set_role() {
    [ -n "${1:-}" ] && [ -n "${2:-}" ] || die "Usage: $0 set-role USERNAME ROLE   (viewer, reviewer or admin)"
    console user:role "$1" "$2"
}

# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------
cmd_settings() { console settings:list; }

cmd_set_setting() {
    [ -n "${1:-}" ] || die "Usage: $0 set-setting KEY   (the value is read from stdin)"
    console settings:set "$1"
}

cmd_config() {
    local key="${1:-}" value="${2:-}"

    [ -n "$key" ] || die "Usage: $0 config KEY [VALUE]"

    if [ $# -lt 2 ]; then
        printf '%s\n' "$(env_get "$key")"
        return 0
    fi

    require_root config
    env_set "$key" "$value"

    local svc; svc="$(web_service)"
    [ -n "$svc" ] && have systemctl && systemctl reload "$svc" >/dev/null 2>&1 || true
}

cmd_migrate() {
    if [ "${1:-}" = "--status" ]; then
        app_script bin/migrate.php --status
    else
        app_script bin/migrate.php
    fi
}

# The application user is granted exactly what the migrations need and nothing
# more. A migration that stops with "command denied" usually means the grant was
# narrowed by hand, or the database was created before this list settled.
cmd_db_grant() {
    require_root db-grant
    [ -n "$DB_CLIENT" ] || die "Neither the mariadb nor the mysql client is installed."

    local db user host
    db="$(env_get DB_DATABASE)"
    user="$(env_get DB_USERNAME)"
    host="$(env_get DB_HOST)"

    [ -n "$db" ] && [ -n "$user" ] || die "DB_DATABASE and DB_USERNAME must be set in .env."

    local from=localhost
    [ "$host" = "127.0.0.1" ] || [ "$host" = "localhost" ] || from="%"

    step "Re-applying the grant on $db to $user@$from"

    "$DB_CLIENT" <<SQL
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
  ON \`$db\`.* TO '$user'@'$from';
FLUSH PRIVILEGES;
SQL

    ok "Granted"
    say "  Deliberately no EVENT, TRIGGER or CREATE ROUTINE: the schema is plain"
    say "  tables written by numbered migrations, and the backup is taken with"
    say "  --skip-events --skip-routines to match."
}

cmd_reset_database() {
    require_root reset-database

    local db; db="$(env_get DB_DATABASE)"
    [ -n "$db" ] || die "DB_DATABASE is not set in .env."
    [ -n "$DB_CLIENT" ] || die "Neither the mariadb nor the mysql client is installed."

    say ""
    printf '%sThis destroys every document, extraction, submission and account in %s.%s\n' \
        "$C_BOLD" "$db" "$C_RESET"
    say ""
    say "  What is already in Clear Books stays in Clear Books — this cannot"
    say "  withdraw a submitted bill. What is lost is InvoGrid's record of it,"
    say "  including which documents have already been submitted — so anything"
    say "  uploaded again would be read and submitted a second time."
    say ""

    # Asks twice and ignores --yes: there is no undo, and a scripted --yes is
    # exactly how somebody empties the wrong database.
    local saved="$ASSUME_YES"
    ASSUME_YES=no

    confirm "Empty $db completely?" || { ASSUME_YES="$saved"; die "Nothing was changed."; }

    local typed
    read -r -p "  Type the database name to confirm: " typed || true
    [ "$typed" = "$db" ] || { ASSUME_YES="$saved"; die "That did not match. Nothing was changed."; }

    ASSUME_YES="$saved"

    step "Rebuilding $db"

    local cnf; cnf="$(db_client_cnf)"
    "$DB_CLIENT" --defaults-extra-file="$cnf" -e "DROP DATABASE \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    cmd_migrate
    ok "Schema rebuilt. Create an administrator:  $0 create-admin"
}

cmd_reset_storage() {
    require_root reset-storage

    say ""
    printf '%sThis deletes every ingested PDF and every rendered page.%s\n' "$C_BOLD" "$C_RESET"
    say ""
    printf '  %sThe PDFs are not recoverable.%s InvoGrid holds the only copy —\n' "$C_BOLD" "$C_RESET"
    say "  they were uploaded to it, not fetched from somewhere that still has"
    say "  them. Restore from a backup, or ask for them again."
    say ""
    say "  The page images alone would be safe to delete: they are re-rendered"
    say "  from the PDF beside them. This command removes both."
    say ""
    say "  The database rows are left alone, so every document will show a"
    say "  missing PDF rather than disappearing."
    say ""
    say "  The uploaded logos are NOT touched."
    say ""

    local saved="$ASSUME_YES"
    ASSUME_YES=no
    confirm "Delete storage/pdf and storage/pages?" || { ASSUME_YES="$saved"; die "Nothing was changed."; }
    read -r -p "  Type DELETE to confirm: " typed || true
    [ "${typed:-}" = "DELETE" ] || { ASSUME_YES="$saved"; die "That did not match. Nothing was changed."; }
    ASSUME_YES="$saved"

    step "Clearing"
    rm -rf "${APP_DIR:?}/storage/pdf"/* "${APP_DIR:?}/storage/pages"/*
    cmd_permissions
    ok "Cleared. Retry a document from /documents to fetch and render it again."
}

# ---------------------------------------------------------------------------
# Pipeline
# ---------------------------------------------------------------------------
cmd_queue() {
    if [ "${1:-}" = "--status" ]; then
        app_script bin/process-queue.php --status
    else
        app_script bin/process-queue.php --verbose "$@"
    fi
}

cmd_refresh() {
    app_script bin/refresh-clearbooks.php "$@"
}

cmd_sync_invoices() {
    # --force by default: somebody typing this has decided it should happen now.
    # The bare script obeys the schedule, which is what cron wants and not what
    # a person at a terminal means.
    if [ "${1:-}" = "--status" ]; then
        app_script bin/sync-invoices.php --status
    else
        app_script bin/sync-invoices.php --force "$@"
    fi
}

cmd_retry() {
    [ -n "${1:-}" ] || die "Which document? Usage: $0 retry ID"

    # Deliberately says who to be rather than doing it silently. Retrying is a
    # human decision that the web screen records against a person, and there is
    # nobody signed in here to record — so this points at the screen that can
    # do it properly, and offers the blunt instrument only if that is no use.
    warn "A retry from the command line has nobody to record it against."
    say "  The document page does it properly, and says which stage it will resume at:"
    say "    $(env_get APP_URL)/documents/$1"
    say ""

    confirm "Queue the failed stage for document $1 anyway?" || die "Nothing was changed."

    console queue:retry "$1"
}

# ---------------------------------------------------------------------------
# Server
# ---------------------------------------------------------------------------
cmd_backup() {
    require_root backup
    [ -n "$DUMP_BIN" ] || die "Neither mariadb-dump nor mysqldump is installed."

    local dir="${1:-$BACKUP_DIR}"
    local stamp; stamp="$(date +%Y%m%d-%H%M%S)"
    local db;    db="$(env_get DB_DATABASE)"

    mkdir -p "$dir"
    chmod 700 "$dir"

    step "Backing up to $dir"

    local dump="$dir/${db}-${stamp}.sql.gz"
    local cnf; cnf="$(db_client_cnf)"

    # No --events and no --routines.
    #
    # The application user is granted SELECT, INSERT, UPDATE, DELETE, CREATE,
    # DROP, ALTER, INDEX and REFERENCES on its own schema and nothing else —
    # see db-grant, where that list is deliberate. Dumping events needs the
    # EVENT privilege it does not have, so asking for them fails the whole
    # backup with "Couldn't execute 'show events': Access denied".
    #
    # Nothing is lost by not asking: this schema is plain tables written by
    # numbered migrations, with no events, routines, triggers or views.
    #
    # --single-transaction stays, and matters for the same reason: a consistent
    # snapshot without LOCK TABLES, which is another privilege it does not hold.
    "$DUMP_BIN" --defaults-extra-file="$cnf" --single-transaction --skip-events --skip-routines "$db" \
        | gzip -9 > "$dump"

    chmod 600 "$dump"
    ok "Database  $(basename "$dump")  ($(du -h "$dump" | cut -f1))"

    # Source PDFs and uploaded logos, but deliberately NOT storage/pages.
    #
    # A page image is re-rendered from the PDF beside it in seconds, so backing
    # them up doubles the size of every backup to save a step that costs
    # nothing. The PDFs are the opposite case and must be in every backup:
    # InvoGrid holds the only copy of an uploaded document, and nothing
    # anywhere else can produce it again.
    local files="$dir/files-${stamp}.tar.gz"
    tar -czf "$files" -C "$APP_DIR/storage" \
        $( [ -d "$APP_DIR/storage/pdf" ] && echo pdf ) \
        $( [ -d "$APP_DIR/storage/uploads" ] && echo uploads )
    chmod 600 "$files"
    ok "Files     $(basename "$files")  ($(du -h "$files" | cut -f1))  — PDFs and logos; page images are re-rendered"

    # .env carries APP_KEY, and without it every stored credential is
    # unreadable — a restored database alone is not a working site.
    cp -p "$ENV_FILE" "$dir/env-${stamp}.bak"
    chmod 600 "$dir/env-${stamp}.bak"
    ok "Config    env-${stamp}.bak"

    if [ "$BACKUP_KEEP" -gt 0 ]; then
        local removed=0 old
        while IFS= read -r old; do rm -f "$old"; removed=$((removed + 1)); done \
            < <(ls -1t "$dir"/*.sql.gz 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)))
        while IFS= read -r old; do rm -f "$old"; done \
            < <(ls -1t "$dir"/files-*.tar.gz 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)))
        while IFS= read -r old; do rm -f "$old"; done \
            < <(ls -1t "$dir"/env-*.bak 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)))
        [ "$removed" -gt 0 ] && info "Removed $removed backup set(s) older than the last $BACKUP_KEEP"
    fi

    say ""
    say "  All three files are needed for a working restore. Copy them off this machine."
}

cmd_restore() {
    require_root restore

    local dump="${1:-}" files="${2:-}"
    [ -n "$dump" ] || die "Usage: $0 restore DUMP.sql.gz [FILES.tar.gz]"
    [ -f "$dump" ] || die "$dump does not exist."
    [ -n "$DB_CLIENT" ] || die "Neither the mariadb nor the mysql client is installed."

    local db; db="$(env_get DB_DATABASE)"

    warn "This replaces everything in $db with the contents of $dump."
    confirm "Restore over $db?" || die "Nothing was changed."

    step "Restoring the database"
    local cnf; cnf="$(db_client_cnf)"

    if [ "${dump##*.}" = "gz" ]; then
        gzip -dc "$dump" | "$DB_CLIENT" --defaults-extra-file="$cnf" "$db"
    else
        "$DB_CLIENT" --defaults-extra-file="$cnf" "$db" < "$dump"
    fi
    ok "Database restored"

    if [ -n "$files" ]; then
        [ -f "$files" ] || die "$files does not exist."
        step "Restoring the files"
        tar -xzf "$files" -C "$APP_DIR/storage"
        ok "PDFs and logos restored — page images will be re-rendered on demand"
    fi

    cmd_permissions
    cmd_migrate
    console doctor || true

    say ""
    warn "If the APP_KEY in .env is not the one this database was written with, no"
    warn "stored credential will decrypt. Restore the matching env-*.bak alongside it,"
    warn "or re-enter every credential in Settings."
}

#
# Apply a new version.
#
# With a directory, copies from there. With nothing, clones the project
# repository into a temp directory first — which is the normal case, and means
# an update is one command on a server with no checkout of its own.
#
cmd_update() {
    require_root update
    local source="${1:-}" tmp=""

    if [ -z "$source" ]; then
        have git || die "git is not installed, so there is nothing to pull with. Pass a directory instead: $0 update /path/to/source"

        tmp="$(mktemp -d)"
        step "Fetching the latest version from $REPO_URL"
        git clone --depth 1 "$REPO_URL" "$tmp/src" >/dev/null 2>&1 \
            || { rm -rf "$tmp"; die "Could not clone $REPO_URL. A private repository needs credentials this machine does not have — pass a directory instead."; }
        source="$tmp/src"
        ok "Cloned $(cd "$source" && git rev-parse --short HEAD)"
    fi

    [ -f "$source/public/index.php" ] || { [ -n "$tmp" ] && rm -rf "$tmp"; die "$source does not look like the InvoGrid source tree."; }

    warn "Back up first if you have not already:  $0 backup"
    if ! confirm "Copy $source over $APP_DIR and run the migrations?"; then
        [ -n "$tmp" ] && rm -rf "$tmp"
        die "Nothing was changed."
    fi

    step "Copying the new version"
    tar -cf - -C "$source" \
        --exclude=./.git --exclude=./.github --exclude=./.claude --exclude=./.env \
        --exclude=./storage/pdf --exclude=./storage/pages \
        --exclude=./storage/uploads --exclude=./storage/logs \
        --exclude='./*.zip' --exclude='./*.tar.gz' \
        . | tar -xf - -C "$APP_DIR"
    ok "Files updated — .env, storage/ and the database were left alone"

    [ -n "$tmp" ] && rm -rf "$tmp"

    cmd_permissions
    cmd_migrate
    console doctor || true

    local svc; svc="$(web_service)"
    [ -n "$svc" ] && have systemctl && systemctl reload "$svc" >/dev/null 2>&1 || true
    ok "Done"
}

cmd_permissions() {
    require_root permissions

    step "Re-applying ownership and modes"

    chown -R root:"$WEB_GROUP" "$APP_DIR"
    find "$APP_DIR" -type d -exec chmod 750 {} +
    find "$APP_DIR" -type f -exec chmod 640 {} +

    chown -R "$WEB_USER":"$WEB_GROUP" "$APP_DIR/storage"
    find "$APP_DIR/storage" -type d -exec chmod 2775 {} +
    find "$APP_DIR/storage" -type f -exec chmod 664 {} +

    local script
    for script in install.sh manage.sh; do
        [ -f "$APP_DIR/$script" ] && chmod 750 "$APP_DIR/$script"
    done

    [ -f "$ENV_FILE" ] && { chown root:"$WEB_GROUP" "$ENV_FILE"; chmod 640 "$ENV_FILE"; }

    if have restorecon && have getenforce && [ "$(getenforce)" = "Enforcing" ]; then
        restorecon -R "$APP_DIR" >/dev/null 2>&1 || true
    fi

    ok "Application root:$WEB_GROUP (750/640), storage $WEB_USER:$WEB_GROUP (2775/664), .env 640"
}

cmd_package() {
    local out="${1:-$PWD/invogrid-$(date +%Y%m%d).tar.gz}"

    step "Building $out"
    tar -czf "$out" -C "$APP_DIR" \
        --exclude=./.git --exclude=./.github --exclude=./.claude --exclude=./.env \
        --exclude=./storage/pdf --exclude=./storage/pages \
        --exclude=./storage/uploads --exclude=./storage/logs \
        --exclude='./*.tar.gz' --exclude='./*.zip' \
        .

    ok "$out  ($(du -h "$out" | cut -f1))"
    say ""
    say "  Copy it to the new server and:"
    say "    mkdir -p invogrid && tar -xzf $(basename "$out") -C invogrid"
    say "    cd invogrid && sudo ./install.sh"
    say ""
    say "  It contains no .env, no documents and no database — nothing secret."
}

cmd_cron_install() {
    require_root cron-install

    local file=/etc/cron.d/invogrid
    cat > "$file" <<CRON
# InvoGrid — installed by manage.sh.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

# The queue worker. Every minute: an uploaded document waits at most that long
# before anything happens to it. Overlapping runs are prevented
# by a lock file inside the script, so a slow LLM call cannot pile up workers.
* * * * * ${WEB_USER} ${PHP_BIN} ${APP_DIR}/bin/process-queue.php >/dev/null 2>&1

# The Clear Books cache — suppliers, account codes, VAT treatments and rates.
# Matching reads this and never Clear Books itself, so a supplier added this
# morning is unmatchable this afternoon without it. The odd minute keeps it off
# the hour with everything else on the machine.
17 * * * * ${WEB_USER} ${PHP_BIN} ${APP_DIR}/bin/refresh-clearbooks.php >/dev/null 2>&1

# The local copy of the bills and credit notes already in Clear Books, which is
# how a document that has been posted before is recognised. The schedule itself
# lives in the database — this only offers the script the chance to decide it is
# due, so an administrator can change "every N minutes" on the Clear Books
# settings screen without editing this file.
*/5 * * * * ${WEB_USER} ${PHP_BIN} ${APP_DIR}/bin/sync-invoices.php >/dev/null 2>&1

# Nightly backup of the database, the PDFs and .env.
15 2 * * * root ${APP_DIR}/manage.sh backup --quiet
CRON

    chmod 644 "$file"
    ok "Wrote $file"
    say "  Queue      every minute"
    say "  Clear Books cache  hourly at :17"
    say "  Invoice sync       every 5 minutes, fetching when the schedule says"
    say "  Backup     02:15, to $BACKUP_DIR, keeping the last $BACKUP_KEEP sets"
}

cmd_cron_remove() {
    require_root cron-remove
    rm -f /etc/cron.d/invogrid
    ok "Removed /etc/cron.d/invogrid"
    warn "Nothing will process documents now until it is put back."
}

cmd_restart() {
    require_root restart

    local svc; svc="$(web_service)"
    [ -n "$svc" ] || die "No web server unit found."

    systemctl restart "$svc"
    ok "Restarted $svc"

    local unit
    for unit in $(systemctl list-units --no-legend 'php*fpm*' 2>/dev/null | awk '{print $1}'); do
        systemctl restart "$unit" && ok "Restarted $unit"
    done
}

# ---------------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------------
ARGS=()
for arg in "$@"; do
    case "$arg" in
        --quiet) QUIET=yes ;;
        --yes|-y) ASSUME_YES=yes ;;
        *) ARGS+=("$arg") ;;
    esac
done
set -- ${ARGS+"${ARGS[@]}"}

command="${1:-help}"
shift || true

case "$command" in
    status)             cmd_status ;;
    doctor)             cmd_doctor ;;
    stats)              cmd_stats ;;
    health)             cmd_health ;;
    test)               cmd_test ;;
    logs)               cmd_logs "$@" ;;

    users)              cmd_users ;;
    create-admin)       cmd_create_admin "$@" ;;
    reset-password)     cmd_reset_password "${1:-}" ;;
    unlock)             cmd_unlock "${1:-}" ;;
    activate)           cmd_activate "${1:-}" ;;
    deactivate)         cmd_deactivate "${1:-}" ;;
    set-role)           cmd_set_role "${1:-}" "${2:-}" ;;

    settings)           cmd_settings ;;
    set-setting)        cmd_set_setting "${1:-}" ;;
    config)             cmd_config "$@" ;;
    migrate)            cmd_migrate "${1:-}" ;;
    db-grant)           cmd_db_grant ;;
    reset-database)     cmd_reset_database ;;
    reset-storage)      cmd_reset_storage ;;

    queue)              cmd_queue "$@" ;;
    refresh)            cmd_refresh "$@" ;;
    sync-invoices)      cmd_sync_invoices "$@" ;;
    retry)              cmd_retry "${1:-}" ;;

    backup)             cmd_backup "${1:-}" ;;
    restore)            cmd_restore "${1:-}" "${2:-}" ;;
    update)             cmd_update "${1:-}" ;;
    permissions)        cmd_permissions ;;
    package)            cmd_package "${1:-}" ;;
    cron-install)       cmd_cron_install ;;
    cron-remove)        cmd_cron_remove ;;
    restart)            cmd_restart ;;

    help|--help|-h)     usage ;;
    *)                  usage; die "Unknown command: $command" ;;
esac
