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

# ── curl, once ───────────────────────────────────────────────────────
#
# Every request goes through here, for two reasons that each cost a whole
# run when they were not handled:
#
#  1. `set -e` + `code=$(curl …)` means ANY curl failure kills the script
#     mid-suite. On 2026-08-31 that looked exactly like "the selftest is
#     broken": the run printed its first section header and stopped, with
#     no failure line, because curl had exited 60 — a certificate error —
#     and the shell aborted before anything could be reported. A transport
#     failure is a RESULT ("000"), not a reason to stop testing routes.
#  2. Local by Flywheel serves a self-signed certificate, so a run against
#     latinprayer.local could never get past its first request. The suite
#     is meant to be runnable before a deploy, not only after one.
#
# --insecure is added ONLY for a *.local / localhost host, so it can never
# weaken a run against the real site.
CURL_OPTS=(-s --max-time 30)
case "$BASE_URL" in
    *.local|*.local/*|https://localhost*|http://localhost*|*127.0.0.1*)
        CURL_OPTS+=(--insecure)
        echo "  (self-signed host — certificate verification off)"
        ;;
esac

# Never fatal: prints curl's output, or nothing, and always succeeds.
fetch() {
    curl "${CURL_OPTS[@]}" "$@" 2>/dev/null || true
}

# ── Helpers ──────────────────────────────────────────────────────────

# Expect exact HTTP status (no redirect following)
check() {
    local url="$1"
    local expected="$2"
    local label="$3"
    TOTAL=$((TOTAL + 1))
    local code
    code=$(fetch -o /dev/null -w "%{http_code}" "$url")
    code="${code:-000}"
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
    code=$(fetch -o /dev/null -L -w "%{http_code}" "$url")
    code="${code:-000}"
    if [ "$code" != "200" ]; then
        echo "  FAIL [final $code != 200] $label"
        FAILURES=$((FAILURES + 1))
        FAILED_URLS+=("$url (final $code != 200)")
    fi
}

# Follow redirects and assert BOTH the final status and WHERE it landed.
#
# This is the helper the old-URL sections need, and it is deliberately
# stricter than the `check … 200` it replaces. Those assertions only ever
# proved "something answered 200 in the end" — which a redirect pointing at
# the WRONG book satisfies perfectly. Since every one of these addresses now
# travels through a redirect, the destination is the only part still worth
# asserting, so it is named explicitly per route.
check_canonical() {
    local url="$1"
    local expected_path="$2"
    local label="$3"
    local expected_code="${4:-200}"
    TOTAL=$((TOTAL + 1))
    local result code final
    result=$(fetch -o /dev/null -L -w "%{http_code} %{url_effective}" "$url")
    result="${result:-000 }"
    code="${result%% *}"
    final="${result#* }"
    if [ "$code" != "$expected_code" ]; then
        echo "  FAIL [final $code != $expected_code] $label"
        FAILURES=$((FAILURES + 1))
        FAILED_URLS+=("$url (final $code != $expected_code)")
    elif [ -n "$expected_path" ] && [[ "$final" != *"$expected_path" ]]; then
        echo "  FAIL [landed on '$final', expected *$expected_path] $label"
        FAILURES=$((FAILURES + 1))
        FAILED_URLS+=("$url (landed on $final, expected *$expected_path)")
    fi
}

# Expect a 301 redirect to a URL containing the expected substring
check_redirect() {
    local url="$1"
    local expected_target="$2"
    local label="$3"
    TOTAL=$((TOTAL + 1))
    local header
    header=$(fetch -o /dev/null -w "%{http_code} %{redirect_url}" "$url")
    header="${header:-000 }"
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
# The Latin book slug each of the above lands on, SAME ORDER as BOOKS and
# GERMAN_BOOKS. Written out rather than derived, deliberately: deriving them
# from the plugin would test it against itself and pass no matter what it
# said. These are the addresses as they exist in the world, so a change to
# latin_slug_for_key() has to come and break this list on purpose.
#
# Verified against the live site on 2026-08-31 — all 73 English and all 73
# German forms land on exactly these, with zero mismatches.
LATIN_BOOKS=(
    genesis exodus leviticus numeri deuteronomium
    iosue iudices ruth 1-samuelis 2-samuelis
    3-regum 4-regum 1-paralipomenon 2-paralipomenon 1-esdrae
    2-esdrae-nehemias tobias iudith esther iob
    psalmi proverbia ecclesiastes canticum-canticorum sapientia
    ecclesiasticus isaias ieremias lamentationes baruch
    ezechiel daniel osee ioel amos
    abdias ionas michaeas nahum habacuc
    sophonias aggaeus zacharias malachias 1-machabaeorum
    2-machabaeorum matthaeus marcus lucas ioannes
    actus-apostolorum romanos 1-corinthios 2-corinthios galatas
    ephesios philippenses colossenses 1-thessalonicenses 2-thessalonicenses
    1-timotheum 2-timotheum titum philemonem hebraeos
    iacobi 1-petri 2-petri 1-ioannis 2-ioannis
    3-ioannis iudae apocalypsis
)


# ── 1. Selftest endpoint ────────────────────────────────────────────
# Checks that the 4 data-consistency selftest checks pass.
#
# Only those four are read, deliberately: this suite is about ROUTES, and a
# failure in an unrelated check belongs to whoever owns that check, not to a
# run that was asked whether the Bible answers. The endpoint answers 500
# whenever any check is red, so its status code is not usable here — the JSON
# is.
section "Selftest (data consistency)"
TOTAL=$((TOTAL + 1))
# NOT "$BASE_URL/bible/?…" — that address 301s now, fetch does not follow,
# and the empty body made all four checks report MISSING. The selftest is not
# a Bible route at all; the site root answers it.
SELFTEST_JSON=$(fetch "$BASE_URL/?dwbible_selftest=1")
SELFTEST_OK=true
for check_name in osis_dataset_consistency interlinear_osis_resolution book_map_consistency all_books_resolve_in_combos; do
    # A 500 still carries the JSON body, but a fatal error would not — and
    # json.load then throws. Under `set -euo pipefail` that non-zero pipe would
    # abort the whole script before the later sections run, so tolerate it with
    # `|| echo MISSING` and let the per-check logic report it as a failure.
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
section "All 73 books on /latin-bible/ → /en/biblia/"
for i in "${!BOOKS[@]}"; do
    check_canonical "$BASE_URL/latin-bible/${BOOKS[$i]}/" "/en/biblia/${LATIN_BOOKS[$i]}/" "latin-bible/${BOOKS[$i]}"
done

# ── 3. All 73 German books on /latin-bibel/ ─────────────────────────
# Some German slugs redirect to canonical (Vulgate) form — that's OK.
# We follow redirects and just verify the final page loads (200).
section "All 73 German books on /latin-bibel/ → /de/biblia/"
for i in "${!GERMAN_BOOKS[@]}"; do
    check_canonical "$BASE_URL/latin-bibel/${GERMAN_BOOKS[$i]}/" "/de/biblia/${LATIN_BOOKS[$i]}/" "latin-bibel/${GERMAN_BOOKS[$i]}"
done

# ── 4. Sample books on /latin/ (single-language, no redirect) ───────
section "Sample books on /latin/ (the Vulgate alone)"
# /latin/ is a DATASET url and STAYS PUT — it is the one surface showing the
# Vulgate without a vernacular column. The BOOK slug is still canonicalised to
# its Latin form (josue -> iosue), so the page is reached at /latin/{latin}/.
# Until 2026-08-31 every one of these 302'd to /en/biblia/, which meant the
# pure Vulgate could not be read anywhere.
check_canonical "$BASE_URL/latin/genesis/"    "/latin/genesis/"     "latin/genesis"
check_canonical "$BASE_URL/latin/josue/"      "/latin/iosue/"       "latin/josue"
check_canonical "$BASE_URL/latin/psalms/"     "/latin/psalmi/"      "latin/psalms"
check_canonical "$BASE_URL/latin/isaias/"     "/latin/isaias/"      "latin/isaias"
check_canonical "$BASE_URL/latin/matthew/"    "/latin/matthaeus/"   "latin/matthew"
check_canonical "$BASE_URL/latin/apocalypse/" "/latin/apocalypsis/" "latin/apocalypse"

# ── 5. Redirects: /bible/ → /latin-bible/ ───────────────────────────
section "Single-language → interlinear redirects"
# The dataset slug picks the LANGUAGE PREFIX and the book turns Latin:
# /bibel/hiob/ → /de/biblia/iob/. Both halves are asserted, because a redirect
# that got the language right and the book wrong would still answer 200.
check_canonical "$BASE_URL/bible/"         "/en/biblia/"         "bible/ → en"
check_canonical "$BASE_URL/bibel/"         "/de/biblia/"         "bibel/ → de"
check_canonical "$BASE_URL/bible/genesis/" "/en/biblia/genesis/" "bible/genesis/"
check_canonical "$BASE_URL/bible/josue/"   "/en/biblia/iosue/"   "bible/josue/ (Vulgate name)"
check_canonical "$BASE_URL/bibel/hiob/"    "/de/biblia/iob/"     "bibel/hiob/ (German name)"

# ── 6. Chapter and verse pages ──────────────────────────────────────
section "Chapter and verse pages"
# The chapter and the verse range survive the redirect intact — that is the
# part worth asserting, since a redirect that keeps the book but drops "8:28-30"
# still lands on a 200 page.
check_canonical "$BASE_URL/latin-bible/genesis/1"      "/en/biblia/genesis/1/"       "latin-bible/genesis/1"
check_canonical "$BASE_URL/latin-bible/john/3:16"      "/en/biblia/ioannes/3:16/"    "latin-bible/john/3:16"
check_canonical "$BASE_URL/latin-bible/romans/8:28-30" "/en/biblia/romanos/8:28-30/" "latin-bible/romans/8:28-30"
check_canonical "$BASE_URL/latin-bible/psalms/23"      "/en/biblia/psalmi/23/"       "latin-bible/psalms/23"
check_canonical "$BASE_URL/latin-bibel/psalmen/23"     "/de/biblia/psalmi/23/"       "latin-bibel/psalmen/23"

# ── 7. JSON API ─────────────────────────────────────────────────────
section "JSON API"
check "$BASE_URL/bible/index.json" 200 "bible/index.json"
check "$BASE_URL/bibel/index.json" 200 "bibel/index.json"
check "$BASE_URL/latin/index.json" 200 "latin/index.json"
check "$BASE_URL/bible/genesis/index.json" 200 "bible/genesis/index.json"
check "$BASE_URL/bible/genesis/1.json" 200 "bible/genesis/1.json"
check "$BASE_URL/bible/john/3:16.json" 200 "bible/john/3:16.json"
check "$BASE_URL/bible-index.json" 200 "bible-index.json (unified)"

# ── 7b. AI URL-guessability: append .json to ANY citation URL ───────
# A cold AI agent that lands on a Bible page and appends ".json" — or guesses
# a /{lang}/… form — must reach the dataset JSON, never a dead HTML 404. Every
# guessable form 301s to /{dataset}/{rest}.json; the direct dataset slug serves
# it. (dwbible-i18n normalize; requires dwi18n active.)
section "AI URL-guessability (.json on any citation form)"
# Canonical page form: /{lang}/biblia/{book}/{ch}.json (Latin book slug, as pages use)
check_redirect "$BASE_URL/de/biblia/ephesios/6.json" "/bibel/ephesios/6.json" "de/biblia/…json → bibel dataset"
check_follow   "$BASE_URL/de/biblia/ephesios/6.json" "de/biblia/…json final 200"
check_redirect "$BASE_URL/en/biblia/ephesians/6.json" "/bible/ephesians/6.json" "en/biblia/…json → bible dataset"
# Older /{lang}/bible/ alias form
check_redirect "$BASE_URL/de/bible/ephesians/6.json" "/bibel/ephesians/6.json" "de/bible/…json → bibel dataset"
# Legacy interlinear combo + .json (chapter, colon-verse, slash-verse)
check_redirect "$BASE_URL/latin-bibel/ephesians/6.json" "/bibel/ephesians/6.json" "latin-bibel/…json → bibel"
check_redirect "$BASE_URL/latin-bibel/ephesians/6:11.json" "/bibel/ephesians/6:11.json" "latin-bibel colon-verse json"
check_redirect "$BASE_URL/latin-bibel/ephesians/6/11.json" "/bibel/ephesians/6/11.json" "latin-bibel slash-verse json"
# Translation-name / native-word aliases + .json
check_redirect "$BASE_URL/menge/ephesians/6.json" "/bibel/ephesians/6.json" "menge/…json → bibel"
check_redirect "$BASE_URL/bibbia/ephesians/6.json" "/italian/ephesians/6.json" "bibbia/…json → italian"
# Direct dataset slug serves without a redirect (no loop) — final 200, no 301
check "$BASE_URL/bibel/ephesios/6.json" 200 "bibel/…json served directly (no redirect)"
# A book name with ".json" appended but no chapter/verse (an agent citing just
# "John" and guessing the machine form) is not a rewrite rule dwbible defines
# (only {book}/index.json, {book}/{ch}.json, … are) — it must 404 cleanly, not
# ping-pong forever between dwbible's own .json normalizer (which thinks
# /bible/{x}.json is already canonical) and dwi18n's unprefixed-content
# redirect (which thought the unresolved book was real content to relocate).
check_canonical "$BASE_URL/bible/john.json" "" "bible/{book}.json (no chapter) — clean 404, no redirect loop" 404
check_canonical "$BASE_URL/bible/nosuchbook.json" "" "bible/{invalid-book}.json — clean 404, no redirect loop" 404

# Content invariants of the unified index (not just HTTP 200):
#   - all 6 translations advertised (la/en/de/fr/es/it)
#   - 73 books, canonical chapter total (1333, Clementine division)
#   - every per-language jsonUrl slug maps to that book's canonicalSlug — guards
#     the order-vs-slug merge bug where a dataset with a different book order
#     (e.g. Italian, offset +2 from job onward) mis-mapped a book (Italian's
#     Daniel had landed under canonical Joel).
section "Unified index content invariants"
TOTAL=$((TOTAL + 1))
UNIFIED_JSON=$(fetch "$BASE_URL/bible-index.json")
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
# The book segment of a real URL is the LATIN slug in every language, so a
# book's 'slug' must be identical across its translations and must appear in
# both urls it publishes. Until 2026-08-31 this asserted the segment equalled
# canonicalSlug ('john'), which was the pre-/biblia/ contract.
mism = []
differs = 0
for b in books:
    cslug = b.get('canonicalSlug', '')
    slugs = set()
    for lg in want_langs:
        t = b.get('translations', {}).get(lg)
        if not t:
            mism.append('%s/%s:missing' % (cslug, lg)); continue
        sl = t.get('slug', '')
        slugs.add(sl)
        for field in ('url', 'jsonUrl'):
            if '/%s/' % sl not in t.get(field, ''):
                mism.append('%s/%s:%s lacks /%s/' % (cslug, lg, field, sl))
    if len(slugs) > 1:
        mism.append('%s: slug differs across translations (%s)' % (cslug, ','.join(sorted(slugs))))
    if slugs and cslug not in slugs:
        differs += 1
if mism:
    problems.append('%d slug mis-mappings (e.g. %s)' % (len(mism), '; '.join(mism[:3])))
# Proof it is the Latin slug and not the canonical key: John must be 'ioannes'.
# (No backticks anywhere in this program: it is passed to python3 -c inside a
# DOUBLE-quoted shell string, so a backticked word is run as a command and its
# empty output is spliced into the source — four of them were, silently.)
john = next((b for b in books if b.get('canonicalSlug') == 'john'), None)
if not john or john.get('translations', {}).get('bible', {}).get('slug') != 'ioannes':
    problems.append('John does not publish the Latin slug ioannes')
if differs < 10:
    problems.append('only %d books differ from canonicalSlug — slug looks like the key, not the URL' % differs)
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
# Per-book sitemaps serve only for datasets with a real web home
# (web_bible_datasets). That is now **five** languages — en/de/es/fr/**it** —
# and Latin alone is homeless: /it/biblia/genesis/ answers 200 while
# /la/biblia/genesis/ answers 404, because every web page is Latin PLUS a
# vernacular and there is no Latin-only reading surface to point a sitemap at.
#
# Italian was listed as homeless here until 2026-08-31. It had gained its home
# some time before that and nothing noticed, because this suite could not run.
check "$BASE_URL/bible-sitemap-bible-genesis.xml" 200 "bible-sitemap-bible-genesis.xml (en)"
check "$BASE_URL/bible-sitemap-bibel-genesis.xml" 200 "bible-sitemap-bibel-genesis.xml (de)"
check "$BASE_URL/bible-sitemap-spanish-genesis.xml" 200 "bible-sitemap-spanish-genesis.xml (es)"
check "$BASE_URL/bible-sitemap-french-genesis.xml" 200 "bible-sitemap-french-genesis.xml (fr)"
check "$BASE_URL/bible-sitemap-bible-josue.xml" 200 "bible-sitemap-bible-josue.xml"
check "$BASE_URL/bible-sitemap-bible-apocalypse.xml" 200 "bible-sitemap-bible-apocalypse.xml"
check "$BASE_URL/bible-sitemap-latin-genesis.xml" 404 "bible-sitemap-latin-genesis.xml (homeless → 404)"
check "$BASE_URL/bible-sitemap-italian-genesis.xml" 200 "bible-sitemap-italian-genesis.xml (it)"
# …and it must list ITALIAN urls. A 200 alone would pass on a sitemap full of
# some other language's pages, which is the failure worth catching here.
TOTAL=$((TOTAL + 1))
if ! fetch "$BASE_URL/bible-sitemap-italian-genesis.xml" | grep -q "/it/biblia/genesis/"; then
    echo "  FAIL italian sitemap does not list /it/biblia/genesis/ urls"
    FAILURES=$((FAILURES + 1))
    FAILED_URLS+=("$BASE_URL/bible-sitemap-italian-genesis.xml (no /it/biblia/ urls)")
fi

# ── 9a. The Vulgate alone, unlisted ─────────────────────────────────
#
# /latin/{book}/ is a DATASET url, not a language-less one — the only surface
# that shows the Vulgate without a vernacular column. It used to 302 to the
# negotiated /{lang}/biblia/, which meant the pure Vulgate could not be read
# at all. Deliberately UNLISTED: noindex, no sitemap, and not the url any
# index advertises, because the Latin text is already on all five locale
# pages and an indexed Latin-only page would compete with them.
section "The Vulgate alone at /latin/ (unlisted)"
check "$BASE_URL/latin/"                200 "latin/ book list"
check "$BASE_URL/latin/genesis/"        200 "latin/genesis (no redirect)"
check "$BASE_URL/latin/genesis/1"       200 "latin/genesis/1"
check "$BASE_URL/latin/ioannes/3:16"    200 "latin/ioannes/3:16"
check "$BASE_URL/latin/genesis/index.json" 200 "latin json still served"
# It must carry noindex — this is the whole reason it is safe to serve.
# Match BOTH quote styles: WordPress core writes this tag itself, as
# <meta name='robots' …> in SINGLE quotes, so a pattern demanding double quotes
# can never match and reports a healthy page as broken (it did, 2026-09-20 —
# filed as dwbible#24 before the tag was read with the quotes it actually has).
TOTAL=$((TOTAL + 1))
if ! fetch "$BASE_URL/latin/genesis/1" | grep -qiE "name=['\"]robots['\"][^>]*noindex"; then
    echo "  FAIL /latin/genesis/1 is missing its noindex"
    FAILURES=$((FAILURES + 1))
    FAILED_URLS+=("$BASE_URL/latin/genesis/1 (no noindex)")
fi
# …and it must stay out of the sitemaps.
check "$BASE_URL/bible-sitemap-latin-genesis.xml" 404 "latin sitemap stays 404"
# The vernacular routes must be UNAFFECTED — they are still canonicalised away.
check_canonical "$BASE_URL/bible/genesis/" "/en/biblia/genesis/" "bible/genesis still canonicalises"
check_canonical "$BASE_URL/bibel/hiob/"    "/de/biblia/iob/"     "bibel/hiob still canonicalises"

# ── 9b. The unified index publishes addresses that RESOLVE ──────────
#
# bible-index.json is what an agent reads INSTEAD of crawling, so a stale URL
# in it is worse than a stale link on a page: nothing will notice. Until
# 2026-08-31 every `url` in it was the pre-/biblia/ shape and 301'd, `slug` was
# the canonical key (`john`) rather than the real Latin slug (`ioannes`), and
# `jsonUrl` had the production host baked in while `url` followed the request.
#
# A sample is checked rather than all 876, because this runs against prod.
section "Unified index publishes live URLs"
INDEX_JSON=$(fetch "$BASE_URL/bible-index.json")
INDEX_URLS=$(echo "$INDEX_JSON" | python3 -c "
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    sys.exit(0)
out = []
for b in d.get('books', [])[:4]:
    for ds, t in b.get('translations', {}).items():
        out.append(t.get('url', ''))
        out.append(t.get('jsonUrl', ''))
print('\n'.join(u for u in out if u))
" 2>/dev/null || echo "")
if [ -z "$INDEX_URLS" ]; then
    TOTAL=$((TOTAL + 1))
    echo "  FAIL bible-index.json unreadable or published no urls"
    FAILURES=$((FAILURES + 1))
    FAILED_URLS+=("$BASE_URL/bible-index.json (no urls)")
else
    while IFS= read -r iu; do
        [ -z "$iu" ] && continue
        # DIRECT 200 — a redirect here means the index is a hop behind the router.
        check "$iu" 200 "index url $iu"
    done <<< "$INDEX_URLS"
fi
# ── 10. Cross-dataset name resolution ───────────────────────────────
section "Cross-dataset name resolution"
# English names on Latin pages
check_canonical "$BASE_URL/latin/genesis/" "/latin/genesis/" "latin/genesis (shared name)"
check_canonical "$BASE_URL/latin/josue/"   "/latin/iosue/"   "latin/josue (Vulgate name on latin)"
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
# A 3-way combo KEEPS its own path — unlike /latin-bible/, which is swapped
# for a language prefix. Only the book slug turns Latin. Worth pinning: the two
# families look alike and behave differently.
check_canonical "$BASE_URL/bible-bibel-latin/genesis/1" "/bible-bibel-latin/genesis/1" "bible-bibel-latin/genesis/1"
check_canonical "$BASE_URL/bible-bibel-latin/psalms/23" "/bible-bibel-latin/psalmi/23" "bible-bibel-latin/psalms/23"
check_canonical "$BASE_URL/bible-bibel-latin/john/1"    "/bible-bibel-latin/ioannes/1" "bible-bibel-latin/john/1"

# ── 13. Expected 404s (should NOT resolve) ──────────────────────────
section "Expected 404s"
# A nonsense book is redirected to the canonical shape BEFORE it is refused,
# so the 404 is at the end of the chain, not at the start. Asserting the raw
# status here is what made these two look broken.
check_canonical "$BASE_URL/latin-bible/not-a-book/" "/en/biblia/not-a-book/" "not-a-book → 404" 404
check_canonical "$BASE_URL/latin-bible/foobar/"     "/en/biblia/foobar/"     "foobar → 404"     404

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
