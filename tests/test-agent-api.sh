#!/usr/bin/env bash
# WHAT:   The agent-facing Bible surface: /bible-ref.json, /bible-search.json, and the
#         fields a verse/range response promises an AI agent.
# WHY:    An agent in a claude.ai chat (2026-09-16) could not reach a verse from a
#         citation, had no search, found "Job 20:12" cited beside "Iob 20:12-13" in
#         one response, got three cross-references where six translations exist, and
#         a range with no top-level text. Each of those is a line below.
# HOW:    curl + python3 -c over the JSON; every assertion names what it checks.
# INPUT:  BASE (default https://latinprayer.local; pass https://latinprayer.org for prod)
# OUTPUT: exit 0 when every check passes.
# RUN:    tests/test-agent-api.sh [BASE]
# TESTED BY: itself — it is the guard.

set -uo pipefail
BASE="${1:-${BASE:-https://latinprayer.local}}"
CURL=(curl -sk --max-time 30)
pass=0; fail=0

ok() { if [ "$1" = "1" ]; then pass=$((pass+1)); echo "  ok   $2"; else fail=$((fail+1)); echo "  FAIL $2"; fi; }
# jq-less JSON probe: prints the python expression's value for the fetched body.
probe() { "${CURL[@]}" "$1" | python3 -c "import json,sys; d=json.load(sys.stdin); print(($2))" 2>/dev/null; }
code() { "${CURL[@]}" -o /dev/null -w '%{http_code}' "$1"; }

echo "/bible-ref.json — the reference resolver:"
U="${BASE}/bible-ref.json?q=Gal+3:28"
ok "$([ "$(code "$U")" = "200" ] && echo 1 || echo 0)" "Gal 3:28 answers 200"
ok "$([ "$(probe "$U" "d['ref']['book']['key']")" = "galatians" ] && echo 1 || echo 0)" "resolves the abbreviation to the canonical key"
ok "$([ "$(probe "$U" "d['ref']['book']['osis']")" = "Gal" ] && echo 1 || echo 0)" "carries the OSIS id"
ok "$([ "$(probe "$U" "d['ref']['chapter'], d['ref']['verseFrom'], d['ref']['verseTo']")" = "(3, 28, 28)" ] && echo 1 || echo 0)" "chapter 3, verse 28"
ok "$([ "$(probe "$U" "sorted(d['passages'].keys())")" = "['en', 'la']" ] && echo 1 || echo 0)" "default text in la + en"
ok "$([ "$(probe "$U" "'Judæus' in d['passages']['la']['text']")" = "True" ] && echo 1 || echo 0)" "the Latin text is the verse"
ok "$([ "$(probe "$U" "d['urls']['html']['en']")" = "${BASE}/en/biblia/galatas/3:28/" ] && echo 1 || echo 0)" "html URL is the canonical /en/biblia/ page"
ok "$([ "$(probe "$U" "d['urls']['json']['la']")" = "${BASE}/latin/galatians/3/28.json" ] && echo 1 || echo 0)" "json URL is the Latin verse file"
ok "$([ "$(probe "$U" "len(d['urls']['json'])")" = "6" ] && echo 1 || echo 0)" "json URLs for all six languages"
ok "$([ "$(probe "$U" "d['ref']['citation']['la']")" = "Galatas 3:28" ] && echo 1 || echo 0)" "Latin citation form"

U="${BASE}/bible-ref.json?q=Ad+Galatas+3,28&lang=all"
ok "$([ "$(probe "$U" "d['ref']['book']['key'], len(d['passages'])")" = "('galatians', 6)" ] && echo 1 || echo 0)" "'Ad Galatas 3,28' + lang=all → six passages"

U="${BASE}/bible-ref.json?q=Job+20:12-13&lang=la"
ok "$([ "$(probe "$U" "d['ref']['verseFrom'], d['ref']['verseTo'], len(d['passages']['la']['verses'])")" = "(12, 13, 2)" ] && echo 1 || echo 0)" "a range returns both verses"
ok "$([ "$(probe "$U" "d['passages']['la']['citation']")" = "Iob 20:12-13 (Clementine Vulgate)" ] && echo 1 || echo 0)" "range citation uses the Latin name (Iob)"

U="${BASE}/bible-ref.json?q=Psalm+23&numbering=hebrew&lang=la"
ok "$([ "$(probe "$U" "d['ref']['chapter']")" = "22" ] && echo 1 || echo 0)" "Hebrew Psalm 23 → Vulgate 22"
ok "$([ "$(probe "$U" "'Dominus regit me' in d['passages']['la']['text']")" = "True" ] && echo 1 || echo 0)" "…which is the Good Shepherd psalm"
ok "$([ "$(probe "$U" "d['_meta']['psalmNumbering']['hebrew']")" = "23" ] && echo 1 || echo 0)" "psalmNumbering states the Hebrew number"
ok "$([ "$(probe "$U" "type(d['_meta']['psalmNumbering']['hebrew']).__name__")" = "int" ] && echo 1 || echo 0)" "…as an integer"
ok "$([ "$(probe "$U" "d['ref']['citation']['la'], d['ref']['citation']['en']")" = "('Psalmus 22', 'Psalm 22')" ] && echo 1 || echo 0)" "one psalm is cited in the singular"

U="${BASE}/bible-ref.json?q=Col+3:11&lang=la&typography=clean"
ok "$([ "$(probe "$U" "' :' in d['passages']['la']['text'] or ' ;' in d['passages']['la']['text']")" = "False" ] && echo 1 || echo 0)" "typography=clean removes the space before : ;"
U="${BASE}/bible-ref.json?q=Col+3:11&lang=la"
ok "$([ "$(probe "$U" "' :' in d['passages']['la']['text']")" = "True" ] && echo 1 || echo 0)" "…and the default keeps the source spacing"

ok "$([ "$(code "${BASE}/bible-ref.json?q=Gal+3:99")" = "404" ] && echo 1 || echo 0)" "a verse the chapter lacks is a 404, not a different verse"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Nowhere+1:1")" = "404" ] && echo 1 || echo 0)" "an unknown book is a 404"
ok "$([ "$(code "${BASE}/bible-ref.json")" = "400" ] && echo 1 || echo 0)" "no q is a 400"
echo "one-chapter books — cited by verse, as every convention writes them:"
for pair in "Jude+3|jude|1|3|3" "Jude+20-21|jude|1|20|21" "Philemon+6|philemon|1|6|6" "2+John+4|2-john|1|4|4" "Abdias+15|abdias|1|15|15"; do
  IFS='|' read -r q bk c f t <<< "$pair"
  ok "$([ "$(probe "${BASE}/bible-ref.json?q=${q}" "d['ref']['book']['key'], d['ref']['chapter'], d['ref']['verseFrom'], d['ref']['verseTo']")" = "('$bk', $c, $f, $t)" ] && echo 1 || echo 0)" "${q//+/ } is verse ${f}, not chapter ${f}"
done
ok "$([ -n "$(probe "${BASE}/bible-ref.json?q=Jude+3" "d['_meta']['readAs']")" ] && echo 1 || echo 0)" "…and the reading is stated, never silent"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Jude+1%3A3" "d['_meta']['readAs']")" = "None" ] && echo 1 || echo 0)" "an explicit chapter:verse is not reinterpreted"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Jude" "d['ref']['chapter']")" = "None" ] && echo 1 || echo 0)" "the bare name is still the whole book"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Genesis+1-3")" = "400" ] && echo 1 || echo 0)" "a bare range on a many-chapter book is refused as ambiguous, never guessed"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Job+20%3A12-13" "d['ref']['chapter'], d['ref']['verseFrom']")" = "(20, 12)" ] && echo 1 || echo 0)" "…and an ordinary chapter:verse range is untouched"

U="${BASE}/bible-ref.json?q=Galatians"
ok "$([ "$(probe "$U" "d['ref']['chapter'], d['urls']['json']['la']")" = "(None, '${BASE}/latin/galatians/index.json')" ] && echo 1 || echo 0)" "a book-only query returns the book's addresses"

echo "Malachias 4 — a chapter every printed Vulgate has and this text numbers 3:19-24:"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Mal+4%3A2" "d['ref']['chapter'], d['ref']['verseFrom']")" = "(3, 20)" ] && echo 1 || echo 0)" "Mal 4:2 is read as 3:20"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Mal+4%3A2&lang=la" "d['passages']['la']['text'][:18]")" = "Et orietur vobis t" ] && echo 1 || echo 0)" "…and carries the Sun of justice, not an empty string"
ok "$([ -n "$(probe "${BASE}/bible-ref.json?q=Mal+4%3A2" "d['_meta']['readAs']")" ] && echo 1 || echo 0)" "…and says it translated the citation"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Malachias+4" "d['ref']['verseFrom'], d['ref']['verseTo']")" = "(19, 24)" ] && echo 1 || echo 0)" "a bare chapter 4 is the whole Elijah prophecy"
ok "$([ "$(probe "${BASE}/latin/malachias/4/2.json" "d['text'][:18]")" = "Et orietur vobis t" ] && echo 1 || echo 0)" "the constructed JSON URL agrees (it 404'd before)"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Mal+4%3A9")" = "404" ] && echo 1 || echo 0)" "a verse past 4:6 exists in neither numbering, and is refused"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Gal+3%3A28" "d['_meta']['readAs']")" = "None" ] && echo 1 || echo 0)" "an ordinary citation is not 'translated'"

echo "/bible-search.json — verse search:"
U="${BASE}/bible-search.json?q=dilexerunt+tenebras&lang=la"
ok "$([ "$(code "$U")" = "200" ] && echo 1 || echo 0)" "answers 200"
ok "$([ "$(probe "$U" "d['_meta']['total'] >= 1 and d['hits'][0]['ref']")" = "Ioannes 3:19" ] && echo 1 || echo 0)" "finds John 3:19 for 'dilexerunt tenebras'"
ok "$([ "$(probe "$U" "d['hits'][0]['htmlUrl']")" = "${BASE}/en/biblia/ioannes/3:19/" ] && echo 1 || echo 0)" "a hit carries its canonical page"
U="${BASE}/bible-search.json?q=iudicium+tenebras&lang=la&book=john"
ok "$([ "$(probe "$U" "[(h['chapter'],h['verse']) for h in d['hits']]")" = "[(3, 19)]" ] && echo 1 || echo 0)" "j/i folded: 'iudicium' finds 'judicium' (and book= narrows)"
ok "$([ "$(probe "$U" "d['_meta']['bookName']")" = "Ioannes" ] && echo 1 || echo 0)" "the narrowed book is named in the searched language, not by slug"
U="${BASE}/bible-search.json?q=loved+darkness&lang=en"
ok "$([ "$(probe "$U" "any(h['book']['key']=='john' and h['chapter']==3 and h['verse']==19 for h in d['hits'])")" = "True" ] && echo 1 || echo 0)" "English search finds John 3:19 among the hits"
U="${BASE}/bible-search.json?q=Dominus&lang=la&limit=5"
ok "$([ "$(probe "$U" "len(d['hits']) == 5 and d['_meta']['truncated']")" = "True" ] && echo 1 || echo 0)" "limit is honoured and truncation stated"
ok "$([ "$(code "${BASE}/bible-search.json?q=zzzz&lang=la")" = "200" ] && echo 1 || echo 0)" "no hits is still a 200"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Cristo&lang=es" "d['_meta']['total']")" = "0" ] && echo 1 || echo 0)" "the Scio Spanish has no 'Cristo' — it is an 18th-century text"
ok "$([ -n "$(probe "${BASE}/bible-search.json?q=Cristo&lang=es" "d['_meta']['noHitsBecause'] or ''")" ] && echo 1 || echo 0)" "…and the empty answer says why, instead of reading as 'this Bible has no Christ'"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Christo&lang=es" "d['_meta']['noHitsBecause']")" = "None" ] && echo 1 || echo 0)" "an answer that found verses carries no such hint"

echo "a request the site cannot honour is refused, not quietly answered otherwise:"
ok "$([ "$(code "${BASE}/bible-search.json?q=Deus&lang=klingon")" = "400" ] && echo 1 || echo 0)" "an unknown language is a 400 (it used to answer in Latin)"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Gal+3%3A28&lang=klingon")" = "400" ] && echo 1 || echo 0)" "…on the resolver too"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&lang=en,klingon" "d['_meta']['translation']['language']")" = "en" ] && echo 1 || echo 0)" "a MIXED list still names English, so it is served"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&lang=la&limit=abc" "d['_meta']['limit']")" = "20" ] && echo 1 || echo 0)" "a malformed limit means the default, not one hit"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&lang=la&limit=-5" "d['_meta']['limit']")" = "20" ] && echo 1 || echo 0)" "…and so does a negative one (absint made -5 mean 5)"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&lang=la&limit=101" "d['_meta']['limit']")" = "100" ] && echo 1 || echo 0)" "over the ceiling still clamps to the ceiling"

echo "verse / range JSON — the promised fields:"
U="${BASE}/latin/job/20/12-13.json"
ok "$([ "$(probe "$U" "d['citation'], d['verses'][0]['citation']")" = "('Iob 20:12-13 (Clementine Vulgate)', 'Iob 20:12 (Clementine Vulgate)')" ] && echo 1 || echo 0)" "one book name per response (Iob everywhere, no Job)"
ok "$([ "$(probe "$U" "'text' in d and d['text'].startswith('Cum enim dulce')")" = "True" ] && echo 1 || echo 0)" "a range carries top-level text"
ok "$([ "$(probe "$U" "d['htmlUrl']")" = "${BASE}/en/biblia/iob/20:12-13/" ] && echo 1 || echo 0)" "a range carries its canonical htmlUrl"
ok "$([ "$(probe "$U" "len([k for k in d['_meta']['crossReferences'] if not k.endswith('HtmlUrl')])")" = "5" ] && echo 1 || echo 0)" "cross-references to all five other translations"
ok "$([ "$(probe "$U" "d['_meta']['book']['names']['en'], d['_meta']['book']['osis']")" = "('Job', 'Job')" ] && echo 1 || echo 0)" "book names in six languages + OSIS"
U="${BASE}/latin/psalms/22/1.json"
ok "$([ "$(probe "$U" "d['_meta']['psalmNumbering']['hebrew']")" = "23" ] && echo 1 || echo 0)" "a psalm verse states its Hebrew number"
ok "$([ "$(probe "$U" "d['citation']")" = "Psalmus 22:1 (Clementine Vulgate)" ] && echo 1 || echo 0)" "a psalm verse cites 'Psalmus', singular"
U="${BASE}/latin/psalms/23.json?numbering=hebrew"
ok "$([ "$(probe "$U" "d['_meta']['chapter'], d['_meta']['psalmNumbering']['vulgate'], d['_meta']['psalmNumbering']['requested']['number']")" = "(22, 22, 23)" ] && echo 1 || echo 0)" "numbering=hebrew on a chapter URL serves Vulgate 22 and says so"
U="${BASE}/latin/psalms/23/1.json?numbering=hebrew"
ok "$([ "$(probe "$U" "'Dominus regit me' in d['text']")" = "True" ] && echo 1 || echo 0)" "numbering=hebrew on a verse URL too"
ok "$([ "$(code "${BASE}/latin/psalms/23.json?numbering=masoretic")" = "400" ] && echo 1 || echo 0)" "an unknown numbering value is a 400, never a silent different psalm"
U="${BASE}/latin/galatians/3/28.json"
ok "$([ "$("${CURL[@]}" "$U" | grep -c 'dwbible_v')" = "0" ] && echo 1 || echo 0)" "no legacy ?dwbible_vfrom URLs anywhere in a verse response"
ok "$([ "$(probe "$U" "d['_meta']['crossReferences']['bibelHtmlUrl']")" = "${BASE}/de/biblia/galatas/3:28/" ] && echo 1 || echo 0)" "cross-reference HTML URLs are the canonical pages"
L=$("${CURL[@]}" -L -o /dev/null -w '%{url_effective}' "${BASE}/bible/galatians/3/?dwbible_vfrom=28")
ok "$([ "$L" = "${BASE}/en/biblia/galatas/3:28/" ] && echo 1 || echo 0)" "a legacy ?dwbible_vfrom link ends on the canonical :verse page with the query dropped"
ok "$([ "$("${CURL[@]}" -sIL "${BASE}/bible/galatians/3/?dwbible_vfrom=28" | grep -ic 'location:.*dwbible_v')" = "0" ] && echo 1 || echo 0)" "…and no hop of that chain carries the parameters"
U="${BASE}/latin/galatians/3/28.json?typography=clean"
ok "$([ "$(probe "$U" "' :' in d['text']")" = "False" ] && echo 1 || echo 0)" "typography=clean on a verse URL"

echo "HTML head — discovery from a page an agent may fetch:"
# grep -c, not grep -q: under `pipefail` a -q that exits on the first match
# SIGPIPEs the printf and the pipeline reads as failed.
H=$("${CURL[@]}" -L "${BASE}/en/bible/galatians/3:28/")
has() { [ "$(printf '%s' "$H" | grep -c "$1")" -gt 0 ] && echo 1 || echo 0; }
ok "$(has 'rel="alternate" type="application/json" href="'"${BASE}"'/bible/galatians/3/28.json"')" "verse page links its own verse JSON"
ok "$(has 'rel="help" type="text/plain" href="'"${BASE}"'/llms.txt"')" "verse page links llms.txt"
ok "$(has 'og:description" content="There is neither Jew nor Greek')" "og:description is the verse (in the page language), not the tagline"

echo
echo "passed ${pass}, failed ${fail}"
[ "$fail" -eq 0 ]
