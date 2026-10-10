<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   DwBible_Kings_Samuel — the candidate-set map and the existence
 *         elimination it does for "1 Kings"/"2 Kings" and their twins.
 * WHY:    dwfactory entry 600/1558 (DW, 2026-09-25): "1 Kings" is genuinely
 *         ambiguous between the modern naming (3 Kings/Solomon) and the
 *         Vulgate/Douay naming (1 Samuel), and this site must never
 *         silently pick one. Most citations settle themselves once the
 *         chapter/verse is checked against both candidates — "1 Kings
 *         17:45" can only be 1 Samuel, because modern 1 Kings 17 has 24
 *         verses and 1 Samuel 17 has 58 — and this is the class that does
 *         that check.
 * HOW:    No WordPress. The class has no WordPress dependency of its own
 *         (it takes the verse-count table as a plain array), so it is
 *         required directly rather than lifted.
 * INPUT:  ../includes/class-dwbible-kings-samuel.php
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-kings-samuel-candidates.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require dirname(__DIR__) . '/includes/class-dwbible-kings-samuel.php';

$fails = 0;
function check(string $what, bool $ok, string $detail = '') {
    global $fails;
    if ($ok) { echo "ok   $what\n"; }
    else { $fails++; echo "FAIL $what" . ($detail ? " — $detail" : '') . "\n"; }
}

// A small stand-in for DwBible_Plugin::verse_counts_by_book() — chapter
// lengths that reproduce the measured facts the dwfactory entry cites:
// modern 1 Kings (3-kings) chapter 17 has 24 verses, 1 Samuel
// (1-kings-samuel) chapter 17 has 58. 22 vs 31 chapters overall.
$COUNTS = [
    '3-kings'        => array_fill(0, 22, 20), // 22 chapters, 20 verses each…
    '1-kings-samuel' => array_fill(0, 31, 20), // …31 chapters, 20 verses each…
    '4-kings'        => array_fill(0, 25, 20),
    '2-kings-samuel' => array_fill(0, 24, 20),
];
$COUNTS['3-kings'][17 - 1]        = 24; // …except chapter 17, the measured pair.
$COUNTS['1-kings-samuel'][17 - 1] = 58;

// ── candidates_for(): which strings are ambiguous, and which are not ───────
$ambiguous_forms = [
    '1 Kings' => ['3-kings', '1-kings-samuel'],
    '1 Kgs'   => ['3-kings', '1-kings-samuel'],
    '1 Regum' => ['3-kings', '1-kings-samuel'],
    '1 Reg'   => ['3-kings', '1-kings-samuel'],
    'Regum I' => ['3-kings', '1-kings-samuel'],
    'I Kings' => ['3-kings', '1-kings-samuel'], // Roman prefix folds onto the Arabic entry
    'I Regum' => ['3-kings', '1-kings-samuel'],
    'I Reg'   => ['3-kings', '1-kings-samuel'],
    '2 Kings' => ['4-kings', '2-kings-samuel'],
    'II Regum' => ['4-kings', '2-kings-samuel'],
    'Regum II' => ['4-kings', '2-kings-samuel'],
];
foreach ($ambiguous_forms as $raw => $want) {
    $got = DwBible_Kings_Samuel::candidates_for($raw);
    check("\"$raw\" is ambiguous: {$want[0]} then {$want[1]}", $got === $want, var_export($got, true));
}

$unambiguous_forms = ['1 Samuel', '1 Samuelis', 'Samuelis I', '1 Sam', 'I Samuelis',
    '3 Kings', '3 Regum', 'III Regum', 'Regum III', '3 Reg',
    '2 Samuel', '4 Kings', 'IV Regum', 'Regum IV', 'Genesis', '', '   '];
foreach ($unambiguous_forms as $raw) {
    check("\"$raw\" is NOT read as ambiguous", DwBible_Kings_Samuel::candidates_for($raw) === null,
          var_export(DwBible_Kings_Samuel::candidates_for($raw), true));
}

// ── a SPELLED ordinal is its digit (q5130) ─────────────────────────────────
// "First Kings 2:11" resolved silently to 3 Kings while "1 Kings 2:11" was
// refused as ambiguous: the spelled form went to the ordinary resolver, which
// found it in the English table. A spelled ordinal meets the SAME check as its
// digit — the forms are the ones the six abbreviation tables hold for the
// Kings books (dwbibledata: bible, bibel, italian), plus their case variants.
$spelled_forms = [
    'First Kings'              => '1 Kings',
    'first kings'              => '1 Kings',
    'FIRST KINGS'              => '1 Kings',
    'Second Kings'             => '2 Kings',
    'The First Book of Kings'  => '1 Kings',
    'The Second Book of Kings' => '2 Kings',
    'First  Kings'             => '1 Kings',
    'Primo dei Re'             => '1 Re',    // Italian: both ambiguous since q7379
    'Secondo dei Re'           => '2 Re',
    'Erstes Buch der Könige'   => '1 Könige', // German likewise
    'Zweites Buch der Könige'  => '2 Könige',
];
foreach ($spelled_forms as $raw => $digit) {
    $got  = DwBible_Kings_Samuel::candidates_for($raw);
    $want = DwBible_Kings_Samuel::candidates_for($digit);
    check("\"$raw\" meets the same check as \"$digit\"", $got === $want, var_export($got, true) . ' vs ' . var_export($want, true));
}
check('"First Kings" is ambiguous, modern first', DwBible_Kings_Samuel::candidates_for('First Kings') === ['3-kings', '1-kings-samuel']);
check('"Second Kings" is ambiguous, modern first', DwBible_Kings_Samuel::candidates_for('Second Kings') === ['4-kings', '2-kings-samuel']);
check('"The First Book of Kings" is ambiguous', DwBible_Kings_Samuel::candidates_for('The First Book of Kings') === ['3-kings', '1-kings-samuel']);

// What must stay as it is: the Douay third/fourth spelled out, Samuel, and
// every other spelled numbered book.
foreach (['Third Kings', 'Fourth Kings', 'The Third Book of Kings', 'The Fourth Book of Kings',
          'Terzo dei Re', 'Quarto dei Re', 'First Samuel', 'Second Samuel', 'The First Book of Samuel',
          'First Corinthians', 'Second Peter', 'Third John', 'First', 'Kings', 'First Kingsley'] as $raw) {
    check("\"$raw\" is NOT read as ambiguous", DwBible_Kings_Samuel::candidates_for($raw) === null,
          var_export(DwBible_Kings_Samuel::candidates_for($raw), true));
}

// "III"/"IV" must never fold onto the I/II map entries — they are the
// unambiguous 3rd/4th-book forms and already resolve elsewhere.
check("\"III Regum\" is not read as \"I\" + \"II Regum\"", DwBible_Kings_Samuel::candidates_for('III Regum') === null);
check("\"IV Kings\" is not folded to \"1\\'V Kings\\'\"", DwBible_Kings_Samuel::candidates_for('IV Kings') === null);

// ── surviving(): rule 2, eliminate by existence ─────────────────────────────
$candidates = DwBible_Kings_Samuel::AMBIGUOUS['1 kings'];

// One survivor: "1 Kings 17:45" — the David-and-Goliath case the dwfactory
// entry was filed over. Modern 1 Kings 17 stops at verse 24; only 1 Samuel
// reaches 45.
$one = DwBible_Kings_Samuel::surviving($candidates, $COUNTS, 17, 17, 45);
check('1 Kings 17:45 -> exactly 1 Samuel survives', $one === ['1-kings-samuel'], var_export($one, true));

// Both survive: book-only ("1 Kings", no chapter) and a shared verse
// ("1 Kings 1:1", both books have a chapter 1 verse 1).
$book_only = DwBible_Kings_Samuel::surviving($candidates, $COUNTS, 0, 0, 0);
check('bare "1 Kings" (no chapter) -> both survive', $book_only === ['3-kings', '1-kings-samuel'], var_export($book_only, true));
$both = DwBible_Kings_Samuel::surviving($candidates, $COUNTS, 1, 1, 1);
check('1 Kings 1:1 -> both survive, modern first', $both === ['3-kings', '1-kings-samuel'], var_export($both, true));

// No survivors: chapter 99 exists in neither book (22 and 31 chapters).
$none = DwBible_Kings_Samuel::surviving($candidates, $COUNTS, 99, 99, 1);
check('1 Kings 99:1 -> no survivors', $none === [], var_export($none, true));

// A chapter that exists in the shorter book (3-kings, 22 chapters) but not
// the longer one is impossible here (1-kings-samuel is always at least as
// long), so exercise the opposite edge instead: a chapter past 3-kings'
// 22 but within 1-kings-samuel's 31 leaves exactly one survivor.
$edge = DwBible_Kings_Samuel::surviving($candidates, $COUNTS, 25, 25, 1);
check('1 Kings 25:1 (past the shorter book, inside the longer) -> 1 Samuel only', $edge === ['1-kings-samuel'], var_export($edge, true));

// ── Italian, Spanish and French (q7379) ─────────────────────────────────────
// The names the site's own editions print for the four books (dwbibledata
// index.csv: Primo/Secondo/Terzo/Quarto dei Re; Primero…/Cuarto de los Reyes;
// Premier/Deuxième/Troisième/Quatrième des Rois) and their digit forms. First
// and second fit both books -> the same two candidates as "1 Kings"/"2 Kings";
// third and fourth are only ever 3 and 4 Kings.
$first  = ['3-kings', '1-kings-samuel'];
$second = ['4-kings', '2-kings-samuel'];
$it_es_fr_ambiguous = [
    'Primo dei Re' => $first,   'Secondo dei Re' => $second,
    'primo dei re' => $first,   'Primo libro dei Re' => $first,
    '1 Re' => $first,           '2 Re' => $second,
    'I Re' => $first,           'II Re' => $second,
    'Primero de los Reyes' => $first,   'Segundo de los Reyes' => $second,
    'Primer libro de los Reyes' => $first,  'Libro Primero de los Reyes' => $first,
    '1 Reyes' => $first,        '2 Reyes' => $second,
    'Premier des Rois' => $first,       'Deuxième des Rois' => $second,
    'Second des Rois' => $second,       'Premier livre des Rois' => $first,
    'PREMIER DES ROIS' => $first,
    '1 Rois' => $first,         '2 Rois' => $second,
];
foreach ($it_es_fr_ambiguous as $raw => $want) {
    $got = DwBible_Kings_Samuel::candidates_for($raw);
    check("\"$raw\" is ambiguous: {$want[0]} then {$want[1]}", $got === $want, var_export($got, true));
}

$third_fourth = [
    'Terzo dei Re' => '3-kings',   'Quarto dei Re' => '4-kings',   '3 Re' => '3-kings',    '4 Re' => '4-kings',
    'Tercero de los Reyes' => '3-kings', 'Cuarto de los Reyes' => '4-kings',
    'Tercer libro de los Reyes' => '3-kings',  '3 Reyes' => '3-kings',  '4 Reyes' => '4-kings',
    'Troisième des Rois' => '3-kings',   'Quatrième des Rois' => '4-kings',
    '3 Rois' => '3-kings',  '4 Rois' => '4-kings',
];
foreach ($third_fourth as $raw => $key) {
    check("\"$raw\" is not ambiguous", DwBible_Kings_Samuel::candidates_for($raw) === null,
          var_export(DwBible_Kings_Samuel::candidates_for($raw), true));
    $got = DwBible_Kings_Samuel::unambiguous_key_for($raw);
    check("\"$raw\" is understood as $key", $got === $key, var_export($got, true));
}

// Only third and fourth: the first/second forms are never a single key, and
// neither are Samuel, other books named with "de los"/"des"/"dei", or German.
foreach (['Primo dei Re', '1 Re', 'Primero de los Reyes', 'Premier des Rois', '1 Kings', '3 Kings', 'Terzo di Samuele',
          'Tercero de los Macabeos', 'Troisième des Corinthiens', 'Primo dei Maccabei', 'Primero de los Macabeos',
          'Premier des Maccabées', 'Deuxième des Rois de France', 'Erstes Buch der Könige', '1 Könige', ''] as $raw) {
    check("\"$raw\" is not a third/fourth Kings name", DwBible_Kings_Samuel::unambiguous_key_for($raw) === null,
          var_export(DwBible_Kings_Samuel::unambiguous_key_for($raw), true));
}
// German stays as it is: no ambiguity, the edition's own naming.
foreach (['Erstes Buch der Könige', 'Zweites Buch der Könige', '1 Könige', '2 Könige', '1. Könige'] as $raw) {
    check("German \"$raw\" is not ambiguous", DwBible_Kings_Samuel::candidates_for($raw) === null);
}
// Other languages' Samuel, Maccabees and the like must not be pulled in.
foreach (['Primo di Samuele', 'Primero de Samuel', 'Premier de Samuel', 'Primo dei Maccabei', 'Primero de los Macabeos',
          'Premier des Maccabées', 'Primo de Paralipomeni', 'Premier des Chroniques', '1 Sam', '1 Samuele', '1 Samuel'] as $raw) {
    check("\"$raw\" is NOT read as ambiguous", DwBible_Kings_Samuel::candidates_for($raw) === null,
          var_export(DwBible_Kings_Samuel::candidates_for($raw), true));
}

echo "\n" . ($fails ? "failed: $fails\n" : "passed: every Kings/Samuel candidate check\n");
exit($fails ? 1 : 0);
