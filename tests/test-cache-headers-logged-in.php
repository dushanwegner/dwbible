<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   No Bible page and no Bible JSON is marked `public` for a logged-in
 *         reader; for a reader who is not logged in every place sends exactly
 *         the header it sent before.
 * WHY:    q7733: /en/biblia/leviticus/5/ and /en/biblia/, opened by DW while
 *         logged in, were sent `public, max-age=86400`; the Cloudflare rule
 *         "bible cache" kept them a day and served his render (admin bar, his
 *         name, four nonces) to every visitor.
 * HOW:    No WordPress, each case in a child process of this file:
 *         - the helper DwBible_Cache_Headers::send_public() alone, logged in,
 *           logged out, and with no is_user_logged_in() at all;
 *         - DwBible_Plugin::render_index() and render_multilingual_book()
 *           (Leviticus 5, the chapter that leaked) through tests/wp-stubs.php
 *           against dwbibledata's real tables, logged in, logged out, and the
 *           index with `?q=`;
 *         - the source: no header() in the plugin marks anything public but
 *           the helper, and each of the nine places calls the helper with the
 *           max-age it sent before.
 *         A header line is caught by DwBible_Cache_Headers::$emit, and
 *         nocache_headers() is a stub that counts its calls.
 * INPUT:  dwbibledata beside the plugin, or DWBIBLEDATA_DIR (render cases only).
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-cache-headers-logged-in.php
 * TESTED: includes/class-dwbible-cache-headers.php, dwbible.php → render_index(),
 *         includes/class-dwbible-render-interlinear.php → render_multilingual_book()
 */

const DWT_MARK = "\n@@DWT@@";

/** Stubs of the child: the reader's login, nocache_headers(), and the header catcher. */
function dwt_child_stubs(string $login): void {
    $GLOBALS['dwt'] = ['lines' => [], 'nocache' => 0];
    if ($login !== 'unknown' && $login !== 'bare') {
        $GLOBALS['dwt_logged_in'] = ($login === 'in');
        eval('function is_user_logged_in() { return $GLOBALS["dwt_logged_in"]; }');
    }
    if ($login !== 'bare') {
        eval('function nocache_headers() { $GLOBALS["dwt"]["nocache"]++; }');
    }
    DwBible_Cache_Headers::$emit = function ($line) { $GLOBALS['dwt']['lines'][] = $line; };
}

/** At the end of the child, print what was declared after the mark, whatever the code printed or however it ended. */
function dwt_report_at_shutdown(): void {
    register_shutdown_function(function () {
        while (ob_get_level()) { ob_end_clean(); }
        fwrite(STDOUT, DWT_MARK . json_encode($GLOBALS['dwt']));
    });
}

$mode = $argv[1] ?? '';
if ($mode === '--helper') {
    error_reporting(E_ALL & ~E_DEPRECATED);
    if (!defined('ABSPATH')) { define('ABSPATH', '/stub/'); }
    require dirname(__DIR__) . '/includes/class-dwbible-cache-headers.php';
    dwt_child_stubs($argv[2]);
    dwt_report_at_shutdown();
    $GLOBALS['dwt']['ret'] = DwBible_Cache_Headers::send_public((int) $argv[3]);
    exit;
}
if ($mode === '--index') {
    ob_start();
    require __DIR__ . '/wp-stubs.php';
    foreach (['status_header' => 'function status_header($s = 0) {}', 'wp_redirect' => 'function wp_redirect($u, $s = 302) { exit; }'] as $fn => $code) {
        if (!function_exists($fn)) { eval($code); }
    }
    dwt_child_stubs($argv[2]);
    $GLOBALS['dwtest_query_vars'][DwBible_Plugin::QV_SLUG] = 'latin-bible';
    $_GET = ($argv[3] ?? '') !== '' ? ['q' => $argv[3]] : [];
    dwt_report_at_shutdown();
    $m = new ReflectionMethod('DwBible_Plugin', 'render_index');
    $m->setAccessible(true);
    $m->invoke(null);
    $GLOBALS['dwt']['body'] = strlen((string) ob_get_contents());
    exit;
}

if ($mode === '--chapter') {
    ob_start();
    require __DIR__ . '/wp-stubs.php';
    foreach (['status_header' => 'function status_header($s = 0) {}', 'wp_redirect' => 'function wp_redirect($u, $s = 302) { exit; }'] as $fn => $code) {
        if (!function_exists($fn)) { eval($code); }
    }
    foreach (['absint' => 'function absint($v) { return abs((int) $v); }',
              'is_wp_error' => 'function is_wp_error($v) { return false; }'] as $fn => $code) {
        if (!function_exists($fn)) { eval($code); }
    }
    dwt_child_stubs($argv[2]);
    $GLOBALS['dwtest_query_vars'][DwBible_Plugin::QV_SLUG] = 'latin-bible';
    $GLOBALS['dwtest_query_vars'][DwBible_Plugin::QV_BOOK] = 'leviticus';
    $GLOBALS['dwtest_query_vars'][DwBible_Plugin::QV_CHAPTER] = '5';
    $_GET = [];
    dwt_report_at_shutdown();
    $m = new ReflectionMethod('DwBible_Plugin', 'render_multilingual_book');
    $m->setAccessible(true);
    $m->invoke(null, 'leviticus', 'latin-bible');
    $GLOBALS['dwt']['body'] = strlen((string) ob_get_contents());
    exit;
}

/** Run one case in a child; what it declared, or null when it printed no report. */
function dwt_run(array $args): ?array {
    $p = proc_open(array_merge([PHP_BINARY, __FILE__], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $at = strrpos($out, DWT_MARK);
    if ($at === false) { fwrite(STDERR, "child " . implode(' ', $args) . " printed no report:\n" . $out . $err . "\n"); return null; }
    return json_decode(substr($out, $at + strlen(DWT_MARK)), true);
}

$fails = 0; $n = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $fails, $n;
    $n++;
    if ($ok) { echo "  ok   $what\n"; } else { $fails++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function public_lines(?array $r): array {
    return array_values(array_filter($r['lines'] ?? [], fn($l) => stripos($l, 'public') !== false));
}
function shown(?array $r): string { return json_encode($r); }

echo "the helper, alone:\n";
$r = dwt_run(['--helper', 'out', '86400']);
check('logged out, 86400: exactly "Cache-Control: public, max-age=86400", no nocache_headers()',
    $r !== null && $r['lines'] === ['Cache-Control: public, max-age=86400'] && $r['nocache'] === 0 && $r['ret'] === true, shown($r));
$r = dwt_run(['--helper', 'out', '3600']);
check('logged out, 3600: exactly "Cache-Control: public, max-age=3600" (the saints sitemap and the book TOC)',
    $r !== null && $r['lines'] === ['Cache-Control: public, max-age=3600'] && $r['nocache'] === 0, shown($r));
$r = dwt_run(['--helper', 'in', '86400']);
check('logged in: nocache_headers() once, nothing public',
    $r !== null && $r['nocache'] === 1 && public_lines($r) === [] && $r['ret'] === false, shown($r));
$r = dwt_run(['--helper', 'unknown', '86400']);
check('no is_user_logged_in() (WordPress cannot say): nocache_headers(), nothing public',
    $r !== null && $r['nocache'] === 1 && public_lines($r) === [], shown($r));
$r = dwt_run(['--helper', 'bare', '86400']);
check('neither is_user_logged_in() nor nocache_headers(): "Cache-Control: private, no-store"',
    $r !== null && $r['lines'] === ['Cache-Control: private, no-store'], shown($r));

echo "render_index(), through tests/wp-stubs.php:\n";
$r = dwt_run(['--index', 'out']);
check('logged out: exactly "Cache-Control: public, max-age=86400", as before, and the page is drawn',
    $r !== null && $r['lines'] === ['Cache-Control: public, max-age=86400'] && $r['nocache'] === 0 && ($r['body'] ?? 0) > 1000, shown($r));
$r = dwt_run(['--index', 'in']);
check('logged in: nocache_headers(), nothing public, and the page is drawn',
    $r !== null && $r['nocache'] === 1 && public_lines($r) === [] && ($r['body'] ?? 0) > 1000, shown($r));
$r = dwt_run(['--index', 'out', 't563 no such book']);
check('logged out with ?q= the resolver cannot place: nocache_headers(), nothing public, as before',
    $r !== null && $r['nocache'] >= 1 && public_lines($r) === [], shown($r));
$r = dwt_run(['--index', 'in', 't563 no such book']);
check('logged in with ?q=: nocache_headers(), nothing public',
    $r !== null && $r['nocache'] >= 1 && public_lines($r) === [], shown($r));

echo "render_multilingual_book(), the chapter that leaked (leviticus 5, latin-bible):\n";
$r = dwt_run(['--chapter', 'out']);
check('logged out: exactly "Cache-Control: public, max-age=86400", as before, and the chapter is drawn',
    $r !== null && $r['lines'] === ['Cache-Control: public, max-age=86400'] && $r['nocache'] === 0 && ($r['body'] ?? 0) > 1000, shown($r));
$r = dwt_run(['--chapter', 'in']);
check('logged in: nocache_headers(), nothing public, and the chapter is drawn',
    $r !== null && $r['nocache'] === 1 && public_lines($r) === [] && ($r['body'] ?? 0) > 1000, shown($r));

echo "the source:\n";
$root = dirname(__DIR__);
$files = array_merge([$root . '/dwbible.php'], glob($root . '/includes/*.php'));
$raw = [];
foreach ($files as $f) {
    if (basename($f) === 'class-dwbible-cache-headers.php') { continue; }
    foreach (file($f) as $i => $line) {
        if (preg_match('/\bheader\s*\(\s*[\'"]\s*Cache-Control\s*:[^\'"]*(public|max-age=[1-9])/i', $line)) {
            $raw[] = substr($f, strlen($root) + 1) . ':' . ($i + 1);
        }
    }
}
check('no header() outside the helper marks a response public or gives it a max-age', $raw === [], implode(', ', $raw));

/** The body of a function, by its name, in one file. */
function fn_body(string $file, string $name): string {
    $src = file_get_contents($file);
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) { return ''; }
    $depth = 0;
    for ($i = strpos($src, '{', $m[0][1]); $i < strlen($src); $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
    }
    return substr($src, $m[0][1], $i - $m[0][1] + 1);
}
// Each place and the max-age it sent before q7733: for a reader who is not logged in, nothing changes.
$places = [
    ['dwbible.php', 'handle_sitemap', 86400, 1],
    ['dwbible.php', 'handle_sitemap_index', 86400, 1],
    ['dwbible.php', 'handle_sitemap_prayers', 86400, 1],
    ['dwbible.php', 'handle_sitemap_saints', 3600, 1],
    ['dwbible.php', 'render_index', 86400, 1],
    ['dwbible.php', 'render_book', 86400, 1],
    ['includes/class-dwbible-render-interlinear.php', 'render_multilingual_book', 86400, 1],
    ['includes/class-dwbible-render-book-toc.php', 'render_book_toc', 3600, 1],
    ['includes/class-dwbible-json-api.php', 'send_json_headers', 86400, 1],
];
foreach ($places as [$file, $name, $age, $count]) {
    $body = fn_body($root . '/' . $file, $name);
    $calls = preg_match_all('/DwBible_Cache_Headers::send_public\(\s*(\d+)\s*\)/', $body, $mm);
    check("{$file} {$name}() calls send_public({$age})",
        $calls === $count && array_map('intval', $mm[1]) === array_fill(0, $count, $age),
        $body === '' ? 'function not found' : 'calls: ' . json_encode($mm[1]));
}
$body = fn_body($root . '/dwbible.php', 'render_index');
check('render_index() keeps nocache_headers() for ?q=', (bool) preg_match('/\$_GET\[\'q\'\].*?nocache_headers\(\)/s', $body));
foreach (['dwbible.php' => "plugin_dir_path(__FILE__) . 'includes/class-dwbible-cache-headers.php'",
          'includes/class-dwbible-render-interlinear.php' => "__DIR__ . '/class-dwbible-cache-headers.php'",
          'includes/class-dwbible-render-book-toc.php' => "__DIR__ . '/class-dwbible-cache-headers.php'",
          'includes/class-dwbible-json-api.php' => "__DIR__ . '/class-dwbible-cache-headers.php'"] as $file => $path) {
    check("{$file} requires the helper itself (a half-pulled OPcache cannot call a class never loaded)",
        strpos(file_get_contents($root . '/' . $file), 'require_once ' . $path . ';') !== false);
}

echo "\n" . ($fails === 0 ? "passed {$n}, failed 0\n" : 'passed ' . ($n - $fails) . ", failed {$fails}\n");
exit($fails === 0 ? 0 : 1);
