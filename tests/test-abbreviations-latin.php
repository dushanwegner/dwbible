<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   The Latin abbreviation table knows "Apc", the Nova Vulgata's
 *         abbreviation for the Apocalypse, and it means the same book "Apoc"
 *         does in every one of the six tables.
 * WHY:    The site's own Latin prose prints "(Apc 12,7)" — St Michael's
 *         patronage reason — and /bible-ref.json answered BOOK_NOT_RECOGNISED
 *         while "Apoc 12,7" resolved: LatinPrayer could not read a citation
 *         LatinPrayer itself prints (factory q574, dwbibledata#19).
 * HOW:    No WordPress. The real loader class is required as shipped and pointed
 *         at the sibling dwbibledata checkout, so this reads the tables the
 *         router reads, through the code the router calls.
 * INPUT:  includes/class-dwbible-abbreviations-loader.php,
 *         ../dwbibledata/data/<dataset>/abbreviations.<lang>.json
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-abbreviations-latin.php
 */

define('ABSPATH', '/');

$root = dirname(__DIR__);
$data = dirname($root) . '/dwbibledata/data/';
if (!is_dir($data)) { fwrite(STDERR, "no dwbibledata/data at $data\n"); exit(2); }

function dwbible_data_dir() { global $data; return $data; }
require "$root/includes/class-dwbible-abbreviations-loader.php";

$fails = 0;
function check(string $what, bool $ok, string $detail = '') {
    global $fails;
    if ($ok) { echo "ok   $what\n"; }
    else { $fails++; echo "FAIL $what" . ($detail ? " — $detail" : '') . "\n"; }
}

$la = DwBible_Abbreviations_Loader::load_abbreviation_map('latin');
check('the Latin table loads', count($la) > 200, count($la) . ' keys');

// The defect and its control: "Apoc" always resolved, "Apc" did not.
check("'apoc' is the Apocalypse (control)", ($la['apoc'] ?? null) === 'Apocalypse', var_export($la['apoc'] ?? null, true));
check("'apc' is the Apocalypse", ($la['apc'] ?? null) === 'Apocalypse', var_export($la['apc'] ?? null, true));

// No table may read "apc" as another book: the resolver tries every dataset,
// first hit wins, so a second meaning anywhere would be a silent wrong answer.
$other = [];
foreach (['bible', 'bibel', 'spanish', 'french', 'italian', 'latin'] as $ds) {
    $map = DwBible_Abbreviations_Loader::load_abbreviation_map($ds);
    if (!$map) { $other[] = "$ds: table did not load"; continue; }
    if (isset($map['apc']) && $map['apc'] !== 'Apocalypse') { $other[] = "$ds: apc -> {$map['apc']}"; }
}
check("no table reads 'apc' as another book", empty($other), implode('; ', $other));

// An abbreviation, not a NAME: the name map (what search and linking treat as
// the book's own word) must not grow a three-letter "name".
$names = DwBible_Abbreviations_Loader::load_name_map('latin');
check("'apc' is not a book name", !isset($names['apc']));
check("'apocalypsis' is still the book name (control)", ($names['apocalypsis'] ?? null) === 'Apocalypse');

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
