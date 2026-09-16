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

echo "a range past the end of a chapter is clamped to what exists, and says so:"
U="${BASE}/latin/john/3/35-40.json"
ok "$([ "$(probe "$U" "d['_meta']['verseRange']")" = "[35, 36]" ] && echo 1 || echo 0)" "verseRange is what the chapter has, not what was asked (John 3 ends at 36)"
ok "$([ "$(probe "$U" "d['citation']")" = "Ioannes 3:35-36 (Clementine Vulgate)" ] && echo 1 || echo 0)" "…and the citation names the verses actually carried"
ok "$([ "$(probe "$U" "[v['verse'] for v in d['verses']]")" = "[35, 36]" ] && echo 1 || echo 0)" "…matching the verses returned"
ok "$([ -n "$(probe "$U" "d['_meta'].get('readAs') or ''")" ] && echo 1 || echo 0)" "…and it says it clamped"
ok "$([ -n "$(probe "${BASE}/bible-ref.json?q=John+3%3A35-40" "d['_meta'].get('readAs') or ''")" ] && echo 1 || echo 0)" "the resolver says so too (it clamped in silence before)"
ok "$([ "$(code "${BASE}/bible-ref.json?q=John+3%3A40-45")" = "404" ] && echo 1 || echo 0)" "a range starting past the end is still refused outright"

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

echo "/bible-books.json — the two name registers must be joinable, not confusable:"
ok "$([ "$(probe "${BASE}/bible-books.json" "len(d['keys']), len(d['slugs']), len(d['names'])")" = "(73, 73, 73)" ] && echo 1 || echo 0)" "keys[] rides beside slugs[] and names[], all 73"
ok "$([ "$(probe "${BASE}/bible-books.json" "d['keys'][d['slugs'].index('iosue')]")" = "josue" ] && echo 1 || echo 0)" "the Latin slug 'iosue' names the canonical key 'josue'"
ok "$([ "$(probe "${BASE}/bible-books.json" "d['keys'][d['slugs'].index('matthaeus')]")" = "matthew" ] && echo 1 || echo 0)" "…and 'matthaeus' names 'matthew'"
ok "$([ "$(probe "${BASE}/bible-books.json" "sum(1 for k,s in zip(d['keys'],d['slugs']) if k!=s) > 40")" = "True" ] && echo 1 || echo 0)" "most books differ between the registers, which is why the join needs keys[]"
ok "$([ -n "$(probe "${BASE}/bible-books.json" "d['_meta']['note']")" ] && echo 1 || echo 0)" "…and the file says which register is which"

echo "a request the site cannot honour is refused, not quietly answered otherwise:"
ok "$([ "$(code "${BASE}/bible-search.json?q=Deus&lang=klingon")" = "400" ] && echo 1 || echo 0)" "an unknown language is a 400 (it used to answer in Latin)"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Gal+3%3A28&lang=klingon")" = "400" ] && echo 1 || echo 0)" "…on the resolver too"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&lang=en,klingon" "d['_meta']['translation']['language']")" = "en" ] && echo 1 || echo 0)" "a MIXED list still names English, so it is served"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&lang=la&limit=abc" "d['_meta']['limit']")" = "20" ] && echo 1 || echo 0)" "a malformed limit means the default, not one hit"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&lang=la&limit=-5" "d['_meta']['limit']")" = "20" ] && echo 1 || echo 0)" "…and so does a negative one (absint made -5 mean 5)"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&lang=la&limit=101" "d['_meta']['limit']")" = "100" ] && echo 1 || echo 0)" "over the ceiling still clamps to the ceiling"

echo "search paging — a total the API can actually deliver:"
U="${BASE}/bible-search.json?q=Herr&lang=de&limit=3"
ok "$([ "$(probe "$U" "d['_meta']['offset'], d['_meta']['shown'], d['_meta']['nextOffset']")" = "(0, 3, 3)" ] && echo 1 || echo 0)" "a truncated answer hands back the offset to ask for next"
ok "$([ "$(probe "${U}&offset=3" "d['_meta']['offset'], d['hits'][0]['ref'] != '$(probe "$U" "d['hits'][0]['ref']")'")" = "(3, True)" ] && echo 1 || echo 0)" "…and that offset really advances (it used to be ignored in silence)"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Herr&lang=de&limit=3&offset=8008" "d['_meta']['nextOffset']")" = "None" ] && echo 1 || echo 0)" "the last page says there is no next"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Herr&lang=de&limit=3&offset=99999" "d['_meta']['shown'], d['_meta']['nextOffset']")" = "(0, None)" ] && echo 1 || echo 0)" "an offset past the end is empty, not an error"
ok "$([ "$(code "${BASE}/bible-search.json?q=Herr&lang=de&offset=abc")" = "400" ] && echo 1 || echo 0)" "a malformed offset is refused, not ignored"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Ierusalem&lang=la&limit=100&offset=700" "d['_meta']['total'], d['_meta']['offset'] + d['_meta']['shown']")" = "(785, 785)" ] && echo 1 || echo 0)" "walking to the end reaches exactly `total` hits"

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

echo "structured data on the pages that are actually indexed:"
ld() { "${CURL[@]}" -L "$1" | python3 -c "
import json,re,sys
h=sys.stdin.read(); out=[]
for b in re.findall(r'<script[^>]*type=\"application/ld\+json\"[^>]*>(.*?)</script>', h, re.S):
    try: d=json.loads(b)
    except Exception: out.append('INVALID'); continue
    for n in d.get('@graph',[d]):
        if n.get('@type')!='WebSite': out.append('%s|%s|%s' % (n.get('@type'), n.get('name'), n.get('url') or ''))
print(';'.join(out))"; }
ok "$([ -n "$(ld "${BASE}/en/biblia/ioannes/3/")" ] && echo 1 || echo 0)" "a canonical chapter page carries Bible markup (it carried none)"
ok "$(ld "${BASE}/en/biblia/ioannes/3/" | grep -q 'Chapter|John 3' && echo 1 || echo 0)" "…naming the book as the reader's language cites it"
ok "$(ld "${BASE}/de/biblia/matthaeus/5/" | grep -q 'Matthäus 5' && echo 1 || echo 0)" "…German says Matthäus, not the slug form"
ok "$(ld "${BASE}/it/biblia/iob/20/" | grep -q 'Giobbe 20' && echo 1 || echo 0)" "…Italian says Giobbe"
ok "$(ld "${BASE}/en/biblia/ioannes/3/" | grep -q "${BASE}/en/biblia/ioannes/3/" && echo 1 || echo 0)" "…and points at the canonical page, not a URL that redirects"
ok "$([ -n "$(ld "${BASE}/es/biblia/psalmi/22/")" ] && echo 1 || echo 0)" "Spanish, French and Italian are covered too (three datasets the old map never had)"
ok "$([ -n "$(ld "${BASE}/latin/john/3/")" ] && echo 1 || echo 0)" "and the Latin-only surface keeps its own"

echo "HTML head — discovery from a page an agent may fetch:"
echo "a citation this site PRINTS resolves back to the passage it names:"
# The vernacular citation names are a SEPARATE set from the URL slugs and the
# search vocabulary — Italian citations are modern ("1 Samuele") while the
# vocabulary follows Martini's Vulgate naming ("Primo dei Re"). Quoting an
# answer's own Italian citation back used to 404 for nineteen of the 73 books.
while IFS='|' read -r q want; do
  [ -z "$q" ] && continue
  U="${BASE}/bible-ref.json?q=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$q")&lang=la"
  ok "$([ "$(probe "$U" "d['ref']['book']['key']")" = "$want" ] && echo 1 || echo 0)" "\"$q\" resolves to $want"
done <<'CITES'
1 Samuele 1:1|1-kings-samuel
1 Corinzi 13:4|1-corinthians
1 Pietro 5:7|1-peter
2 Tessalonicesi 1:1|2-thessalonians
1 Giovanni 4:8|1-john
Nahún 1:1|nahum
CITES

# A legacy slug is not a key. The Spanish/Italian slug map answers "ester" for
# "Ester", which names no book — so this used to search a book that does not
# exist: 0 hits, NO error, and the raw input echoed back as the book's name.
# One of the 366 printed book names, and the only silent zero among them.
U="${BASE}/bible-search.json?q=et&lang=la&book=Ester&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['bookName']")" = "Esther" ] && echo 1 || echo 0)" "book=Ester names the book Esther, not the raw input"
ok "$([ "$(probe "$U" "d['_meta']['total'] > 0")" = "True" ] && echo 1 || echo 0)" "book=Ester actually searches Esther"

echo
echo "a citation whose BOOK is fine but whose range is not blames the range, not the book:"
# The grammar is anchored, so "Mt 5:1-7:29" used to parse as the book name
# "Mt 5:1-7:" and 404 with "no book could be recognised" — while the same
# message listed "Mt" as a supported abbreviation.
U="${BASE}/bible-ref.json?q=Mt+5:1-7:29&lang=la"
ok "$([ "$(code "$U")" = "400" ] && echo 1 || echo 0)" "a cross-chapter range answers 400, not 404"
ok "$([ "$(probe "$U" "d['error']")" = "CITATION_NOT_UNDERSTOOD" ] && echo 1 || echo 0)" "…as CITATION_NOT_UNDERSTOOD"
ok "$([ "$(probe "$U" "d['book']['key']")" = "matthew" ] && echo 1 || echo 0)" "…and it names the book it DID recognise"

U="${BASE}/bible-ref.json?q=Zzzz+5:1-7:29&lang=la"
ok "$([ "$(probe "$U" "d['error']")" = "BOOK_NOT_RECOGNISED" ] && echo 1 || echo 0)" "a genuinely unknown book still says so"

# A third of this canon starts with a digit. A name pattern that forbade digits
# sent every numbered book back to BOOK_NOT_RECOGNISED — the very error the
# block above exists to prevent. 13 of a full year's 242 unresolvable Mass
# reading references were numbered books.
while IFS='|' read -r q want; do
  [ -z "$q" ] && continue
  U="${BASE}/bible-ref.json?q=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$q")&lang=la"
  ok "$([ "$(probe "$U" "d['error']")" = "CITATION_NOT_UNDERSTOOD" ] && echo 1 || echo 0)" "\"$q\" blames the citation, not the book"
  ok "$([ "$(probe "$U" "d['book']['key']")" = "$want" ] && echo 1 || echo 0)" "…and names $want"
done <<'NUMBERED'
1 Cor 9:24-27; 10:1-5|1-corinthians
1 Pet 5:1-4; 5:10-11|1-peter
2 Cor 11:19-33; 12:1-9|2-corinthians
NUMBERED

echo
echo "the no-hits hint tells the truth about the edition it describes:"
# It used to say the Scío writes "Spíritu" beside "Espíritu". The corpus has
# Espíritu 716 times and standalone Spíritu ZERO times, so a reader following
# "search the period spelling" got nothing.
U="${BASE}/bible-search.json?q=zzzznothing&lang=es&limit=1"
ok "$([ "$(probe "$U" "'Spíritu' in (d['_meta'].get('noHitsBecause') or '')")" = "False" ] && echo 1 || echo 0)" "the Spanish hint no longer claims a spelling the edition lacks"
ok "$([ "$(probe "$U" "'dixo' in (d['_meta'].get('noHitsBecause') or '')")" = "True" ] && echo 1 || echo 0)" "…and names the x-for-j rule, which the edition does use"
# every spelling the hints DO name must actually be in its corpus
ok "$([ "$(probe "${BASE}/bible-search.json?q=dixo&lang=es&limit=1" "d['_meta']['total'] > 2000")" = "True" ] && echo 1 || echo 0)" "the Spanish hint's 'dixo' is real"
ok "$([ "$(probe "${BASE}/bible-search.json?q=muger&lang=es&limit=1" "d['_meta']['total'] > 500")" = "True" ] && echo 1 || echo 0)" "the Spanish hint's 'muger' is real"
ok "$([ "$(probe "${BASE}/bible-search.json?q=shew&lang=en&limit=1" "d['_meta']['total'] > 0")" = "True" ] && echo 1 || echo 0)" "the English hint's 'shew' is real"
ok "$([ "$(probe "${BASE}/bible-search.json?q=nol&lang=it&limit=1" "d['_meta']['total'] > 0")" = "True" ] && echo 1 || echo 0)" "the Italian hint's 'nol' is real"

echo
echo "a range that runs backwards is refused, not answered empty:"
# "John 3:10-5" came back well-formed and carrying NOTHING — verses [], text "",
# and a citation reading "Ioannes 3:10" because the dash is only written when
# vt > vf. A well-formed answer carrying nothing is the shape a model papers
# over from memory.
U="${BASE}/bible-ref.json?q=John+3:10-5&lang=la"
ok "$([ "$(code "$U")" = "400" ] && echo 1 || echo 0)" "a reversed range answers 400"
ok "$([ "$(probe "$U" "d['error']")" = "RANGE_REVERSED" ] && echo 1 || echo 0)" "…as RANGE_REVERSED"
ok "$([ "$(probe "$U" "'3:5-10' in d['suggestion']")" = "True" ] && echo 1 || echo 0)" "…and suggests the range the reader meant"
# the right way round is untouched
U="${BASE}/bible-ref.json?q=John+3:5-10&lang=la"
ok "$([ "$(probe "$U" "len(d['passages']['la']['verses'])")" = "6" ] && echo 1 || echo 0)" "the same range the right way round still returns its six verses"

echo
echo "a book filter naming no real book is refused, not answered with a silent zero:"
# internal_key_from_any_book() returns a bare legacy slug when nothing maps it
# to a canonical key. Nine German abbreviations this site PUBLISHES land there,
# and the search used to answer 200 / total 0 with the raw slug echoed back as
# bookName — indistinguishable from "that word is not in that book".
while IFS='|' read -r b; do
  [ -z "$b" ] && continue
  U="${BASE}/bible-search.json?q=et&lang=la&book=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$b")&limit=1"
  ok "$([ "$(code "$U")" = "404" ] && echo 1 || echo 0)" "book=\"$b\" is refused 404"
  ok "$([ "$(probe "$U" "d['error']")" = "BOOK_NOT_RECOGNISED" ] && echo 1 || echo 0)" "…as BOOK_NOT_RECOGNISED"
done <<'PHANTOM'
Buch Ester
BrJer
Buch Jesus Sirach
PHANTOM
# and every book that IS real still narrows
for b in Ester Esth Jdt Sir Neh psalms Iob; do
  U="${BASE}/bible-search.json?q=et&lang=la&book=${b}&limit=1"
  ok "$([ "$(probe "$U" "d['_meta']['total'] > 0")" = "True" ] && echo 1 || echo 0)" "book=$b still narrows to a real book"
done

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
