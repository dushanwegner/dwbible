<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   Each edition's footer line says what data/bible-sources.json says
 *         for it, word for word, in every page language: the edition's name,
 *         then its notice in that language. The footer and the file cannot
 *         drift (BJX27, BKA23).
 * WHY:    The footer was a hand-written copy since q7734, then the English
 *         rights files; now the one file the app reads too.
 * HOW:    No WordPress (tests/wp-stubs.php). The expected text is read here
 *         from the file itself, not through the plugin; the page is rendered
 *         through DwBible_Sources::render for every dataset in every language.
 * INPUT:  data/bible-sources.json
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-sources-rights.php
 */
require __DIR__ . '/wp-stubs.php';

$failures = 0; $passes = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failures, &$passes) {
    if ($ok) { $passes++; echo "  ok   $what\n"; return; }
    $failures++; echo "FAIL   $what", $detail !== '' ? " — $detail" : '', "\n";
};

$path = dirname(__DIR__) . '/data/bible-sources.json';
$file = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
$check('data/bible-sources.json is there and is JSON', is_array($file), $path);
$editions = $file['editions'] ?? [];
$sets = ['latin', 'bible', 'bibel', 'spanish', 'french', 'italian'];
$norm = function (string $t): string { return trim(preg_replace('/\s+/u', ' ', $t)); };

$check('the file holds exactly the six sets, in order', array_keys($editions) === $sets, implode(',', array_keys($editions)));
$check('EDITIONS holds exactly the six sets', DwBible_Sources::EDITIONS === $sets, implode(',', (array) DwBible_Sources::EDITIONS));

foreach ($sets as $ds) {
    $name = (string) ($editions[$ds]['name'] ?? '');
    $check("$ds: the file names the edition", $name !== '');
    foreach (['en', 'de', 'es', 'fr', 'it'] as $lang) {
        $notice = $norm((string) ($editions[$ds]['notice'][$lang] ?? ''));
        $check("$ds/$lang: the file holds a notice", $notice !== '');
        $html = DwBible_Sources::render([$ds], $lang);
        preg_match('#<li class="dw-sources-foot__item" data-source="' . $ds . '">(.*?)</li>#s', $html, $m);
        $line = html_entity_decode(strip_tags($m[1] ?? ''), ENT_QUOTES);
        $check("$ds/$lang: the line is the edition's name, then the file's notice, word for word", $line === $name . ' · ' . $notice, "got '$line'");
    }
}

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
