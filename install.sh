#!/usr/bin/env bash
#
# InvoGrid installer.
#
#   sudo ./install.sh
#
# Takes a Debian/Ubuntu or RHEL-family machine from nothing to a working
# install: packages, database, configuration, web server, cron and the first
# administrator. Re-runnable — an existing database is left alone and its
# credentials refreshed rather than being dropped.
#
# What it deliberately does not do: obtain a TLS certificate, or configure a
# reverse proxy. Both are site decisions with better tools than a shell script.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

INSTALL_DIR="${INSTALL_DIR:-/var/www/invogrid}"
APP_NAME="${APP_NAME:-InvoGrid}"
APP_URL="${APP_URL:-}"
APP_TIMEZONE="${APP_TIMEZONE:-Europe/London}"

DB_NAME="${DB_NAME:-invogrid}"
DB_USER="${DB_USER:-invogrid}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-}"
DB_ROOT_CNF=""
DB_PASSWORD_GENERATED=no

WEB_SERVER="${WEB_SERVER:-apache}"
SERVER_NAME="${SERVER_NAME:-}"
TLS_MODE="${TLS_MODE:-proxy}"
TLS_CERT="${TLS_CERT:-}"
TLS_KEY="${TLS_KEY:-}"
MAKE_DEFAULT_SITE="${MAKE_DEFAULT_SITE:-no}"

ADMIN_USERNAME="${ADMIN_USERNAME:-}"
ADMIN_NAME="${ADMIN_NAME:-}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"

INSTALL_CRON="${INSTALL_CRON:-}"
SKIP_PACKAGES=no
NON_INTERACTIVE=no
DRY_RUN=no
ASSUME_YES=no
ANSWERS_FILE=""


if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[36m'
else
    C_RESET=""; C_BOLD=""; C_DIM=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""
fi

say()  { printf '%s\n' "$*"; }
step() { printf '\n%s==>%s %s%s%s\n' "$C_BLUE" "$C_RESET" "$C_BOLD" "$*" "$C_RESET"; }
ok()   { printf '  %s[ ok ]%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
info() { printf '  %s[ .. ]%s %s\n' "$C_DIM" "$C_RESET" "$*"; }
warn() { printf '  %s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$*"; }
die()  { printf '\n%sError:%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

cleanup() { [ -n "$DB_ROOT_CNF" ] && rm -f "$DB_ROOT_CNF"; }
trap cleanup EXIT

have() { command -v "$1" >/dev/null 2>&1; }

# Captured and tested, never piped into `grep -q`: under `set -o pipefail`,
# grep exiting on its first match SIGPIPEs the producer and the pipeline then
# reports failure — so a match reads as a miss. It has cost an install before.
unit_exists() {
    have systemctl || return 1
    local units
    units="$(systemctl list-unit-files --no-legend "$1.service" 2>/dev/null || true)"
    [ -n "$units" ]
}

usage() {
    cat <<'USAGE'
InvoGrid installer

  sudo ./install.sh [options]

Options
  --answers=FILE       read the answers from a file (shell KEY=value lines)
  --non-interactive    never prompt; every required answer must already be set
  --dir=PATH           where to install          (default /var/www/invogrid)
  --domain=NAME        the hostname the site answers on
  --web-server=WHICH   apache | nginx | none     (default apache)
  --tls=MODE           proxy | direct-https | plain-http
  --skip-packages      do not install anything with the package manager
  --cron               install the cron entries without asking
  --dry-run            show the plan and stop without changing anything
  --yes                assume yes for confirmations
  --help               this text

Answers file keys
  INSTALL_DIR APP_NAME APP_URL APP_TIMEZONE
  DB_NAME DB_USER DB_PASSWORD DB_HOST DB_PORT DB_ROOT_PASSWORD
  WEB_SERVER SERVER_NAME TLS_MODE TLS_CERT TLS_KEY MAKE_DEFAULT_SITE
  ADMIN_USERNAME ADMIN_NAME ADMIN_PASSWORD INSTALL_CRON

An answers file holds a database password and an administrator password, so
create it with mode 600 and delete it when the install is done.
USAGE
}

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
for arg in "$@"; do
    case "$arg" in
        --answers=*)     ANSWERS_FILE="${arg#*=}" ;;
        --non-interactive) NON_INTERACTIVE=yes; ASSUME_YES=yes ;;
        --dir=*)         INSTALL_DIR="${arg#*=}" ;;
        --domain=*)      SERVER_NAME="${arg#*=}" ;;
        --web-server=*)  WEB_SERVER="${arg#*=}" ;;
        --tls=*)         TLS_MODE="${arg#*=}" ;;
        --skip-packages) SKIP_PACKAGES=yes ;;
        --cron)          INSTALL_CRON=yes ;;
        --dry-run)       DRY_RUN=yes ;;
        --yes|-y)        ASSUME_YES=yes ;;
        --help|-h)       usage; exit 0 ;;
        *)               usage; die "Unknown option: $arg" ;;
    esac
done

if [ -n "$ANSWERS_FILE" ]; then
    [ -r "$ANSWERS_FILE" ] || die "Cannot read $ANSWERS_FILE."
    # shellcheck disable=SC1090
    . "$ANSWERS_FILE"
fi

# ---------------------------------------------------------------------------
# Prompts
#
# Anything already set — by an answers file, a flag or the environment — is
# taken as answered and never asked about again.
# ---------------------------------------------------------------------------
ask() { # ask VARNAME "Question" "default"
    local name="$1" question="$2" default="${3:-}" current answer
    current="${!name:-}"

    [ -n "$current" ] && return 0
    if [ "$NON_INTERACTIVE" = yes ]; then
        printf -v "$name" '%s' "$default"
        return 0
    fi

    if [ -n "$default" ]; then
        read -r -p "  $question [$default]: " answer || true
        printf -v "$name" '%s' "${answer:-$default}"
    else
        read -r -p "  $question: " answer || true
        printf -v "$name" '%s' "$answer"
    fi
}

ask_required() { # ask_required VARNAME "Question" ["default"]
    local name="$1" question="$2" default="${3:-}"

    while true; do
        ask "$name" "$question" "$default"
        [ -n "${!name:-}" ] && return 0
        [ "$NON_INTERACTIVE" = yes ] && die "$name must be set (answers file or environment)."
        warn "That cannot be empty."
        printf -v "$name" '%s' ""
    done
}

ask_secret() { # ask_secret VARNAME "Question" min_length
    local name="$1" question="$2" min="${3:-12}" first second

    [ -n "${!name:-}" ] && return 0
    if [ "$NON_INTERACTIVE" = yes ]; then
        die "$name must be set (answers file or environment)."
    fi

    while true; do
        read -r -s -p "  $question: " first || true; echo
        if [ "${#first}" -lt "$min" ]; then
            warn "At least $min characters."
            continue
        fi
        read -r -s -p "  Again: " second || true; echo
        if [ "$first" != "$second" ]; then
            warn "Those did not match."
            continue
        fi
        printf -v "$name" '%s' "$first"
        return 0
    done
}

confirm() {
    local question="$1" answer
    [ "$ASSUME_YES" = yes ] && return 0
    read -r -p "  $question [y/N]: " answer || true
    [ "${answer,,}" = y ] || [ "${answer,,}" = yes ]
}

choose() { # choose VARNAME "Question" "opt:description" ...
    local name="$1" question="$2"; shift 2
    local options=("$@") i answer

    [ -n "${!name:-}" ] && return 0
    if [ "$NON_INTERACTIVE" = yes ]; then
        printf -v "$name" '%s' "${options[0]%%:*}"
        return 0
    fi

    say ""
    say "  $question"
    for i in "${!options[@]}"; do
        printf '    %d) %-14s %s\n' $((i + 1)) "${options[$i]%%:*}" "${options[$i]#*:}"
    done

    while true; do
        read -r -p "  Choose [1]: " answer || true
        answer="${answer:-1}"
        if [[ "$answer" =~ ^[0-9]+$ ]] && [ "$answer" -ge 1 ] && [ "$answer" -le "${#options[@]}" ]; then
            printf -v "$name" '%s' "${options[$((answer - 1))]%%:*}"
            return 0
        fi
        warn "Pick a number from the list."
    done
}

random_password() {
    if have openssl; then
        openssl rand -base64 24 | tr -d '/+=' | cut -c1-24
    else
        tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 24
    fi
}

version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -1)" = "$2" ]; }

sql_quote() { printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g"; }

# .env is read by a small parser, not a shell. Quote anything with a space or a
# character that would end the value early.
env_quote() {
    local value="$1"
    case "$value" in
        *[\ \#\"\']*) printf '"%s"' "${value//\"/\\\"}" ;;
        *)            printf '%s' "$value" ;;
    esac
}

run_as_web() {
    if have runuser; then
        runuser -u "$WEB_USER" -- "$@"
    else
        sudo -u "$WEB_USER" -- "$@"
    fi
}

php_app() { ( cd "$INSTALL_DIR" && run_as_web "$PHP_BIN" "$@" ); }

# ---------------------------------------------------------------------------
# 1. The machine
# ---------------------------------------------------------------------------
step "Checking the machine"

# --dry-run only looks, so it does not need root. Requiring it would make the
# one command that exists to be run before you commit to anything the one you
# cannot try without committing to something.
if [ "$DRY_RUN" != yes ] && [ "$(id -u)" -ne 0 ]; then
    die "This needs root:  sudo ./install.sh
     (--dry-run works without it, and changes nothing.)"
fi

[ -f "$SOURCE_DIR/public/index.php" ] || die "Run this from inside the InvoGrid source tree."

PKG=""
if   have apt-get; then PKG=apt
elif have dnf;     then PKG=dnf
elif have yum;     then PKG=yum
fi

if [ -r /etc/os-release ]; then
    . /etc/os-release
    ok "${PRETTY_NAME:-unknown OS}"
else
    warn "No /etc/os-release — carrying on, but package names are a guess."
fi

[ -n "$PKG" ] || { [ "$SKIP_PACKAGES" = yes ] || die "No apt, dnf or yum found. Re-run with --skip-packages and install the requirements yourself."; }
[ -n "$PKG" ] && ok "Package manager: $PKG"

# ---------------------------------------------------------------------------
# 2. What is already here
# ---------------------------------------------------------------------------
step "Looking for PHP, a web server, MariaDB and poppler"

PHP_BIN="$(command -v php || true)"
if [ -n "$PHP_BIN" ]; then
    PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
    if version_ge "$PHP_VERSION" 8.2; then
        ok "PHP $PHP_VERSION"
    else
        warn "PHP $PHP_VERSION is too old — 8.2 or newer is needed."
    fi
else
    info "PHP is not installed yet"
fi

# Written out rather than as `a || b && c || d`: that chain parses as
# `((a || b) && c) || d`, so if the success branch ever returned non-zero both
# branches would print. Cheap to get wrong, confusing to read afterwards.
if have mariadb || have mysql; then
    ok "MariaDB/MySQL client present"
else
    info "No database client yet"
fi

if unit_exists mariadb || unit_exists mysqld || unit_exists mysql; then
    ok "A database server is installed"
else
    info "No database server yet"
fi

# The one dependency without which nothing works at all: no pdftoppm, no page
# images, no OCR, no document ever read. Worth its own line rather than being
# lost in a package list.
if have pdftoppm; then
    ok "pdftoppm — $(pdftoppm -v 2>&1 | head -1)"
else
    info "poppler-utils is not installed yet (pdftoppm) — nothing can be read without it"
fi

# ---------------------------------------------------------------------------
# 3. Settings
# ---------------------------------------------------------------------------
step "Settings"

ask INSTALL_DIR "Install to" "$INSTALL_DIR"
ask APP_NAME    "Application name" "$APP_NAME"
ask APP_TIMEZONE "Timezone" "$APP_TIMEZONE"

choose WEB_SERVER "Which web server?" \
    "apache:Apache with mod_rewrite" \
    "nginx:nginx with PHP-FPM" \
    "none:configure it yourself"

if [ "$WEB_SERVER" != none ]; then
    ask SERVER_NAME "Hostname this site answers on (blank for any)" ""
fi

choose TLS_MODE "How does HTTPS reach this machine?" \
    "proxy:a reverse proxy in front terminates TLS" \
    "direct-https:this machine holds the certificate" \
    "plain-http:no TLS at all (a private LAN only)"

if [ "$TLS_MODE" = direct-https ]; then
    ask_required TLS_CERT "Path to the certificate (fullchain.pem)"
    ask_required TLS_KEY  "Path to the private key"
    [ -f "$TLS_CERT" ] || die "$TLS_CERT does not exist."
    [ -f "$TLS_KEY" ]  || die "$TLS_KEY does not exist."
fi

if [ -z "$APP_URL" ]; then
    scheme=https
    [ "$TLS_MODE" = plain-http ] && scheme=http
    APP_URL="$scheme://${SERVER_NAME:-localhost}"
fi
ask APP_URL "The address people will use" "$APP_URL"

ask DB_NAME "Database name" "$DB_NAME"
ask DB_USER "Database user" "$DB_USER"

if [ -z "$DB_PASSWORD" ]; then
    DB_PASSWORD="$(random_password)"
    DB_PASSWORD_GENERATED=yes
    ok "Generated a database password (it goes in .env and nowhere else)"
fi

# ---------------------------------------------------------------------------
# 4. The first administrator
# ---------------------------------------------------------------------------
step "The first administrator"

say "  The first account is always an administrator: there would otherwise be"
say "  nobody able to configure the integrations."
say ""

ask_required ADMIN_USERNAME "Username" ""
ask ADMIN_NAME "Full name" "$ADMIN_USERNAME"

if [ -z "$ADMIN_PASSWORD" ] && [ "$NON_INTERACTIVE" != yes ]; then
    say ""
    say "  At least 12 characters, using three of: lower case, upper case,"
    say "  numbers, symbols. A passphrase is fine, and easier to remember."
    ask_secret ADMIN_PASSWORD "Password" 12
fi

[ -n "$ADMIN_PASSWORD" ] || die "ADMIN_PASSWORD must be set."

# ---------------------------------------------------------------------------
# 5. The plan
# ---------------------------------------------------------------------------
step "Plan"

say "  Files          $SOURCE_DIR  ->  $INSTALL_DIR"
say "  Document root  $INSTALL_DIR/public"
say "  URL            $APP_URL"
say "  Database       $DB_NAME (user $DB_USER@localhost) on $DB_HOST:$DB_PORT"
say "  Web server     $WEB_SERVER"
say "  TLS            $TLS_MODE"
say "  Administrator  $ADMIN_USERNAME"
say "  Packages       $([ "$SKIP_PACKAGES" = yes ] && echo 'not touched' || echo "installed with $PKG")"
say ""

if [ "$DRY_RUN" = yes ]; then
    say "${C_BOLD}--dry-run: nothing was changed.${C_RESET}"
    exit 0
fi

confirm "Go ahead?" || die "Nothing was changed."

# ---------------------------------------------------------------------------
# 6. Packages
# ---------------------------------------------------------------------------
pkg_install() {
    case "$PKG" in
        apt) DEBIAN_FRONTEND=noninteractive apt-get install -y "$@" >/dev/null ;;
        dnf) dnf install -y "$@" >/dev/null ;;
        yum) yum install -y "$@" >/dev/null ;;
    esac
}

if [ "$SKIP_PACKAGES" != yes ]; then
    step "Installing packages"

    case "$PKG" in
        apt) apt-get update -qq >/dev/null || true ;;
        dnf|yum) : ;;
    esac

    packages=()

    if [ "$PKG" = apt ]; then
        packages+=(php-cli php-mysql php-mbstring php-curl php-xml)
        [ "$WEB_SERVER" = apache ] && packages+=(apache2 libapache2-mod-php)
        [ "$WEB_SERVER" = nginx ]  && packages+=(nginx php-fpm)
        packages+=(mariadb-server mariadb-client)
        packages+=(poppler-utils)
        packages+=(curl ca-certificates)
    else
        packages+=(php-cli php-mysqlnd php-mbstring php-json)
        [ "$WEB_SERVER" = apache ] && packages+=(httpd php)
        [ "$WEB_SERVER" = nginx ]  && packages+=(nginx php-fpm)
        packages+=(mariadb-server)
        packages+=(poppler-utils)
        packages+=(curl ca-certificates)
    fi

    info "${packages[*]}"
    pkg_install "${packages[@]}" || warn "Some packages did not install. The checks below will say what is missing."

    PHP_BIN="$(command -v php || true)"
    ok "Done"
fi

[ -n "$PHP_BIN" ] || die "PHP is still not installed. Install it and re-run."

# ---------------------------------------------------------------------------
# 7. PHP extensions
# ---------------------------------------------------------------------------
step "Checking PHP extensions"

# Captured and matched rather than piped into grep -q, for the pipefail reason
# noted on unit_exists.
php_modules="$("$PHP_BIN" -m 2>/dev/null || true)"

has_module() { [[ $'\n'"$php_modules"$'\n' == *$'\n'"$1"$'\n'* ]]; }

missing=()
for module in PDO pdo_mysql mbstring curl json openssl fileinfo; do
    if has_module "$module"; then
        ok "$module"
    else
        missing+=("$module")
        warn "$module is missing"
    fi
done

if [ "${#missing[@]}" -gt 0 ]; then
    say ""
    say "  openssl in particular is not optional: it encrypts every stored API"
    say "  token, and without it InvoGrid refuses to save one rather than"
    say "  writing it to the database in the clear."
    die "Install the missing PHP extensions and re-run."
fi

if ! have pdftoppm; then
    die "pdftoppm is still missing. InvoGrid renders every page with it, so nothing can be read without it.
     apt install poppler-utils   (or dnf install poppler-utils)"
fi
ok "pdftoppm"

# ---------------------------------------------------------------------------
# 8. Services
# ---------------------------------------------------------------------------
step "Starting services"

service_up() { # service_up NAME [required]
    local name="$1" required="${2:-no}"

    if ! unit_exists "$name"; then
        [ "$required" = yes ] && die "No $name service found."
        return 0
    fi

    systemctl enable --now "$name" >/dev/null 2>&1 || true

    if [ "$(systemctl is-active "$name" 2>/dev/null)" = active ]; then
        ok "$name"
    else
        warn "$name did not start"
        [ "$required" = yes ] && die "InvoGrid cannot run without $name."
    fi
}

DB_UNIT=""
for candidate in mariadb mysqld mysql; do
    unit_exists "$candidate" && { DB_UNIT="$candidate"; break; }
done
[ -n "$DB_UNIT" ] || die "No MariaDB/MySQL service found. Install one, or point DB_HOST at another machine."
service_up "$DB_UNIT" yes

case "$WEB_SERVER" in
    apache) unit_exists apache2 && service_up apache2 || service_up httpd ;;
    nginx)  service_up nginx ;;
esac

for unit in $(systemctl list-unit-files --no-legend 'php*fpm*' 2>/dev/null | awk '{print $1}' | sed 's/\.service$//'); do
    service_up "$unit"
done

# ---------------------------------------------------------------------------
# 9. Database
# ---------------------------------------------------------------------------
step "Setting up the database"

DB_CLIENT="$(command -v mariadb || command -v mysql || true)"
[ -n "$DB_CLIENT" ] || die "No mariadb or mysql client found."

db_root() {
    if [ -n "$DB_ROOT_CNF" ]; then
        "$DB_CLIENT" --defaults-extra-file="$DB_ROOT_CNF" "$@"
    else
        "$DB_CLIENT" -u root "$@"
    fi
}

# A fresh MariaDB authenticates root over the unix socket, so as root we are
# usually already in. Fall back to a password, kept in a 600 defaults file so it
# never appears in the process list.
if "$DB_CLIENT" -u root -e 'SELECT 1' >/dev/null 2>&1; then
    ok "Connected as root over the local socket"
else
    if [ -z "$DB_ROOT_PASSWORD" ]; then
        [ "$NON_INTERACTIVE" = yes ] && die "The database root account needs a password and DB_ROOT_PASSWORD is not set."
        say "  The database root account is password-protected."
        read -r -s -p "  Database root password: " DB_ROOT_PASSWORD || true; echo
    fi

    DB_ROOT_CNF="$(mktemp)"
    chmod 600 "$DB_ROOT_CNF"
    printf '[client]\nuser=root\npassword=%s\n' "$DB_ROOT_PASSWORD" > "$DB_ROOT_CNF"

    db_root -e 'SELECT 1' >/dev/null 2>&1 || die "Could not connect to the database as root."
    ok "Connected as root"
fi

info "Server: $(db_root -N -B -e 'SELECT VERSION()' 2>/dev/null || echo unknown)"

db_name_sql="$(sql_quote "$DB_NAME")"
db_user_sql="$(sql_quote "$DB_USER")"
db_pass_sql="$(sql_quote "$DB_PASSWORD")"

db_existed=no
[ -n "$(db_root -N -B -e "SHOW DATABASES LIKE '${db_name_sql}'" 2>/dev/null || true)" ] && db_existed=yes

# The application user gets what it needs to own and migrate its own schema:
# SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX and REFERENCES.
#
# Withheld, and these are the ones that matter: no GRANT OPTION, no CREATE USER,
# no FILE, no SUPER, no PROCESS, and no rights on any other database. A
# compromise stays inside this schema.
#
# No EVENT or CREATE ROUTINE either, which is why manage.sh takes its backup
# with --skip-events --skip-routines: asking for what the user cannot dump
# fails the whole backup.
db_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${db_user_sql}'@'localhost' IDENTIFIED BY '${db_pass_sql}';
ALTER USER '${db_user_sql}'@'localhost' IDENTIFIED BY '${db_pass_sql}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON \`${DB_NAME}\`.* TO '${db_user_sql}'@'localhost';
FLUSH PRIVILEGES;
SQL

if [ "$db_existed" = yes ]; then
    ok "Database '$DB_NAME' already existed — left as it is, credentials refreshed"
else
    ok "Created '$DB_NAME'"
fi
ok "Granted '$DB_USER'@'localhost' what the application and its migrations need"

# ---------------------------------------------------------------------------
# 10. Files
# ---------------------------------------------------------------------------
step "Installing to $INSTALL_DIR"

WEB_USER=www-data
for candidate in www-data apache http nginx; do
    id -u "$candidate" >/dev/null 2>&1 && { WEB_USER="$candidate"; break; }
done
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || printf '%s' "$WEB_USER")"
ok "Web server user: $WEB_USER:$WEB_GROUP"

mkdir -p "$INSTALL_DIR"

if [ "$SOURCE_DIR" != "$INSTALL_DIR" ]; then
    tar -cf - -C "$SOURCE_DIR" \
        --exclude=./.git --exclude=./.github --exclude=./.claude --exclude=./.env \
        --exclude=./storage/pdf --exclude=./storage/pages \
        --exclude=./storage/uploads --exclude=./storage/logs \
        --exclude='./*.tar.gz' --exclude='./*.zip' \
        . | tar -xf - -C "$INSTALL_DIR"
    ok "Copied"
else
    info "Installing in place"
fi

mkdir -p "$INSTALL_DIR"/storage/{pdf,pages,uploads,logs}

# ---------------------------------------------------------------------------
# 11. Configuration
# ---------------------------------------------------------------------------
step "Writing the configuration"

ENV_FILE="$INSTALL_DIR/.env"

APP_KEY="$(php_app bin/console.php key:generate 2>/dev/null | grep -oE 'base64:[A-Za-z0-9+/=]+' | head -1 || true)"
[ -n "$APP_KEY" ] || APP_KEY="base64:$(openssl rand -base64 32)"

if [ -f "$ENV_FILE" ]; then
    cp -p "$ENV_FILE" "$ENV_FILE.$(date +%Y%m%d-%H%M%S).bak"
    existing_key="$(grep -E '^[[:space:]]*APP_KEY=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | xargs || true)"

    # Never replace a working APP_KEY. Every stored credential is encrypted with
    # it, and a new one turns them all into unreadable blobs with no error at
    # the moment it happens — only later, when a token will not decrypt.
    if [ -n "$existing_key" ]; then
        APP_KEY="$existing_key"
        info "Kept the existing APP_KEY — the stored credentials depend on it"
    fi
fi

FORCE_HTTPS=true
[ "$TLS_MODE" = plain-http ] && FORCE_HTTPS=false

TRUST_PROXY=false
[ "$TLS_MODE" = proxy ] && TRUST_PROXY=true

cat > "$ENV_FILE" <<ENV
# InvoGrid — written by install.sh on $(date '+%Y-%m-%d %H:%M:%S').
#
# APP_KEY encrypts every credential in the database. Back it up with the
# database, not instead of it: a database restored onto a machine without the
# matching key has secrets nobody can read.

APP_NAME=$(env_quote "$APP_NAME")
APP_ENV=production
APP_DEBUG=false
APP_URL=$(env_quote "$APP_URL")
APP_KEY=$APP_KEY
APP_TIMEZONE=$(env_quote "$APP_TIMEZONE")

DB_HOST=$(env_quote "$DB_HOST")
DB_PORT=$DB_PORT
DB_DATABASE=$(env_quote "$DB_NAME")
DB_USERNAME=$(env_quote "$DB_USER")
DB_PASSWORD=$(env_quote "$DB_PASSWORD")

FORCE_HTTPS=$FORCE_HTTPS
TRUST_PROXY=$TRUST_PROXY

LOGIN_MAX_ATTEMPTS=5
LOGIN_DECAY_MINUTES=15
LOGIN_LOCKOUT_MINUTES=15

PASSWORD_MIN_LENGTH=12
PASSWORD_MIN_CLASSES=3

MAX_PDF_BYTES=104857600
ENV

chown root:"$WEB_GROUP" "$ENV_FILE"
chmod 640 "$ENV_FILE"
ok "Wrote $ENV_FILE"

# ---------------------------------------------------------------------------
# 12. Permissions
# ---------------------------------------------------------------------------
step "Setting ownership and permissions"

chown -R root:"$WEB_GROUP" "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 750 {} +
find "$INSTALL_DIR" -type f -exec chmod 640 {} +

chown -R "$WEB_USER":"$WEB_GROUP" "$INSTALL_DIR/storage"
find "$INSTALL_DIR/storage" -type d -exec chmod 2775 {} +
find "$INSTALL_DIR/storage" -type f -exec chmod 664 {} +

for script in install.sh manage.sh; do
    [ -f "$INSTALL_DIR/$script" ] && chmod 750 "$INSTALL_DIR/$script"
done

chown root:"$WEB_GROUP" "$ENV_FILE"; chmod 640 "$ENV_FILE"

if have restorecon && have getenforce && [ "$(getenforce)" = "Enforcing" ]; then
    restorecon -R "$INSTALL_DIR" >/dev/null 2>&1 || true
    ok "Restored SELinux contexts"
fi

ok "Application root:$WEB_GROUP (750/640), storage $WEB_USER:$WEB_GROUP (2775/664), .env 640"

# ---------------------------------------------------------------------------
# 13. Web server
# ---------------------------------------------------------------------------
PHP_FPM_SOCKET=""
for sock in /run/php/php*-fpm.sock /run/php-fpm/www.sock /var/run/php-fpm/www.sock; do
    [ -S "$sock" ] && { PHP_FPM_SOCKET="$sock"; break; }
done

apache_body() {
    cat <<BLOCK
    <Directory ${INSTALL_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php

        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ index.php [L]
        </IfModule>

        <IfModule mod_headers.c>
            Header always set X-Content-Type-Options "nosniff"
            Header always set X-Frame-Options "SAMEORIGIN"
            Header always set Referrer-Policy "strict-origin-when-cross-origin"
        </IfModule>

        <FilesMatch "^\.">
            Require all denied
        </FilesMatch>
    </Directory>

    # Nothing above public/ is web-reachable, but say so twice: storage/ holds
    # every downloaded document.
    <Directory ${INSTALL_DIR}>
        Require all denied
    </Directory>
BLOCK

    local modules; modules="$("$APACHE_BIN" -M 2>/dev/null || true)"

    if [ -n "$PHP_FPM_SOCKET" ] && ! [[ "$modules" =~ php[0-9_]*_module ]]; then
        cat <<BLOCK

    <FilesMatch \.php\$>
        SetHandler "proxy:unix:${PHP_FPM_SOCKET}|fcgi://localhost"
    </FilesMatch>
BLOCK
    fi
}

if [ "$WEB_SERVER" = apache ]; then
    step "Configuring Apache"

    APACHE_BIN="$(command -v apache2ctl || command -v apachectl || command -v httpd || true)"
    [ -n "$APACHE_BIN" ] || die "Apache is not installed."

    log_dir=/var/log/httpd
    [ -d /var/log/apache2 ] && log_dir=/var/log/apache2

    if [ "$PKG" = apt ]; then
        sites=/etc/apache2/sites-available
        a2enmod rewrite headers >/dev/null 2>&1 || true
        [ -n "$PHP_FPM_SOCKET" ] && a2enmod proxy_fcgi setenvif >/dev/null 2>&1 || true
    else
        sites=/etc/httpd/conf.d
        [ -d "$sites" ] || sites=/etc/apache2/vhosts.d
    fi
    [ -d "$sites" ] || die "Could not find Apache's configuration directory."

    conf="$sites/invogrid.conf"
    name_line=""
    [ -n "$SERVER_NAME" ] && name_line="    ServerName ${SERVER_NAME}"

    {
        echo "# InvoGrid — written by install.sh on $(date '+%Y-%m-%d %H:%M:%S')."
        echo "# The document root is public/; everything else stays outside it."
        echo ""

        if [ "$TLS_MODE" = direct-https ]; then
            cat <<VHOST
<VirtualHost *:80>
${name_line}
    RewriteEngine On
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
${name_line}
    DocumentRoot ${INSTALL_DIR}/public

    SSLEngine on
    SSLCertificateFile ${TLS_CERT}
    SSLCertificateKeyFile ${TLS_KEY}

$(apache_body)

    ErrorLog ${log_dir}/invogrid-error.log
    CustomLog ${log_dir}/invogrid-access.log combined
</VirtualHost>
VHOST
        else
            cat <<VHOST
<VirtualHost *:80>
${name_line}
    DocumentRoot ${INSTALL_DIR}/public

$(apache_body)

    ErrorLog ${log_dir}/invogrid-error.log
    CustomLog ${log_dir}/invogrid-access.log combined
</VirtualHost>
VHOST
        fi
    } > "$conf"

    if [ "$PKG" = apt ]; then
        a2ensite invogrid >/dev/null 2>&1 || true
        [ "$MAKE_DEFAULT_SITE" = yes ] && a2dissite 000-default >/dev/null 2>&1 || true
        [ "$TLS_MODE" = direct-https ] && a2enmod ssl >/dev/null 2>&1 || true
    fi

    "$APACHE_BIN" -t >/dev/null 2>&1 || warn "Apache reports a configuration problem — check $conf"
    systemctl reload apache2 >/dev/null 2>&1 || systemctl reload httpd >/dev/null 2>&1 || true
    ok "Wrote $conf"

elif [ "$WEB_SERVER" = nginx ]; then
    step "Configuring nginx"

    [ -n "$PHP_FPM_SOCKET" ] || warn "No PHP-FPM socket found — the site will not serve PHP until one exists."

    if [ -d /etc/nginx/sites-available ]; then
        conf=/etc/nginx/sites-available/invogrid
        link=/etc/nginx/sites-enabled/invogrid
    else
        conf=/etc/nginx/conf.d/invogrid.conf
        link=""
    fi

    listen="listen 80;"
    tls_block=""
    if [ "$TLS_MODE" = direct-https ]; then
        listen="listen 443 ssl;"
        tls_block="    ssl_certificate ${TLS_CERT};
    ssl_certificate_key ${TLS_KEY};"
    fi

    cat > "$conf" <<NGINX
# InvoGrid — written by install.sh on $(date '+%Y-%m-%d %H:%M:%S').
server {
    ${listen}
    server_name ${SERVER_NAME:-_};
${tls_block}

    root ${INSTALL_DIR}/public;
    index index.php;

    client_max_body_size 16m;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
    }

    # Nothing above public/ is reachable, and dotfiles never are.
    location ~ /\. { deny all; }
}
NGINX

    [ -n "$link" ] && ln -sf "$conf" "$link"

    if [ "$TLS_MODE" = direct-https ]; then
        cat >> "$conf" <<NGINX

server {
    listen 80;
    server_name ${SERVER_NAME:-_};
    return 301 https://\$host\$request_uri;
}
NGINX
    fi

    nginx -t >/dev/null 2>&1 || warn "nginx reports a configuration problem — check $conf"
    systemctl reload nginx >/dev/null 2>&1 || true
    ok "Wrote $conf"
fi

# ---------------------------------------------------------------------------
# 14. Firewall
# ---------------------------------------------------------------------------
if [ "$WEB_SERVER" != none ]; then
    if have ufw && ufw status 2>/dev/null | head -1 | grep -qi active; then
        step "Opening the firewall"
        ufw allow 'Apache Full' >/dev/null 2>&1 || ufw allow 80/tcp >/dev/null 2>&1 || true
        [ "$TLS_MODE" = direct-https ] && ufw allow 443/tcp >/dev/null 2>&1 || true
        ok "ufw updated"
    elif have firewall-cmd && systemctl is-active firewalld >/dev/null 2>&1; then
        step "Opening the firewall"
        firewall-cmd --permanent --add-service=http >/dev/null 2>&1 || true
        [ "$TLS_MODE" = direct-https ] && firewall-cmd --permanent --add-service=https >/dev/null 2>&1 || true
        firewall-cmd --reload >/dev/null 2>&1 || true
        ok "firewalld updated"
    fi
fi

# ---------------------------------------------------------------------------
# 15. Migrations and the first administrator
# ---------------------------------------------------------------------------
step "Applying the database migrations"
php_app bin/migrate.php || die "The migrations failed. Fix the reason above and re-run:  sudo $INSTALL_DIR/manage.sh migrate"

step "Creating the administrator"

# create-admin.php is interactive by design — the password is asked for so it
# never lands in shell history. Here it is already known, so it is fed on stdin
# rather than passed as an argument, which would put it in the process list.
if php_app bin/console.php user:list 2>/dev/null | grep -q "^  ${ADMIN_USERNAME} "; then
    info "'$ADMIN_USERNAME' already exists — left alone"
else
    printf '%s\n%s\n' "$ADMIN_PASSWORD" "$ADMIN_PASSWORD" \
        | php_app bin/create-admin.php --username="$ADMIN_USERNAME" --name="$ADMIN_NAME" --role=admin >/dev/null \
        && ok "Created '$ADMIN_USERNAME'" \
        || die "Could not create the administrator. Do it by hand:  sudo $INSTALL_DIR/manage.sh create-admin"
fi

# ---------------------------------------------------------------------------
# 16. Cron
# ---------------------------------------------------------------------------
if [ -z "$INSTALL_CRON" ] && [ "$NON_INTERACTIVE" != yes ]; then
    say ""
    say "  InvoGrid needs three cron entries to work at all:"
    say "    - the queue worker, every minute — without it nothing is ever processed"
    say "    - the Clear Books cache refresh, hourly"
    say "    - the invoice sync, every five minutes (it fetches on its own schedule)"
    say "  and a nightly backup."
    say ""
    confirm "Install them?" && INSTALL_CRON=yes || INSTALL_CRON=no
fi

if [ "$INSTALL_CRON" = yes ]; then
    step "Installing the cron entries"
    "$INSTALL_DIR/manage.sh" cron-install
fi

# ---------------------------------------------------------------------------
# 17. A convenience command
# ---------------------------------------------------------------------------
if [ -d /usr/local/bin ]; then
    ln -sf "$INSTALL_DIR/manage.sh" /usr/local/bin/invogrid
    ok "sudo invogrid <command> works from anywhere"
fi

# ---------------------------------------------------------------------------
# 18. Check it over
# ---------------------------------------------------------------------------
step "Checking it over"
php_app bin/console.php doctor || true

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
say ""
say "${C_GREEN}${C_BOLD}Installed.${C_RESET}"
say ""
say "  Site           ${APP_URL}/login"
say "  Sign in as     ${ADMIN_USERNAME}"
say "  Files          ${INSTALL_DIR}"
say "  Document root  ${INSTALL_DIR}/public"
say "  Database       ${DB_NAME} (user ${DB_USER}@localhost)"
say "  Storage        ${INSTALL_DIR}/storage"
say ""

if [ "$DB_PASSWORD_GENERATED" = yes ]; then
    say "  The database password was generated and written to ${INSTALL_DIR}/.env."
    say "  It is not stored anywhere else — back that file up."
    say ""
fi

say "  To put a document in: sign in, then Upload. PDFs only."
say ""
say "      /documents/upload"
say ""
say "  The queue processes it in the background — the cron entry above is what"
say "  actually moves a document past 'Received'."
say ""

case "$TLS_MODE" in
    proxy)
        say "  ${C_BOLD}This install expects a reverse proxy in front of it.${C_RESET}"
        say "  The proxy must forward Host and X-Forwarded-Proto: https, or the"
        say "  application will not know it is on HTTPS and will redirect in a loop."
        say ""
        ;;
    plain-http)
        say "  ${C_BOLD}There is no TLS on this install.${C_RESET} Passwords, session"
        say "  cookies and uploaded invoices cross the network in the clear. Put it"
        say "  behind a proxy or add a certificate before it leaves the LAN."
        say ""
        ;;
esac

say "  Next, in the web interface:"
say "    1. Settings → Clear Books — the client id, secret and business id, then"
say "       complete the consent flow. Until that is done every cached list is"
say "       empty and every document lands in review saying so."
say "    2. An LLM key for whichever provider you have chosen:"
say "         sudo invogrid set-setting anthropic_api_key"
say "    3. Settings → Branding — your logo, light and dark."
say "    4. Documents → Upload — put a PDF in and watch it go through."
say ""
say "  Day-to-day:"
say "    sudo invogrid status"
say "    sudo invogrid doctor"
say "    sudo invogrid backup"
say "    sudo invogrid help"
say ""

if [ "$INSTALL_CRON" != yes ]; then
    warn "The cron entries were not installed, so nothing will process documents."
    say "  ${C_DIM}Add them with:  sudo ${INSTALL_DIR}/manage.sh cron-install${C_RESET}"
    say ""
fi

if [ -n "$ANSWERS_FILE" ]; then
    warn "The answers file $ANSWERS_FILE holds passwords. Delete it now."
fi
