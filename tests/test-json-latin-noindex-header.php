<?php
/**
 * WHAT:    Asserts the Latin-only JSON API (`/latin/{book}/{chapter}.json`)
 *          sends its own `X-Robots-Tag: noindex` header, matching the HTML
 *          twin's noindex (DwBible_Plugin::noindex_latin_only()).
 * WHY:     noindex_latin_only() runs on the `wp_robots` filter, which fires on
 *          `wp_head` — a hook the JSON path (serve_json_file(), which calls
 *          `exit` before WordPress ever renders a template) never reaches. So
 *          the Latin-only text was noindexed on the HTML page but had zero
 *          exclusion signal on its own JSON twin, even though the site's own
 *          rationale for the HTML noindex ("the Vulgate is on all five locale
 *          pages already, so letting this one be indexed would have the site
 *          competing with itself for the same text") applies identically to
 *          the JSON. robots.txt does not block it and this API is deliberately
 *          crawlable (llms.txt invites AI retrieval bots) — noindex via
 *          X-Robots-Tag only asks search engines not to rank the URL; it does
 *          not block a live agent fetch, so parity costs nothing (quality loop
 *          tick 282).
 * HOW:     Static source check — no WordPress. serve_json_file() must send an
 *          X-Robots-Tag header gated on the 'latin' slug.
 * RUN:     php tests/test-json-latin-noindex-header.php
 * TESTED:  includes/class-dwbible-json-api.php → DwBible_JSON_API_Trait::serve_json_file()
 */

$src = file_get_contents(dirname(__DIR__) . '/includes/class-dwbible-json-api.php');

$at = strpos($src, 'function serve_json_file(');
if ($at === false) { fwrite(STDERR, "cannot find serve_json_file()\n"); exit(2); }
$depth = 0;
for ($i = strpos($src, '{', $at); $i < strlen($src); $i++) {
    if ($src[$i] === '{') { $depth++; }
    elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
}
$fn = substr($src, $at, $i - $at + 1);

$fail = 0;

if (!preg_match('/\$slug\s*===\s*[\'"]latin[\'"][^;]*\{[^}]*header\s*\(\s*[\'"]X-Robots-Tag:\s*noindex/s', $fn)) {
    fwrite(STDERR, "FAIL: serve_json_file() never sends X-Robots-Tag: noindex for the 'latin' slug\n");
    $fail++;
}

if ($fail === 0) {
    echo "PASS: serve_json_file() sends X-Robots-Tag: noindex on the Latin-only JSON twin\n";
    exit(0);
}
exit(1);
