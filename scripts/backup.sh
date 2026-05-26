#!/usr/bin/env bash
# ============================================================
# Laskie RMS — daily backup
# ============================================================
# Captures:
#   - DB:      mysqldump --single-transaction → .sql.gz
#   - Uploads: receipts / contracts / avatars / docs / remittance → .tar.gz
#
# Defaults (overridable via env):
#   LASKIE_BACKUP_DIR        backup destination (outside doc root)
#                            default: $HOME/laskie-backups
#   LASKIE_BACKUP_RETENTION  days to keep on disk
#                            default: 14
#   LASKIE_BACKUP_LOG        log file path
#                            default: $LASKIE_BACKUP_DIR/backup.log
#
# Cron (root or the web user — needs read on config/db.php and uploads/):
#
#   # m  h  dom mon dow  command
#     30 2  *   *   *    /var/www/laskie/scripts/backup.sh >> /var/log/laskie-backup.log 2>&1
#
# Off-host retention (90-day spec in CLAUDE.md §11):
#   The script only handles LOCAL rotation. For off-host, append an rsync
#   step against your remote store. Example you'd add at the bottom:
#
#     rsync -avz --delete --max-delete=5 \
#         "$BACKUP_DIR/" backup@offsite:/laskie-backups/
#
#   Pair with the remote-side retention of your choosing (rclone, restic,
#   tarsnap, etc.). The local script keeps 14 days; off-host keeps 90.
# ============================================================

set -euo pipefail
umask 077

# Run from project root regardless of cwd.
cd "$(dirname "$0")/.."
PROJECT_DIR="$(pwd)"

# --- Config ---
BACKUP_DIR="${LASKIE_BACKUP_DIR:-$HOME/laskie-backups}"
RETENTION_DAYS="${LASKIE_BACKUP_RETENTION:-14}"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR" 2>/dev/null || true
LOG_FILE="${LASKIE_BACKUP_LOG:-$BACKUP_DIR/backup.log}"

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*" | tee -a "$LOG_FILE"
}

# --- Read DB credentials via PHP ---
# config/db.php defines DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET as
# constants. Re-export them as shell vars so we don't have to parse PHP here.
eval "$(php -r '
require_once getcwd() . "/config/db.php";
foreach (["DB_HOST","DB_NAME","DB_USER","DB_PASS","DB_CHARSET"] as $k) {
    if (defined($k)) printf("%s=%s\n", $k, escapeshellarg(constant($k)));
}
' 2>/dev/null || true)"

if [[ -z "${DB_NAME:-}" || -z "${DB_USER:-}" ]]; then
    log "ERROR: could not read DB credentials from config/db.php"
    exit 1
fi
DB_CHARSET="${DB_CHARSET:-utf8mb4}"
DB_HOST="${DB_HOST:-localhost}"

# --- Filenames ---
TS="$(date +%Y%m%d_%H%M%S)"
SQL_FILE="$BACKUP_DIR/laskie-db-$TS.sql.gz"
UPLOADS_FILE="$BACKUP_DIR/laskie-uploads-$TS.tar.gz"

# --- DB backup ---
log "DB backup → $SQL_FILE"
# Capture mysqldump stderr separately so a 0-byte output + non-zero exit
# leaves a usable diagnostic in the log without polluting the .sql.gz.
TMP_STDERR="$(mktemp)"
trap 'rm -f "$TMP_STDERR"' EXIT

if MYSQL_PWD="$DB_PASS" mysqldump \
        --single-transaction \
        --quick \
        --add-drop-table \
        --routines \
        --triggers \
        --default-character-set="$DB_CHARSET" \
        -h "$DB_HOST" \
        -u "$DB_USER" \
        "$DB_NAME" \
        2> "$TMP_STDERR" \
    | gzip -c > "$SQL_FILE"
then
    chmod 600 "$SQL_FILE"
    SIZE=$(du -h "$SQL_FILE" 2>/dev/null | cut -f1)
    log "DB backup OK ($SIZE)"
else
    log "ERROR: mysqldump failed:"
    sed 's/^/    /' "$TMP_STDERR" | tee -a "$LOG_FILE"
    rm -f "$SQL_FILE"
    exit 2
fi

# --- Uploads backup ---
if [[ -d uploads ]]; then
    log "Uploads backup → $UPLOADS_FILE"
    if tar -czf "$UPLOADS_FILE" -C "$PROJECT_DIR" uploads 2> "$TMP_STDERR"; then
        chmod 600 "$UPLOADS_FILE"
        SIZE=$(du -h "$UPLOADS_FILE" 2>/dev/null | cut -f1)
        log "Uploads backup OK ($SIZE)"
    else
        log "ERROR: tar failed for uploads/:"
        sed 's/^/    /' "$TMP_STDERR" | tee -a "$LOG_FILE"
        rm -f "$UPLOADS_FILE"
        exit 3
    fi
else
    log "uploads/ not found (skipped)"
fi

# --- Retention: prune local backups older than RETENTION_DAYS ---
log "Pruning backups older than $RETENTION_DAYS days"
# -mtime +N matches files modified more than N*24h ago. Quote the patterns
# so the shell doesn't glob them away.
find "$BACKUP_DIR" -maxdepth 1 -type f \
    \( -name 'laskie-db-*.sql.gz' -o -name 'laskie-uploads-*.tar.gz' \) \
    -mtime +"$RETENTION_DAYS" -print | while read -r f; do
    rm -f "$f"
    log "  pruned $(basename "$f")"
done

log "Backup complete"
