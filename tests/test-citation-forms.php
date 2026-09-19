<?php
/**
 * WHAT:   The citation forms a lectionary, a German Bible or a Latin scholarly
 *         apparatus PRINTS are read or refused by name: half-verse letters and
 *         "f."/"sq." are read (and said so); "ff."/"sqq.", verse lists, a
 *         missing separator and a cross-chapter range each get advice about
 *         THEIR fault.
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

eval('class Ref { ' . lift_const($src, 'CITATION_PATTERN') . ' public static ' . lift($src, 'normalize_printed_forms') . ' public static ' . lift($src, 'citation_advice') . ' public static ' . lift($src, 'normalize_unicode_digits') . ' public static ' . lift($src, 'normalize_unicode_punctuation') . ' public static ' . lift($src, 'parse_query') . ' private static ' . lift($src, 'strip_format_chars') . ' private static ' . lift($src, 'strip_wrapping_punctuation') . ' private static ' . lift($src, 'split_printed_locations') . ' }');

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
    ['Mt 5:1-7:29',    'ONE chapter'],
    ['Mt 5,1-7,29',    'ONE chapter'],
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
ok(strpos(Ref::citation_advice('Joh 3,16ff', 'John'), 'ONE chapter') === false, "…and \"ff.\" is not told about chapters");

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

echo "ordinary whole-chapter and range citations are unaffected:\n";
$r = Ref::parse_query('John 3');
ok($r['name'] === 'John' && $r['ref'] === '3', 'John 3 -> whole chapter 3');
$r = Ref::parse_query('John 3:16-18');
ok($r['name'] === 'John' && $r['ref'] === '3:16-18', 'John 3:16-18 -> range kept');
$r = Ref::parse_query('John 3:0-5');
ok($r['name'] === 'John' && $r['ref'] === '3:0-5', 'John 3:0-5 -> "0" verse still read (tick 200), left for the caller to refuse');

echo "\npassed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
