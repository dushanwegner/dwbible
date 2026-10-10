<?php
/**
 * WHAT:   Every book on every index page stands under its own heading — and
 *         the same heading in every edition — whatever order number the
 *         edition's dataset gave it.
 * WHY:    build_index_html put books under headings by RANGES of the order
 *         number. The Italian dataset numbers ~27 books differently from the
 *         Latin one, so /it/biblia/ showed the two Maccabees under "Wisdom
 *         Books" and Wisdom and Ecclesiasticus under "Prophets" (q7380).
 * HOW:    No WordPress: tests/wp-stubs.php loads the real plugin and the page
 *         is built for each of the eleven index slugs. The expected heading of
 *         a book is read from the LATIN dataset's own index.csv (its order
 *         number, the Vulgate's order) against the heading ranges below, which
 *         are written here and not taken from the plugin.
 * INPUT:  ../dwbibledata/data/<dataset>/html/index.csv (or DWBIBLEDATA_DIR)
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-index-headings.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wp-stubs.php';

$failures = 0; $passes = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failures, &$passes) {
    if ($ok) { $passes++; echo "  ok   $what\n"; return; }
    $failures++; echo "FAIL   $what", $detail !== '' ? " — $detail" : '', "\n";
};

// The Vulgate's order (the Latin dataset's order numbers) -> heading.
$ranges = [
    [1, 5, 'Pentateuch'], [6, 19, 'Historical Books'], [20, 26, 'Wisdom Books'], [27, 46, 'Prophets'],
    [47, 50, 'Gospels'], [51, 65, 'Acts & Letters'], [66, 73, 'Catholic Epistles & Apocalypse'],
];
$expected = [];   // canonical key => heading
$fh = fopen(dwbible_data_dir() . 'latin/html/index.csv', 'r');
fgetcsv($fh, 0, ',', '"', '');
while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if (count($r) < 4) { continue; }
    $key = DwBible_Plugin::key_from_any_book_slug(DwBible_Plugin::slugify($r[1]));
    foreach ($ranges as [$lo, $hi, $label]) {
        if ((int) $r[0] >= $lo && (int) $r[0] <= $hi && $key !== null) { $expected[$key] = $label; }
    }
}
fclose($fh);
$check('the Latin index names 73 books by key', count($expected) === 73, 'got ' . count($expected));

/** heading => [book key, …] in page order. */
$page = function (string $slug): array {
    $GLOBALS['dwtest_query_vars'] = [DwBible_Plugin::QV_SLUG => $slug];
    $m = new ReflectionMethod('DwBible_Plugin', 'build_index_html');
    $html = $m->invoke(null);
    $out = [];
    foreach (array_slice(preg_split('#(?=<section class="dwbible-category">)#', $html), 1) as $sec) {
        preg_match('#rowlist-head">([^<]*)<#', $sec, $h);
        preg_match_all('#<a href="([^"]*)" class="dwbible-tile#', $sec, $a);
        $keys = [];
        foreach ($a[1] as $u) { $keys[] = DwBible_Plugin::key_from_any_book_slug(basename(rtrim($u, '/'))) ?? ('?' . $u); }
        $out[html_entity_decode($h[1])] = $keys;
    }
    return $out;
};

$latin_page = $page('latin');
foreach (['latin', 'latin-bible', 'latin-bibel', 'latin-spanish', 'latin-french', 'latin-italian',
          'bible', 'bibel', 'spanish', 'french', 'italian'] as $slug) {
    $p = $page($slug);
    $all = array_merge(...array_values($p));
    $check("$slug: the page lists 73 books, each once", count($all) === 73 && count(array_unique($all)) === 73, 'got ' . count($all));
    $bad = [];
    foreach ($p as $heading => $keys) {
        foreach ($keys as $k) {
            if (($expected[$k] ?? null) !== $heading) { $bad[] = "$k under \"$heading\" (belongs under \"" . ($expected[$k] ?? '?') . '")'; }
        }
    }
    $check("$slug: every book stands under its own heading", !$bad, implode('; ', $bad));
    $check("$slug: headings and books in the order of the Latin page", $p === $latin_page);
}

echo "\n", $failures ? "failed $failures of " . ($passes + $failures) . "\n" : "passed $passes, failed 0\n";
exit($failures ? 1 : 0);
