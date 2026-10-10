<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   A citation RANGE whose two ends sit under a different numbering is
 *         read end by end: "Mal 3:1-4:6" (the printed Vulgate's 4:1-6 is this
 *         site's 3:19-24) and "Ps 116:1-19" with numbering=hebrew (Vulgate
 *         114:1 to 115:10) resolve, say so, and drop and double no verse.
 * WHY:    q584 and q587: the mapping was applied to a citation, not to each end
 *         of a range, so both answered an error while each end resolved alone.
 * HOW:    The plugin's own /bible-ref.json resolver (DwBible_Plugin::
 *         serve_reference_json) is called with no WordPress, against the real
 *         tables of dwbibledata: each query runs in a child process of this
 *         file (the resolver ends the request with exit).
 * INPUT:  dwbibledata beside the plugin, or DWBIBLEDATA_DIR.
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-range-numbering.php
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

/** "c:v" of every verse of the Latin passage, in order. */
function verses(array $r): array {
    $ref = $r['json']['ref'] ?? [];
    $ch = $ref['chapter'] ?? 0;
    $out = [];
    foreach (($r['json']['passages']['la']['verses'] ?? []) as $v) { $out[] = ($v['chapter'] ?? $ch) . ':' . $v['verse']; }
    return $out;
}

$fails = 0; $n = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $fails, $n;
    $n++;
    if ($ok) { echo "  ok   $what\n"; } else { $fails++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "Malachias, a range across the numbering boundary:\n";
$r = resolve('Mal 3:1-4:6');
$v = verses($r);
check('Mal 3:1-4:6 resolves', $r['status'] === 200, "status {$r['status']} {$r['error']}");
check('Mal 3:1-4:6 is 3:1 to 3:24, 24 verses', count($v) === 24 && ($v[0] ?? '') === '3:1' && end($v) === '3:24', implode(',', array_slice($v, 0, 2)) . '… ' . count($v));
check('Mal 3:1-4:6 says the numbering was translated',
      (bool) preg_match('/3:19-24.*4:1-6/', (string) ($r['json']['_meta']['readAs'] ?? '')), (string) ($r['json']['_meta']['readAs'] ?? ''));
$r = resolve('Mal 3:19-4:6'); $v = verses($r);
check('Mal 3:19-4:6 is 3:19 to 3:24, 6 verses', $r['status'] === 200 && count($v) === 6 && $v[0] === '3:19' && end($v) === '3:24');
$r = resolve('Mal 2:1-4:6'); $v = verses($r);
check('Mal 2:1-4:6 is 2:1 to 3:24, 17 + 24 verses', $r['status'] === 200 && count($v) === 41 && $v[0] === '2:1' && end($v) === '3:24', count($v) . ' ' . $r['error']);
$r = resolve('Mal 3:20-4:1');
check('Mal 3:20-4:1 (mapped ends 3:20 to 3:19) is refused as backwards', $r['status'] === 400 && $r['error'] === 'RANGE_REVERSED', "{$r['status']} {$r['error']}");
$r = resolve('Mal 3:1-4:7'); $v = verses($r);
check('Mal 3:1-4:7 is clamped at 3:24 and says so (as Mal 4:7 alone is)', $r['status'] === 200 && end($v) === '3:24', "{$r['status']} {$r['error']}");
$r = resolve('Mal 4:1-5:3');
check('Mal 4:1-5:3 stays refused: chapter 5 does not exist', $r['status'] === 404 && $r['error'] === 'CHAPTER_NOT_FOUND', "{$r['status']} {$r['error']}");

echo "Malachias, the forms that already worked are the same:\n";
foreach ([['Mal 3:1', ['3:1']], ['Mal 4:6', ['3:24']], ['Mal 4:1-6', ['3:19', '3:20', '3:21', '3:22', '3:23', '3:24']], ['Mal 3:19-24', ['3:19', '3:20', '3:21', '3:22', '3:23', '3:24']], ['Mal 2:3-3:2', null]] as [$q, $want]) {
    $r = resolve($q); $v = verses($r);
    check("$q resolves", $r['status'] === 200 && ($want === null || $v === $want), implode(',', $v) . " {$r['error']}");
}

echo "Psalms in Hebrew numbering, a range that spans two Vulgate psalms:\n";
$r = resolve('Ps 116:1-19', 'hebrew'); $v = verses($r);
check('Ps 116:1-19 hebrew resolves', $r['status'] === 200, "status {$r['status']} {$r['error']}");
check('Ps 116:1-19 hebrew is 114:1 to 115:10, 19 verses (9 + 10)', count($v) === 19 && ($v[0] ?? '') === '114:1' && end($v) === '115:10' && $v[8] === '114:9' && $v[9] === '115:1', count($v) . ' ' . ($v[0] ?? '-') . '..' . (end($v) ?: '-'));
check('Ps 116:1-19 hebrew says the numbering was translated', (bool) preg_match('/Hebrew.*116.*Vulgate.*114.*115/s', (string) ($r['json']['_meta']['readAs'] ?? '')), (string) ($r['json']['_meta']['readAs'] ?? ''));
check('Ps 116:1-19 hebrew carries the psalm numbering block', ($r['json']['_meta']['psalmNumbering']['requested']['number'] ?? null) === 116);
$r = resolve('Ps 116:5-12', 'hebrew'); $v = verses($r);
check('Ps 116:5-12 hebrew is 114:5-9 and 115:1-3, 8 verses', $r['status'] === 200 && count($v) === 8 && $v[0] === '114:5' && end($v) === '115:3', count($v) . ' ' . $r['error']);
$r = resolve('Ps 147:1-20', 'hebrew'); $v = verses($r);
check('Ps 147:1-20 hebrew is 146:1-11 and 147:1-9, 20 verses', $r['status'] === 200 && count($v) === 20 && $v[0] === '146:1' && end($v) === '147:9', count($v) . ' ' . $r['error']);
$r = resolve('Ps 113:1-9', 'hebrew'); $v = verses($r);
check('Ps 113:1-9 hebrew is one Vulgate psalm: 112:1-9', $r['status'] === 200 && count($v) === 9 && $v[0] === '112:1' && end($v) === '112:9', "{$r['status']} {$r['error']}");

echo "Psalms: the forms that already worked are the same:\n";
$r = resolve('Ps 116:10-19', 'hebrew'); $v = verses($r);
check('Ps 116:10-19 hebrew is 115:1-10', $r['status'] === 200 && count($v) === 10 && $v[0] === '115:1' && end($v) === '115:10');
$r = resolve('Ps 9:1-10:18', 'hebrew'); $v = verses($r);
check('Ps 9:1-10:18 hebrew is 9:1-39', $r['status'] === 200 && count($v) === 39 && $v[0] === '9:1' && end($v) === '9:39');
$r = resolve('Ps 116:1-2'); $v = verses($r);
check('Ps 116:1-2 without numbering is Vulgate 116:1-2', $r['status'] === 200 && $v === ['116:1', '116:2'], "{$r['status']} {$r['error']}");
$r = resolve('Ps 116:19-1', 'hebrew');
check('Ps 116:19-1 hebrew stays refused as backwards', $r['status'] === 400 && $r['error'] === 'RANGE_REVERSED', "{$r['status']} {$r['error']}");
$r = resolve('Ps 300:1-3', 'hebrew');
check('Ps 300:1-3 hebrew stays refused: no such psalm', $r['status'] === 404 && $r['error'] === 'CHAPTER_NOT_FOUND', "{$r['status']} {$r['error']}");

echo "\n" . ($fails === 0 ? "passed {$n}, failed 0\n" : "passed " . ($n - $fails) . ", failed {$fails}\n");
exit($fails === 0 ? 0 : 1);
