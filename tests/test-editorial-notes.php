<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   The editorial notes stand where dwbibledata's notes.json puts them, in the page's
 *         language, between verse groups and never inside one: before Esther 10:4, under the
 *         chapter head of Esther 13 (with its link to 10:4) and of Psalm 22, and nowhere on a
 *         chapter that has none.
 * WHY:    q7766. A note is Latin Prayer's own words beside scripture; inside a verse group it
 *         would reach copy, share and the verse tools, which read that group as scripture.
 * HOW:    No WordPress: tests/wp-stubs.php loads the real plugin; whole chapters are rendered
 *         (render_multilingual_book) against dwbibledata's real notes.json, in English and
 *         Spanish. The expected wording is written here, not read from the data.
 * INPUT:  ../dwbibledata/data/ (or DWBIBLEDATA_DIR) — a dwbibledata with data/notes.json
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-editorial-notes.php
 */
require __DIR__ . '/wp-stubs.php';
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
if (!function_exists('dwi18n_current')) {
    function dwi18n_current() { return $GLOBALS['dwtest_lang'] ?? 'en'; }
}
if (!is_readable(dwbible_data_dir() . 'notes.json')) {
    fwrite(STDERR, 'no notes.json in ' . dwbible_data_dir() . "\n");
    exit(2);
}

$failures = 0; $passes = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failures, &$passes) {
    if ($ok) { $passes++; echo "  ok   $what\n"; return; }
    $failures++; echo "FAIL   $what", $detail !== '' ? " — $detail" : '', "\n";
};

$page = function (string $lang, string $slug, string $book, int $ch): string {
    $GLOBALS['dwtest_lang'] = $lang;
    $GLOBALS['dwtest_query_vars'] = [DwBible_Plugin::QV_SLUG => $slug, DwBible_Plugin::QV_BOOK => $book, DwBible_Plugin::QV_CHAPTER => (string) $ch];
    ob_start();
    $m = new ReflectionMethod('DwBible_Plugin', 'render_multilingual_book');
    $m->setAccessible(true);
    $m->invoke(null, $book, $slug);
    return (string) ob_get_clean();
};

/** [[place, label, text, href|null, index in the page], …] for every note on the page. */
$notes = function (string $html): array {
    preg_match_all('#<aside class="dwbible-note dwbible-note--([a-z]+)" role="note" lang="[a-z]+" data-note="[^"]*"><span class="dwbible-note__label">([^<]*)</span>(.*?)</aside>#s', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($m as $n) {
        $href = preg_match('#href="([^"]+)"#', $n[3][0], $h) ? $h[1] : null;
        $out[] = [$n[1][0], $n[2][0], html_entity_decode(strip_tags($n[3][0]), ENT_QUOTES), $href, $n[0][1]];
    }
    return $out;
};

// Esther 10, English: one note, before verse 4's group and after verse 3's.
$html = $page('en', 'latin-bible', 'esther', 10);
$n = $notes($html);
$check('Esther 10 (en): one note', count($n) === 1, 'got ' . count($n));
$check('Esther 10 (en): it stands before a verse', ($n[0][0] ?? '') === 'verse');
$check('Esther 10 (en): its label is "Note"', ($n[0][1] ?? '') === 'Note');
$check('Esther 10 (en): it says where the Greek parts stand', strpos($n[0][2] ?? '', 'the Greek parts of Esther stand here, after chapter 10. Modern Bibles place them within chapters 1 to 10.') !== false, $n[0][2] ?? '');
$v3 = strpos($html, 'dwbible-interlinear-verse--v3"');
$v4 = strpos($html, 'dwbible-interlinear-verse--v4"');
$at = $n[0][4] ?? -1;
$check('Esther 10 (en): between the groups of verses 3 and 4', $v3 !== false && $v4 !== false && $v3 < $at && $at < $v4);
$start3 = strrpos(substr($html, 0, (int) $v3), '<div');
$group3 = substr($html, (int) $start3, $at - (int) $start3);
$check('Esther 10 (en): verse 3\'s group is closed before the note (never inside a verse)', substr_count($group3, '<div') === substr_count($group3, '</div>'));

// Esther 10, Spanish: the same place, the Spanish words and label.
$n = $notes($page('es', 'latin-spanish', 'esther', 10));
$check('Esther 10 (es): the label is "Nota"', ($n[0][1] ?? '') === 'Nota');
$check('Esther 10 (es): the Spanish text', strpos($n[0][2] ?? '', 'las partes griegas de Ester están aquí') !== false, $n[0][2] ?? '');

// Esther 13: the chapter-head echo, before verse 1, its "10:4" a link to Esther 10:4.
$html = $page('en', 'latin-bible', 'esther', 13);
$n = $notes($html);
$check('Esther 13 (en): one note, under the chapter head', count($n) === 1 && $n[0][0] === 'chapter');
$check('Esther 13 (en): before verse 1', ($n[0][4] ?? PHP_INT_MAX) < strpos($html, 'dwbible-interlinear-verse--v1"'));
$check('Esther 13 (en): the echo text', ($n[0][2] ?? '') === 'Chapters 11 to 16: the Greek parts of Esther. See the note at 10:4.', $n[0][2] ?? '');
$check('Esther 13 (en): "10:4" links to Esther 10:4', (bool) preg_match('#/esther/10:4/?$#', (string) ($n[0][3] ?? '')), (string) ($n[0][3] ?? 'no link'));

// Psalm 22: the family row fills both numbers.
$n = $notes($page('en', 'latin-bible', 'psalms', 22));
$check('Psalm 22 (en): "Numbered 22 here …; 23 in most modern Bibles."', ($n[0][2] ?? '') === 'Numbered 22 here, as in the Vulgate; 23 in most modern Bibles.', $n[0][2] ?? '');
$n = $notes($page('en', 'latin-bible', 'psalms', 113));
$check('Psalm 113 (en): one Vulgate psalm is two Hebrew ones', strpos($n[0][2] ?? '', '114–115 in most modern Bibles') !== false, $n[0][2] ?? '');

// Places with no note.
$check('Psalm 5 (en): no note (Vulgate and Hebrew agree)', $notes($page('en', 'latin-bible', 'psalms', 5)) === []);
$check('Esther 9 (en): no note', $notes($page('en', 'latin-bible', 'esther', 9)) === []);

// Book heads come from the family keyed by book.
$lines = DwBible_Notes::lines('3-kings', 'book');
$check('3 Regum: the book-name line', ($lines[0]['text'] ?? '') === 'In the Vulgate this book is called 3 Regum. Most modern Bibles call it 1 Kings.', $lines[0]['text'] ?? '');
$check('Esther 1: no chapter note', DwBible_Notes::lines('esther', 'chapter', 1) === []);

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
