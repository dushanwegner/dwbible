<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   The names the site's own Italian, Spanish and French editions print
 *         for the books of Samuel and Kings answer by the Kings/Samuel rule:
 *         "Primo dei Re", "Primero de los Reyes", "Premier des Rois" (and
 *         their second) and the digit forms "1 Re", "1 Reyes", "1 Rois" name
 *         both 1 Kings and 1 Samuel -> BOOK_AMBIGUOUS where both fit, the one
 *         book where only one does; third and fourth are 3 and 4 Kings.
 * WHY:    q7379: "Primo dei Re 2:11" resolved silently to the third book of
 *         Kings, and the Spanish and French names were not recognised at all.
 * HOW:    The plugin's own /bible-ref.json resolver (DwBible_Plugin::
 *         serve_reference_json), no WordPress, against the real tables of
 *         dwbibledata: each query runs in a child process of this file.
 * INPUT:  dwbibledata beside the plugin, or DWBIBLEDATA_DIR.
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-kings-names-resolver.php
 */

if (($argv[1] ?? '') === '--one') {
    // Child: resolve one query, print {"status":…,"body":…}.
    ob_start();
    require __DIR__ . '/wp-stubs.php';
    foreach (['status_header', 'header', 'header_remove', 'nocache_headers', 'sanitize_key', 'wp_json_encode', 'site_url'] as $fn) {
        if (function_exists($fn)) { continue; }
        eval([
            'status_header'   => 'function status_header($s = 0) { $GLOBALS["dwt_status"] = (int) $s; }',
            'header'          => 'function header($h = "") {}',
            'header_remove'   => 'function header_remove($h = "") {}',
            'nocache_headers' => 'function nocache_headers() {}',
            'sanitize_key'    => 'function sanitize_key($k) { return strtolower(preg_replace("/[^a-zA-Z0-9_\\-]/", "", (string) $k)); }',
            'wp_json_encode'  => 'function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }',
            'site_url'        => 'function site_url($p = "") { return "https://latinprayer.org" . $p; }',
        ][$fn]);
    }
    if (!function_exists('set_query_var')) { function set_query_var($k, $v) { $GLOBALS['dwtest_query_vars'][$k] = $v; } }
    $GLOBALS['dwt_status'] = 200;
    $_GET = ['q' => $argv[2]] + (isset($argv[3]) && $argv[3] !== '' ? ['numbering' => $argv[3]] : []);
    register_shutdown_function(function () {
        $b = ob_get_contents();
        while (ob_get_level()) { ob_end_clean(); }
        fwrite(STDOUT, json_encode(['status' => $GLOBALS['dwt_status'], 'body' => $b]));
    });
    $m = new ReflectionMethod('DwBible_Plugin', 'serve_reference_json');
    $m->setAccessible(true);
    $m->invoke(null);
    exit;
}

function resolve(string $q, string $numbering = ''): array {
    $cmd = [PHP_BINARY, __FILE__, '--one', $q, $numbering];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $r = json_decode($out, true);
    if (!is_array($r)) { return ['status' => -1, 'json' => [], 'error' => null]; }
    $j = json_decode($r['body'], true) ?: [];
    return ['status' => $r['status'], 'json' => $j, 'error' => $j['error'] ?? null];
}

$fails = 0; $n = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $fails, $n;
    $n++;
    if ($ok) { echo "  ok   $what\n"; } else { $fails++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

function book_of(array $r): string { return (string) ($r['json']['ref']['book']['key'] ?? ''); }

// Both books fit -> BOOK_AMBIGUOUS, never a silent pick.
foreach (['Primo dei Re 2:11', 'Secondo dei Re 2:11', 'Primero de los Reyes 2:11', 'Segundo de los Reyes 2:11',
          'Premier des Rois 2:11', 'Deuxième des Rois 2:11', '1 Re 2:11', '1 Reyes 2:11', '1 Rois 2:11',
          'Primo dei Re', 'Primero de los Reyes', 'Premier des Rois', '1 Kings 2:11'] as $q) {
    $r = resolve($q);
    check("$q -> BOOK_AMBIGUOUS", $r['error'] === 'BOOK_AMBIGUOUS', "status {$r['status']} error " . var_export($r['error'], true));
}

// Only one book has the passage -> that book.
foreach ([
    'Primo dei Re 17:45'        => '1-kings-samuel',
    'Primero de los Reyes 17:45' => '1-kings-samuel',
    'Premier des Rois 17:45'    => '1-kings-samuel',
    '1 Re 17:45'                => '1-kings-samuel',
    'Primero de los Reyes 25:1' => '1-kings-samuel',
] as $q => $key) {
    $r = resolve($q);
    check("$q -> $key", $r['error'] === null && book_of($r) === $key, "status {$r['status']} error " . var_export($r['error'], true) . ' book ' . book_of($r));
}

// Third and fourth: the Vulgate's own numbers, understood in every spelling.
foreach ([
    'Terzo dei Re 2:11' => '3-kings', 'Quarto dei Re 2:11' => '4-kings',
    'Tercero de los Reyes 2:11' => '3-kings', 'Cuarto de los Reyes 2:11' => '4-kings',
    'Troisième des Rois 2:11' => '3-kings', 'Quatrième des Rois 2:11' => '4-kings',
    '3 Reyes 2:11' => '3-kings', '4 Rois 2:11' => '4-kings', '3 Re 2:11' => '3-kings',
    // German stays as it was: its edition names the books the modern way.
    '1 Könige 2:11' => '3-kings', 'Erstes Buch der Könige 2:11' => '3-kings', '2 Könige 2:11' => '4-kings',
    // Samuel by its own names is untouched.
    'Primo di Samuele 2:11' => '1-kings-samuel', '1 Samuel 2:11' => '1-kings-samuel',
    'Genesis 1:1' => 'genesis',
] as $q => $key) {
    $r = resolve($q);
    check("$q -> $key", $r['error'] === null && book_of($r) === $key, "status {$r['status']} error " . var_export($r['error'], true) . ' book ' . book_of($r));
}

echo "\n" . ($fails ? "failed $fails of $n\n" : "passed $n, failed 0\n");
exit($fails ? 1 : 0);
