<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   Ezra, Esra, Esdrae, 1 Esdrae, Esdras (and their abbreviations) all
 *         resolve to the Vulgate's 1 Esdras; Nehemiah / 2 Esdrae stay 2 Esdras /
 *         Nehemias; and no abbreviation table points at a book that does not exist.
 * WHY:    /bible-ref.json answered "Ezra 1:1" with BOOK_NOT_RECOGNISED (no table
 *         held the word) and "Esra 1:1" with PASSAGE_UNAVAILABLE: the German table
 *         keyed the book "Esra", a name no dataset has (the book is "1_Esra"), so
 *         the abbreviation resolved to a book that does not exist (factory q602,
 *         q573). Four more German keys did the same: Nehemia, Ester, Judit, Sirach.
 * HOW:    No WordPress. The real abbreviation loader, the real slugify() and the
 *         real DwBible_Canonicalization are used as shipped, against the sibling
 *         dwbibledata checkout. A name is resolved the way the router does it: the
 *         datasets in the router's order, first table hit wins, then the book the
 *         table's target names in book_map.json.
 * INPUT:  ../dwbibledata/data/<dataset>/abbreviations.<lang>.json, book_map.json
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-ezra-esdras.php
 */

define('ABSPATH', '/');

$root = dirname(__DIR__);
$data = dirname($root) . '/dwbibledata/data/';
if (!is_dir($data)) { fwrite(STDERR, "no dwbibledata/data at $data\n"); exit(2); }

function dwbible_data_dir() { global $data; return $data; }

/** Lift one method's source out of a file, by name. */
function lift(string $file, string $name): string {
    $src = file_get_contents($file);
    $at  = strpos($src, "function {$name}(");
    if ($at === false) { fwrite(STDERR, "cannot find {$name}() in {$file}\n"); exit(2); }
    $open = strpos($src, '{', $at);
    $depth = 0;
    for ($i = $open; $i < strlen($src); $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
    }
    return substr($src, $at, $i - $at + 1);
}

eval('class DwBible_Plugin { public static ' . lift("$root/dwbible.php", 'slugify') . ' }');
require "$root/includes/class-dwbible-canonicalization.php";
require "$root/includes/class-dwbible-abbreviations-loader.php";

$fails = 0;
function check(string $what, bool $ok, string $detail = '') {
    global $fails;
    if ($ok) { echo "ok   $what\n"; }
    else { $fails++; echo "FAIL $what" . ($detail ? " — $detail" : '') . "\n"; }
}

$bookMap  = json_decode(file_get_contents($data . 'book_map.json'), true);
$bookMap  = $bookMap['books'] ?? $bookMap;
$datasets = ['bibel', 'bible', 'spanish', 'french', 'italian', 'latin'];
$tables   = [];
foreach ($datasets as $ds) { $tables[$ds] = DwBible_Abbreviations_Loader::load_abbreviation_map($ds); }

/** The internal key a typed name resolves to through the abbreviation tables, or null. */
function resolve(string $name): ?string {
    global $tables, $datasets, $bookMap;
    $k = mb_strtolower(trim($name), 'UTF-8');
    foreach ($datasets as $ds) {
        if (!isset($tables[$ds][$k])) { continue; }
        $slug = DwBible_Plugin::slugify($tables[$ds][$k]);
        foreach ($datasets as $d2) {
            $key = DwBible_Canonicalization::canonicalize_key_from_dataset_book_slug($bookMap, $d2, $slug);
            if ($key !== null) { return $key; }
        }
        return null; // the table named a book no dataset has
    }
    return null;
}
// "ezra" is an alias key of its own in book_map.json for 1-esdras; both mean the same book.
function same_book(?string $k, string $want): bool {
    global $bookMap;
    if ($k === null) { return false; }
    return $k === $want || ($bookMap[$k]['latin'] ?? null) === $want;
}

$ezra = [
    'Ezra', 'Esra', 'Esdrae', '1 Esdrae', 'Esdras', 'I Esdrae', '1 Esdras', 'I Esdras',
    'Ezr', 'Esr', 'Esd', 'Esdr', '1 Esd', '1 Ezra', 'Book of Ezra', 'Buch Esra', 'Liber Esdrae',
];
foreach ($ezra as $name) {
    $k = resolve($name);
    check("'$name' is 1 Esdras", same_book($k, '1-esdras'), var_export($k, true));
}
// Controls: the other half of the pair must keep its meaning.
foreach (['Nehemiah', 'Nehemias', 'Nehemia', 'Neh', '2 Esdrae', 'II Esdrae', '2 Esdras', 'Buch Nehemia'] as $name) {
    $k = resolve($name);
    check("'$name' is still 2 Esdras / Nehemias", same_book($k, '2-esdras-nehemias'), var_export($k, true));
}

// No table may point at a book that does not exist. The five German keys that did
// ("Esra", "Nehemia", "Ester", "Judit", "Sirach") are what q573 reported; the one
// that still does, Brief_Jeremias, has no book of its own in the Vulgate (Baruch 6),
// so it is the only key allowed to be absent from book_map.json.
$allowedOrphan = ['bibel' => ['Brief_Jeremias']];
foreach ($datasets as $ds) {
    $col = $ds === 'bibel' ? 'bibel' : 'bible';
    $valid = [];
    foreach ($bookMap as $entry) { if (isset($entry[$col])) { $valid[$entry[$col]] = true; } }
    $bad = [];
    foreach (array_unique(array_values($tables[$ds])) as $short) {
        if (!isset($valid[$short]) && !in_array($short, $allowedOrphan[$ds] ?? [], true)) { $bad[] = $short; }
    }
    check("every $ds abbreviation key is a book that exists", !$bad, implode(', ', $bad));
}

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
