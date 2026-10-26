<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   Each edition's footer line says what its data/<set>/copyright.md
 *         says, and no more: the line is the file's text, reservations
 *         included, so the footer and the six rights files cannot drift (BJX27).
 * WHY:    DwBible_Sources::EDITIONS was a hand-written copy since q7734 and
 *         said "public domain" flatly where the files record a reservation.
 * HOW:    No WordPress (tests/wp-stubs.php). The expected text is read here from
 *         the file itself, not from the plugin; the page is rendered through
 *         DwBible_Sources::render for every dataset in every page language.
 * INPUT:  ../dwbibledata/data/<set>/copyright.md (or DWBIBLEDATA_DIR)
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-sources-rights.php
 */
require __DIR__ . '/wp-stubs.php';

$failures = 0; $passes = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failures, &$passes) {
    if ($ok) { $passes++; echo "  ok   $what\n"; return; }
    $failures++; echo "FAIL   $what", $detail !== '' ? " — $detail" : '', "\n";
};

$root = rtrim(dwbible_data_dir(), '/');
$sets = ['latin', 'bible', 'bibel', 'spanish', 'french', 'italian'];
$norm = function (string $t): string { return trim(preg_replace('/\s+/u', ' ', $t)); };

$check('EDITIONS holds exactly the six sets', array_keys(DwBible_Sources::EDITIONS) === $sets, implode(',', array_keys(DwBible_Sources::EDITIONS)));

foreach ($sets as $ds) {
    $path = "$root/$ds/copyright.md";
    $check("$ds: copyright.md is there", is_readable($path), $path);
    $md = is_readable($path) ? (string) file_get_contents($path) : '';
    // The file's body: everything after its "# heading" line.
    $body = $norm(preg_replace('/\A\s*#[^\n]*\n/', '', $md));
    $check("$ds: the file has a body", $body !== '');
    foreach (['en', 'de', 'es', 'fr', 'it'] as $lang) {
        $html = DwBible_Sources::render([$ds], $lang);
        preg_match('#<li class="dw-sources-foot__item" data-source="' . $ds . '">(.*?)</li>#s', $html, $m);
        $line = html_entity_decode(strip_tags($m[1] ?? ''), ENT_QUOTES);
        $name = DwBible_Sources::EDITIONS[$ds]['name'];
        $check("$ds/$lang: the line is the edition's name, then the file's text, word for word", $line === $name . ' · ' . $body, "got '$line'");
    }
    // Nothing is claimed beyond the file: "public domain" only where the file says it.
    $line = html_entity_decode(strip_tags(DwBible_Sources::render([$ds], 'en')), ENT_QUOTES);
    $check("$ds: 'public domain' appears in the footer only if it appears in the file", (stripos($line, 'public domain') === false) || (stripos($body, 'public domain') !== false));
    $check("$ds: no 'everywhere' claim", stripos($line, 'everywhere') === false || stripos($body, 'everywhere') !== false);
}

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
