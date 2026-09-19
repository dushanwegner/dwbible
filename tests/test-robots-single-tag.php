<?php
/**
 * WHAT:    Asserts the Latin-only surface's noindex signal is added through
 *          WordPress's `wp_robots` filter, not a raw second `<meta name="robots">`
 *          echoed alongside WP core's own one.
 * WHY:     WP core already prints one robots meta tag (via `wp_robots`, hooked to
 *          `wp_head` at priority 1) carrying `max-image-preview:large`. A plugin
 *          that ALSO echoes `<meta name="robots" content="noindex, follow">` on
 *          `wp_head` produces TWO same-named meta tags on one page — invalid HTML,
 *          and a real risk for a site that explicitly welcomes AI crawlers
 *          (robots.txt: ChatGPT-User, Claude-User, PerplexityBot …): many simple
 *          parsers read only the FIRST `<meta name="robots">` match and would see
 *          `max-image-preview:large` only, missing noindex entirely, on every one
 *          of the ~5,500 /latin/ pages this is meant to keep out of an index.
 *          Filtering `wp_robots` instead lets WP core MERGE the directive into its
 *          own single tag — proven live on this site: `/?s=test` already renders
 *          `content='noindex, follow, max-image-preview:large'` in one tag because
 *          WordPress core's own search-noindex uses this filter.
 * HOW:     Static source check — no WordPress. The function must not contain a
 *          raw `<meta name="robots"` echo, and must register on the `wp_robots`
 *          filter (quality loop tick 276).
 * RUN:     php tests/test-robots-single-tag.php
 * TESTED:  dwbible.php → DwBible_Plugin::noindex_latin_only()
 */

$src = file_get_contents(dirname(__DIR__) . '/dwbible.php');

$at = strpos($src, 'function noindex_latin_only(');
if ($at === false) { fwrite(STDERR, "cannot find noindex_latin_only()\n"); exit(2); }
$depth = 0;
for ($i = strpos($src, '{', $at); $i < strlen($src); $i++) {
    if ($src[$i] === '{') { $depth++; }
    elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
}
$fn = substr($src, $at, $i - $at + 1);

$fail = 0;

if (strpos($fn, '<meta name="robots"') !== false || strpos($fn, "<meta name='robots'") !== false) {
    fwrite(STDERR, "FAIL: noindex_latin_only() still echoes a raw <meta name=\"robots\"> tag — duplicates WP core's own\n");
    $fail++;
}

if (strpos($src, "add_filter('wp_robots'") === false && strpos($src, 'add_filter("wp_robots"') === false) {
    fwrite(STDERR, "FAIL: dwbible.php never registers on the wp_robots filter — noindex is not merged into WP core's tag\n");
    $fail++;
}

if ($fail === 0) {
    echo "PASS: noindex_latin_only() uses wp_robots, no duplicate meta tag\n";
    exit(0);
}
exit(1);
