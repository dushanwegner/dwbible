#!/usr/bin/env bash
# test-bible-routes.sh — HTTP smoke test for all Bible routes
#
# WHAT:  Hits every book across all dataset combos, plus JSON API, redirects,
#        sitemaps, and edge cases. Reports any unexpected HTTP status codes.
#
# USAGE: ./tests/test-bible-routes.sh [BASE_URL]
#        Default BASE_URL: https://latinprayer.org
#
# DEPENDS ON: curl
#
# TESTED BY: Run it. Green = all routes work. Red = something is broken.

set -euo pipefail

BASE_URL="${1:-https://latinprayer.org}"
BASE_URL="${BASE_URL%/}"  # strip trailing slash

FAILURES=0
TOTAL=0
FAILED_URLS=()

# ── Helpers ──────────────────────────────────────────────────────────

check() {
    local url="$1"
    local expected="$2"
    local label="$3"
    TOTAL=$((TOTAL + 1))
    local code
    code=$(curl -s -o /dev/null -w "%{http_code}" "$url" 2>/dev/null)
    if [ "$code" != "$expected" ]; then
        echo "  FAIL [$code != $expected] $label"
        FAILURES=$((FAILURES + 1))
        FAILED_URLS+=("$url ($code != $expected)")
    fi
}

check_redirect() {
    local url="$1"
    local expected_target="$2"
    local label="$3"
    TOTAL=$((TOTAL + 1))
    local header
    header=$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" "$url" 2>/dev/null)
    local code="${header%% *}"
    local target="${header#* }"
    if [ "$code" != "301" ]; then
        echo "  FAIL [$code != 301] $label"
        FAILURES=$((FAILURES + 1))
        FAILED_URLS+=("$url ($code != 301)")
    elif [ -n "$expected_target" ] && [[ "$target" != *"$expected_target"* ]]; then
        echo "  FAIL [redirect to '$target', expected *$expected_target*] $label"
        FAILURES=$((FAILURES + 1))
        FAILED_URLS+=("$url (wrong redirect target)")
    fi
}

section() {
    echo ""
    echo "=== $1 ==="
}

# ── All 73 books (English Douay-Rheims slugs) ───────────────────────
# These are the canonical slugs from the bible index CSV.
BOOKS=(
    genesis exodus leviticus numbers deuteronomy
    josue judges ruth 1-kings-samuel 2-kings-samuel
    3-kings 4-kings 1-paralipomenon 2-paralipomenon
    1-esdras 2-esdras-nehemias tobias judith esther
    job psalms proverbs ecclesiastes canticle-of-canticles
    wisdom ecclesiasticus isaias jeremias lamentations
    baruch ezechiel daniel osee joel amos abdias
    jonas micheas nahum habacuc sophonias aggeus
    zacharias malachias 1-machabees 2-machabees
    matthew mark luke john acts romans
    1-corinthians 2-corinthians galatians ephesians
    philippians colossians 1-thessalonians 2-thessalonians
    1-timothy 2-timothy titus philemon hebrews james
    1-peter 2-peter 1-john 2-john 3-john jude apocalypse
)

# German slugs for bibel-specific tests
GERMAN_BOOKS=(
    genesis exodus levitikus numeri deuteronomium
    josua richter rut 1-samuel 2-samuel
    1-koenige 2-koenige 1-chronik 2-chronik
    1-esra 2-esra-nehemia tobit judith esther
    hiob psalmen sprueche prediger hoheslied
    weisheit jesus-sirach jesaja jeremia klagelieder
    baruch hesekiel daniel hosea joel amos obadja
    jona micha nahum habakuk zefanja haggai
    sacharja maleachi 1-makkabaeer 2-makkabaeer
    matthaeus markus lukas johannes apostelgeschichte roemer
    1-korinther 2-korinther galater epheser
    philipper kolosser 1-thessalonicher 2-thessalonicher
    1-timotheus 2-timotheus titus philemon hebraeer jakobus
    1-petrus 2-petrus 1-johannes 2-johannes 3-johannes judas offenbarung
)

# ── 1. Selftest endpoint ────────────────────────────────────────────
section "Selftest endpoint"
check "$BASE_URL/bible/?dwbible_selftest=1" 200 "selftest endpoint"

# ── 2. All 73 books on /latin-bible/ (the primary interlinear combo) ─
section "All 73 books on /latin-bible/"
for book in "${BOOKS[@]}"; do
    check "$BASE_URL/latin-bible/$book/" 200 "latin-bible/$book"
done

# ── 3. All 73 books on /latin-bibel/ ────────────────────────────────
section "All 73 German books on /latin-bibel/"
for book in "${GERMAN_BOOKS[@]}"; do
    check "$BASE_URL/latin-bibel/$book/" 200 "latin-bibel/$book"
done

# ── 4. Sample books on /latin/ (single-language, no redirect) ───────
section "Sample books on /latin/"
for book in genesis josue psalms isaias matthew apocalypse; do
    check "$BASE_URL/latin/$book/" 200 "latin/$book"
done

# ── 5. Redirects: /bible/ → /latin-bible/ ───────────────────────────
section "Single-language → interlinear redirects"
check_redirect "$BASE_URL/bible/" "/latin-bible/" "bible/ → latin-bible/"
check_redirect "$BASE_URL/bibel/" "/latin-bibel/" "bibel/ → latin-bibel/"
check_redirect "$BASE_URL/bible/genesis/" "/latin-bible/genesis/" "bible/genesis/ → latin-bible/genesis/"
check_redirect "$BASE_URL/bible/josue/" "/latin-bible/josue/" "bible/josue/ → latin-bible/josue/"
check_redirect "$BASE_URL/bibel/hiob/" "/latin-bibel/hiob/" "bibel/hiob/ → latin-bibel/hiob/"

# ── 6. Chapter and verse pages ──────────────────────────────────────
section "Chapter and verse pages"
check "$BASE_URL/latin-bible/genesis/1" 200 "latin-bible/genesis/1"
check "$BASE_URL/latin-bible/john/3:16" 200 "latin-bible/john/3:16"
check "$BASE_URL/latin-bible/romans/8:28-30" 200 "latin-bible/romans/8:28-30"
check "$BASE_URL/latin-bible/psalms/23" 200 "latin-bible/psalms/23"
check "$BASE_URL/latin-bibel/psalmen/23" 200 "latin-bibel/psalmen/23"

# ── 7. JSON API ─────────────────────────────────────────────────────
section "JSON API"
check "$BASE_URL/bible/index.json" 200 "bible/index.json"
check "$BASE_URL/bibel/index.json" 200 "bibel/index.json"
check "$BASE_URL/latin/index.json" 200 "latin/index.json"
check "$BASE_URL/bible/genesis/index.json" 200 "bible/genesis/index.json"
check "$BASE_URL/bible/genesis/1.json" 200 "bible/genesis/1.json"
check "$BASE_URL/bible/john/3:16.json" 200 "bible/john/3:16.json"
check "$BASE_URL/bible-index.json" 200 "bible-index.json (unified)"

# ── 8. AI access files ──────────────────────────────────────────────
section "AI access (llms.txt)"
check "$BASE_URL/llms.txt" 200 "llms.txt"
check "$BASE_URL/llms-full.txt" 200 "llms-full.txt"

# ── 9. Sitemaps ─────────────────────────────────────────────────────
section "Sitemaps"
check "$BASE_URL/sitemap-index.xml" 200 "sitemap-index.xml"
check "$BASE_URL/bible-sitemap-bible-genesis.xml" 200 "bible-sitemap-bible-genesis.xml"
check "$BASE_URL/bible-sitemap-latin-genesis.xml" 200 "bible-sitemap-latin-genesis.xml"
check "$BASE_URL/bible-sitemap-bibel-genesis.xml" 200 "bible-sitemap-bibel-genesis.xml"
check "$BASE_URL/bible-sitemap-bible-josue.xml" 200 "bible-sitemap-bible-josue.xml"
check "$BASE_URL/bible-sitemap-bible-apocalypse.xml" 200 "bible-sitemap-bible-apocalypse.xml"

# ── 10. Cross-dataset name resolution ───────────────────────────────
section "Cross-dataset name resolution"
# English names on Latin pages
check "$BASE_URL/latin/genesis/" 200 "latin/genesis (shared name)"
check "$BASE_URL/latin/josue/" 200 "latin/josue (Vulgate name on latin)"
# German names on interlinear combo
check "$BASE_URL/latin-bibel/hiob/" 200 "latin-bibel/hiob (German name)"
check "$BASE_URL/latin-bibel/psalmen/" 200 "latin-bibel/psalmen (German name)"
check "$BASE_URL/latin-bibel/matthaeus/" 200 "latin-bibel/matthaeus (German name)"

# ── 11. Abbreviation resolution ─────────────────────────────────────
section "Abbreviation / shorthand URLs"
# These should redirect to canonical slugs
check "$BASE_URL/latin-bible/Gen/" 200 "latin-bible/Gen (abbreviation)"
check "$BASE_URL/latin-bible/Matt/" 200 "latin-bible/Matt (abbreviation)"

# ── 12. 3-way interlinear combos ────────────────────────────────────
section "3-way interlinear combos"
check "$BASE_URL/bible-bibel-latin/genesis/1" 200 "bible-bibel-latin/genesis/1"
check "$BASE_URL/bible-bibel-latin/psalms/23" 200 "bible-bibel-latin/psalms/23"
check "$BASE_URL/bible-bibel-latin/john/1" 200 "bible-bibel-latin/john/1"

# ── 13. Expected 404s (should NOT resolve) ──────────────────────────
section "Expected 404s"
check "$BASE_URL/latin-bible/not-a-book/" 404 "not-a-book → 404"
check "$BASE_URL/latin-bible/foobar/" 404 "foobar → 404"

# ── Report ───────────────────────────────────────────────────────────
echo ""
echo "════════════════════════════════════════════════════════════════"
if [ "$FAILURES" -eq 0 ]; then
    echo "  ALL $TOTAL TESTS PASSED"
else
    echo "  $FAILURES / $TOTAL TESTS FAILED"
    echo ""
    echo "  Failed URLs:"
    for f in "${FAILED_URLS[@]}"; do
        echo "    - $f"
    done
fi
echo "════════════════════════════════════════════════════════════════"

exit "$FAILURES"
