#!/usr/bin/env bash
# ============================================================
# Laskie RMS — filesystem permission policy (single source of truth)
# ============================================================
# Sourced by fix-perms.sh (applies it) and check-perms.sh (asserts it).
# They MUST agree, so the rules live here once: a checker that has drifted
# from its fixer is exactly the bug this whole exercise is closing.
#
# ── The model ────────────────────────────────────────────────
# This working copy IS the live deployment (Apache DocumentRoot), so the web
# user www-data needs to READ the tree. Group ownership is how it gets that:
# everything is bulik:www-data and every directory is setgid, so files created
# later inherit group www-data automatically. Losing setgid means new files
# land bulik:bulik and Apache 500s — so setgid stays.
#
# What www-data must NOT have is WRITE. Group-write on served content means
# the web user can rewrite the code it executes (app PHP), the code browsers
# execute (assets/*.js, *.css), the rules that protect it (.htaccess), and the
# scripts a human later runs as themselves (deploy.sh, vendor/bin/phpunit).
# So: directories are 2755, not 2775. Read, traverse, no write.
#
# uploads/ is the one exception — the app genuinely writes there (every
# mkdir/file_put_contents/rename in the codebase resolves under UPLOAD_BASE).
# It is owned by www-data and group-writable by design.
#
# uploads/.htaccess is carved back OUT of that exception. It is not data, it
# is the control file that strips PHP/CGI handlers from the upload directory —
# the guard that stops an uploaded file from ever executing. Leaving it
# writable by the web user means a single write re-opens the upload→RCE path
# the guard exists to close. Any fixer must apply this carve-out AFTER its
# recursive chown/chmod on uploads/, or the recursion sweeps it straight back.
#
# ── Why the uploads ROOT is not writable either ──────────────
# Carving out the file is NOT enough on its own, and this was found the hard
# way: write permission on a DIRECTORY lets you unlink anything inside it
# whatever that file's own mode says. With uploads/ owned by www-data and
# group-writable, `rm uploads/.htaccess` as the web user succeeded against a
# 644 root-of-trust file. So the uploads ROOT is bulik:www-data 2755 — the web
# user can traverse and read it but cannot create or delete entries in it —
# while the per-category SUBDIRECTORIES underneath stay www-data-writable,
# which is where uploads actually land.
#
# The cost: the app can no longer mkdir a brand-new category at runtime
# (handleUpload would fail with its "Upload directory not writable" error).
# fix-perms.sh therefore pre-creates the canonical set below, so the only time
# this bites is someone adding a genuinely new upload category — who needs to
# add it here and re-run the fixer.
POLICY_UPLOAD_SUBDIRS=(avatars contracts dividends docs ids payments receipts remittance)

set -euo pipefail

# Root of the live tree. Override for a staging copy or a dry run elsewhere.
LASKIE_ROOT="${LASKIE_ROOT:-/home/bulik/apps/laskie}"
# Human owner of the code. Apache reads via the group, never the owner.
LASKIE_OWNER="${LASKIE_OWNER:-bulik}"
# Group Apache runs as; also the user that owns uploads/.
LASKIE_WEBGROUP="${LASKIE_WEBGROUP:-www-data}"
LASKIE_WEBUSER="${LASKIE_WEBUSER:-www-data}"

LASKIE_UPLOADS="$LASKIE_ROOT/uploads"

# Files that carry credentials — group-readable so Apache can load them, never
# world-readable. config/functions.php is deliberately NOT here: it holds no
# secrets and stays 644 like the rest of the app code.
POLICY_SECRETS=(".env" "config/db.php" "config/env.php")
# Developer secret at the doc root. Mode 600 does the work — at that mode the
# group is irrelevant, so it keeps the tree-wide bulik:www-data ownership
# rather than being the one file with a different group.
POLICY_PRIVATE=(".deploy_credentials")

# Refuse to operate on a directory that isn't obviously this app. Both scripts
# call this before touching anything — a mistyped LASKIE_ROOT with a recursive
# chmod behind it is not a recoverable mistake.
policy_assert_root() {
    [[ -d "$LASKIE_ROOT" ]] || { echo "perms: '$LASKIE_ROOT' is not a directory" >&2; return 1; }
    for marker in config/functions.php install.sql .htaccess; do
        [[ -e "$LASKIE_ROOT/$marker" ]] || {
            echo "perms: '$LASKIE_ROOT' has no $marker — refusing to run (wrong directory?)" >&2
            return 1
        }
    done
}

# policy_for <abs-path> <f|d>  →  prints "owner:group mode[,mode...]"
# The mode field lists every mode acceptable for that path. fix-perms applies
# the FIRST one; check-perms accepts ANY of them. The list exists only where a
# genuine variation is expected — vendor ships executables alongside plain PHP,
# and blessing "644 or 755" there beats hardcoding composer's bin list.
#
# A mode field of "*" means "any mode, as long as it is not group- or
# other-writable". Used where another tool owns the exact bits and only the
# write bit is ours to care about.
policy_for() {
    local path="$1" type="$2" rel base
    rel="${path#"$LASKIE_ROOT"/}"
    [[ "$rel" == "$path" ]] && rel=""          # path IS the root
    base="${path##*/}"

    # ── .git/: git owns the modes, we only insist it isn't web-writable ──
    # Loose objects are 444 by design (immutable content-addressed blobs), so
    # asserting 644 here flags hundreds of files that are MORE restrictive than
    # required — noise that would train us to ignore the checker. Nothing under
    # .git is served (the root .htaccess 403s it) and Apache never reads it, so
    # the only property that matters is that www-data cannot write it.
    if [[ "$rel" == ".git" || "$rel" == .git/* ]]; then
        printf '%s:%s *\n' "$LASKIE_OWNER" "$LASKIE_WEBGROUP"; return
    fi

    # ── uploads/ ROOT: traversable by the web user, not writable ──
    # This is what actually protects uploads/.htaccess — see the header note.
    if [[ "$rel" == "uploads" ]]; then
        printf '%s:%s 2755\n' "$LASKIE_OWNER" "$LASKIE_WEBGROUP"; return
    fi

    # ── under uploads/: the app's own write area ──
    if [[ "$rel" == uploads/* ]]; then
        # ...except any .htaccess, which is a control file, not data.
        if [[ "$base" == ".htaccess" ]]; then
            printf '%s:%s 644\n' "$LASKIE_OWNER" "$LASKIE_WEBGROUP"; return
        fi
        # Two modes are accepted here because two are produced naturally and
        # both are correct: the fixer normalises to 2775/664, while PHP's own
        # umask leaves a freshly uploaded file at 644. Since www-data OWNS
        # these, owner-write already grants what the app needs — insisting on
        # an exact mode would flag ~120 healthy receipts on every run and
        # train us to ignore the checker.
        if [[ "$type" == "d" ]]; then
            printf '%s:%s 2775,2755\n' "$LASKIE_WEBUSER" "$LASKIE_WEBGROUP"
        else
            printf '%s:%s 664,644\n' "$LASKIE_WEBUSER" "$LASKIE_WEBGROUP"
        fi
        return
    fi

    # ── credentials ──
    local s
    for s in "${POLICY_PRIVATE[@]}";  do
        [[ "$rel" == "$s" ]] && { printf '%s:%s 600\n' "$LASKIE_OWNER" "$LASKIE_WEBGROUP"; return; }
    done
    for s in "${POLICY_SECRETS[@]}"; do
        [[ "$rel" == "$s" ]] && { printf '%s:%s 640\n' "$LASKIE_OWNER" "$LASKIE_WEBGROUP"; return; }
    done

    # ── everything else: readable by the web group, writable by nobody else ──
    if [[ "$type" == "d" ]]; then
        # setgid so new files keep group www-data; no group write.
        printf '%s:%s 2755\n' "$LASKIE_OWNER" "$LASKIE_WEBGROUP"; return
    fi
    if [[ "$path" == *.sh ]]; then
        printf '%s:%s 755\n' "$LASKIE_OWNER" "$LASKIE_WEBGROUP"; return
    fi
    if [[ "$rel" == vendor/* ]]; then
        # Composer-managed: plain sources are 644, bin/ entry points 755.
        printf '%s:%s 644,755\n' "$LASKIE_OWNER" "$LASKIE_WEBGROUP"; return
    fi
    printf '%s:%s 644\n' "$LASKIE_OWNER" "$LASKIE_WEBGROUP"
}
