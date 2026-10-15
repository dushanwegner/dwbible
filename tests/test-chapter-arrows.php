<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   The chapter arrows — the two at the top (.dwbible-ctl-prev/next) and
 *         the two of the pager at the foot — carry the real address of the
 *         previous and next chapter in the served HTML, in the same language
 *         and view, across book bounds; the Bible's first chapter has no back
 *         arrow to follow and its last no forward one.
 * WHY:    Under a combo slug (latin-bible) the book order and the chapter
 *         counts came back empty, so every arrow on every interlinear chapter
 *         was href="#" and did nothing (DW, 15 Oct 2026, q7734). The old code
 *         also wrapped around: Genesis 1 went back to the Apocalypse.
 * HOW:    No WordPress: tests/wp-stubs.php loads the real plugin and whole
 *         chapter pages are rendered (render_multilingual_book) in English and
 *         German. The expected addresses are written here.
 * INPUT:  ../dwbibledata/data/ (or DWBIBLEDATA_DIR)
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-chapter-arrows.php
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

/** The four arrows of a chapter page: class => href (null = the anchor has no href), plus 'disabled' classes. */
$arrows = function (string $slug, string $book, int $ch): array {
    $GLOBALS['dwtest_query_vars'] = [DwBible_Plugin::QV_SLUG => $slug, DwBible_Plugin::QV_BOOK => $book, DwBible_Plugin::QV_CHAPTER => (string) $ch];
    ob_start();
    $m = new ReflectionMethod('DwBible_Plugin', 'render_multilingual_book');
    $m->setAccessible(true);
    $m->invoke(null, $book, $slug);
    $html = (string) ob_get_clean();
    $out = [];
    foreach (['dwbible-ctl-prev', 'dwbible-ctl-next', 'pager__prev', 'pager__next'] as $cls) {
        if (!preg_match('#<a((?:(?!>).)*\bclass="[^"]*\b' . preg_quote($cls, '#') . '\b[^"]*"(?:(?!>).)*)>#s', $html, $a)) { $out[$cls] = 'MISSING'; continue; }
        $out[$cls] = preg_match('#\bhref="([^"]*)"#', $a[1], $h) ? $h[1] : null;
        $out[$cls . ':disabled'] = (bool) preg_match('#class="[^"]*\bis-disabled\b#', $a[1]) && strpos($a[1], 'aria-disabled="true"') !== false;
    }
    return $out;
};

$u = fn(string $slug, string $book, int $ch) => "https://stub.test/$slug/$book/$ch/";
$cases = [
    // [what, slug, url book, chapter, expected prev (null = none), expected next (null = none)]
    ['English Leviticus 5, inside a book',          'latin-bible', 'leviticus',   5,  $u('latin-bible', 'leviticus', 4),    $u('latin-bible', 'leviticus', 6)],
    ['English Leviticus 1, a book\'s first chapter', 'latin-bible', 'leviticus',  1,  $u('latin-bible', 'exodus', 40),      $u('latin-bible', 'leviticus', 2)],
    ['German Leviticus 1, a book\'s first chapter',  'latin-bibel', 'leviticus',  1,  $u('latin-bibel', 'exodus', 40),      $u('latin-bibel', 'leviticus', 2)],
    ['English Exodus 40, a book\'s last chapter',    'latin-bible', 'exodus',     40, $u('latin-bible', 'exodus', 39),      $u('latin-bible', 'leviticus', 1)],
    ['English Genesis 1, the Bible\'s first',        'latin-bible', 'genesis',    1,  null,                                 $u('latin-bible', 'genesis', 2)],
    ['English Apocalypse 22, the Bible\'s last',     'latin-bible', 'apocalypsis', 22, $u('latin-bible', 'apocalypsis', 21), null],
    ['German Apocalypse 22, the Bible\'s last',      'latin-bibel', 'apocalypsis', 22, $u('latin-bibel', 'apocalypsis', 21), null],
];
foreach ($cases as [$what, $slug, $book, $ch, $prev, $next]) {
    $a = $arrows($slug, $book, $ch);
    foreach (['dwbible-ctl-prev' => $prev, 'pager__prev' => $prev, 'dwbible-ctl-next' => $next, 'pager__next' => $next] as $cls => $want) {
        $got = $a[$cls];
        if ($want === null) {
            $check("$what: $cls has no href and is drawn disabled", $got === null && $a[$cls . ':disabled'], 'got ' . var_export($got, true));
        } else {
            $check("$what: $cls goes to $want", $got === $want, 'got ' . var_export($got, true));
            $check("$what: $cls is not drawn disabled", !$a[$cls . ':disabled']);
        }
    }
}

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
