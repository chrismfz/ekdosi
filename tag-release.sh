#!/usr/bin/env bash
# ekdosi release-tagger. Run on the DEV box AFTER a release PR merges to main.
#
#   sh tag-release.sh          → STATUS + options (read-only, does nothing)
#   sh tag-release.sh --tag    → the WHOLE release, one safe step: pull main →
#                                (cut the release if [Unreleased] has changes) →
#                                commit → verify → push main → create & push the tag
#   sh tag-release.sh --tag -y → same, no confirmation prompt (for automation)
#   sh tag-release.sh --help
#
# Why one command: the old flow was three manual steps (ekdosi:release → git
# commit → tag), and it was possible to TAG BEFORE COMMITTING — which tagged the
# wrong commit (HEAD without the version bump) while reading the new version from
# the uncommitted config. `--tag` now does the whole thing atomically and REFUSES
# to tag unless the commit it points at actually carries that version. The version
# is READ from config/app.php (the canonical source `ekdosi:release` bumps) so
# there's no number to type. The remote (origin) is the source of truth for
# «tagged». We deliberately do NOT auto-tag from CI.

# Re-exec under bash when invoked as `sh tag-release.sh` on a box whose /bin/sh is
# dash/posh (no `pipefail`, no `local`). Keeps `sh tag-release.sh` working anywhere.
if [ -z "${BASH_VERSION:-}" ]; then exec bash "$0" "$@"; fi

set -euo pipefail

cd "$(dirname "$0")"

read_version() {
    grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" config/app.php \
        | head -1 | grep -oE "'[0-9][^']*'" | tr -d "'" || true
}

# The version recorded AT a given git ref (defaults to HEAD) — used to PROVE the
# commit we're about to tag actually carries the bump (the check the old script
# lacked, which let v1.12.0 land on a 1.11.0 commit).
version_at() {
    git show "${1:-HEAD}:config/app.php" 2>/dev/null \
        | grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" \
        | head -1 | grep -oE "'[0-9][^']*'" | tr -d "'" || true
}

remote_has_tag() {
    [ -n "$(git ls-remote --tags origin "refs/tags/$1" 2>/dev/null || true)" ]
}
local_has_tag() {
    git rev-parse -q --verify "refs/tags/$1" >/dev/null 2>&1
}

# The raw text under «## [Unreleased]» (up to the next «## [»). Non-empty ⇒ there
# are changes not yet cut into a version.
unreleased_body() {
    awk '/^## \[Unreleased\]/{f=1;next} f&&/^## \[/{exit} f&&/[^[:space:]]/{print}' CHANGELOG.md 2>/dev/null || true
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

UNRELEASED=$(unreleased_body)
if [ -n "$UNRELEASED" ]; then
    PENDING="ΝΑΙ — το --tag θα κόψει έκδοση + θα ταγάρει"
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
    if [ "$TAG_DONE" -eq 0 ] || [ -n "$UNRELEASED" ]; then
        echo "    • sh tag-release.sh --tag      → όλα σε ένα: (έκδοση αν χρειάζεται) + commit + push + tag"
    else
        echo "    • (τίποτα) — το ${TAG} είναι στο origin· η τρέχουσα έκδοση είναι ταγαρισμένη"
    fi
    echo "    • sh tag-release.sh --help     → βοήθεια"
    echo
}

die() { echo "✋ $1" >&2; exit 1; }

do_tag() {
    local assume_yes="${1:-0}"

    echo "==> Sync main..."
    git checkout main
    # A clean tree is REQUIRED before we touch anything — a stray uncommitted edit
    # is exactly how a release got tagged on the wrong commit. Refuse loudly.
    if [ -n "$(git status --porcelain)" ]; then
        die "Το working tree έχει αλλαγές. Κάνε commit/stash πρώτα, μετά ξανατρέξε το --tag."
    fi
    git pull --ff-only || die "Το 'git pull --ff-only' απέτυχε (το main απέκλινε;). Ξεμπλέξ' το χειροκίνητα."

    # Did the pull bring unreleased changes? Cut the release now (roll CHANGELOG +
    # bump config), so the tag we make actually carries the new version.
    local cut_now=0
    if [ -n "$(unreleased_body)" ]; then
        cut_now=1
        echo "==> [Unreleased] έχει αλλαγές — κόβω έκδοση..."
        php artisan ekdosi:release || die "Το 'php artisan ekdosi:release' απέτυχε."
        # ekdosi:release must touch ONLY these two files — anything else is a surprise.
        local changed
        changed=$(git status --porcelain | awk '{print $2}' | sort | tr '\n' ' ' | xargs)
        if [ "$changed" != "CHANGELOG.md config/app.php" ]; then
            git checkout -- CHANGELOG.md config/app.php 2>/dev/null || true
            die "Το ekdosi:release άλλαξε απροσδόκητα αρχεία ($changed) — έγινε revert. Έλεγξέ το με το χέρι."
        fi
    fi

    VERSION=$(read_version)
    [ -n "${VERSION:-}" ] || die "Δεν βρήκα 'version' στο config/app.php."
    TAG="v${VERSION}"

    if remote_has_tag "$TAG"; then
        # Nothing to cut AND already tagged → truly done. (If we just cut, this
        # would be a version collision — revert and stop.)
        if [ "$cut_now" -eq 1 ]; then
            git checkout -- CHANGELOG.md config/app.php 2>/dev/null || true
            die "Το ${TAG} υπάρχει ΗΔΗ στο origin αλλά μόλις έκοψα ${TAG} — σύγκρουση έκδοσης. Έγινε revert· έλεγξε το CHANGELOG."
        fi
        echo "✓ Το ${TAG} υπάρχει ήδη στο origin — τίποτα να κάνω."
        exit 0
    fi

    # --- preview + confirm (before ANY commit/tag/push) ---
    local commit_line
    if [ "$cut_now" -eq 1 ]; then
        commit_line="release: ${TAG}  (CHANGELOG + config/app.php)"
    else
        commit_line="(κανένα — ήδη commited)"
    fi
    cat <<EOF

  Θα γίνει:
  ─────────────────────────────────────────────
  Έκδοση:        ${VERSION}$([ "$cut_now" -eq 1 ] && echo '   (μόλις κόπηκε από το [Unreleased])')
  Tag:           ${TAG}
  Commit:        ${commit_line}
  Push:          origin/main + ${TAG}
  ─────────────────────────────────────────────
EOF
    if [ "$assume_yes" -ne 1 ]; then
        printf '  Συνέχεια; [y/N] '
        read -r ans
        case "$ans" in
            y|Y|yes|Yes|ναι|ΝΑΙ) ;;
            *)
                [ "$cut_now" -eq 1 ] && git checkout -- CHANGELOG.md config/app.php 2>/dev/null || true
                echo "Άκυρο — τίποτα δεν άλλαξε."
                exit 0
                ;;
        esac
    fi

    if [ "$cut_now" -eq 1 ]; then
        git add CHANGELOG.md config/app.php
        git commit -m "release: ${TAG}"
    fi

    # --- the guard the old script lacked: the commit MUST carry this version ---
    if ! grep -qE "^## \[${VERSION}\] - " CHANGELOG.md; then
        die "Το CHANGELOG δεν έχει «## [${VERSION}] - …» — δεν κόπηκε σωστά η έκδοση. Δεν ταγάρω."
    fi
    if [ "$(version_at HEAD)" != "$VERSION" ]; then
        die "Το config/app.php στο HEAD δεν είναι ${VERSION} (είναι '$(version_at HEAD)'). Το commit δεν φέρει την έκδοση — ΔΕΝ ταγάρω (αυτό ήταν το παλιό bug)."
    fi

    echo "==> Push main..."
    git push origin main

    echo "==> Tag ${TAG} στο $(git rev-parse --short HEAD)..."
    local_has_tag "$TAG" || git tag "$TAG"
    git push origin "$TAG"
    echo "✓ Το ${TAG} είναι στο origin, στο σωστό commit."
}

ASSUME_YES=0
CMD=""
for arg in "$@"; do
    case "$arg" in
        -y|--yes) ASSUME_YES=1 ;;
        -t|--tag) CMD="tag" ;;
        ''|status|-s|--status) [ -z "$CMD" ] && CMD="status" ;;
        -h|--help) CMD="help" ;;
        *) echo "Άγνωστο όρισμα: $arg" >&2; CMD="status" ;;
    esac
done

case "${CMD:-status}" in
    tag) do_tag "$ASSUME_YES" ;;
    help) grep -E '^#( |$)' "$0" | sed -E 's/^# ?//' ;;
    *) show_status ;;
esac
