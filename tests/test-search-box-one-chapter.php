<?php
/**
 * WHAT:   The Bible's search box (`/<language>/biblia/?q=…`) sends a bare number
 *         after one of the five ONE-CHAPTER books (Abdias, Philemon, 2 John,
 *         3 John, Jude) to that VERSE — where /bible-ref.json lands — and a
 *         bare range ("Jude 3-5") to those verses; books of several chapters
 *         behave as before.
 * WHY:    q585: `?q=Jude 3` redirected to the whole book and lost the verse,
 *         while the resolver read it as Jude 1:3.
 * HOW:    No WordPress. DwBible_Plugin::maybe_redirect_query() is called in a
 *         child process of this file against dwbibledata's real tables; the
 *         redirect (wp_redirect + exit) is caught and printed.
 * INPUT:  dwbibledata beside the plugin, or DWBIBLEDATA_DIR.
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-search-box-one-chapter.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (($argv[1] ?? '') === '--one') {
    ob_start();
    require __DIR__ . '/wp-stubs.php';
    foreach (['nocache_headers', 'wp_redirect'] as $fn) {
        if (function_exists($fn)) { continue; }
        eval([
            'nocache_headers' => 'function nocache_headers() {}',
            'wp_redirect'     => 'function wp_redirect($u, $s = 302) { echo "REDIRECT " . $s . " " . $u; exit; }',
        ][$fn]);
    }
    $GLOBALS['dwtest_query_vars'][DwBible_Plugin::QV_SLUG] = $argv[3] ?? 'latin-bible';
    $_GET = ['q' => $argv[2]];
    register_shutdown_function(function () {
        $b = ob_get_contents();
        while (ob_get_level()) { ob_end_clean(); }
        fwrite(STDOUT, $b === '' ? 'NOREDIRECT' : $b);
    });
    $m = new ReflectionMethod('DwBible_Plugin', 'maybe_redirect_query');
    $m->setAccessible(true);
    $m->invoke(null);
    exit;
}

/** Where the box sends $q: the path after the host, or '' when it does not redirect. */
function box(string $q, string $slug = 'latin-bible'): string {
    $p = proc_open([PHP_BINARY, __FILE__, '--one', $q, $slug], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return preg_match('#^REDIRECT 302 https://stub\.test(.*)$#', $out, $m) ? $m[1] : '';
}

$fails = 0; $n = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $fails, $n;
    $n++;
    if ($ok) { echo "  ok   $what\n"; } else { $fails++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "the five one-chapter books: the box lands where the resolver lands:\n";
foreach ([['Abdias', 'abdias'], ['Philemon', 'philemonem'], ['2 John', '2-ioannis'], ['3 John', '3-ioannis'], ['Jude', 'iudae']] as [$name, $slug]) {
    $base = "/latin-bible/{$slug}/";
    $r = box("{$name} 3");
    check("{$name} 3 -> verse 1:3", $r === $base . '1:3/', "got '{$r}'");
    $r = box("{$name} 1:3");
    check("{$name} 1:3 -> verse 1:3", $r === $base . '1:3/', "got '{$r}'");
    $r = box("{$name} 1");
    check("{$name} 1 -> verse 1:1 (the resolver reads a bare number as a verse)", $r === $base . '1:1/', "got '{$r}'");
    $r = box($name);
    check("{$name} -> the whole book", $r === $base, "got '{$r}'");
    $r = box("{$name} 3-5");
    check("{$name} 3-5 -> verses 1:3-5", $r === $base . '1:3-5/', "got '{$r}'");
}
$r = box('Jude 99');
check('Jude 99 (no such verse) -> the book, as a verse past the end always was', $r === '/latin-bible/iudae/1/' || $r === '/latin-bible/iudae/', "got '{$r}'");

echo "books of several chapters: as before:\n";
foreach ([['John 3', '/latin-bible/ioannes/3/'], ['John 3:16', '/latin-bible/ioannes/3:16/'], ['John', '/latin-bible/ioannes/'], ['John 3:16-18', '/latin-bible/ioannes/3:16-18/'], ['John 99', '/latin-bible/ioannes/']] as [$q, $want]) {
    $r = box($q);
    check("{$q} -> {$want}", $r === $want, "got '{$r}'");
}
$r = box('Genesis 1-3');
check('Genesis 1-3 (chapters or verses?) still does not redirect', $r === '', "got '{$r}'");

echo "\n" . ($fails === 0 ? "passed {$n}, failed 0\n" : 'passed ' . ($n - $fails) . ", failed {$fails}\n");
exit($fails === 0 ? 0 : 1);
