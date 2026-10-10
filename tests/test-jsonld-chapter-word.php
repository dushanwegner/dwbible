<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   The third breadcrumb item of a chapter page's structured data names
 *         the chapter in the PAGE's language — Chapter, Kapitel, Capítulo,
 *         Chapitre, Capitolo, Caput — and the schema.org @type values stay as
 *         they are.
 * WHY:    class-dwbible-jsonld.php wrote the literal "Chapter {$chapter}" for
 *         every language, so the German page's breadcrumb read "Chapter 3"
 *         beside "Johannes 3" (q580). Crawlers read it, visitors do not.
 * HOW:    No WordPress: tests/wp-stubs.php loads the real plugin and the real
 *         tables; chapter_jsonld() is private and is called by reflection.
 * INPUT:  ../dwbibledata/data/ (or DWBIBLEDATA_DIR)
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-jsonld-chapter-word.php
 */
require __DIR__ . '/wp-stubs.php';

$failures = 0; $passes = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failures, &$passes) {
    if ($ok) { $passes++; echo "  ok   $what\n"; return; }
    $failures++; echo "FAIL   $what", $detail !== '' ? " — $detail" : '', "\n";
};

$m = new ReflectionMethod('DwBible_JsonLd', 'chapter_jsonld');
$words = ['en' => 'Chapter', 'de' => 'Kapitel', 'es' => 'Capítulo', 'fr' => 'Chapitre', 'it' => 'Capitolo', 'la' => 'Caput'];
// dataset slug => page language
$pages = ['bible' => 'en', 'bibel' => 'de', 'spanish' => 'es', 'french' => 'fr', 'italian' => 'it', 'latin' => 'la'];

foreach ($pages as $dataset => $lang) {
    $ld    = $m->invoke(null, $dataset, 'ioannes', 3, $lang, 'Test Edition');
    $graph = $ld['@graph'];
    $items = $graph[0]['itemListElement'];
    $check("$dataset ($lang): the third breadcrumb item is \"{$words[$lang]} 3\"", ($items[2]['name'] ?? '') === $words[$lang] . ' 3', (string) ($items[2]['name'] ?? 'missing'));
}

// The schema names do not change, in any language.
$ld = $m->invoke(null, 'bibel', 'ioannes', 3, 'de', 'Menge');
$check('@type of the list is BreadcrumbList', $ld['@graph'][0]['@type'] === 'BreadcrumbList');
$check('@type of each crumb is ListItem', array_unique(array_column($ld['@graph'][0]['itemListElement'], '@type')) === ['ListItem']);
$check('@type of the content is Chapter', $ld['@graph'][1]['@type'] === 'Chapter');
$check('the Chapter\'s own name is the book and the number', preg_match('/ 3$/', $ld['@graph'][1]['name']) === 1, $ld['@graph'][1]['name']);

// One source for the word: the book page's chapter rows use it too.
foreach ($words as $lang => $word) {
    $check("DwBible_Plugin::chapter_word('$lang') is \"$word\"", DwBible_Plugin::chapter_word($lang) === $word);
}
$check('an unknown language falls back to "Chapter"', DwBible_Plugin::chapter_word('xx') === 'Chapter');

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
