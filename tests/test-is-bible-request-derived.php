<?php
/**
 * WHAT:   is_bible_request() recognises a dataset slug from the derived list
 *         (DwBible_Plugin::all_slugs(), i.e. the dwbible_slugs option), not
 *         from a hardcoded six.
 * WHY:    It was the last copy of ['bible','bibel','latin','spanish','french',
 *         'italian'] in the plugin: add a dataset to the option and the router,
 *         sitemap and self-test knew it, while this still said "not a Bible
 *         request" for its single-language page (q244).
 *
 * RUN:    php tests/test-is-bible-request-derived.php
 *         (stubs only; set DWBIBLEDATA_DIR=<dir with a data/ folder> if
 *         ../dwbibledata is not there)
 * TESTED: dwbible.php → DwBible_Plugin::is_bible_request()
 */
if (!getenv('DWBIBLEDATA_DIR') && !is_dir(dirname(__DIR__, 2) . '/dwbibledata/data')) {
    $d = sys_get_temp_dir() . '/dwbible-test-data-' . getmypid();
    mkdir($d . '/data', 0777, true);
    putenv('DWBIBLEDATA_DIR=' . $d);
}
require __DIR__ . '/wp-stubs.php';

$fail = 0;
function ib_check($cond, $msg) { global $fail; if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $msg\n"); } }

$m = new ReflectionMethod('DwBible_Plugin', 'is_bible_request');
$m->setAccessible(true);
function ib_request(ReflectionMethod $m, string $slug): bool {
    $GLOBALS['dwtest_query_vars'] = [DwBible_Plugin::QV_SLUG => $slug];
    return $m->invoke(null);
}

// The default six still are.
$GLOBALS['dwtest_options'] = [];
foreach (['bible', 'bibel', 'latin', 'spanish', 'french', 'italian'] as $s) {
    ib_check(ib_request($m, $s), "default dataset '$s' is a Bible request");
}
ib_check(ib_request($m, 'bible-latin'), 'a combination is one');
ib_check(!ib_request($m, 'contact'), 'an ordinary page is not one');
ib_check(!ib_request($m, 'greek'), "'greek' is not one while it is not a dataset");

// A dataset added to the option is, with no second edit.
$GLOBALS['dwtest_options'] = ['dwbible_slugs' => 'bible,bibel,latin,spanish,french,italian,greek'];
ib_check(in_array('greek', DwBible_Plugin::all_slugs(), true), "the derived list now holds 'greek'");
ib_check(ib_request($m, 'greek'), "'greek' is a Bible request once it is a dataset");
ib_check(!ib_request($m, 'contact'), 'and an ordinary page still is not');

if (isset($d)) { @rmdir($d . '/data'); @rmdir($d); }
if ($fail) { fwrite(STDERR, "$fail failed\n"); exit(1); }
echo "ALL PASS\n";
