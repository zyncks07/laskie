#!/usr/bin/env bash
# ============================================================
# Laskie RMS — restore filesystem permissions
# ============================================================
# Applies the policy in perms-policy.sh. Idempotent, fast (a handful of
# batched find calls), and free of side effects — no image re-encoding, no
# database access, no network. Safe to run after any batch of edits.
#
#   sudo bash scripts/fix-perms.sh
#   LASKIE_ROOT=/path sudo -E bash scripts/fix-perms.sh
#
# Verify afterwards with the read-only checker (no sudo needed):
#   bash scripts/check-perms.sh
#
# Why this is needed at all: umask 002 + setgid directories mean every file
# bulik creates — an Edit, a git checkout, an editor save — is born 664, i.e.
# writable by the web group. Setting umask 022 stops most of that drift, but a
# file created by a process that didn't inherit it (cron, a different editor,
# sudo) still lands group-writable. This restores the whole tree in one pass.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./perms-policy.sh
source "$SCRIPT_DIR/perms-policy.sh"

[[ $EUID -eq 0 ]] || { echo "Run with sudo: sudo bash $0" >&2; exit 1; }
policy_assert_root || exit 2

G=$'\033[0;32m'; B=$'\033[1m'; N=$'\033[0m'
[[ -t 1 ]] || { G=""; B=""; N=""; }
ok()   { printf '%s[ OK ]%s %s\n' "$G" "$N" "$*"; }
step() { printf '\n%s▶ %s%s\n' "$B" "$*" "$N"; }

ROOT="$LASKIE_ROOT"
UP="$LASKIE_UPLOADS"
OWNER="$LASKIE_OWNER"
WGRP="$LASKIE_WEBGROUP"
WUSR="$LASKIE_WEBUSER"

before_f=$(find "$ROOT" -type f -perm -g=w 2>/dev/null | wc -l)
before_d=$(find "$ROOT" -type d -perm -g=w 2>/dev/null | wc -l)

# uploads/ and .git/ are pruned from the passes below and handled separately:
# uploads/ in step 3, .git/ in step 5.
PRUNE=( -path "$UP" -prune -o -path "$ROOT/.git" -prune -o )

# ── 1. Code tree: owned by the human, readable by the web group ──────────
step "Code tree → $OWNER:$WGRP"
find "$ROOT" "${PRUNE[@]}" \( -type f -o -type d \) -exec chown "$OWNER:$WGRP" {} +
ok "ownership set"

step "Directories → 2755 (setgid kept, group write dropped)"
# Setgid is load-bearing: it is what makes a file created here inherit group
# www-data so Apache can read it. Without it new files land bulik:bulik and the
# site 500s. Dropping only the group-write bit is the entire change.
find "$ROOT" "${PRUNE[@]}" -type d -exec chmod 2755 {} +
ok "directories locked (web user can traverse and read, not create or unlink)"

step "Files → 644 / 755"
# Two disjoint passes so executables keep their exec bit without needing a
# hardcoded list of composer's bin entry points. A blanket 644 followed by
# re-adding +x would have to guess which files those were.
find "$ROOT" "${PRUNE[@]}" -type f ! -perm -u=x -exec chmod 644 {} +
find "$ROOT" "${PRUNE[@]}" -type f   -perm -u=x -exec chmod 755 {} +
# *.sh is executable by intent even if the bit was lost somewhere.
find "$ROOT" "${PRUNE[@]}" -type f -name '*.sh' -exec chmod 755 {} +
ok "files locked (www-data can read code, never write it)"

# ── 2. Credentials ───────────────────────────────────────────────────────
step "Credentials"
for s in "${POLICY_SECRETS[@]}"; do
    [[ -e "$ROOT/$s" ]] || continue
    chown "$OWNER:$WGRP" "$ROOT/$s"
    chmod 640            "$ROOT/$s"
    ok "$s → $OWNER:$WGRP 640 (Apache reads via group; not world-readable)"
done
for s in "${POLICY_PRIVATE[@]}"; do
    [[ -e "$ROOT/$s" ]] || continue
    chown "$OWNER:$WGRP" "$ROOT/$s"
    chmod 600            "$ROOT/$s"
    ok "$s → $OWNER:$WGRP 600 (owner only; the group cannot read it at this mode)"
done

# ── 3. uploads/: the app's own write area ────────────────────────────────
step "uploads/ → $WUSR:$WGRP (writable by the app, by design)"
if [[ -d "$UP" ]]; then
    # The uploads root is about to stop being writable by the web user, so the
    # app can no longer mkdir a missing category at runtime. Pre-create the
    # canonical set — idempotent, and it turns a silent upload failure into a
    # non-event.
    for sub in "${POLICY_UPLOAD_SUBDIRS[@]}"; do
        [[ -d "$UP/$sub" ]] || { mkdir -p "$UP/$sub"; ok "created missing uploads/$sub"; }
    done

    chown -R "$WUSR:$WGRP" "$UP"
    find "$UP" -type d -exec chmod 2775 {} +
    find "$UP" -type f -exec chmod 664  {} +
    ok "uploads subdirs 2775, files 664"

    # ── 4. Take the uploads ROOT back off the web user ───────────────────
    # MUST come after the recursion above, which would otherwise sweep both of
    # these straight back — the exact regression laskie-recompress.sh has been
    # re-introducing on every run.
    #
    # Both lines are needed. Fixing only the file's mode is not enough: write
    # permission on the *directory* lets www-data unlink anything inside it no
    # matter what mode the file has. Verified by probe — `rm uploads/.htaccess`
    # as www-data succeeded against a 644 file until the root was locked.
    chown "$OWNER:$WGRP" "$UP"
    chmod 2755           "$UP"
    ok "uploads/ root → $OWNER:$WGRP 2755 (web user may traverse, not create or unlink)"

    if [[ -e "$UP/.htaccess" ]]; then
        chown "$OWNER:$WGRP" "$UP/.htaccess"
        chmod 644            "$UP/.htaccess"
        ok "uploads/.htaccess → $OWNER:$WGRP 644 (no-exec guard, now genuinely immutable to www-data)"
    else
        printf '  !! uploads/.htaccess is MISSING — restore it (git checkout -- uploads/.htaccess);\n' >&2
        printf '     without it uploaded files are one Apache config change from executing\n' >&2
    fi
fi

# ── 5. .git/: strip the write bits, leave git's own modes alone ──────────
step ".git/ → not writable by $WUSR"
if [[ -d "$ROOT/.git" ]]; then
    chown -R "$OWNER:$WGRP" "$ROOT/.git"
    # Only the offending bits, nothing else: git writes loose objects at 444 on
    # purpose and normalising them to 644 would churn thousands of files to make
    # them LESS restrictive. -perm /022 matches "group or other writable".
    find "$ROOT/.git" -perm /022 -exec chmod g-w,o-w {} +
    ok "git metadata read-only to the web user (git's own modes preserved)"
fi

# ── 6. Report ────────────────────────────────────────────────────────────
after_f=$(find "$ROOT" -type f -perm -g=w 2>/dev/null | wc -l)
after_d=$(find "$ROOT" -type d -perm -g=w 2>/dev/null | wc -l)
step "Result"
printf '  group-writable files:       %5s → %s\n' "$before_f" "$after_f"
printf '  group-writable directories: %5s → %s\n' "$before_d" "$after_d"
printf '  (what remains is uploads/, which the app writes to by design)\n'
ok "done — verify with: bash $SCRIPT_DIR/check-perms.sh"
