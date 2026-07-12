#!/usr/bin/env bash
# ekdosi release-tagger. Run on the DEV box AFTER a release PR merges to main.
#
#   sh tag-release.sh          → STATUS + options (read-only, does nothing)
#   sh tag-release.sh --tag    → pull main + create & push the tag from config/app.php
#   sh tag-release.sh --help
#
# The version is READ from config/app.php (the canonical source `ekdosi:release`
# bumps) so there's no number to type and no way to tag the wrong one. The remote
# (origin) is the source of truth for «tagged» — a tag that exists locally but was
# never pushed still counts as NOT done. Tagging is idempotent. We deliberately do
# NOT auto-tag from CI (a GitHub Action would burn Actions minutes and couple
# releasing to CI) — this local script is the tagger.
set -euo pipefail

cd "$(dirname "$0")"

read_version() {
    grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" config/app.php \
        | head -1 | grep -oE "'[0-9][^']*'" | tr -d "'" || true
}

# Is TAG on origin? Empty output ⇒ no (or the remote is unreachable — we fall back
# to the local tag for the read-only status so it still works offline).
remote_has_tag() {
    [ -n "$(git ls-remote --tags origin "refs/tags/$1" 2>/dev/null || true)" ]
}
local_has_tag() {
    git rev-parse -q --verify "refs/tags/$1" >/dev/null 2>&1
}

# --- read current state (all read-only) ---
VERSION=$(read_version)
TAG="v${VERSION}"
BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')
LATEST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo '(κανένα)')
CHANGELOG_TOP=$(grep -m1 -E '^## \[[0-9]' CHANGELOG.md 2>/dev/null || echo '(—)')
TREE=$([ -z "$(git status --porcelain 2>/dev/null)" ] && echo 'καθαρό' || echo 'με αλλαγές')

if remote_has_tag "$TAG"; then
    TAG_STATE="✓ στο origin (ταγαρισμένο)"
    TAG_DONE=1
elif local_has_tag "$TAG"; then
    TAG_STATE="⚠ τοπικά μόνο — δεν έγινε push (τρέξε --tag)"
    TAG_DONE=0
else
    TAG_STATE="✗ ΔΕΝ υπάρχει — χρειάζεται tag"
    TAG_DONE=0
fi

# Non-empty [Unreleased] ⇒ there are changes not yet cut into a version.
UNRELEASED=$(awk '/^## \[Unreleased\]/{f=1;next} f&&/^## \[/{exit} f&&/[^[:space:]]/{print}' CHANGELOG.md 2>/dev/null || true)
if [ -n "$UNRELEASED" ]; then
    PENDING="ΝΑΙ — τρέξε πρώτα «php artisan ekdosi:release»"
else
    PENDING="όχι"
fi

show_status() {
    cat <<EOF

  ekdosi — κατάσταση έκδοσης
  ─────────────────────────────────────────────
  Έκδοση (config/app.php):   ${VERSION:-'(δεν βρέθηκε)'}
  CHANGELOG κορυφή:          ${CHANGELOG_TOP}
  Τελευταίο git tag:         ${LATEST_TAG}
  Tag ${TAG}:                ${TAG_STATE}
  Branch:                    ${BRANCH} (${TREE})
  Αδημοσίευτες αλλαγές:      ${PENDING}
  ─────────────────────────────────────────────

  Τι μπορείς να κάνεις:
EOF
    if [ "$BRANCH" != 'main' ]; then
        echo "    • (είσαι σε '${BRANCH}', όχι main — το --tag κάνει checkout main μόνο του)"
    fi
    if [ -n "$UNRELEASED" ]; then
        echo "    • php artisan ekdosi:release   → κόψε έκδοση (αυτόματο επίπεδο) ΠΡΙΝ ταγάρεις"
    fi
    if [ "$TAG_DONE" -eq 0 ]; then
        echo "    • sh tag-release.sh --tag      → pull main + δημιουργία & push του ${TAG}"
    else
        echo "    • (τίποτα) — το ${TAG} είναι στο origin· η τρέχουσα έκδοση είναι ταγαρισμένη"
    fi
    echo "    • sh tag-release.sh --help     → βοήθεια"
    echo
}

do_tag() {
    echo "==> Sync main..."
    git checkout main
    git pull --ff-only

    # Re-read after the pull (main may carry a newer bump than the working copy).
    VERSION=$(read_version)
    if [ -z "${VERSION:-}" ]; then
        echo "✋ Δεν βρήκα 'version' στο config/app.php μετά το pull — άκυρο." >&2
        exit 1
    fi
    TAG="v${VERSION}"

    # The remote is authoritative: only «already on origin» means done. A tag that
    # exists locally but not on origin (a prior push that failed) must still push.
    if remote_has_tag "$TAG"; then
        echo "✓ Το ${TAG} υπάρχει ήδη στο origin — τίποτα να κάνω."
        exit 0
    fi
    if ! grep -qE "^## \[${VERSION}\] - " CHANGELOG.md; then
        echo "⚠ Δεν υπάρχει «## [${VERSION}] - …» στο CHANGELOG — σιγουρέψου ότι έτρεξες ekdosi:release." >&2
    fi

    echo "==> Tag ${TAG} στο main ($(git rev-parse --short HEAD))..."
    if local_has_tag "$TAG"; then
        echo "ℹ Υπάρχει ήδη τοπικό ${TAG} — γίνεται μόνο push στο origin."
    else
        git tag "${TAG}"
    fi
    git push origin "${TAG}"
    echo "✓ Το ${TAG} είναι στο origin."
}

case "${1:-}" in
    ''|status|-s|--status) show_status ;;
    -t|--tag) do_tag ;;
    -h|--help)
        grep -E '^#( |$)' "$0" | sed -E 's/^# ?//'
        ;;
    *)
        echo "Άγνωστο όρισμα: $1" >&2
        show_status
        exit 1
        ;;
esac
