#!/usr/bin/env bash
# test-bible-routes.sh — HTTP smoke test for all Bible routes
#
# WHAT:  Hits every book across all dataset combos, plus JSON API, redirects,
#        sitemaps, and edge cases. Reports any unexpected HTTP status codes.
#
# USAGE: ./tests/test-bible-routes.sh [BASE_URL]
#        Default BASE_URL: https://latinprayer.org
#
# DEPENDS ON: curl, python3 (for selftest JSON parsing)
#
# TESTED BY: Run it. Green = all routes work. Red = something is broken.

set -euo pipefail

BASE_URL="${1:-https://latinprayer.org}"
BASE_URL="${BASE_URL%/}"  # strip trailing slash

FAILURES=0
TOTAL=0
FAILED_URLS=()

# ── Helpers ──────────────────────────────────────────────────────────

# Expect exact HTTP status (no redirect following)
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

# Follow redirects, expect final status 200
# Use for URLs that may canonicalize via 301 (German slugs, abbreviations)
check_follow() {
    local url="$1"
    local label="$2"
    TOTAL=$((TOTAL + 1))
    local code
    code=$(curl -s -o /dev/null -L -w "%{http_code}" "$url" 2>/dev/null)
    if [ "$code" != "200" ]; then
        echo "  FAIL [final $code != 200] $label"
        FAILURES=$((FAILURES + 1))
        FAILED_URLS+=("$url (final $code != 200)")
    fi
}

# Expect a 301 redirect to a URL containing the expected substring
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
# These are the canonical slugs from the bible/latin index CSVs.
# They work without redirects on /latin-bible/.
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

# German slugs from the bibel index CSV.
# On /latin-bibel/ some redirect to Vulgate canonical form (which is fine).
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
# Checks that the 4 data-consistency selftest checks pass.
# The selftest overall may return 500 due to other (pre-existing) issues,
# so we parse the JSON and check only the data-consistency checks.
section "Selftest (data consistency)"
TOTAL=$((TOTAL + 1))
SELFTEST_JSON=$(curl -s "$BASE_URL/bible/?dwbible_selftest=1" 2>/dev/null)
SELFTEST_OK=true
for check_name in osis_dataset_consistency interlinear_osis_resolution book_map_consistency all_books_resolve_in_combos; do
    # The selftest endpoint may return a 500 HTML page (pre-existing, unrelated
    # checks can fail); json.load then throws. Under `set -euo pipefail` that
    # non-zero pipe would abort the whole script before the later sections run,
    # so tolerate it with `|| echo MISSING` and let the per-check logic report.
    result=$(echo "$SELFTEST_JSON" | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
except Exception:
    print('MISSING'); sys.exit(0)
for c in data.get('checks', []):
    if c.get('name') == '$check_name':
        print('ok' if c.get('ok') else 'FAIL: ' + json.dumps(c.get('error', {})))
        sys.exit(0)
print('MISSING')
" 2>/dev/null || echo "MISSING")
    if [ "$result" != "ok" ]; then
        echo "  FAIL selftest/$check_name: $result"
        SELFTEST_OK=false
    fi
done
if [ "$SELFTEST_OK" != "true" ]; then
    FAILURES=$((FAILURES + 1))
    FAILED_URLS+=("selftest data-consistency checks")
fi

# ── 2. All 73 books on /latin-bible/ (the primary interlinear combo) ─
section "All 73 books on /latin-bible/"
for book in "${BOOKS[@]}"; do
    check "$BASE_URL/latin-bible/$book/" 200 "latin-bible/$book"
done

# ── 3. All 73 German books on /latin-bibel/ ─────────────────────────
# Some German slugs redirect to canonical (Vulgate) form — that's OK.
# We follow redirects and just verify the final page loads (200).
section "All 73 German books on /latin-bibel/ (follow redirects)"
for book in "${GERMAN_BOOKS[@]}"; do
    check_follow "$BASE_URL/latin-bibel/$book/" "latin-bibel/$book"
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

# Content invariants of the unified index (not just HTTP 200):
#   - all 6 translations advertised (la/en/de/fr/es/it)
#   - 73 books, canonical chapter total (1333, Clementine division)
#   - every per-language jsonUrl slug maps to that book's canonicalSlug — guards
#     the order-vs-slug merge bug where a dataset with a different book order
#     (e.g. Italian, offset +2 from job onward) mis-mapped a book (Italian's
#     Daniel had landed under canonical Joel).
section "Unified index content invariants"
TOTAL=$((TOTAL + 1))
UNIFIED_JSON=$(curl -s "$BASE_URL/bible-index.json" 2>/dev/null)
UNIFIED_RESULT=$(echo "$UNIFIED_JSON" | python3 -c "
import json, sys, re
try:
    d = json.load(sys.stdin)
except Exception as e:
    print('FAIL: not valid JSON (%s)' % e); sys.exit(0)
books = d.get('books', [])
langs = list(d.get('_meta', {}).get('translations', {}).keys())
problems = []
want_langs = ['latin', 'bible', 'bibel', 'french', 'spanish', 'italian']
missing_langs = [l for l in want_langs if l not in langs]
if missing_langs:
    problems.append('translations missing: %s' % ','.join(missing_langs))
if len(books) != 73:
    problems.append('book count %d != 73' % len(books))
total_ch = sum(b.get('totalChapters', 0) for b in books)
if total_ch != 1333:
    problems.append('chapter total %d != 1333' % total_ch)
mism = []
for b in books:
    cslug = b.get('canonicalSlug', '')
    for lg in want_langs:
        t = b.get('translations', {}).get(lg)
        if not t:
            mism.append('%s/%s:missing' % (cslug, lg)); continue
        m = re.search(r'/%s/([^/]+)/index\.json' % re.escape(lg), t.get('jsonUrl', ''))
        if m and m.group(1) != cslug:
            mism.append('%s/%s->%s' % (cslug, lg, m.group(1)))
if mism:
    problems.append('%d slug mis-mappings (e.g. %s)' % (len(mism), '; '.join(mism[:3])))
print('ok' if not problems else 'FAIL: ' + ' | '.join(problems))
" 2>/dev/null)
if [ "$UNIFIED_RESULT" != "ok" ]; then
    echo "  FAIL bible-index.json invariants: $UNIFIED_RESULT"
    FAILURES=$((FAILURES + 1))
    FAILED_URLS+=("bible-index.json content invariants")
else
    echo "  ok  bible-index.json invariants (6 translations, 73 books, 1333 chapters, slug mapping)"
fi

# ── 8. AI access files ──────────────────────────────────────────────
section "AI access (llms.txt)"
check "$BASE_URL/llms.txt" 200 "llms.txt"
check "$BASE_URL/llms-full.txt" 200 "llms-full.txt"

# ── 9. Sitemaps ─────────────────────────────────────────────────────
section "Sitemaps"
check "$BASE_URL/sitemap-index.xml" 200 "sitemap-index.xml"
# Per-book sitemaps serve only for datasets with a real web home (web_bible_datasets):
# en/de/es/fr → 200. Homeless datasets (latin/italian — no /{lang}/bible/ yet) → 404.
check "$BASE_URL/bible-sitemap-bible-genesis.xml" 200 "bible-sitemap-bible-genesis.xml (en)"
check "$BASE_URL/bible-sitemap-bibel-genesis.xml" 200 "bible-sitemap-bibel-genesis.xml (de)"
check "$BASE_URL/bible-sitemap-spanish-genesis.xml" 200 "bible-sitemap-spanish-genesis.xml (es)"
check "$BASE_URL/bible-sitemap-french-genesis.xml" 200 "bible-sitemap-french-genesis.xml (fr)"
check "$BASE_URL/bible-sitemap-bible-josue.xml" 200 "bible-sitemap-bible-josue.xml"
check "$BASE_URL/bible-sitemap-bible-apocalypse.xml" 200 "bible-sitemap-bible-apocalypse.xml"
check "$BASE_URL/bible-sitemap-latin-genesis.xml" 404 "bible-sitemap-latin-genesis.xml (homeless → 404)"
check "$BASE_URL/bible-sitemap-italian-genesis.xml" 404 "bible-sitemap-italian-genesis.xml (homeless → 404)"

# ── 10. Cross-dataset name resolution ───────────────────────────────
section "Cross-dataset name resolution"
# English names on Latin pages
check "$BASE_URL/latin/genesis/" 200 "latin/genesis (shared name)"
check "$BASE_URL/latin/josue/" 200 "latin/josue (Vulgate name on latin)"
# German names on interlinear combo (may redirect to canonical form)
check_follow "$BASE_URL/latin-bibel/hiob/" "latin-bibel/hiob (German name)"
check_follow "$BASE_URL/latin-bibel/psalmen/" "latin-bibel/psalmen (German name)"
check_follow "$BASE_URL/latin-bibel/matthaeus/" "latin-bibel/matthaeus (German name)"

# ── 11. Abbreviation resolution ─────────────────────────────────────
section "Abbreviation / shorthand URLs (follow redirects)"
# Abbreviations resolve and redirect to canonical slug
check_follow "$BASE_URL/latin-bible/Gen/" "latin-bible/Gen (abbreviation)"
check_follow "$BASE_URL/latin-bible/Matt/" "latin-bible/Matt (abbreviation)"
check_follow "$BASE_URL/latin-bible/1Cor/" "latin-bible/1Cor (abbreviation)"
check_follow "$BASE_URL/latin-bible/Rev/" "latin-bible/Rev (abbreviation)"

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
