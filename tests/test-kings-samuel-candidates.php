<?php
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

echo "\n" . ($fails ? "failed: $fails\n" : "passed: every Kings/Samuel candidate check\n");
exit($fails ? 1 : 0);
