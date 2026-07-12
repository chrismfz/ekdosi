#!/usr/bin/env bash
# ekdosi release-tagger. Run on the DEV box AFTER a release PR merges to main.
#
#   sh tag-release.sh          → STATUS + options (read-only, does nothing)
#   sh tag-release.sh --tag    → pull main + create & push the tag from config/app.php
#   sh tag-release.sh --help
#
# The version is READ from config/app.php (the canonical source `ekdosi:release`
# bumps) so there's no number to type and no way to tag the wrong one. Tagging is
# idempotent. We deliberately do NOT auto-tag from CI (a GitHub Action would burn
# Actions minutes and couple releasing to CI) — this local script is the tagger.
set -euo pipefail

cd "$(dirname "$0")"

# --- read current state (all read-only) ---
VERSION=$(grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" config/app.php \
    | head -1 | grep -oE "'[0-9][^']*'" | tr -d "'" || true)
TAG="v${VERSION}"
BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')
LATEST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo '(κανένα)')
CHANGELOG_TOP=$(grep -m1 -E '^## \[[0-9]' CHANGELOG.md 2>/dev/null || echo '(—)')
TREE=$([ -z "$(git status --porcelain 2>/dev/null)" ] && echo 'καθαρό' || echo 'με αλλαγές')

if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null 2>&1; then
    TAG_STATE="✓ υπάρχει"
    TAG_EXISTS=1
else
    TAG_STATE="✗ ΔΕΝ υπάρχει — χρειάζεται tag"
    TAG_EXISTS=0
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
    if [ -n "$UNRELEASED" ]; then
        echo "    • php artisan ekdosi:release   → κόψε έκδοση (αυτόματο επίπεδο) ΠΡΙΝ ταγάρεις"
    fi
    if [ "$TAG_EXISTS" -eq 0 ]; then
        echo "    • sh tag-release.sh --tag      → pull main + δημιουργία & push του ${TAG}"
    else
        echo "    • (τίποτα) — το ${TAG} υπάρχει ήδη· η τρέχουσα έκδοση είναι ταγαρισμένη"
    fi
    echo "    • sh tag-release.sh --help     → βοήθεια"
    echo
}

do_tag() {
    if [ -z "${VERSION:-}" ]; then
        echo "✋ Δεν βρήκα 'version' στο config/app.php — άκυρο." >&2
        exit 1
    fi

    echo "==> Sync main..."
    git checkout main
    git pull --ff-only

    # Re-read after the pull (main may carry a newer bump than the working copy).
    VERSION=$(grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" config/app.php \
        | head -1 | grep -oE "'[0-9][^']*'" | tr -d "'")
    TAG="v${VERSION}"

    if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null 2>&1; then
        echo "✓ Το tag ${TAG} υπάρχει ήδη — τίποτα να κάνω."
        exit 0
    fi
    if ! grep -qE "^## \[${VERSION}\] - " CHANGELOG.md; then
        echo "⚠ Δεν υπάρχει «## [${VERSION}] - …» στο CHANGELOG — σιγουρέψου ότι έτρεξες ekdosi:release." >&2
    fi

    echo "==> Tag ${TAG} στο main ($(git rev-parse --short HEAD))..."
    git tag "${TAG}"
    git push origin "${TAG}"
    echo "✓ Δημιουργήθηκε + έγινε push το ${TAG}."
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
