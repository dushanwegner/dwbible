<?php
/**
 * WHAT:   The citation forms a lectionary or a German Bible PRINTS are read or
 *         refused by name: half-verse letters and "f." are read (and said so);
 *         "ff.", verse lists, a missing separator and a cross-chapter range each
 *         get advice about THEIR fault.
 * WHY:    Every one of these answered 400 with one sentence about keeping a range
 *         inside one chapter — "Mt 5,1-12a", the commonest lectionary form,
 *         included (quality loop tick 110).
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

eval('class Ref { public static ' . lift($src, 'normalize_printed_forms') . ' public static ' . lift($src, 'citation_advice') . ' public static ' . lift($src, 'normalize_unicode_digits') . ' }');

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
    ['Joh 3,16f',   'Joh 3,16-17', '16f'],
    ['Joh 3,16 f.', 'Joh 3,16-17', '16f'],
] as [$in, $want, $named]) {
    $r = Ref::normalize_printed_forms($in);
    ok($r['query'] === $want && strpos((string) $r['note'], $named) !== false,
       "\"{$in}\" → \"{$r['query']}\", note names {$named}");
}

echo "a reader's own spelling is never touched:\n";
foreach (['1 Sam 3,10', 'Joh. 3,16', 'Esther 10:3', 'Judith 16', 'Apc 12', 'Joh 3,16ff', 'Mt 5:1-7:29', '2 Kor 5,17', 'Joh 3,16-18'] as $in) {
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

echo "refused, with advice about THAT fault:\n";
foreach ([
    ['Joh 3,16ff',     'ff.'],
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
ok(strpos(Ref::citation_advice('Joh 3,16ff', 'John'), 'ONE chapter') === false, "…and \"ff.\" is not told about chapters");

echo "\npassed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
