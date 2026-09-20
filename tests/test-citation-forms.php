<?php
/**
 * WHAT:   The citation forms a lectionary, a German Bible or a Latin scholarly
 *         apparatus PRINTS are read or refused by name: half-verse letters,
 *         "f."/"sq." and a range that CROSSES A CHAPTER BOUNDARY are read (and
 *         said so); "ff."/"sqq.", verse lists, a missing separator and a
 *         cross-chapter range written with "." each get advice about THEIR fault.
 * WHY:    Every one of these answered 400 with one sentence about keeping a range
 *         inside one chapter — "Mt 5,1-12a", the commonest lectionary form,
 *         included (quality loop tick 110); "sq."/"sqq." (Latin sequens/sequentes,
 *         the same shape as German "f."/"ff.") added at tick 278.
 * HOW:    No WordPress. The two methods are lifted out of the real source file at
 *         runtime (never copied), so this tests what ships.
 * INPUT:  includes/class-dwbible-reference.php
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-citation-forms.php
 */

$src = file_get_contents(dirname(__DIR__) . '/includes/class-dwbible-reference.php');

/** Lift one method's source by name (through its closing brace). */
function lift(string $src, string $name): string {
    $at = strpos($src, "function {$name}(");
    if ($at === false) { fwrite(STDERR, "cannot find {$name}()\n"); exit(2); }
    $depth = 0;
    for ($i = strpos($src, '{', $at); $i < strlen($src); $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
    }
    return substr($src, $at, $i - $at + 1);
}

/** Lift one const line by name. */
function lift_const(string $src, string $name): string {
    if (!preg_match('/const\s+' . preg_quote($name, '/') . '\s*=.*?;/', $src, $m)) {
        fwrite(STDERR, "cannot find const {$name}\n"); exit(2);
    }
    return $m[0];
}

eval('class Ref { ' . lift_const($src, 'CITATION_PATTERN') . ' ' . lift_const($src, 'ROMAN_NUMERAL') . ' ' . lift_const($src, 'MAX_CHAPTER_SPAN') . ' public static ' . lift($src, 'normalize_printed_forms') . ' public static ' . lift($src, 'citation_advice') . ' public static ' . lift($src, 'normalize_unicode_digits') . ' public static ' . lift($src, 'normalize_unicode_punctuation') . ' public static ' . lift($src, 'parse_query') . ' public static ' . lift($src, 'parse_ref') . ' public static ' . lift($src, 'parse_verse_list') . ' private static ' . lift($src, 'strip_format_chars') . ' private static ' . lift($src, 'strip_wrapping_punctuation') . ' private static ' . lift($src, 'strip_trailing_superscript_footnote') . ' private static ' . lift($src, 'split_printed_locations') . ' private static ' . lift($src, 'normalize_roman_chapter') . ' private static ' . lift($src, 'roman_to_int') . ' }');

$pass = 0; $fail = 0;
function ok(bool $c, string $what): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$what}\n"; } else { $fail++; echo "  FAIL {$what}\n"; }
}

echo "read, and said so:\n";
foreach ([
    ['Mt 5,1-12a',  'Mt 5,1-12',   '12a'],
    ['Joh 3,16a',   'Joh 3,16',    '16a'],
    ['John 3:16b',  'John 3:16',   '16b'],
    ['Ps 22,2b-5',  'Ps 22,2-5',   '2b'],
    ['Lk 1,26-38a', 'Lk 1,26-38',  '38a'],
    ['Joh 3,16f',    'Joh 3,16-17', '16f'],
    ['Joh 3,16 f.',  'Joh 3,16-17', '16f'],
    ['John 3:16sq',  'John 3:16-17', '16sq'],
    ['John 3:16 sq.','John 3:16-17', '16sq'],
] as [$in, $want, $named]) {
    $r = Ref::normalize_printed_forms($in);
    ok($r['query'] === $want && strpos((string) $r['note'], $named) !== false,
       "\"{$in}\" → \"{$r['query']}\", note names {$named}");
}

echo "a reader's own spelling is never touched:\n";
foreach (['1 Sam 3,10', 'Joh. 3,16', 'Esther 10:3', 'Judith 16', 'Apc 12', 'Joh 3,16ff', 'John 3:16sqq', 'Mt 5:1-7:29', '2 Kor 5,17', 'Joh 3,16-18'] as $in) {
    $r = Ref::normalize_printed_forms($in);
    ok($r['query'] === $in && $r['note'] === null, "\"{$in}\" unchanged, no note");
}

echo "non-ASCII decimal digits normalized to ASCII (quality loop tick 206):\n";
foreach ([
    ["John \u{0663}:\u{0661}\u{0666}", 'John 3:16'],   // Arabic-Indic
    ["John \u{FF13}:\u{FF11}\u{FF16}", 'John 3:16'],   // fullwidth
    ["John \u{0969}:\u{0967}\u{0966}", 'John 3:10'],   // Devanagari
] as [$in, $want]) {
    $r = Ref::normalize_printed_forms($in);
    ok($r['query'] === $want, "\"{$in}\" -> \"{$r['query']}\"");
}
$r = Ref::normalize_printed_forms("John \u{00B3}:\u{00B9}\u{2076}"); // superscript ³:¹⁶ — not Nd, left alone
ok($r['query'] === "John \u{00B3}:\u{00B9}\u{2076}", 'superscript digits (not decimal) left untouched');

echo "fullwidth separator punctuation normalized to ASCII (quality loop tick 230):\n";
foreach ([
    ["John 3\u{FF1A}16", 'John 3:16'],   // fullwidth colon
    ["John 3\u{FF0C}16", 'John 3,16'],   // fullwidth comma
    ["John 3\u{FF0E}16", 'John 3.16'],   // fullwidth full stop
] as [$in, $want]) {
    $r = Ref::normalize_printed_forms($in);
    ok($r['query'] === $want, "\"{$in}\" -> \"{$r['query']}\"");
}

echo "refused, with advice about THAT fault:\n";
foreach ([
    ['Joh 3,16ff',     'ff.'],
    ['John 3:16sqq',   'sqq.'],
    ['Joh 3,16.18',    'several passages'],
    ['Joh 3,16-18.21', 'several passages'],
    ['Joh 3,16; 4,1',  'several passages'],
    ['Joh 3 16',       'separator'],
    // A cross-chapter range written with "." on both sides is the ONE
    // cross-chapter shape the grammar still refuses: "." between two digits is
    // this site's German verse-list separator (split_printed_locations()), so
    // "5.1-7.29" cannot be told apart from a list. The advice names the fix.
    ['Mt 5.1-7.29',    'cross-chapter'],
    ['Joh 3,16x',      'Write chapter:verse'],
] as [$in, $want]) {
    $a = Ref::citation_advice($in, 'John');
    ok(strpos($a, $want) !== false, "\"{$in}\" → advice about: {$want}");
}

echo "a comma or \"and\" naming a SECOND BOOK is several passages too, not a one-chapter verse list (quality loop tick 254):\n";
foreach ([
    ['Ps 23:1, John 3:16',       'several passages'],
    ['John 3:16, Romans 8:28',   'several passages'],
    ['Gen 1:1 and John 1:1',     'several passages'],
] as [$in, $want]) {
    $a = Ref::citation_advice($in, 'Psalms');
    ok(strpos($a, $want) !== false, "\"{$in}\" → advice about: {$want}");
    ok(strpos($a, 'a comma list of verses in one chapter') === false, "\"{$in}\" → not told to write a same-chapter verse list");
}
ok(strpos(Ref::citation_advice('Ps 3:16, 18, 20-21', 'Psalms'), 'a comma list of verses in one chapter') !== false,
   "…but a genuine same-book comma verse-list keeps its own advice");
ok(strpos(Ref::citation_advice('Joh 3,16ff', 'John'), 'cross-chapter') === false, "…and \"ff.\" is not told about chapters");

echo "the several-passages advice names the READER'S OWN locations, not a fixed example (quality loop tick 242):\n";
foreach ([
    ['Joh 3,16.18',    ['3:16', '3:18']],
    ['Joh 3,16; 4,1',  ['3:16', '4:1']],
    ['Joh 3,16; 4,5',  ['3:16', '4:5']],
] as [$in, $want]) {
    $a = Ref::citation_advice($in, 'John');
    foreach ($want as $loc) {
        ok(strpos($a, "John {$loc}") !== false, "\"{$in}\" advice names \"John {$loc}\"");
    }
}
ok(strpos(Ref::citation_advice('Joh 3,16; 4,5', 'John'), '3:18') === false, "…and \"Joh 3,16; 4,5\" is not told about a verse 18 it never named");

echo "a range-END with no range-START is refused, not silently widened to the whole chapter (quality loop tick 218):\n";
foreach (['John 3:-5', 'Ps 22:-9', '1 Cor 13:-3'] as $in) {
    $r = Ref::parse_query($in);
    ok($r['ref'] === '', "\"{$in}\" -> refused (got ref \"{$r['ref']}\")");
}
echo "wrapping/trailing punctuation around a complete citation is stripped, not refused (quality loop tick 248):\n";
foreach ([
    ['John 3:16.',   'John', '3:16'],
    ['John 3:16,',   'John', '3:16'],
    ['John 3:16;',   'John', '3:16'],
    ['(John 3:16)',  'John', '3:16'],
    ['[John 3:16]',  'John', '3:16'],
    ['"John 3:16"',  'John', '3:16'],
    ['(John 3:16).', 'John', '3:16'],
    ['John 3:16-18.', 'John', '3:16-18'],
] as [$in, $wantName, $wantRef]) {
    $r = Ref::parse_query($in);
    ok(trim((string) $r['name'], '(["\' ') === $wantName && $r['ref'] === $wantRef,
       "\"{$in}\" -> name \"{$r['name']}\", ref \"{$r['ref']}\" (want {$wantName}/{$wantRef})");
}
echo "…but a real second location after a semicolon is untouched (no trailing wrapper to strip; tick 242's grammar unaffected):\n";
$r = Ref::parse_query('John 3:16; 4:5');
ok($r['name'] === 'John 3:16;', "\"John 3:16; 4:5\" -> book half unchanged (\"{$r['name']}\"), still fails book lookup downstream");

echo "a trailing SUPERSCRIPT/SUBSCRIPT footnote-style marker is dropped before book/ref lookup, not left to break it (quality loop tick 290): superscript digits stay footnote decoration (tick 206), but the citation itself must still resolve:\n";
foreach ([
    ["John 3:16\u{00B3}",        'John',   '3:16'],
    ["1 Cor 13:4\u{00B2}",       '1 Cor',  '13:4'],
    ["Song \u{00B3}",            'Song',   ''],
    ["1 Cor \u{00B3}",           '1 Cor',  ''],
    ["John \u{00B3}:\u{00B9}\u{2076}", 'John', ''],
] as [$in, $wantName, $wantRef]) {
    $r = Ref::parse_query($in);
    ok($r['name'] === $wantName && $r['ref'] === $wantRef,
       "\"{$in}\" -> name \"{$r['name']}\", ref \"{$r['ref']}\" (want {$wantName}/{$wantRef})");
}

echo "ordinary whole-chapter and range citations are unaffected:\n";
$r = Ref::parse_query('John 3');
ok($r['name'] === 'John' && $r['ref'] === '3', 'John 3 -> whole chapter 3');
$r = Ref::parse_query('John 3:16-18');
ok($r['name'] === 'John' && $r['ref'] === '3:16-18', 'John 3:16-18 -> range kept');
$r = Ref::parse_query('John 3:0-5');
ok($r['name'] === 'John' && $r['ref'] === '3:0-5', 'John 3:0-5 -> "0" verse still read (tick 200), left for the caller to refuse');

echo "a range may CROSS A CHAPTER BOUNDARY (dwbible issue 21) — the form the four Holy Week Passions are printed in, every one of which used to be refused:\n";
foreach ([
    ['Mt 26:36-27:60',       'Mt',    '26:36-27:60'],
    ['Jn 18:1-19:42',        'Jn',    '18:1-19:42'],
    ['Luke 22:39-23:53',     'Luke',  '22:39-23:53'],
    ['Mk 14:32-15:46',       'Mk',    '14:32-15:46'],
    ['Gen 1:1-2:3',          'Gen',   '1:1-2:3'],
    // The separator convention is the READER'S, and it must be the same one at
    // both ends: a Latin/German citation writes the comma throughout.
    ['Io 18,1-19,42',        'Io',    '18:1-19:42'],
    ["Jn 18:1\u{2013}19:42", 'Jn',    '18:1-19:42'],   // en dash
    ['Mt 26 : 36 - 27 : 60', 'Mt',    '26:36-27:60'],  // spaced out
] as [$in, $wantName, $wantRef]) {
    $r = Ref::parse_query($in);
    ok($r['name'] === $wantName && $r['ref'] === $wantRef,
       "\"{$in}\" -> name \"{$r['name']}\", ref \"{$r['ref']}\" (want {$wantName}/{$wantRef})");
}

echo "…and the forms that must NOT be read as one: the END of a range is a chapter only when it carries a verse of its own, in the SAME separator the citation opened with:\n";
foreach ([
    ['Mt 5:1-12',      'Mt',          '5:1-12'],     // within-chapter range, unchanged
    ['Luke 24:13-35',  'Luke',        '24:13-35'],   // 35 is a VERSE, not chapter 35
    ['Mt 5-7',         'Mt 5-',       '7'],          // bare chapter range: AMBIGUOUS_RANGE downstream
    ['John 3:0-5',     'John',        '3:0-5'],      // "0" is read (tick 200), the caller refuses it
    ['John 3:16-0',    'John',        '3:16-0'],
    ['John 3:16-4:0',  'John',        '3:16-4:0'],   // a range-end CHAPTER's verse "0" survives too
    ['Ps 44:11-12, 14','Ps 44:11-',   '12:14'],      // a verse LIST, not 44:11 to 12:14
    ['Joh 3,16-18.21', 'Joh 3,16-',   '18:21'],      // several passages, not 3:16 to 18:21
    ['Mt 5.1-7.29',    'Mt 5.1-',     '7:29'],       // "." is the verse-list separator here
] as [$in, $wantName, $wantRef]) {
    $r = Ref::parse_query($in);
    ok($r['name'] === $wantName && $r['ref'] === $wantRef,
       "\"{$in}\" -> name \"{$r['name']}\", ref \"{$r['ref']}\" (want {$wantName}/{$wantRef})");
}
foreach (['John 3:-5', 'Ps 22:-9'] as $in) {
    $r = Ref::parse_query($in);
    ok($r['ref'] === '', "\"{$in}\" -> still refused as a range-end with no start (got ref \"{$r['ref']}\")");
}
// The comma list the site's own calendar prints must still reach parse_verse_list()
// intact — the cross-chapter suffix must never swallow its second item.
$vl = Ref::parse_verse_list('Ps 44:11-12, 14');
ok($vl !== null && $vl['chapter'] === 44 && $vl['spans'] === [[11, 12], [14, 14]],
   '"Ps 44:11-12, 14" is still read as chapter 44, verses 11-12 and 14');

echo "the canonical ref string parses back to its four numbers, and an END CHAPTER is told from an END VERSE by whether it carries one:\n";
foreach ([
    ['18:1-19:42', ['ch' => 18, 'vf' => 1,  'chTo' => 19, 'vt' => 42, 'verseGiven' => true]],
    ['24:13-35',   ['ch' => 24, 'vf' => 13, 'chTo' => 24, 'vt' => 35, 'verseGiven' => true]],
    ['3:16',       ['ch' => 3,  'vf' => 16, 'chTo' => 3,  'vt' => 16, 'verseGiven' => true]],
    ['3:0-5',      ['ch' => 3,  'vf' => 0,  'chTo' => 3,  'vt' => 5,  'verseGiven' => true]],
    ['5',          ['ch' => 5,  'vf' => 0,  'chTo' => 5,  'vt' => 0,  'verseGiven' => false]],
] as [$in, $want]) {
    ok(Ref::parse_ref($in) === $want, "parse_ref(\"{$in}\") -> " . json_encode(Ref::parse_ref($in)));
}
foreach (['', 'John 3:16', '3:16-', 'x'] as $in) {
    ok(Ref::parse_ref($in) === null, "parse_ref(\"{$in}\") -> null (not a canonical ref)");
}

echo "the span ceiling — a citation may cross a boundary, it may not ask for a whole book:\n";
$passions = ['26:36-27:60', '18:1-19:42', '22:39-23:53', '14:32-15:46', '1:1-2:3'];
foreach ($passions as $ref) {
    $p = Ref::parse_ref($ref);
    ok($p['chTo'] - $p['ch'] + 1 <= Ref::MAX_CHAPTER_SPAN, "\"{$ref}\" is within the ceiling of " . Ref::MAX_CHAPTER_SPAN . " chapters");
}
$g = Ref::parse_ref('1:1-50:26'); // Gen 1:1-50:26 — the whole book
ok($g['chTo'] - $g['ch'] + 1 > Ref::MAX_CHAPTER_SPAN, '"1:1-50:26" (the whole of Genesis) is over the ceiling');

echo "a Roman numeral CHAPTER, the Vulgate/Denzinger apparatus's own citation form, is read as its Arabic value (quality loop tick 284):\n";
foreach ([
    ['Jo. III, 16',    'Jo 3, 16',    'III'],
    ['John III, 16',   'John 3, 16',   'III'],
    ['Matth. V, 3',    'Matth 5, 3',  'V'],
    ['Rom. I, 20',     'Rom 1, 20',   'I'],
    ['1 Cor. XIII, 4', '1 Cor 13, 4', 'XIII'],
    ['Gen I, 1',       'Gen 1, 1',     'I'],
    ['John III',       'John 3',       'III'],
    ['Apoc IV, 1-2',   'Apoc 4, 1-2',  'IV'],
] as [$in, $want, $named]) {
    $r = Ref::normalize_printed_forms($in);
    ok($r['query'] === $want && strpos((string) $r['note'], $named) !== false,
       "\"{$in}\" → \"{$r['query']}\", note names {$named}");
}
echo "…but a Roman numeral BOOK-COUNT PREFIX (tick 122, read elsewhere — before the name, not after it) is left alone here:\n";
foreach (['III Reg 19, 8', 'III Reg. 19, 8', 'II Mach 12,46', 'I Petr 2,9'] as $in) {
    $r = Ref::normalize_printed_forms($in);
    ok($r['query'] === $in && $r['note'] === null, "\"{$in}\" unchanged, no note");
}

echo "\npassed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
