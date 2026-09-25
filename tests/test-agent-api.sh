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

echo "chapter/verse 0 — no book starts there, and it must not be read as 'omitted':"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Ps+0:1")" = "404" ] && echo 1 || echo 0)" "chapter 0 is refused, not answered as the book with no text"
ok "$([ "$(code "${BASE}/bible-ref.json?q=John+0:1")" = "404" ] && echo 1 || echo 0)" "chapter 0 (John) is refused too"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Genesis+1:0")" = "404" ] && echo 1 || echo 0)" "verse 0 is refused, not answered as the whole chapter"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Ps+1:0")" = "404" ] && echo 1 || echo 0)" "verse 0 (Ps) is refused too"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Ps+2" "d['ref']['chapter']")" = "2" ] && echo 1 || echo 0)" "a bare chapter (no verse at all) still answers the whole chapter"

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

echo
echo "a comma list of verses — the form this site's OWN calendar prints its Mass readings in:"
# /calendar/<date>.json cites "Ps 112:1, 2, 9" (the introit of 2026-07-10), "Ps 44:11-12, 14"
# (the gradual of 2026-08-15) and "Ps 88:12,15" (the offertory of 2026-01-01). The resolver
# refused all three with CITATION_NOT_UNDERSTOOD — so a reader (or an agent) copying a
# reading's own citation into the lookup tool llms.txt advertises as universal was told the
# chapter and verse part could not be read. The server must read back what it prints.
while IFS='|' read -r q ch nums cite; do
  [ -z "$q" ] && continue
  U="${BASE}/bible-ref.json?q=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$q")&lang=la"
  ok "$([ "$(code "$U")" = "200" ] && echo 1 || echo 0)" "\"$q\" resolves (200), not CITATION_NOT_UNDERSTOOD"
  ok "$([ "$(probe "$U" "d['ref']['chapter']")" = "$ch" ] && echo 1 || echo 0)" "…in chapter $ch"
  ok "$([ "$(probe "$U" "[v['verse'] for v in d['passages']['la']['verses']]")" = "$nums" ] && echo 1 || echo 0)" "…carrying exactly the verses $nums"
  ok "$([ "$(probe "$U" "d['ref']['verseNumbers']")" = "$nums" ] && echo 1 || echo 0)" "…named in ref.verseNumbers"
  ok "$([ "$(probe "$U" "d['ref']['citation']['la']")" = "$cite" ] && echo 1 || echo 0)" "…and cited back as the list, never collapsed to a range"
done <<'VERSELISTS'
Ps 112:1, 2, 9|112|[1, 2, 9]|Psalmus 112:1, 2, 9
Ps 44:11-12, 14|44|[11, 12, 14]|Psalmus 44:11-12, 14
Ps 88:12,15|88|[12, 15]|Psalmus 88:12, 15
Ps 112:9, 1, 2|112|[1, 2, 9]|Psalmus 112:1, 2, 9
Ps 112:1, 1, 2|112|[1, 2]|Psalmus 112:1, 2
VERSELISTS
# The text must be the UNION of the verses named — not the span that contains them.
U="${BASE}/bible-ref.json?q=Ps+112%3A1%2C+2%2C+9&lang=la"
ok "$([ "$(probe "$U" "'Laudate, pueri' in d['passages']['la']['text'] and 'matrem filiorum' in d['passages']['la']['text']")" = "True" ] && echo 1 || echo 0)" "the text runs from verse 1 through verse 9"
ok "$([ "$(probe "$U" "'A solis ortu' in d['passages']['la']['text']")" = "False" ] && echo 1 || echo 0)" "…and skips verse 3, which the citation does not name"
ok "$([ "$(probe "$U" "d['ref']['verseFrom'], d['ref']['verseTo']")" = "(1, 9)" ] && echo 1 || echo 0)" "verseFrom/verseTo still bound the passage, for a consumer that reads only those"
ok "$([ -n "$(probe "$U" "d['_meta'].get('readAs') or ''")" ] && echo 1 || echo 0)" "…and the discontinuity is stated, because the URLs can only span it"
ok "$([ "$(probe "$U" "d['urls']['json']['la']")" = "${BASE}/latin/psalms/112/1-9.json" ] && echo 1 || echo 0)" "the addresses are the smallest servable range containing the list"
# A verse named in a list that the chapter has not got is a 404 — never dropped in silence.
ok "$([ "$(code "${BASE}/bible-ref.json?q=Ps+112%3A1%2C+2%2C+99")" = "404" ] && echo 1 || echo 0)" "a list naming a verse the chapter lacks is refused (Psalm 112 ends at 9)"
# The ordinary forms keep their exact old shape: no verseNumbers, no new note.
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Job+20%3A12-13&lang=la" "d['ref'].get('verseNumbers')")" = "None" ] && echo 1 || echo 0)" "a plain range carries no verseNumbers — the field marks a list"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Ps+44%2C11&lang=la" "d['ref']['chapter'], d['ref']['verseFrom'], d['ref']['verseTo']")" = "(44, 11, 11)" ] && echo 1 || echo 0)" "the German comma \"Ps 44,11\" is still chapter 44 verse 11, not a list"

echo "…invisible format characters INSIDE the chapter:verse part (not the book name, which already tolerates them) must be stripped, not treated as an unreadable citation:"
U="${BASE}/bible-ref.json?q=John+3%E2%80%8B%3A16&lang=en"
ok "$([ "$(code "$U")" = "200" ] && echo 1 || echo 0)" "a zero-width space between the chapter and the colon still resolves (200), not CITATION_NOT_UNDERSTOOD"
ok "$([ "$(probe "$U" "d['ref']['chapter'], d['ref']['verseFrom'], d['ref']['verseTo']")" = "(3, 16, 16)" ] && echo 1 || echo 0)" "…as chapter 3, verse 16"
U="${BASE}/bible-ref.json?q=John+3%3A1%E2%80%8B6&lang=en"
ok "$([ "$(code "$U")" = "200" ] && echo 1 || echo 0)" "a zero-width space inside the verse number ('1\xe2\x80\x8b6' for 16) still resolves"
ok "$([ "$(probe "$U" "d['ref']['verseFrom']")" = "16" ] && echo 1 || echo 0)" "…reading the verse as 16, not split"
U="${BASE}/bible-ref.json?q=John+3%3A%E2%80%8B16&lang=en"
ok "$([ "$(code "$U")" = "200" ] && echo 1 || echo 0)" "a zero-width space right after the colon still resolves"

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
# "Cristo" used to be the example here: 0 verses, because Scío writes "Christo". Since
# tick 117 the search folds the two, so it finds them — and the hint is tested with a word
# the 1790s text cannot contain.
ok "$([ "$(probe "${BASE}/bible-search.json?q=Cristo&lang=es" "d['_meta']['total'] > 500")" = "True" ] && echo 1 || echo 0)" "the Scio Spanish writes 'Christo', and 'Cristo' now finds it"
ok "$([ -n "$(probe "${BASE}/bible-search.json?q=gasolina&lang=es" "d['_meta']['noHitsBecause'] or ''")" ] && echo 1 || echo 0)" "…and an empty Spanish answer still says why, naming the edition's orthography"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Christo&lang=es" "d['_meta']['noHitsBecause']")" = "None" ] && echo 1 || echo 0)" "an answer that found verses carries no such hint"

echo "…NFD-decomposed accents (macOS/PDF paste writes a base letter + a separate combining mark) must fold like the precomposed form, not split into two tokens at the mark:"
U="${BASE}/bible-search.json?q=u%CC%88ber&lang=de&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['tokens']")" = "['uber']" ] && echo 1 || echo 0)" "NFD 'über' (u + combining diaeresis) is one token, not ['u','ber']"
ok "$([ "$(probe "$U" "d['_meta']['total']")" = "$(probe "${BASE}/bible-search.json?q=%C3%BCber&lang=de&limit=1" "d['_meta']['total']")" ] && echo 1 || echo 0)" "…and its total matches the precomposed 'über'"
U="${BASE}/bible-search.json?q=pe%CC%80re&lang=fr&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['tokens']")" = "['pere']" ] && echo 1 || echo 0)" "NFD 'père' (e + combining grave) is one token too"
U="${BASE}/bible-search.json?q=segu%CC%81n&lang=es&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['tokens']")" = "['segun']" ] && echo 1 || echo 0)" "NFD 'según' (u + combining acute) is one token too"

echo "…invisible format characters (zero-width space, soft hyphen — what a copy from a PDF or a mobile keyboard pastes mid-word) must be stripped, not treated as a word boundary:"
U="${BASE}/bible-search.json?q=Ie%E2%80%8Bsus&lang=la&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['tokens']")" = "['iesus']" ] && echo 1 || echo 0)" "a zero-width space (U+200B) inside 'Iesus' is invisible, so the token stays whole"
ok "$([ "$(probe "$U" "d['_meta']['total']")" = "$(probe "${BASE}/bible-search.json?q=Iesus&lang=la&limit=1" "d['_meta']['total']")" ] && echo 1 || echo 0)" "…and its total matches the clean 'Iesus' (503), not an unrelated 'ie'+'sus' split"
U="${BASE}/bible-search.json?q=Ie%C2%ADsus&lang=la&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['tokens']")" = "['iesus']" ] && echo 1 || echo 0)" "a soft hyphen (U+00AD) inside 'Iesus' is invisible too"

echo "…visible combining marks (\p{Mn}) outside the small accent map — Hebrew niqqud, Arabic tashkil — must fold away too, not act as a word boundary:"
U="${BASE}/bible-search.json?q=%D7%91%D6%B0%D6%BC%D7%A8%D6%B5%D7%90%D7%A9%D7%81%D6%B4%D7%99%D7%AA&lang=la&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['tokens']")" = "['בראשית']" ] && echo 1 || echo 0)" "Hebrew 'בְּרֵאשִׁית' (with niqqud) is one token, not split at each vowel point"

echo "…full-width Latin letters (U+FF21-FF5A/FF41-FF5A, what a CJK IME's full-width mode or a paste from a full-width-set PDF produces) must fold to the ordinary ASCII letter, not survive as a different codepoint:"
U="${BASE}/bible-search.json?q=%EF%BC%A4%EF%BD%85%EF%BD%95%EF%BD%93&lang=la&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['tokens']")" = "['deus']" ] && echo 1 || echo 0)" "full-width 'Ｄｅｕｓ' tokenizes to ordinary 'deus'"
ok "$([ "$(probe "$U" "d['_meta']['total']")" = "$(probe "${BASE}/bible-search.json?q=deus&lang=la&limit=1" "d['_meta']['total']")" ] && echo 1 || echo 0)" "…and its total matches the ordinary 'deus' (1927), not zero"
ok "$([ "$(probe "$U" "d['_meta']['noHitsBecause']")" = "None" ] && echo 1 || echo 0)" "…so it carries no misleading orthography hint"

echo "…mojibake (UTF-8 bytes mis-decoded as Latin-1, then re-encoded — a common copy-paste artifact) must be repaired, not silently split into unrelated short tokens:"
U="${BASE}/bible-search.json?q=se%C3%83%C2%B1or&lang=es&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['tokens']")" = "['senor']" ] && echo 1 || echo 0)" "mojibake 'seÃ±or' repairs to 'señor' → one token, not ['sea','or']"
ok "$([ "$(probe "$U" "d['_meta']['total']")" = "$(probe "${BASE}/bible-search.json?q=se%C3%B1or&lang=es&limit=1" "d['_meta']['total']")" ] && echo 1 || echo 0)" "…and its total matches the correctly-encoded 'señor' (7284), not an unrelated 'sea'+'or' split (826)"
U="${BASE}/bible-search.json?q=%C3%83%C2%BCber&lang=de&limit=1"
ok "$([ "$(probe "$U" "d['_meta']['tokens']")" = "['uber']" ] && echo 1 || echo 0)" "mojibake 'Ã¼ber' repairs to 'über' too, in a different language"

echo "book= on /bible-search.json reaches the same Kings/Samuel candidate set (dwfactory entry 600/1558) — DISAMBIGUATE, not refuse:"
# Before this fix, book=1+Regum answered 404 BOOK_NOT_RECOGNISED — a step
# backwards from the old silent-3-Kings behaviour, not an improvement: always
# useless beats sometimes wrong, but the actual rule is "disambiguate", and it
# has to reach every endpoint that takes a book name, not only the two it was
# first wired into.
U="${BASE}/bible-search.json?q=Deus&book=1+Regum&lang=la"
ok "$([ "$(code "$U")" = "300" ] && echo 1 || echo 0)" "book=1 Regum is ambiguous: 300, not 404 BOOK_NOT_RECOGNISED"
ok "$([ "$(probe "$U" "d['error']")" = "BOOK_AMBIGUOUS" ] && echo 1 || echo 0)" "…named BOOK_AMBIGUOUS, the same code the resolver uses"
ok "$([ "$(probe "$U" "[c['book']['key'] for c in d['candidates']]")" = "['3-kings', '1-kings-samuel']" ] && echo 1 || echo 0)" "…modern (3-kings) offered before Vulgate/Douay (1-kings-samuel)"
ok "$([ "$(probe "$U" "all(c['opensWith'] for c in d['candidates'])")" = "True" ] && echo 1 || echo 0)" "…each candidate carries its opening words, same as the resolver"
ok "$([ "$(probe "$U" "d['suggestion']")" = "Name the book directly to skip this: book=3-kings or book=1-kings-samuel." ] && echo 1 || echo 0)" "…and the suggestion names THIS endpoint's own parameter (book=), not q="
# If the caller then names one book directly, it searches that one — exactly
# as any other book name always has.
ok "$([ "$(probe "${BASE}/bible-search.json?q=Salomon&book=3-kings&lang=la" "d['_meta']['book'], d['_meta']['total'] > 0")" = "('3-kings', True)" ] && echo 1 || echo 0)" "book=3-kings (named directly) searches 3 Kings, hits included"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&book=1-kings-samuel&lang=la" "d['_meta']['book']")" = "1-kings-samuel" ] && echo 1 || echo 0)" "book=1-kings-samuel (named directly) searches 1 Samuel"
# An unambiguous Samuel/Kings form is untouched: still resolves straight
# through, no disambiguation for a name only one book ever answers to.
ok "$([ "$(probe "${BASE}/bible-search.json?q=Deus&book=3+Kings&lang=la" "d['_meta']['book']")" = "3-kings" ] && echo 1 || echo 0)" "book=\"3 Kings\" (unambiguous) still resolves straight through"

echo "…an array-shaped value on a one-value parameter (?book[]=Genesis&book[]=Exodus) is refused, not silently cast to the literal word 'Array':"
ok "$([ "$(code "${BASE}/bible-search.json?q=amor&book%5B%5D=Genesis&book%5B%5D=Exodus")" = "400" ] && echo 1 || echo 0)" "book[]=a&book[]=b is a 400, not the 404 'book \"Array\" could not be recognised'"
ok "$([ "$(probe "${BASE}/bible-search.json?q=amor&book%5B%5D=Genesis&book%5B%5D=Exodus" "d['error']")" = "UNSUPPORTED_PARAM" ] && echo 1 || echo 0)" "…names the real defect, UNSUPPORTED_PARAM"
ok "$([ "$(code "${BASE}/bible-search.json?q%5B%5D=amor&q%5B%5D=verbum")" = "400" ] && echo 1 || echo 0)" "q[]=a&q[]=b is refused, not a silent 200 search for the literal word 'Array'"
ok "$([ "$(code "${BASE}/bible-search.json?q=amor&lang%5B%5D=la&lang%5B%5D=en")" = "400" ] && echo 1 || echo 0)" "lang[] is refused too"
ok "$([ "$(code "${BASE}/bible-search.json?q=amor&offset%5B%5D=0&offset%5B%5D=1")" = "400" ] && echo 1 || echo 0)" "offset[] is refused too"
ok "$([ "$(code "${BASE}/bible-ref.json?q%5B%5D=Gal+3:28&q%5B%5D=John+1:1")" = "400" ] && echo 1 || echo 0)" "the resolver's q[] is refused too, not the 404 'No book in \"Array\" could be recognised'"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Gal+3:28&lang%5B%5D=la&lang%5B%5D=en")" = "400" ] && echo 1 || echo 0)" "the resolver's lang[] is refused too"

echo "/bible-books.json — the two name registers must be joinable, not confusable:"
ok "$([ "$(probe "${BASE}/bible-books.json" "len(d['keys']), len(d['slugs']), len(d['names'])")" = "(73, 73, 73)" ] && echo 1 || echo 0)" "keys[] rides beside slugs[] and names[], all 73"
ok "$([ "$(probe "${BASE}/bible-books.json" "d['keys'][d['slugs'].index('iosue')]")" = "josue" ] && echo 1 || echo 0)" "the Latin slug 'iosue' names the canonical key 'josue'"
ok "$([ "$(probe "${BASE}/bible-books.json" "d['keys'][d['slugs'].index('matthaeus')]")" = "matthew" ] && echo 1 || echo 0)" "…and 'matthaeus' names 'matthew'"
ok "$([ "$(probe "${BASE}/bible-books.json" "sum(1 for k,s in zip(d['keys'],d['slugs']) if k!=s) > 40")" = "True" ] && echo 1 || echo 0)" "most books differ between the registers, which is why the join needs keys[]"
ok "$([ -n "$(probe "${BASE}/bible-books.json" "d['_meta']['note']")" ] && echo 1 || echo 0)" "…and the file says which register is which"

echo "a request the site cannot honour is refused, not quietly answered otherwise:"
ok "$([ "$(code "${BASE}/bible-search.json?q=Deus&lang=klingon")" = "400" ] && echo 1 || echo 0)" "an unknown language is a 400 (it used to answer in Latin)"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Gal+3%3A28&lang=klingon")" = "400" ] && echo 1 || echo 0)" "…on the resolver too"
# ONE translation per search: a list or "all" was silently narrowed to its first
# language ("all" searched Latin only), while the refusal advertised both (tick 87).
ok "$([ "$(code "${BASE}/bible-search.json?q=Deus&lang=la,en")" = "400" ] && echo 1 || echo 0)" "a search naming two languages is refused, not narrowed to the first"
ok "$([ "$(code "${BASE}/bible-search.json?q=Deus&lang=all")" = "400" ] && echo 1 || echo 0)" "a search for lang=all is refused, not served as Latin"
ok "$(probe "${BASE}/bible-search.json?q=Deus&lang=en,la" "1 if d.get('error')=='UNSUPPORTED_PARAM' and 'one' in (d.get('message','')+d.get('suggestion','')).lower() else 0")" "…with a code and a message saying search takes one language"
ok "$(probe "${BASE}/bible-search.json?q=Deus&lang=klingon" "0 if '\"all\"' in d.get('suggestion','') else 1")" "the search's own refusal no longer advertises a list or \"all\""
# numbering= has real meaning on /bible-ref.json and every psalm URL, is one of
# the six params serve_search_json() protects with agent_reject_array_params(),
# and even has misnamed-param aliases (psalms=, versification=) pointing at it —
# but serve_search_json() never calls agent_numbering_mode(), so it was silently
# discarded: a search hit answered the identical Vulgate ref+citation whether
# numbering=hebrew, numbering=garbage, or the param was absent (quality loop tick 321).
U="${BASE}/bible-search.json?q=Dominus+regit+me&lang=la"
ok "$([ "$(code "$U&numbering=hebrew")" = "400" ] && echo 1 || echo 0)" "numbering=hebrew on a search is refused, not silently ignored"
ok "$([ "$(probe "$U&numbering=hebrew" "d.get('error')")" = "UNSUPPORTED_PARAM" ] && echo 1 || echo 0)" "…named UNSUPPORTED_PARAM"
ok "$([ "$(code "$U&numbering=garbage")" = "400" ] && echo 1 || echo 0)" "…and an unrecognised numbering value is refused too, not silently accepted"

echo
echo "a query containing a double quote does not break _meta.content's own quoting:"
U="${BASE}/bible-search.json?q=John+%22quoted%22&lang=en"
ok "$([ "$(code "$U")" = "200" ] && echo 1 || echo 0)" "a quoted word inside the query still answers 200"
ok "$(probe "$U" "1 if d['_meta']['content'].count(chr(34)) == 2 else 0")" "_meta.content wraps the query in exactly one pair of double quotes, not four"
ok "$(probe "${BASE}/bible-search.json?q=Deus&lang=LA,la" "1 if d.get('_meta',{}).get('total',0)>1000 else 0")" "a repeated language is still one language, and served"
ok "$(probe "${BASE}/bible-ref.json?q=Gal+3%3A28&lang=all" "1 if len(d.get('passages',{}))==6 else 0")" "the resolver still takes lang=all (a list means something there)"
# An ambiguous bare range is refused — and its retry advice must not be a third,
# wrong reading: "Genesis 1-3" suggested "Genesis 1:3" (ONE verse) "for verses" (tick 92).
ok "$(probe "${BASE}/bible-ref.json?q=Genesis+1-3" "0 if 'Genesis 1:3\"' in d.get('suggestion','') else 1")" "…and does not suggest the single verse 1:3 as 'verses'"
ok "$(probe "${BASE}/bible-ref.json?q=Genesis+1-3" "1 if ':1-3' in d.get('suggestion','') and 'Genesis 1\"' in d.get('suggestion','') else 0")" "…it offers verses 1-3 of a named chapter, and the chapters one by one"
# A CITATION sent to the search finds no verse TEXT — and the answer used to blame the
# edition's spelling ("keeps early-modern English: thee, thou…") instead (tick 93).
ok "$(probe "${BASE}/bible-search.json?q=John+3%3A16&lang=en" "1 if 'bible-ref.json' in (d.get('_meta',{}).get('noHitsBecause') or '') else 0")" "a citation sent to the search is pointed at the resolver"
ok "$(probe "${BASE}/bible-search.json?q=John+3%3A16&lang=en" "0 if 'thee' in (d.get('_meta',{}).get('noHitsBecause') or '') else 1")" "…and not told to try the edition's spelling"
ok "$(probe "${BASE}/bible-search.json?q=quantumphysics&lang=en" "1 if 'thee' in (d.get('_meta',{}).get('noHitsBecause') or '') else 0")" "a word search that finds nothing keeps the orthography hint"
# numbering=hebrew at the VERSE: where the Vulgate joins or splits psalms, a Hebrew verse
# lives at another Vulgate verse — Hebrew 10:1 is Vulgate 9:22, not 9:1 (tick 98).
hv() { probe "${BASE}/bible-ref.json?q=$1&lang=la&numbering=hebrew" "1 if (str(d.get('ref',{}).get('chapter'))+':'+str(d.get('ref',{}).get('verseFrom')))=='$2' and '$3'.lower() in ((d.get('passages') or {}).get('la') or {}).get('text','').lower() else 0"; }
ok "$(hv "Ps+10%3A1" "9:22" "recessisti longe")" "Hebrew Ps 10:1 is Vulgate 9:22 (the joined psalm), not the title of 9"
ok "$(hv "Ps+115%3A1" "113:9" "Non nobis")" "Hebrew Ps 115:1 is Vulgate 113:9"
ok "$(hv "Ps+116%3A10" "115:1" "Credidi")" "Hebrew Ps 116:10 is Vulgate 115:1 (the split psalm), not refused"
ok "$(hv "Ps+147%3A12" "147:1" "Lauda")" "Hebrew Ps 147:12 is Vulgate 147:1"
ok "$(hv "Ps+116%3A1" "114:1" "Dilexi")" "Hebrew Ps 116:1 stays Vulgate 114:1 (control)"
ok "$(hv "Ps+23%3A1" "22:1" "Dominus regit")" "Hebrew Ps 23:1 stays Vulgate 22:1 (control)"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Ps+116%3A8-12&numbering=hebrew")" = "400" ] && echo 1 || echo 0)" "a Hebrew range across the 116 split is refused, not narrowed"
ok "$(probe "${BASE}/latin/psalms/10/1.json?numbering=hebrew" "1 if 'recessisti longe' in (d.get('text') or '') else 0")" "…and the verse JSON URL reads Hebrew 10:1 the same way"
# A psalm SEARCH HIT names its Hebrew verse too — "Psalm 22:1" is "My God, my God" in
# most readers' Bibles (tick 99).
ok "$(probe "${BASE}/bible-search.json?q=Dominus+regit+me&lang=la&limit=1" "1 if (d.get('hits') or [{}])[0].get('hebrewRef')=='23:1' else 0")" "a psalm search hit carries hebrewRef (Vulgate 22:1 = Hebrew 23:1)"
ok "$(probe "${BASE}/bible-search.json?q=Ut+quid+Domine+recessisti&lang=la&limit=1" "1 if (d.get('hits') or [{}])[0].get('hebrewRef')=='10:1' else 0")" "…across the joined psalm (Vulgate 9:22 = Hebrew 10:1)"
ok "$(probe "${BASE}/bible-search.json?q=Deus+caritas&lang=la&limit=1" "0 if 'hebrewRef' in (d.get('hits') or [{}])[0] else 1")" "…and a hit outside the Psalms carries none (control)"
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
# The grammar is anchored, so a citation it cannot read parses as a book NAME
# with the numbers glued on ("Mt 5.1-") and 404'd with "no book could be
# recognised" — while the same message listed "Mt" as a supported abbreviation.
# "Mt 5.1-7.29" is the shape that still cannot be read: "." between two digits
# is the verse-list separator here, so it is not a range (dwbible issue 21).
U="${BASE}/bible-ref.json?q=Mt+5.1-7.29&lang=la"
ok "$([ "$(code "$U")" = "400" ] && echo 1 || echo 0)" "an unreadable range answers 400, not 404"
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
echo "invalid UTF-8 bytes in q= are told apart from an empty q= (tick 267):"
# A truncated 4-byte sequence, a lone continuation byte, and an overlong
# encoding — each pasted mid-word, as a broken PDF extractor might. WordPress's
# own sanitize_text_field() wipes the WHOLE string on ANY invalid byte, so this
# used to answer MISSING_QUERY ("Pass the words to find as ?q=…") — the caller
# DID pass bytes, just not valid UTF-8 ones.
for bytes in "de%F0%9D%90us" "de%80us" "de%C1%81us"; do
  U="${BASE}/bible-search.json?q=${bytes}&lang=la&limit=1"
  ok "$([ "$(code "$U")" = "400" ] && echo 1 || echo 0)" "q=${bytes} answers 400"
  ok "$([ "$(probe "$U" "d['error']")" = "INVALID_ENCODING" ] && echo 1 || echo 0)" "…as INVALID_ENCODING, not MISSING_QUERY"
done
ok "$([ "$(probe "${BASE}/bible-search.json?q=&lang=la" "d['error']")" = "MISSING_QUERY" ] && echo 1 || echo 0)" "an actually-empty q= still answers MISSING_QUERY"

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
# The Latin hint used to say the search "folds j/i and æ/œ, so either spelling
# matches" — while printing "cælum", the very word a reader typing the common
# ecclesiastical "coelum" had got wrong. Folding covers æ/ae and œ/oe; it does
# NOT equate oe with ae, so that reader was sent away thinking the word absent.
U="${BASE}/bible-search.json?q=coelum&lang=la&limit=1"
ok "$([ "$(probe "$U" "'coelum' in (d['_meta'].get('noHitsBecause') or '')")" = "True" ] && echo 1 || echo 0)" "the Latin hint names the oe/ae trap it used to hide"
ok "$([ "$(probe "$U" "'either spelling matches' in (d['_meta'].get('noHitsBecause') or '')")" = "False" ] && echo 1 || echo 0)" "…and no longer claims either spelling matches"
ok "$([ "$(probe "${BASE}/bible-search.json?q=c%C3%A6lum&lang=la&limit=1" "d['_meta']['total'] == 175")" = "True" ] && echo 1 || echo 0)" "the Latin hint's 'cælum' count is real"
ok "$([ "$(probe "${BASE}/bible-search.json?q=Coeli+enarrant&lang=la&limit=1" "d['_meta']['total'] == 0")" = "True" ] && echo 1 || echo 0)" "Ps 18:2 as most books print it — 'Coeli enarrant' — still finds nothing"
ok "$([ "$(probe "${BASE}/bible-search.json?q=C%C3%A6li+enarrant&lang=la&limit=1" "d['_meta']['total'] == 1")" = "True" ] && echo 1 || echo 0)" "…and the edition's own spelling finds the psalm"
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

# llms.txt: "any HTML Bible URL plus ?format=json 301-redirects to its .json equivalent".
# That held only for a page nobody had read yet: once dwcache stored the HTML, `format`
# was stripped from the key and the cached PAGE answered the JSON request (tick 108).
# So the page is read first — the state every real verse is in.
U="${BASE}/en/biblia/ioannes/3:16/"
"${CURL[@]}" -o /dev/null "$U"; "${CURL[@]}" -o /dev/null "$U"
ok "$([ "$("${CURL[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "${U}?format=json")" = "301 ${BASE}/bible/ioannes/3/16.json" ] && echo 1 || echo 0)" "?format=json on a Bible page someone has already read still 301s to its JSON"

# A trailing slash on a chapter/verse .json URL — an agent's URL-normaliser easily
# appends one to anything that looks like a directory. On 5 of 6 dataset slugs this
# used to skip the .json guessability bridge (its regex required the path to END at
# ".json", so ".json/" fell through) and land in the plain HTML legacy-redirect branch
# instead, which mishandled the untranslated remainder and 404'd (tick 180).
ok "$([ "$(code "${BASE}/bible/john/3/16.json/")" = "200" ] && echo 1 || echo 0)" "a trailing slash after chapter/verse .json still answers 200 (bible)"
ok "$([ "$(code "${BASE}/bibel/matthaeus/5/20.json/")" = "200" ] && echo 1 || echo 0)" "…and on bibel"
ok "$([ "$(probe "${BASE}/bible/john/3/16.json/" "d['citation']")" = "John 3:16 (Douay-Rheims)" ] && echo 1 || echo 0)" "…and it is the actual verse, not a 404 page"

# British and American spelling are ONE word to a reader, and the English corpus mixes
# them: "neighbour" 182 times, "neighbor" 4 — and one of the four is Matthew 19:19. A
# search in either spelling silently lost the other half (tick 105).
total() { probe "${BASE}/bible-search.json?q=$1&lang=en&limit=1" "d['_meta']['total']"; }
for pair in "neighbour:neighbor" "honour:honor" "Saviour:Savior" "sepulchre:sepulcher" "worshipped:worshiped" "plough:plow" "offence:offense" "fulfil:fulfill"; do
  uk="${pair%%:*}"; us="${pair##*:}"; a=$(total "$uk"); b=$(total "$us")
  ok "$([ -n "$a" ] && [ "$a" = "$b" ] && [ "$a" -gt 0 ] && echo 1 || echo 0)" "English search: \"$uk\" and \"$us\" find the same verses (${a:-?} / ${b:-?})"
done
ok "$([ "$(probe "${BASE}/bible-search.json?q=love+thy+neighbour&lang=en&limit=50" "any(h['book']['key']=='matthew' and h['chapter']==19 and h['verse']==19 for h in d['hits'])")" = "True" ] && echo 1 || echo 0)" "\"love thy neighbour\" finds Matthew 19:19, which spells it \"neighbor\""
ok "$([ "$(total four)" -gt 0 ] && [ "$(total your)" -gt 0 ] && [ "$(total hour)" -gt 0 ] && echo 1 || echo 0)" "…and no generic our→or fold: four, your, hour still match"

# Scío (1790s) spells as its century did, and a few verses as ours does: "quando" in 1,846
# verses, "cuando" in 6. A modern search found the six and gave no hint (tick 117).
es_total() { probe "${BASE}/bible-search.json?q=$1&lang=es&limit=1" "d['_meta']['total']"; }
for pair in "cuando:quando" "Cristo:Christo" "dijo+Jes%C3%BAs:dixo+Jesus" "mujer:muger" "profeta:propheta" "Jerusal%C3%A9n:Jerusalem" "Jesucristo:Jesu-Christo"; do
  modern="${pair%%:*}"; period="${pair##*:}"; a=$(es_total "$modern"); b=$(es_total "$period")
  ok "$([ -n "$a" ] && [ "$a" = "$b" ] && [ "$a" -gt 0 ] && echo 1 || echo 0)" "Spanish search: \"${modern//+/ }\" finds what \"${period//+/ }\" finds (${a:-?} / ${b:-?})"
done
ok "$([ "$(es_total hoy)" -gt 0 ] && [ "$(es_total ley)" -gt 0 ] && echo 1 || echo 0)" "…and no generic y→i or x→j fold: hoy, ley still match"

# Martini (1780s) writes "Davidde" 471 times and "Davide" once, "figliuolo" 2,235 and
# "figlio" 100, plurals in -j ("giudizj", "prodigj"), "sacrifizio"/"sagrifizio" 162 and
# "sacrificio" 6. "Davide" found ONE verse, "Figlio dell'uomo" none (tick 123).
it_total() { probe "${BASE}/bible-search.json?q=$1&lang=it&limit=1" "d['_meta']['total']"; }
for pair in "Davide:Davidde" "Figlio+dell%27uomo:Figliuolo+dell%27uomo" "sacrificio:sagrifizio" "giudizi:giudizj" "aiuto:ajuto" "nemici:nimici" "meraviglia:maraviglia" "elemosina:limosina" "consacrato:consagrato"; do
  modern="${pair%%:*}"; period="${pair##*:}"; a=$(it_total "$modern"); b=$(it_total "$period")
  m="${modern//+/ }"; p="${period//+/ }"
  ok "$([ -n "$a" ] && [ "$a" = "$b" ] && [ "$a" -gt 0 ] && echo 1 || echo 0)" "Italian search: \"${m//%27/\'}\" finds what \"${p//%27/\'}\" finds (${a:-?} / ${b:-?})"
done
ok "$([ "$(it_total Davide)" -gt 400 ] && echo 1 || echo 0)" "…and \"Davide\" finds David's verses, not one ($(it_total Davide))"

# German has no ß/ü/ö/ä on an ASCII keyboard, so the standard fallback (Duden-documented)
# writes each as a digraph: ß→ss, ü→ue, ö→oe, ä→ae. ß already folded via search_normalize's
# accent map; ü/ö/ä only had their diacritic STRIPPED (über→uber), so the digraph spelling
# a reader actually types ("ueber") found nothing where "über" found 4,035 (tick 273).
de_total() { probe "${BASE}/bible-search.json?q=$1&lang=de&limit=1" "d['_meta']['total']"; }
for pair in "%C3%BCber:ueber" "gro%C3%9F:gross" "m%C3%B6ge:moege" "w%C3%BCste:wueste"; do
  umlaut="${pair%%:*}"; digraph="${pair##*:}"; a=$(de_total "$umlaut"); b=$(de_total "$digraph")
  ok "$([ -n "$a" ] && [ "$a" = "$b" ] && [ "$a" -gt 0 ] && echo 1 || echo 0)" "German search: umlaut and its ue/oe/ae digraph find the same verses (${a:-?} / ${b:-?})"
done

# A classical Latin dictionary marks short vowels with a BREVE (ă ĕ ĭ ŏ ŭ), the sibling
# of the macron (ā ē ī ō ū) for long ones — a reader quoting a lexicon entry pastes both
# the same way. The macron already folds (search_normalize's accent map has ā/ē/ī/ō/ū);
# the breve was never added, so "pŏpŭlŭs" found 0 verses where "populus" found 550 (tick 279).
la_total() { probe "${BASE}/bible-search.json?q=$1&lang=la&limit=1" "d['_meta']['total']"; }
for pair in "populus:p%C5%8Fp%C5%ADl%C5%ADs" "deus:d%C4%95us" "vita:v%C4%ADta" "agnus:%C4%83gnus"; do
  plain="${pair%%:*}"; breve="${pair##*:}"; a=$(la_total "$plain"); b=$(la_total "$breve")
  ok "$([ -n "$a" ] && [ "$a" = "$b" ] && [ "$a" -gt 0 ] && echo 1 || echo 0)" "Latin search: \"$plain\" finds what its breve-marked spelling finds (${a:-?} / ${b:-?})"
done

# A PLAUSIBLE MISNAMING of a parameter is refused and the right name given (tick 111).
# Ignored, `language=de` searched the Latin, found nothing and blamed its spelling;
# `psalms=hebrew` served a different psalm. Harmless extras (cache-busters) still pass.
# The plain URL is read first, so it sits in dwcache: a key that dropped the misnamed
# parameter would answer from that entry and the refusal would never run (the tick-108 bug).
for w in "bible-search.json?q=Liebe" "bible-search.json?q=Deus" "bible-ref.json?q=Psalm+23" "bible-ref.json?q=Joh+3,16"; do
  "${CURL[@]}" -o /dev/null "${BASE}/$w"; "${CURL[@]}" -o /dev/null "${BASE}/$w"
done
refused_names() { probe "$1" "d.get('error') == 'UNKNOWN_PARAM' and '$2' in d.get('suggestion','')"; }
ok "$([ "$(refused_names "${BASE}/bible-search.json?q=Liebe&language=de" "lang=")" = "True" ] && echo 1 || echo 0)" "search: language=de is refused, naming lang="
ok "$([ "$(refused_names "${BASE}/bible-search.json?q=Deus&page=2" "offset")" = "True" ] && echo 1 || echo 0)" "search: page=2 is refused, naming offset"
ok "$([ "$(refused_names "${BASE}/bible-search.json?q=Deus&books=Iob" "book=")" = "True" ] && echo 1 || echo 0)" "search: books= is refused, naming book="
ok "$([ "$(refused_names "${BASE}/bible-ref.json?q=Psalm+23&psalms=hebrew" "numbering=")" = "True" ] && echo 1 || echo 0)" "resolver: psalms=hebrew is refused, naming numbering= (ignored, it served another psalm)"
ok "$([ "$(refused_names "${BASE}/bible-ref.json?q=Joh+3,16&language=de" "lang=")" = "True" ] && echo 1 || echo 0)" "resolver: language=de is refused, naming lang="
ok "$([ "$(code "${BASE}/bible-search.json?q=Deus&_=12345")" = "200" ] && [ "$(code "${BASE}/bible-ref.json?q=Gal+3:28&utm_source=x")" = "200" ] && echo 1 || echo 0)" "…while a cache-buster or a utm_ tag is still ignored"

# Forms a lectionary or a German Bible prints (tick 110). Half-verse letters and "f." are
# READ, and say so; "ff.", verse lists and a missing separator are REFUSED with advice
# about THAT fault — every one of them used to be told a range must stay in one chapter.
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Mt+5,1-12a" "d['ref']['book']['key'], d['ref']['chapter'], d['ref']['verseFrom'], d['ref']['verseTo']")" = "('matthew', 5, 1, 12)" ] && echo 1 || echo 0)" "a half-verse letter (Mt 5,1-12a) is read as the whole verse"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Mt+5,1-12a" "'12a' in (d['_meta']['readAs'] or '')")" = "True" ] && echo 1 || echo 0)" "…and readAs names the letter it did not divide"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Joh+3,16f" "d['ref']['chapter'], d['ref']['verseFrom'], d['ref']['verseTo'], '16f' in (d['_meta']['readAs'] or '')")" = "(3, 16, 17, True)" ] && echo 1 || echo 0)" "German 'f.' (Joh 3,16f) is read as the verse and the next, and says so"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Joh+3,16ff" "'ff' in d.get('suggestion','') and 'cross-chapter' not in d.get('suggestion','')")" = "True" ] && echo 1 || echo 0)" "'ff.' is refused with advice about ff, not about chapters"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Joh+3,16.18" "'several' in d.get('suggestion','') and 'cross-chapter' not in d.get('suggestion','')")" = "True" ] && echo 1 || echo 0)" "a verse list (Joh 3,16.18) is refused as several passages"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Joh+3+16" "'separator' in d.get('suggestion','')")" = "True" ] && echo 1 || echo 0)" "a missing chapter:verse separator (Joh 3 16) is named as such"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Mt+5:1-7:29&lang=la" "d['ref']['citation']['en']")" = "Matthew 5:1-7:29" ] && echo 1 || echo 0)" "…while a real cross-chapter range is now READ, not refused"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Mt+5.1-7.29" "'cross-chapter' in d.get('suggestion','') and '\":\"' in d.get('suggestion','')")" = "True" ] && echo 1 || echo 0)" "…and the one written with \".\" is told which punctuation to use"

echo
echo "A RANGE MAY CROSS A CHAPTER BOUNDARY (dwbible issue 21) — three of the four Holy Week"
echo "Passions were truncated at the break, each stopping before the crucifixion, because the"
echo "lectionary could not express the reading:"
# The passage is ASSEMBLED out of the per-chapter files: the tail of the first,
# all of every middle chapter, the head of the last. Verse counts are the Latin
# spine's: Mt 26 has 75 verses (36-75 = 40) + 27:1-60 = 100; Mk 14 has 72
# (32-72 = 41) + 15:1-46 = 87; Lk 22 has 71 (39-71 = 33) + 23:1-53 = 86;
# Jn 18 has 40 (1-40) + 19:1-42 = 82; Gen 1 has 31 + 2:1-3 = 34.
while IFS='|' read -r q key cite n; do
  [ -z "$q" ] && continue
  U="${BASE}/bible-ref.json?q=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$q")&lang=la"
  ok "$([ "$(code "$U")" = "200" ] && echo 1 || echo 0)" "\"$q\" answers 200"
  ok "$([ "$(probe "$U" "d['ref']['book']['key']")" = "$key" ] && echo 1 || echo 0)" "…resolves to $key"
  ok "$([ "$(probe "$U" "d['ref']['citation']['la']")" = "$cite" ] && echo 1 || echo 0)" "…cited as the source prints it: $cite"
  ok "$([ "$(probe "$U" "len(d['passages']['la']['verses'])")" = "$n" ] && echo 1 || echo 0)" "…and carries all $n verses, both chapters"
done <<'PASSIONS'
Mt 26:36-27:60|matthew|Matthaeus 26:36-27:60|100
Mk 14:32-15:46|mark|Marcus 14:32-15:46|87
Luke 22:39-23:53|luke|Lucas 22:39-23:53|86
Jn 18:1-19:42|john|Ioannes 18:1-19:42|82
Gen 1:1-2:3|genesis|Genesis 1:1-2:3|34
PASSIONS

# The chapter boundary itself: the last verse of the first chapter is followed by
# verse 1 of the next, with nothing missing and nothing repeated. Every verse
# carries its chapter, because "5" alone is not an address across two of them.
U="${BASE}/bible-ref.json?q=Jn+18:1-19:42&lang=la"
ok "$([ "$(probe "$U" "[(v['chapter'], v['verse']) for v in d['passages']['la']['verses']][39:41]")" = "[(18, 40), (19, 1)]" ] && echo 1 || echo 0)" "the seam is continuous: 18:40 then 19:1"
ok "$([ "$(probe "$U" "d['ref']['chapter'], d['ref']['chapterTo'], d['ref']['verseFrom'], d['ref']['verseTo']")" = "(18, 19, 1, 42)" ] && echo 1 || echo 0)" "chapterTo names the chapter it ends in"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Gal+3:28" "d['ref']['chapterTo']")" = "None" ] && echo 1 || echo 0)" "…and stays null for a passage inside one chapter"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Gal+3:28&lang=la" "'chapter' in d['passages']['la']['verses'][0]")" = "False" ] && echo 1 || echo 0)" "…where the verses carry no chapter field either"
# The Latin/German comma convention, used at BOTH ends, reads the same passage.
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Io+18,1-19,42&lang=la" "d['ref']['citation']['la'], len(d['passages']['la']['verses'])")" = "('Ioannes 18:1-19:42', 82)" ] && echo 1 || echo 0)" "\"Io 18,1-19,42\" is the same reading"
# The links: this site addresses a passage by ONE chapter, so they open the first
# part of it — and readAs says so rather than letting a reader assume otherwise.
ok "$([ "$(probe "$U" "d['urls']['html']['en']")" = "${BASE}/en/biblia/ioannes/18:1-40/" ] && echo 1 || echo 0)" "the page link opens the part in chapter 18"
ok "$([ "$(probe "$U" "'the rest is in chapter 19' in (d['_meta']['readAs'] or '')")" = "True" ] && echo 1 || echo 0)" "…and readAs says where the rest is"
# A range past the end of the LAST chapter is clamped and said, exactly as an
# over-long range inside one chapter always has been.
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Jn+18:1-19:99&lang=la" "d['ref']['verseTo'], 'chapter 19 ends at verse 42' in (d['_meta']['readAs'] or '')")" = "(42, True)" ] && echo 1 || echo 0)" "an over-long cross-chapter range is clamped, and readAs names the clamp"

echo
echo "…but a citation may not stand in for \"read me the book\":"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Gen+1:1-50:26" "d['error']")" = "RANGE_TOO_LONG" ] && echo 1 || echo 0)" "Gen 1:1-50:26 (fifty chapters) is refused by name"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Gen+1:1-50:26" "'50 chapters' in d['message'] and 'at most 5' in d['message']")" = "True" ] && echo 1 || echo 0)" "…and the refusal says how many it asked for and how many are allowed"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Gen+1:1-6:22" "d['error']")" = "RANGE_TOO_LONG" ] && echo 1 || echo 0)" "six chapters is over the ceiling"
ok "$([ "$(code "${BASE}/bible-ref.json?q=Gen+1:1-5:1")" = "200" ] && echo 1 || echo 0)" "…and five is not"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Jn+19:42-18:1" "d['error']")" = "RANGE_REVERSED" ] && echo 1 || echo 0)" "chapters the wrong way round are refused, not answered empty"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Jn+18:1-99:42" "d['error'], 'has 21 chapters' in d['suggestion']")" = "('CHAPTER_NOT_FOUND', True)" ] && echo 1 || echo 0)" "an end chapter the book has not got is named as such, not as a long span"
# "0" is a falsy STRING — the trap this codebase uses isset()+!=='' for. A range
# END of 0 used to come back well-formed and carrying nothing at all.
ok "$([ "$(probe "${BASE}/bible-ref.json?q=John+3:16-4:0" "d['error']")" = "VERSE_NOT_FOUND" ] && echo 1 || echo 0)" "a range-end verse of 0 is refused, not answered with an empty passage"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=John+3:16-0" "d['error']")" = "VERSE_NOT_FOUND" ] && echo 1 || echo 0)" "…and so is a same-chapter range-end of 0"
# A BARE chapter range stays AMBIGUOUS_RANGE: "Mt 5-7" could be three chapters
# or three verses of one, and guessing is how a reader is handed the wrong text.
ok "$([ "$(probe "${BASE}/bible-ref.json?q=Mt+5-7" "d['error']")" = "AMBIGUOUS_RANGE" ] && echo 1 || echo 0)" "a bare chapter range is still refused as ambiguous"

# A numbered book as a German, French or Spanish reader writes it: digit, SPACE,
# abbreviation — "1 Kor 13,4" is how the Einheitsübersetzung prints it, "1 Co 13,4"
# the Bible de Jérusalem. The tables hold "1Kor" and "1. Kor"; the spaced form
# answered BOOK_NOT_RECOGNISED for 39 abbreviations (tick 104). "1 Re" is NOT pinned
# here: it already resolves, and whether it means 1 Samuel (Martini) or 3 Kings
# (modern Italian/Spanish) is outside dwfactory entry 600/1558's scope — that
# decision covers the English/Latin forms it measured, not every vernacular one.
for pair in "1+Kor+13,4:1-corinthians" "2+Kor+5,17:2-corinthians" "1+K%C3%B6n+3,9:3-kings" "1+Joh+4,8:1-john" \
            "1+Petr+2,9:1-peter" "1+Co+13,4:1-corinthians" "1+R+19,8:3-kings" "2+Tes+3,10:2-thessalonians"; do
  q="${pair%%:*}"; want="${pair##*:}"
  got=$(probe "${BASE}/bible-ref.json?q=${q}&lang=la" "d['ref']['book']['key']")
  ok "$([ "$got" = "$want" ] && echo 1 || echo 0)" "spaced numbered abbreviation \"${q//+/ }\" is ${want} (got ${got:-an error})"
done

# A numbered book with a ROMAN numeral — "III Reg. 19, 8", "II Mach 12:46", "I Petr 2:9":
# how the Vulgate, the Missal and Denzinger cite. Only the few the English table happened
# to list ("I Cor", "II Tim") resolved; 155 of 208 Roman forms of the tables' numbered
# abbreviations answered "no book recognised" (tick 122). III and IV exist only in the
# Vulgate's numbering, so III Reg is 3 Kings, unambiguously — "I Kings" moved out of this
# block (dwfactory entry 600/1558): it is now one of the ambiguous forms below, a Roman
# numeral included, so it is no longer a name a Roman-numeral fold can settle on its own.
for pair in "III+Reg+19,8:3-kings" "IV+Reg+2,11:4-kings" "II+Mach+12,46:2-machabees" "I+Par+29,11:1-paralipomenon" \
            "I+Petr+2,9:1-peter" "I+Kor+13,4:1-corinthians" "II.+Tes+3,10:2-thessalonians" "I+Co+13,4:1-corinthians" \
            "I+Samuelis+3,10:1-kings-samuel" "Iob+19,25:job" "Is+53,5:isaias"; do
  q="${pair%%:*}"; want="${pair##*:}"
  got=$(probe "${BASE}/bible-ref.json?q=${q}&lang=la" "d['ref']['book']['key']")
  ok "$([ "$got" = "$want" ] && echo 1 || echo 0)" "Roman numeral \"${q//+/ }\" is ${want} (got ${got:-an error})"
done
# THE UNAMBIGUOUS FORMS (dwfactory entry 600/1558, DW 2026-09-25): a Samuel/Kings citation
# that names its book number under only ONE convention resolves straight through, in every
# language and in Roman or Arabic numerals — "3 Kings"/"III Regum" is never Samuel, "1 Samuel"
# is never Kings. "1 Re"/"I Re" (Italian/Spanish) are outside this decision's scope (see above)
# and still resolve to the modern reading unpinned, as they always have.
for pair in "1+Re+19,8:3-kings" "I+Re+19,8:3-kings" \
            "1+Samuel+3,10:1-kings-samuel" "1+Samuele+3,10:1-kings-samuel" "I+Sam+3,10:1-kings-samuel" \
            "3+Kings+19,8:3-kings" "III+Regum+19,8:3-kings"; do
  q="${pair%%:*}"; want="${pair##*:}"
  got=$(probe "${BASE}/bible-ref.json?q=${q}&lang=la" "d['ref']['book']['key']")
  ok "$([ "$got" = "$want" ] && echo 1 || echo 0)" "unambiguous: \"${q//+/ }\" is ${want} (got ${got:-an error})"
done
# …and the citation this API prints follows the Vulgate's own numbering in Latin, the
# modern one in English — the two systems disagree, and each book's block says so
# (otherNaming), rather than a Latin title contradicting its own key (dwfactory entry 600).
cit=$(probe "${BASE}/bible-ref.json?q=3-kings+1:1&lang=all" "d['ref']['citation']['en'] + '|' + d['ref']['citation']['la']")
ok "$([ "$cit" = "1 Kings 1:1|3 Regum 1:1" ] && echo 1 || echo 0)" "3 Kings is cited \"1 Kings\" (en) / \"3 Regum\" (la) (got ${cit:-an error})"
cit=$(probe "${BASE}/bible-ref.json?q=1-kings-samuel+3:10&lang=all" "d['ref']['citation']['en']")
ok "$([ "$cit" = "1 Samuel 3:10" ] && echo 1 || echo 0)" "1 Samuel is cited \"1 Samuel\" (got ${cit:-an error})"
other=$(probe "${BASE}/bible-ref.json?q=3-kings+1:1" "d['ref']['book']['otherNaming']")
ok "$([ -n "$other" ] && [[ "$other" == *'1 Kings'* ]] && echo 1 || echo 0)" "3 Kings names its modern equivalent (otherNaming, got ${other:-an error})"
other=$(probe "${BASE}/bible-ref.json?q=1-kings-samuel+1:1" "d['ref']['book']['otherNaming']")
ok "$([ -n "$other" ] && [[ "$other" == *'Regum'* ]] && echo 1 || echo 0)" "1 Samuel names the Vulgate's I Regum (otherNaming, got ${other:-an error})"

echo "Kings / Samuel — genuinely ambiguous forms resolve to a candidate set (dwfactory entry 600/1558):"
# Rule 3: exactly one survivor answers plainly and says which convention was read.
# "1 Kings 17:45" is David and Goliath — modern 1 Kings ch.17 has 24 verses, 1 Samuel's
# has 58, so only 1 Samuel can hold verse 45. Before this feature it answered
# VERSE_NOT_FOUND (the bug the whole entry was filed over).
ok "$([ "$(probe "${BASE}/bible-ref.json?q=1+Kings+17:45" "d['ref']['book']['key']")" = "1-kings-samuel" ] && echo 1 || echo 0)" "1 Kings 17:45 settles itself: only 1 Samuel has a verse 45 there"
readas=$(probe "${BASE}/bible-ref.json?q=1+Kings+17:45" "d['_meta']['readAs']")
ok "$([[ "$readas" == *'1 Samuel 17:45'* ]] && [[ "$readas" == *'Vulgate'* ]] && echo 1 || echo 0)" "…and readAs says which convention was read (got ${readas:-an error})"
ok "$([ "$(probe "${BASE}/bible-ref.json?q=1+Kings+17:45" "'Thou comest to me with a sword' in d['passages']['en']['text']")" = "True" ] && echo 1 || echo 0)" "…and the text is David's answer to Goliath, not VERSE_NOT_FOUND"

# Rule 4: several survivors — disambiguate, modern reading first, each with its opening
# words. Never a 400 (malformed) or 404 (missing): 300, a well-formed request naming two
# real answers.
U="${BASE}/bible-ref.json?q=1+Kings+1:1"
ok "$([ "$(code "$U")" = "300" ] && echo 1 || echo 0)" "1 Kings 1:1 is ambiguous: 300 Multiple Choices, not 200 or 400"
ok "$([ "$(probe "$U" "d['error']")" = "BOOK_AMBIGUOUS" ] && echo 1 || echo 0)" "…named BOOK_AMBIGUOUS"
ok "$([ "$(probe "$U" "[c['book']['key'] for c in d['candidates']]")" = "['3-kings', '1-kings-samuel']" ] && echo 1 || echo 0)" "…modern (3-kings) offered before Vulgate/Douay (1-kings-samuel)"
ok "$([ "$(probe "$U" "all(c['opensWith'] for c in d['candidates'])")" = "True" ] && echo 1 || echo 0)" "…each candidate carries its opening words"
ok "$([ "$(probe "$U" "d['candidates'][0]['citation']")" = "1 Kings 1:1" ] && echo 1 || echo 0)" "…the first candidate is cited \"1 Kings 1:1\""

# A BARE "1 Kings" search-box query never silently lands on Solomon either — it falls
# through to the book index rather than guessing, the same "which did you mean" the
# index already gives an unresolvable prefix.
ok "$([ "$(code "${BASE}/en/biblia/?q=1+Kings+1:1")" = "200" ] && echo 1 || echo 0)" "the search box does not redirect an ambiguous \"1 Kings 1:1\" (falls through, 200)"
loc=$("${CURL[@]}" -o /dev/null -D - "${BASE}/en/biblia/?q=1+Kings+17:45" | grep -i '^location:' | tr -d '\r')
ok "$([[ "$loc" == *'1-samuelis/17:45'* ]] && echo 1 || echo 0)" "…but a single-survivor search redirects straight there (got ${loc:-no redirect})"

# "Regum I" — the Vulgate's OWN name for 1 Samuel — used to reach 3 Kings silently
# (dwfactory entry 600's measured bug); it now joins the candidate set instead.
U="${BASE}/bible-ref.json?q=Regum+I+1:1"
ok "$([ "$(code "$U")" = "300" ] && echo 1 || echo 0)" "\"Regum I 1:1\" is ambiguous, not silently Solomon"
ok "$([ "$(probe "$U" "sorted(c['book']['key'] for c in d['candidates'])")" = "['1-kings-samuel', '3-kings']" ] && echo 1 || echo 0)" "…and both candidates are offered"

# Rule 5: no survivors — say which candidates were tried and how long their chapters are.
# A bare VERSE_NOT_FOUND naming only one silently-assumed convention is what this whole
# feature exists to stop.
U="${BASE}/bible-ref.json?q=1+Kings+99:1"
ok "$([ "$(code "$U")" = "404" ] && echo 1 || echo 0)" "1 Kings 99:1 has no survivors: 404"
msg=$(probe "$U" "d['message']")
ok "$([[ "$msg" == *'1 Kings'*'22 chapters'* ]] && [[ "$msg" == *'1 Samuel'*'31 chapters'* ]] && echo 1 || echo 0)" "…and names both candidates tried, with their chapter counts (got: ${msg:-an error})"
ok "$([ "$(probe "$U" "len(d['candidatesTried'])")" = "2" ] && echo 1 || echo 0)" "…both as a structured list too"

# The documents an agent reads FIRST must be readable from another origin, like the
# JSON they describe. A browser-hosted agent that may fetch /bible-ref.json but not
# the llms.txt telling it that endpoint exists never learns about it. dwtheme serves
# all three; ?format=json already sent the header while its text twin did not.
cors() { "${CURL[@]}" -L -o /dev/null -D - -H 'Origin: https://example.org' "$1" | grep -ci '^access-control-allow-origin: \*'; }
for u in "/llms.txt" "/llms-full.txt" "/prayers/actus-contritionis/?format=text" "/prayers/actus-contritionis/?format=json"; do
  ok "$([ "$(cors "${BASE}${u}")" -gt 0 ] && echo 1 || echo 0)" "$u is readable cross-origin (Access-Control-Allow-Origin: *)"
done

echo
echo "passed ${pass}, failed ${fail}"
[ "$fail" -eq 0 ]
