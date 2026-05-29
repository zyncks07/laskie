#!/usr/bin/env bash
# =============================================================
# Laskie Rental Property Management System
# Automated Deployment Script — Debian 13 (Trixie) bare server
#
# Usage:
#   sudo bash deploy.sh [--domain example.com] [--port 80]
#
# The script must be run from inside the copied project folder:
#   /home/$user/apps/laskie/deploy.sh
# =============================================================

set -euo pipefail

# ── ANSI colors ───────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
ok()      { echo -e "${GREEN}[ OK ]${NC}  $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
step()    { echo -e "\n${CYAN}${BOLD}▶ $*${NC}"; }
die()     { echo -e "\n${RED}[ERROR]${NC} $*\n" >&2; exit 1; }

# ── Root check ────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "Run with sudo: sudo bash deploy.sh [--domain yourdomain.com]"

# ── Resolve project root & invoking user ──────────────────────
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INVOKE_USER="${SUDO_USER:-$USER}"
INVOKE_HOME="$(eval echo ~"$INVOKE_USER")"

[[ -f "${APP_ROOT}/install.sql" ]]   || die "install.sql not found. Run this script from the laskie project folder."
[[ -f "${APP_ROOT}/config/db.php" ]] || die "config/db.php not found. Project folder seems incomplete."

# ── Argument parsing ──────────────────────────────────────────
DOMAIN=""
PORT=80

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)  DOMAIN="$2"; shift 2 ;;
        --port)    PORT="$2";   shift 2 ;;
        --help|-h)
            echo "Usage: sudo bash deploy.sh [--domain example.com] [--port 80]"
            echo "  --domain   Server domain name or IP (default: auto-detect primary IP)"
            echo "  --port     Apache listen port (default: 80)"
            exit 0 ;;
        *) die "Unknown argument: $1. Use --help for usage." ;;
    esac
done

# Auto-detect IP if domain not supplied
if [[ -z "$DOMAIN" ]]; then
    DOMAIN=$(hostname -I 2>/dev/null | awk '{print $1}')
    DOMAIN="${DOMAIN:-localhost}"
fi

# Build app URL
if [[ "$PORT" == "80" ]]; then
    APP_URL="http://${DOMAIN}"
else
    APP_URL="http://${DOMAIN}:${PORT}"
fi

# ── Banner ────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${CYAN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYAN}║     Laskie Rental Property Management System        ║${NC}"
echo -e "${BOLD}${CYAN}║            Automated Deployment v1.1                ║${NC}"
echo -e "${BOLD}${CYAN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
info "Project root  : ${APP_ROOT}"
info "Serving at    : ${APP_URL}"
info "System user   : ${INVOKE_USER}"
echo ""

# ── Step 1: Package update ────────────────────────────────────
step "Updating package lists"
apt-get update -qq
ok "Package lists updated."

# ── Step 2: Dependency check & install ───────────────────────
step "Checking dependencies"

REQUIRED_PKGS=(
    apache2
    mariadb-server
    php
    libapache2-mod-php
    php-mysql
    php-mbstring
    php-gd
    php-zip
    php-curl
    php-xml
    openssl
    curl
    chromium
)

MISSING_PKGS=()
for pkg in "${REQUIRED_PKGS[@]}"; do
    if ! dpkg -s "$pkg" &>/dev/null; then
        MISSING_PKGS+=("$pkg")
    else
        ok "$pkg — installed"
    fi
done

if [[ ${#MISSING_PKGS[@]} -gt 0 ]]; then
    warn "Missing packages: ${MISSING_PKGS[*]}"
    info "Installing missing packages (this may take a minute)..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y "${MISSING_PKGS[@]}" -qq
    ok "All packages installed."
else
    ok "All dependencies satisfied."
fi

# ── Step 3: Verify PHP version ────────────────────────────────
step "Verifying PHP version"
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "0.0")
PHP_MAJOR=$(echo "$PHP_VER" | cut -d. -f1)
PHP_MINOR=$(echo "$PHP_VER" | cut -d. -f2)
if [[ "$PHP_MAJOR" -lt 8 ]] || { [[ "$PHP_MAJOR" -eq 8 ]] && [[ "$PHP_MINOR" -lt 1 ]]; }; then
    die "PHP 8.1+ required but found PHP ${PHP_VER}. Check your system repositories."
fi
ok "PHP ${PHP_VER} — meets minimum requirement (8.1+)."

# ── Step 3b: Ensure exec() is not disabled ─────────────────────
step "Checking PHP exec() availability"
PHP_INI=$(php --ini 2>/dev/null | grep "Loaded Configuration" | awk '{print $NF}')
if [[ -f "$PHP_INI" ]]; then
    DISABLED=$(grep -i '^disable_functions' "$PHP_INI" | grep -o 'exec' || true)
    if [[ "$DISABLED" == "exec" ]]; then
        warn "exec() is listed in disable_functions in ${PHP_INI}."
        warn "PDF generation (Chromium headless) requires exec(). Removing it..."
        sed -i 's/\(disable_functions\s*=.*\)exec,\?/\1/' "$PHP_INI"
        sed -i 's/,exec//' "$PHP_INI"
        ok "exec() removed from disable_functions."
    else
        ok "exec() is available."
    fi
else
    warn "Could not locate php.ini — skipping exec() check."
fi

# ── Step 4: Apache modules ─────────────────────────────────────
step "Enabling Apache modules"
a2enmod rewrite  >/dev/null 2>&1 && ok "mod_rewrite enabled."
a2enmod headers  >/dev/null 2>&1 && ok "mod_headers enabled."
a2enmod expires  >/dev/null 2>&1 && ok "mod_expires enabled."
a2enmod deflate  >/dev/null 2>&1 && ok "mod_deflate enabled."

# ── Step 5: Start & enable services ───────────────────────────
step "Starting services"
systemctl enable apache2  --quiet 2>/dev/null
systemctl enable mariadb  --quiet 2>/dev/null
systemctl start  apache2
systemctl start  mariadb
ok "Apache is running."
ok "MariaDB is running."

# ── Step 5b: Firewall (UFW) ────────────────────────────────────
step "Checking firewall"
if command -v ufw &>/dev/null && ufw status 2>/dev/null | grep -q "^Status: active"; then
    info "UFW is active — opening port ${PORT}/tcp..."
    ufw allow "${PORT}/tcp" comment "Laskie HTTP" >/dev/null
    ok "UFW rule added for port ${PORT}."
    if [[ "$PORT" != "443" ]]; then
        ufw allow 443/tcp comment "Laskie HTTPS" >/dev/null 2>&1 || true
    fi
else
    ok "UFW not active — skipping firewall config."
fi

# ── Step 6: Database setup ─────────────────────────────────────
step "Setting up database"

DB_NAME="laskie_rental"
DB_USER="laskie_db_user"
DB_PASS="$(openssl rand -base64 32 | tr -d '/+=\n' | head -c 28)"

# Create DB and user (idempotent)
# DB_PASS is alphanumeric-only (base64 minus /+=\n), so SQL-safe to embed.
# Root connection uses Unix socket auth — no password required here.
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';

GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "Database '${DB_NAME}' and user '${DB_USER}' ready."

# Import schema only if database is empty (idempotent re-run safety)
TABLE_COUNT=$(mysql -u root -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" 2>/dev/null || echo "0")

if [[ "$TABLE_COUNT" -eq 0 ]]; then
    info "Importing schema and seed data from install.sql..."
    mysql -u root "${DB_NAME}" < "${APP_ROOT}/install.sql"
    ok "Schema imported successfully."
else
    info "Database already has tables (${TABLE_COUNT} found) — skipping schema import."

    # Still ensure unit_rate_history exists (may be missing on older installs)
    RATE_TBL=$(mysql -u root -N -e \
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema='${DB_NAME}' AND table_name='unit_rate_history';" 2>/dev/null || echo "0")

    if [[ "$RATE_TBL" -eq 0 ]]; then
        warn "unit_rate_history table missing — creating it now..."
        mysql -u root "${DB_NAME}" <<SQL2
CREATE TABLE IF NOT EXISTS unit_rate_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    monthly_rate DECIMAL(12,2) NOT NULL,
    effective_date DATE NOT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES rental_units(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
SQL2
        ok "unit_rate_history table created."
    else
        ok "unit_rate_history table exists."
    fi

    # Ensure dividend_recipients / dividend_distributions tables exist
    for TBL in dividend_recipients dividend_distributions; do
        TBL_EXISTS=$(mysql -u root -N -e \
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema='${DB_NAME}' AND table_name='${TBL}';" 2>/dev/null || echo "0")
        if [[ "$TBL_EXISTS" -eq 0 ]]; then
            warn "${TBL} table missing — creating it now..."
            if [[ "$TBL" == "dividend_recipients" ]]; then
                mysql -u root "${DB_NAME}" <<SQL3
CREATE TABLE IF NOT EXISTS dividend_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL3
            else
                mysql -u root "${DB_NAME}" <<SQL4
CREATE TABLE IF NOT EXISTS dividend_distributions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    distribution_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recipient_id) REFERENCES dividend_recipients(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL4
            fi
            ok "${TBL} table created."
        else
            ok "${TBL} table exists."
        fi
    done

    # Ensure dividend_returns table exists (added in vault update)
    DIVRET_TBL=$(mysql -u root -N -e \
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema='${DB_NAME}' AND table_name='dividend_returns';" 2>/dev/null || echo "0")
    if [[ "$DIVRET_TBL" -eq 0 ]]; then
        warn "dividend_returns table missing — creating it now..."
        mysql -u root "${DB_NAME}" <<SQL5
CREATE TABLE IF NOT EXISTS dividend_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    return_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recipient_id) REFERENCES dividend_recipients(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL5
        ok "dividend_returns table created."
    else
        ok "dividend_returns table exists."
    fi

    # Ensure master_password setting exists (may be missing on older installs)
    MASTER_SET=$(mysql -u root -N -e \
        "SELECT COUNT(*) FROM settings WHERE setting_key='master_password';" "${DB_NAME}" 2>/dev/null || echo "0")
    if [[ "$MASTER_SET" -eq 0 ]]; then
        warn "master_password setting missing — seeding default (Admin@2024)..."
        MASTER_HASH=$(php -r "echo password_hash('Admin@2024', PASSWORD_BCRYPT, ['cost'=>12]);")
        mysql -u root "${DB_NAME}" \
            -e "INSERT INTO settings (setting_key, setting_value) VALUES ('master_password', '${MASTER_HASH}');"
        ok "master_password seeded."
    else
        ok "master_password setting exists."
    fi
fi

# ── Step 7: Write credentials to .env + thin config/db.php ────
# Credentials live in .env (chmod 600, owned by www-data). config/db.php is
# now static and ships with the repo — copied from config/db.php.example.
step "Writing database credentials"

cat > "${APP_ROOT}/.env" <<ENV
# Laskie RMS — generated by deploy.sh on $(date -Iseconds)
DB_HOST=localhost
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
DB_CHARSET=utf8mb4
ENV
chmod 600 "${APP_ROOT}/.env"
chown www-data:www-data "${APP_ROOT}/.env"
ok ".env written (chmod 600, owner www-data)."

if [[ -f "${APP_ROOT}/config/db.php.example" ]]; then
    cp "${APP_ROOT}/config/db.php.example" "${APP_ROOT}/config/db.php"
    ok "config/db.php copied from template."
else
    # Fallback: older copies of the repo may not ship the template yet. Write
    # the same thin loader inline so the install still completes.
    cat > "${APP_ROOT}/config/db.php" <<'PHP'
<?php
// config/db.php — generated by deploy.sh (template fallback)
require_once __DIR__ . '/env.php';

if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    'laskie_rental');
if (!defined('DB_USER'))    define('DB_USER',    'laskie_db_user');
if (!defined('DB_PASS'))    define('DB_PASS',    '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET . ";connect_timeout=5";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    try {
        $tzName = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='db_timezone'")->fetchColumn();
        $tzName = ($tzName && in_array($tzName, DateTimeZone::listIdentifiers())) ? $tzName : 'Asia/Manila';
    } catch (Exception $_e) { $tzName = 'Asia/Manila'; }
    date_default_timezone_set($tzName);
    $pdo->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone($tzName)))->format('P') . "'");
} catch (PDOException $e) {
    if (defined('JSON_RESPONSE') && JSON_RESPONSE) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database connection failed. Check config/db.php and /var/www/laskie/.env']);
        exit;
    }
    die('<div style="font-family:sans-serif;padding:2rem;color:#dc2626;"><h2>Database Connection Error</h2><p>Could not connect to the database. Please check <code>config/db.php</code> / <code>/var/www/laskie/.env</code> and ensure MariaDB is running.</p></div>');
}
PHP
    ok "config/db.php written (inline fallback)."
fi

# ── Step 7b: Patch PDF scripts with actual URL and path ────────
step "Patching PDF scripts for this deployment"

# Build the internal URL Apache is listening on (always use localhost for internal requests)
if [[ "$PORT" == "80" ]]; then
    INTERNAL_URL="http://localhost"
else
    INTERNAL_URL="http://localhost:${PORT}"
fi

# Escape APP_ROOT for use as a sed replacement string (handles slashes and special chars)
ESCAPED_ROOT=$(printf '%s\n' "${APP_ROOT}" | sed 's/[\/&]/\\&/g')

for PDF_SCRIPT in \
    "${APP_ROOT}/payments/soa_pdf_download.php" \
    "${APP_ROOT}/payments/audit_pdf_download.php"; do

    if [[ -f "$PDF_SCRIPT" ]]; then
        # Replace the hardcoded dev URL with the actual deployment URL
        sed -i "s|http://localhost:[0-9]\+/payments/|${INTERNAL_URL}/payments/|g" "$PDF_SCRIPT"

        # Replace hardcoded assets path with the actual APP_ROOT
        sed -i "s|file:///home/[^/]*/apps/laskie/assets/vendor/|file://${ESCAPED_ROOT}/assets/vendor/|g" "$PDF_SCRIPT"

        ok "Patched: $(basename "$PDF_SCRIPT")"
    fi
done

# ── Step 8: File permissions ───────────────────────────────────
step "Setting file permissions"

# Apache (www-data) needs execute permission on every ancestor directory
chmod o+x "${INVOKE_HOME}"
[[ -d "${INVOKE_HOME}/apps" ]] && chmod o+x "${INVOKE_HOME}/apps"

# App files: owned by the system user, readable by all
chown -R "${INVOKE_USER}:${INVOKE_USER}" "${APP_ROOT}"
find "${APP_ROOT}" -type d -exec chmod 755 {} \;
find "${APP_ROOT}" -type f -exec chmod 644 {} \;

# deploy.sh stays executable
chmod 755 "${APP_ROOT}/deploy.sh"

# Uploads dirs: writable by www-data
for udir in "${APP_ROOT}/uploads" \
             "${APP_ROOT}/uploads/receipts" \
             "${APP_ROOT}/uploads/remittance" \
             "${APP_ROOT}/uploads/contracts" \
             "${APP_ROOT}/uploads/docs"; do
    [[ -d "$udir" ]] || mkdir -p "$udir"
done
chown -R www-data:www-data "${APP_ROOT}/uploads"
chmod -R 775 "${APP_ROOT}/uploads"

# config/db.php: readable only by owner and www-data
chown "${INVOKE_USER}:www-data" "${APP_ROOT}/config/db.php"
chmod 640 "${APP_ROOT}/config/db.php"

ok "Permissions configured."

# ── Step 9: Apache virtual host ────────────────────────────────
step "Configuring Apache virtual host"

VHOST_FILE="/etc/apache2/sites-available/laskie.conf"

cat > "${VHOST_FILE}" <<VHOST
<VirtualHost *:${PORT}>
    ServerName ${DOMAIN}
    DocumentRoot ${APP_ROOT}

    <Directory ${APP_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block direct access to sensitive directories at the server level
    <Directory ${APP_ROOT}/config>
        Require all denied
    </Directory>
    <Directory ${APP_ROOT}/includes>
        Require all denied
    </Directory>

    ErrorLog  \${APACHE_LOG_DIR}/laskie_error.log
    CustomLog \${APACHE_LOG_DIR}/laskie_access.log combined
</VirtualHost>
VHOST

a2ensite laskie.conf >/dev/null 2>&1
ok "Site laskie.conf enabled."

# Disable default site if using port 80 to avoid conflicts
if [[ "$PORT" == "80" ]]; then
    a2dissite 000-default.conf >/dev/null 2>&1 || true
    ok "Default site disabled."
fi

info "Testing Apache configuration..."
CONFIGTEST=$(apache2ctl configtest 2>&1)
if echo "$CONFIGTEST" | grep -q "Syntax OK"; then
    ok "Apache config is valid."
else
    echo "$CONFIGTEST" >&2
    die "Apache config test failed. Review ${VHOST_FILE}"
fi

systemctl reload apache2
ok "Apache reloaded."

# ── Step 10: Save credentials to file ─────────────────────────
# Written outside the web root so .htaccess and webserver directory rules
# cannot accidentally expose it, even if the dotfile block is ever removed.
CREDS_FILE="${INVOKE_HOME}/.laskie_deploy_creds"
cat > "${CREDS_FILE}" <<CREDS
# Laskie Deployment Credentials — $(date)
# Keep this file private. Delete after noting credentials.
APP_URL=${APP_URL}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
ADMIN_USER=admin
ADMIN_PASS=Admin@2024
MASTER_PASS=Admin@2024
CREDS
chmod 600 "${CREDS_FILE}"
chown "${INVOKE_USER}:${INVOKE_USER}" "${CREDS_FILE}"
ok "Credentials saved to ${CREDS_FILE} (chmod 600, outside web root)."

# ── Step 11: Verify DB connection ─────────────────────────────
step "Verifying database connection"
TEST=$(MYSQL_PWD="${DB_PASS}" mysql -u "${DB_USER}" "${DB_NAME}" \
    -e "SELECT COUNT(*) FROM users;" -N 2>&1) || die "DB connection test failed: ${TEST}"
ok "Database connection verified (${TEST} admin user(s) found)."

# ── Done ──────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${GREEN}║                 Deployment Complete!                ║${NC}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BOLD}App URL${NC}         →  ${APP_URL}"
echo -e "  ${BOLD}App root${NC}        →  ${APP_ROOT}"
echo ""
echo -e "  ${BOLD}Database${NC}        →  ${DB_NAME}"
echo -e "  ${BOLD}DB user${NC}         →  ${DB_USER}"
echo -e "  ${BOLD}DB password${NC}     →  ${YELLOW}${DB_PASS}${NC}"
echo ""
echo -e "  ${BOLD}Login${NC}           →  admin / ${YELLOW}Admin@2024${NC}"
echo -e "  ${BOLD}Master password${NC} →  ${YELLOW}Admin@2024${NC}  (Settings > Security)"
echo -e "  ${RED}[!]${NC} Change both passwords immediately after first login."
echo ""
echo -e "  ${BOLD}Chromium PDF${NC}    →  /usr/bin/chromium (headless PDF generation)"
echo -e "  ${BOLD}Error log${NC}       →  /var/log/apache2/laskie_error.log"
echo -e "  ${BOLD}Credentials file${NC}→  ${CREDS_FILE}"
echo ""
echo -e "  ${BOLD}${YELLOW}TODO — daily backup${NC}"
echo -e "    Script: ${APP_ROOT}/scripts/backup.sh (DB .sql.gz + uploads .tar.gz)"
echo -e "    See INSTALL.md → DAILY BACKUP for the cron + restore steps."
echo -e "    Suggested entry (run as www-data):"
echo -e "      ${BOLD}30 2 * * * LASKIE_BACKUP_DIR=/srv/laskie-backups ${APP_ROOT}/scripts/backup.sh >> /var/log/laskie-backup.log 2>&1${NC}"
echo ""
