<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   Every Bible page names the editions it shows, at its foot, in the
 *         page's language: one summary line naming them, which opens into one
 *         line per edition with its facts and its source link.
 * WHY:    The foot read copyright.md from the page's data folder, and the
 *         interlinear pages latinprayer.org serves have a combo slug
 *         (latin-bible) with no folder of its own — so no Bible page printed a
 *         word about its editions (q7734).
 * HOW:    No WordPress: tests/wp-stubs.php loads the real plugin, and whole
 *         pages are rendered — the index (render_index) and a chapter
 *         (render_multilingual_book) — in English and in German. The expected
 *         names and links are written here, not read from the plugin.
 * INPUT:  ../dwbibledata/data/ (or DWBIBLEDATA_DIR)
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-sources-foot.php
 */
require __DIR__ . '/wp-stubs.php';
// The page code sends Cache-Control with header(); held output keeps PHP from
// warning that the headers went out with the first ok line.
ob_start();
foreach (['absint', 'status_header', 'nocache_headers', 'is_wp_error', 'get_permalink', 'esc_js', 'wp_strip_all_tags', 'wp_json_encode'] as $fn) {
    if (function_exists($fn)) { continue; }
    eval([
        'absint'            => 'function absint($n) { return abs((int) $n); }',
        'status_header'     => 'function status_header($s = 0) {}',
        'nocache_headers'   => 'function nocache_headers() {}',
        'is_wp_error'       => 'function is_wp_error($x) { return false; }',
        'get_permalink'     => 'function get_permalink(...$a) { return ""; }',
        'esc_js'            => 'function esc_js($s) { return addslashes((string) $s); }',
        'wp_strip_all_tags' => 'function wp_strip_all_tags($s) { return strip_tags((string) $s); }',
        'wp_json_encode'    => 'function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }',
    ][$fn]);
}

$failures = 0; $passes = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failures, &$passes) {
    if ($ok) { $passes++; echo "  ok   $what\n"; return; }
    $failures++; echo "FAIL   $what", $detail !== '' ? " — $detail" : '', "\n";
};

/** The served HTML of the index ('' book) or of a chapter, under a combo slug. */
$page = function (string $slug, string $book = '', int $ch = 0): string {
    $GLOBALS['dwtest_query_vars'] = [DwBible_Plugin::QV_SLUG => $slug];
    ob_start();
    if ($book === '') {
        $m = new ReflectionMethod('DwBible_Plugin', 'render_index');
        $m->setAccessible(true);
        $m->invoke(null);
    } else {
        $GLOBALS['dwtest_query_vars'][DwBible_Plugin::QV_BOOK] = $book;
        $GLOBALS['dwtest_query_vars'][DwBible_Plugin::QV_CHAPTER] = (string) $ch;
        $m = new ReflectionMethod('DwBible_Plugin', 'render_multilingual_book');
        $m->setAccessible(true);
        $m->invoke(null, $book, $slug);
    }
    return (string) ob_get_clean();
};

/** The foot's parts: [count of feet, summary text, [dataset => line text], [hrefs]]. */
$foot = function (string $html): array {
    $n = substr_count($html, '<footer class="dw-sources-foot"');
    if (!preg_match('#<footer class="dw-sources-foot"[^>]*>(.*?)</footer>#s', $html, $m)) { return [$n, '', [], []]; }
    $f = $m[1];
    $summary = preg_match('#<details class="dw-sources-foot__details"><summary class="dw-sources-foot__summary">(.*?)</summary>#s', $f, $s) ? html_entity_decode(strip_tags($s[1]), ENT_QUOTES) : '';
    $lines = [];
    preg_match_all('#<li class="dw-sources-foot__item" data-source="([a-z]+)">(.*?)</li>#s', $f, $li, PREG_SET_ORDER);
    foreach ($li as $l) { $lines[$l[1]] = html_entity_decode(strip_tags($l[2]), ENT_QUOTES); }
    preg_match_all('#href="([^"]+)"#', $f, $h);
    return [$n, $summary, $lines, $h[1]];
};

$cases = [
    // [what, slug, book, chapter, summary, datasets, a word of the vernacular line, its link]
    ['English index',          'latin-bible', '',          0, 'Bibles on this page: Vulgata Clementina · Douay-Rheims', ['latin', 'bible'], 'Project Gutenberg', 'https://www.gutenberg.org/ebooks/8300'],
    ['German index',           'latin-bibel', '',          0, 'Bibeln auf dieser Seite: Vulgata Clementina · Menge-Bibel', ['latin', 'bibel'], 'übersetzt von Hermann Menge (1841–1939)', 'https://github.com/renehamburger/Menge-Bibel'],
    ['English Leviticus 5',    'latin-bible', 'leviticus', 5, 'Bibles on this page: Vulgata Clementina · Douay-Rheims', ['latin', 'bible'], 'Project Gutenberg', 'https://www.gutenberg.org/ebooks/8300'],
    ['German Leviticus 5',     'latin-bibel', 'leviticus', 5, 'Bibeln auf dieser Seite: Vulgata Clementina · Menge-Bibel', ['latin', 'bibel'], 'in Deutschland seit 2010 gemeinfrei', 'https://github.com/renehamburger/Menge-Bibel'],
];
foreach ($cases as [$what, $slug, $book, $ch, $summary, $datasets, $word, $link]) {
    $html = $page($slug, $book, $ch);
    [$n, $sum, $lines, $hrefs] = $foot($html);
    $check("$what: one sources foot", $n === 1, "got $n");
    $check("$what: its closed line names the editions in the page's language", $sum === $summary, "got '$sum'");
    $check("$what: one line per edition shown, Latin first", array_keys($lines) === $datasets, 'got ' . implode(',', array_keys($lines)));
    $check("$what: the vernacular line says '$word'", strpos($lines[$datasets[1]] ?? '', $word) !== false, 'got ' . ($lines[$datasets[1]] ?? '(none)'));
    $check("$what: the vernacular line links its source", in_array($link, $hrefs, true), 'got ' . implode(' ', $hrefs));
    $check("$what: the Latin line names 1592 and makes up no translator", strpos($lines['latin'] ?? '', '1592') !== false && !preg_match('/translated|übersetzt/u', $lines['latin'] ?? ''), 'got ' . ($lines['latin'] ?? '(none)'));
    if ($book !== '') {
        $check("$what: the foot comes after the chapter's pager", strpos($html, 'pager--reader') !== false && strpos($html, 'pager--reader') < strpos($html, 'dw-sources-foot'));
    }
}

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
