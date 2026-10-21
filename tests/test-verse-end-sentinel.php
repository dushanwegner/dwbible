<?php
/**
 * WHAT:   A range end past the chapter - the "-999" the lectionary writes into the first
 *         leg of a cross-chapter citation ("Jn 18:1-19:42" is "18:1-999" + "19:1-42") -
 *         reads as the chapter's last verse wherever a reference is shown: the teaser's
 *         "Open in Bible" URL (passage_teaser) and the helper the page title uses
 *         (clamp_verse_end). John 18:1-999 must read John 18:1-40.
 * WHY:    q591 moved the four Passions to single cross-chapter citations. The Mass row's
 *         link and the page it opens said "John 18:1-999" (title, og:title, URL). The
 *         passage endpoints always clamped it; the shown reference did not.
 *
 * RUN:    php tests/test-verse-end-sentinel.php
 *         (stubs only; needs the data: ../dwbibledata/data, or DWBIBLEDATA_DIR=<dir with data/>)
 * TESTED: dwbible.php -> DwBible_Plugin::clamp_verse_end(), DwBible_Plugin::passage_teaser()
 */
if (!getenv('DWBIBLEDATA_DIR') && !is_dir(dirname(__DIR__, 2) . '/dwbibledata/data')) {
    fwrite(STDERR, "SKIP: no dwbibledata/data next to this plugin; set DWBIBLEDATA_DIR\n");
    exit(2);
}
require __DIR__ . '/wp-stubs.php';
if (!function_exists('absint')) { function absint($n) { return abs((int) $n); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('add_query_arg')) { function add_query_arg($k, $v, $u) { return $u . (strpos($u, '?') === false ? '?' : '&') . urlencode($k) . '=' . urlencode($v); } }
if (!defined('MONTH_IN_SECONDS')) define('MONTH_IN_SECONDS', 2592000);

$fail = 0;
function vs_check($got, $want, $what) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) $fail++;
    echo ($ok ? 'PASS' : 'FAIL'), "  $what", $ok ? '' : "  got " . var_export($got, true) . ", want " . var_export($want, true), "\n";
}
$end  = fn($book, $ch, $vt) => DwBible_Plugin::clamp_verse_end($book, $ch, $vt);
$url  = fn($book, $ch, $v) => DwBible_Plugin::passage_teaser($book, $ch, $v, 'en')['url'];

// The four Passions' first legs and the other cross-chapter readings.
vs_check($end('john', 18, 999), 40, 'John 18:1-999 -> 40');
vs_check($end('matthew', 26, 999), 75, 'Matthew 26:36-999 -> 75');
vs_check($end('mark', 14, 999), 72, 'Mark 14:32-999 -> 72');
vs_check($end('luke', 22, 999), 71, 'Luke 22:39-999 -> 71');
vs_check($end('2-corinthians', 10, 999), 18, '2 Corinthians 10:17-999 -> 18');
vs_check($end('ioannes', 18, 999), 40, 'the Vulgate spelling of the book clamps the same');
// Nothing else moves.
vs_check($end('john', 18, 30), 30, 'a range end inside the chapter stays');
vs_check($end('john', 18, 40), 40, 'the last verse stays');
vs_check($end('john', 99, 999), 999, 'a chapter that does not exist: unchanged');
vs_check($end('nosuchbook', 1, 999), 999, 'a book that does not exist: unchanged');

// The teaser's link: the Mass page's "Open in Bible".
vs_check($url('john', 18, '1-999'), 'https://stub.test/en/biblia/ioannes/18:1-40/', 'teaser URL for John 18:1-999');
vs_check($url('matthew', 26, '36-999'), 'https://stub.test/en/biblia/matthaeus/26:36-75/', 'teaser URL for Matthew 26:36-999');
vs_check($url('john', 3, '16-18'), 'https://stub.test/en/biblia/ioannes/3:16-18/', 'an ordinary range is untouched');
vs_check($url('john', 3, '16'), 'https://stub.test/en/biblia/ioannes/3:16/', 'a single verse is untouched');

// The request: /ioannes/18:1-999/ arrives as chapter 18, verses 1-999 (query vars), and
// everything built from it afterwards - the page title, the card - reads 18:1-40.
$set = function ($ch, $vf, $vt) {
    $GLOBALS['dwtest_query_vars'] = [DwBible_Plugin::QV_CHAPTER => $ch, DwBible_Plugin::QV_VFROM => $vf, DwBible_Plugin::QV_VTO => $vt];
};
$set(18, 1, 999);
DwBible_Plugin::clamp_requested_verse_end('john', 'ioannes', 18);
vs_check(get_query_var(DwBible_Plugin::QV_VTO), 40, 'request 18:1-999: the range-end query var becomes 40');
vs_check(DwBible_Plugin::verse_range_title('John', 18, (int) get_query_var(DwBible_Plugin::QV_VFROM), (int) get_query_var(DwBible_Plugin::QV_VTO)), 'John 18:1-40', 'the page title reads John 18:1-40');
$set(18, 1, 30);
DwBible_Plugin::clamp_requested_verse_end('john', 'ioannes', 18);
vs_check(get_query_var(DwBible_Plugin::QV_VTO), 30, 'request 18:1-30 is left alone');
$set(3, 16, 0);
DwBible_Plugin::clamp_requested_verse_end('john', 'ioannes', 3);
vs_check(get_query_var(DwBible_Plugin::QV_VTO), 0, 'a single verse (no range end) is left alone');

// The title itself.
vs_check(DwBible_Plugin::verse_range_title('John', 3, 16, 0), 'John 3:16', 'title: a verse');
vs_check(DwBible_Plugin::verse_range_title('John', 3, 16, 16), 'John 3:16', 'title: 16-16 is a verse');
vs_check(DwBible_Plugin::verse_range_title('John', 3, 16, 12), 'John 3:16', 'title: a range that runs backwards is the verse');
vs_check(DwBible_Plugin::verse_range_title('John', 3, 16, 18), 'John 3:16-18', 'title: a range');
vs_check(DwBible_Plugin::verse_range_title('John', 3, 16, 0, "  For God\nso loved  "), 'John 3:16 (»For God so loved«)', 'title: the snippet, whitespace folded');
vs_check(mb_strlen(DwBible_Plugin::verse_range_title('John', 3, 16, 0, str_repeat('ab ', 60))), mb_strlen('John 3:16 (»') + 81 + mb_strlen('«)'), 'title: the snippet is cut at 80 characters and an ellipsis');

// og:url and the card's picture come from the request address.
$cu = fn($u) => DwBible_Plugin::clamp_range_in_url($u, 'john');
vs_check($cu('https://stub.test/en/biblia/ioannes/18:1-999/'), 'https://stub.test/en/biblia/ioannes/18:1-40/', 'og:url: 18:1-999 -> 18:1-40');
vs_check($cu('https://stub.test/en/biblia/ioannes/18:1-999'), 'https://stub.test/en/biblia/ioannes/18:1-40', 'og:url: without the trailing slash');
vs_check($cu('https://stub.test/en/biblia/ioannes/18:1-999/?x=1'), 'https://stub.test/en/biblia/ioannes/18:1-40/?x=1', 'og:url: the query string stays');
vs_check($cu('https://stub.test/en/biblia/ioannes/18:1-30/'), 'https://stub.test/en/biblia/ioannes/18:1-30/', 'og:url: an inside range is untouched');
vs_check($cu('https://stub.test/en/biblia/ioannes/3:16/'), 'https://stub.test/en/biblia/ioannes/3:16/', 'og:url: a single verse is untouched');
vs_check($cu('https://stub.test/en/biblia/ioannes/'), 'https://stub.test/en/biblia/ioannes/', 'og:url: the book page is untouched');

// The card's facts, driven for real: site_card_page_facts() takes the request URL and hands it back clamped.
$GLOBALS['dwtest_query_vars'] = [DwBible_Plugin::QV_FLAG => 1, DwBible_Plugin::QV_SLUG => 'biblia', DwBible_Plugin::QV_BOOK => 'ioannes',
    DwBible_Plugin::QV_CHAPTER => 18, DwBible_Plugin::QV_VFROM => 1, DwBible_Plugin::QV_VTO => 999];
$facts = DwBible_Plugin::site_card_page_facts(['url' => 'https://stub.test/en/biblia/ioannes/18:1-999/']);
vs_check($facts['url'] ?? null, 'https://stub.test/en/biblia/ioannes/18:1-40/', 'site_card_page_facts: og:url reads 18:1-40');

// The page renderers need WordPress; offline, read their code (comments stripped, so a commented-out
// call does not count) and require that each goes through the tested clamp and title functions.
// render_multilingual_book() is the interlinear page - /en/biblia/... is served by it.
$code = function ($file) {
    $o = '';
    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $o .= is_array($t) ? $t[1] : $t;
    }
    return $o;
};
$rb = $code(__DIR__ . '/../dwbible.php');
preg_match('/private static function render_book\(\$slug\) \{(.*?)\n    private static function render_404/s', $rb, $mm);
vs_check(strpos($mm[1] ?? '', 'self::clamp_requested_verse_end(') !== false, true, 'render_book clamps the request through clamp_requested_verse_end()');
vs_check(strpos($mm[1] ?? '', 'self::verse_range_title(') !== false, true, 'render_book builds the title through verse_range_title()');
$il = $code(__DIR__ . '/../includes/class-dwbible-render-interlinear.php');
$p1 = strpos($il, 'self::clamp_requested_verse_end(');
$p2 = strpos($il, 'DwBible_Reference::parse_chapter_and_range(');
vs_check($p1 !== false && $p2 !== false && $p1 < $p2, true, 'the interlinear page clamps the request before it reads the range');

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
