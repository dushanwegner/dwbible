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

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
