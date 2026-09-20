#!/usr/bin/env bash
# test-query-cache.sh — a typed Bible search must never become the Bible index.
#
# WHAT:  Asks for `/{lang}/biblia/?q=<something>` and then for the plain index,
#        and asserts the index came back as the index. dwcache builds its key
#        from the URL with unknown query params STRIPPED, so a parameter that
#        changes the response has to be added to its allow-list — otherwise the
#        two URLs share one entry and break in both directions: the lookup is
#        answered with the cached index, and the lookup's answer is stored AS
#        the index for every visitor. That shipped for a few minutes on
#        2026-08-28; this is the guard.
#
# INPUT: BASE_URL argv[1] — default https://latinprayer.org. Run it against
#        PRODUCTION: the bug only exists where a page cache is on (dwcache is
#        off outside wp_get_environment_type() === 'production').
# OUTPUT: one ok/FAIL line per case; exit 1 on any failure.
#
# USAGE: ./tests/test-query-cache.sh [BASE_URL]
#
# DEPENDS ON: curl (-k, so the local site's self-signed cert is no obstacle).
# TESTED BY: itself — poison first, then read the index back.

set -uo pipefail

BASE="${1:-https://latinprayer.org}"
BASE="${BASE%/}"
MARKER="zzqqzz$$"
FAIL=0

check() { # name, condition-already-evaluated
  if [ "$2" = "0" ]; then echo "ok   $1"; else echo "FAIL $1${3:+   $3}"; FAIL=$((FAIL + 1)); fi
}

for lang in en de; do
  idx="$BASE/$lang/biblia/"

  # 1. A query the resolver cannot place renders the index with the filter
  #    holding it — that response is the one that must not be kept.
  #    Matched with `case`, never `echo "$body" | grep`: grep -q exits at the
  #    first match, echo takes SIGPIPE for the rest of a 74 KB page, and under
  #    `set -o pipefail` that reads as a FAILING check — a race that fails a
  #    passing site about half the time.
  q_body=$(curl -sk "$idx?q=$MARKER")
  case "$q_body" in *"value=\"$MARKER\""*) r=0 ;; *) r=1 ;; esac
  check "$lang: an unresolvable query renders with the filter holding it" $r ""

  # 2. …and the plain index, fetched right after, is still the plain index.
  idx_body=$(curl -sk "$idx")
  case "$idx_body" in *"value=\"$MARKER\""*) r=1 ;; *) r=0 ;; esac
  check "$lang: the plain index did NOT inherit that query" $r "the query response was cached as the index"

  # 3. A resolvable query redirects — and the index still does not.
  loc=$(curl -sk -o /dev/null -w '%{url_effective}' -L "$idx?q=Matthew+5:41")
  case "$loc" in *matthaeus/5:41*) r=0 ;; *) r=1 ;; esac
  check "$lang: a citation resolves to the verse" $r "$loc"

  loc2=$(curl -sk -o /dev/null -w '%{url_effective}' -L "$idx")
  [ "${loc2%/}" = "${idx%/}" ]
  check "$lang: the plain index still lands on itself" $? "$loc2"

  # 4. The query response must not be storable by anything downstream either.
  cc=$(curl -skI "$idx?q=$MARKER" | tr -d '\r' | awk -F': ' 'tolower($1)=="cache-control"{print tolower($2)}')
  case "$cc" in *no-store*) r=0 ;; *) r=1 ;; esac
  check "$lang: the query response says no-store" $r "cache-control: ${cc:-<none>}"
done

# 5. A verse page already cached as plain HTML (two reads — dwcache's normal
#    state for anything popular) must not shadow its own ?format=json twin:
#    'format' was added to the key for the .json dataset routes (2026-09-16)
#    but never for the HTML verse page it redirects FROM, so a read page
#    replayed its own cached HTML to ?format=json for the rest of the TTL —
#    live on both dwcache (origin) and Cloudflare (edge, caching the origin's
#    own wrong 200) — quality loop tick 318.
verse="$BASE/en/biblia/ioannes/3:16/"
curl -sk -o /dev/null "$verse"
curl -sk -o /dev/null "$verse"
loc3=$(curl -sk -o /dev/null -w '%{url_effective}' -L "$verse?format=JSON")
case "$loc3" in *ioannes/3/16.json*) r=0 ;; *) r=1 ;; esac
check "a verse page read twice still 301s ?format=JSON to its dataset JSON" $r "$loc3"

if [ "$FAIL" -eq 0 ]; then echo "all pass"; else echo "$FAIL FAILURES"; fi
exit $((FAIL > 0))
