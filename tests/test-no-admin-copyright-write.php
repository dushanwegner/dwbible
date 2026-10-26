<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   No code path of the plugin writes under dwbible_data_dir(), and no
 *         admin page offers to edit a data/<set>/copyright.md.
 * WHY:    BJX27 made the public footers print data/<set>/copyright.md word
 *         for word, but wp-admin -> LP Bible -> Footers could still overwrite
 *         bible/ and bibel/ copyright.md on production, behind git's back
 *         (BJX33). The footer is changed in dwbibledata, reviewed in git.
 * HOW:    No WordPress. Reads the plugin's PHP source: every statement that
 *         calls a filesystem writer (file_put_contents, fwrite, rename, copy,
 *         unlink, mkdir, wp_mkdir_p, touch, fopen in a writing mode ...) is
 *         flagged when it names copyright.md or dwbible_data_dir(), or uses a
 *         variable that dwbible_data_dir() was assigned to earlier in the
 *         same file. The Footers submenu must not be registered either.
 * INPUT:  dwbible.php, includes/*.php, tools/*.php
 * OUTPUT: exit 0 when nothing writes there.
 * RUN:    php tests/test-no-admin-copyright-write.php
 */

$root = dirname(__DIR__);
$files = array_merge([ "$root/dwbible.php" ], glob("$root/includes/*.php") ?: [], glob("$root/tools/*.php") ?: []);
$writers = '/\b(file_put_contents|fwrite|fputs|fputcsv|rename|copy|unlink|mkdir|rmdir|wp_mkdir_p|touch|move_uploaded_file|put_contents)\s*\(|\bfopen\s*\([^;]*[\'"][wacx]\+?b?[\'"]/';

$fails = 0;
function check(string $what, bool $ok, string $detail = '') {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    if (!$ok) { $fails++; }
}

foreach ($files as $f) {
    $src = (string) file_get_contents($f);
    $rel = substr($f, strlen($root) + 1);
    $bad = [];
    foreach (explode(';', $src) as $i => $stmt) {
        if (!preg_match($writers, $stmt)) { continue; }
        $touches = stripos($stmt, 'copyright') !== false || strpos($stmt, 'dwbible_data_dir') !== false;
        if (!$touches && preg_match_all('/\$(\w+)/', $stmt, $m)) {
            $before = substr($src, 0, strpos($src, $stmt));
            foreach (array_unique($m[1]) as $v) {
                if (preg_match('/\$' . $v . '\s*=[^;]*dwbible_data_dir\s*\(/', $before)) { $touches = true; break; }
            }
        }
        if ($touches) { $bad[] = trim(preg_replace('/\s+/', ' ', $stmt)); }
    }
    check("$rel writes nothing under dwbible_data_dir()", !$bad, $bad ? substr($bad[0], 0, 120) : '');
}

$main = (string) file_get_contents("$root/dwbible.php");
check('no Footers submenu is registered', strpos($main, 'dwbible_footers') === false);
$admin = (string) file_get_contents("$root/includes/class-dwbible-admin-settings.php");
check('the settings class has no footer save handler', strpos($admin, 'dwbible_footer_save_all') === false);

exit($fails ? 1 : 0);
