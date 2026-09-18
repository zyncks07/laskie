#!/usr/bin/env bash
# ============================================================
# Laskie RMS — permission checker (read-only)
# ============================================================
# Asserts the policy in perms-policy.sh. Writes nothing, needs no sudo, has no
# side effects — safe to run any time, including at the end of a session that
# touched files.
#
#   bash scripts/check-perms.sh          # report + exit 1 if anything drifted
#   bash scripts/check-perms.sh -q       # exit code only
#   LASKIE_ROOT=/path bash scripts/check-perms.sh
#
# Exit 0 = clean, 1 = violations found, 2 = could not run.
#
# Why this exists: the 2026-06-10 audit's fix (W7-1) chmod'd app PHP to 644 and
# added a re-chmod line to laskie-recompress.sh, which was called durable. It
# wasn't — it only covered *.php, and only when someone remembered to run the
# script. api/cat_chart_api.php was committed afterwards and sat group-writable
# for months with nothing to notice. This is the thing that notices.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./perms-policy.sh
source "$SCRIPT_DIR/perms-policy.sh"

QUIET=0
[[ "${1:-}" == "-q" || "${1:-}" == "--quiet" ]] && QUIET=1

policy_assert_root || exit 2

R=$'\033[0;31m'; G=$'\033[0;32m'; Y=$'\033[0;33m'; B=$'\033[1m'; N=$'\033[0m'
[[ -t 1 ]] || { R=""; G=""; Y=""; B=""; N=""; }

say() { [[ $QUIET -eq 1 ]] || printf '%b\n' "$*"; }

violations=0
writable=0
scanned=0

# One find, one pass, no subprocess per file — the tree is ~2k entries under
# vendor/ alone and a stat(1) per entry would make this too slow to run often.
# %m is the octal mode WITHOUT a leading zero for plain 0644, but four digits
# for setgid dirs, so compare as integers-as-strings after normalising.
while IFS='|' read -r mode owner group type path; do
    scanned=$((scanned + 1))

    expected="$(policy_for "$path" "$type")"
    exp_own="${expected%% *}"
    exp_modes="${expected##* }"

    bad=""

    # The headline invariant: the web user must never be able to write code,
    # rules, or scripts. Called out separately from a plain mode mismatch
    # because this is the one that has security consequences.
    if [[ "$exp_own" != "$LASKIE_WEBUSER:$LASKIE_WEBGROUP" ]] && (( (8#$mode & 0022) != 0 )); then
        bad="group/other-writable"
        writable=$((writable + 1))
    fi

    # "*" = any mode, provided the group/other-writable check above passed.
    if [[ "$exp_modes" != "*" && ",$exp_modes," != *",$mode,"* ]]; then
        bad="${bad:+$bad; }mode $mode != ${exp_modes//,/ or }"
    fi
    if [[ "$owner:$group" != "$exp_own" ]]; then
        bad="${bad:+$bad; }owner $owner:$group != $exp_own"
    fi

    if [[ -n "$bad" ]]; then
        violations=$((violations + 1))
        say "  ${R}✗${N} $(printf '%-5s %-18s' "$mode" "$owner:$group") ${path#"$LASKIE_ROOT"/}  ${Y}($bad)${N}"
    fi
done < <(find "$LASKIE_ROOT" \( -type f -o -type d \) -printf '%m|%u|%g|%y|%p\n' 2>/dev/null)

say ""
if (( violations == 0 )); then
    say "${G}[ OK ]${N} $scanned paths checked, all match policy."
    exit 0
fi

say "${R}${B}$violations of $scanned paths violate the permission policy${N}"
(( writable > 0 )) && say "${R}  → $writable of them are writable by $LASKIE_WEBUSER (the web user can modify these)${N}"
say "  Fix with: ${B}sudo bash $SCRIPT_DIR/fix-perms.sh${N}"
exit 1
