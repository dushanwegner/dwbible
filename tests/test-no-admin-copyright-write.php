<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   Every call in the plugin's PHP that can write, delete or move a file
 *         (or run a shell command) is on a short, reviewed list, and the Footers
 *         screen and its save handler are gone. A call that is not on the list
 *         fails, wherever it points: under dwbible_data_dir(), under
 *         DWBIBLEDATA_DIR, through a helper, a variable, a wrapper or an
 *         argument. A new writer needs a reviewer to add it here, with the
 *         reason it is allowed.
 *         What it proves: the TEXT of every such call written in the source is
 *         listed. What it does not follow: a writer name held in a variable
 *         ($fn = 'unlink'; $fn($p)), error_log() with type 3, DOMDocument::save
 *         and SimpleXMLElement::asXML, backtick shell commands, and an
 *         allowlisted call whose variable is pointed somewhere else
 *         ($cache_file = ...; imagepng($im, $cache_file)): the list pins the
 *         text of a call, not its target. A reviewer reads the diff for those.
 * WHY:    BJX27 made the public footers print data/<set>/copyright.md word
 *         for word, but wp-admin -> LP Bible -> Footers could still overwrite
 *         bible/ and bibel/ copyright.md on production, behind git's back
 *         (BJX33). The footer is changed in dwbibledata, reviewed in git.
 * HOW:    No WordPress. Tokenises every PHP file of the plugin (comments are
 *         ignored; \name and name are the same) and collects every call to a
 *         function that writes, deletes or moves a file or an image
 *         (file_put_contents, fwrite, rename, copy, unlink, mkdir, wp_mkdir_p,
 *         touch, unzip_file, copy_dir, gzopen, imagepng ..., fopen unless its
 *         second argument is a literal read mode), every put_contents and
 *         writeImage(s) method call, every use of $wp_filesystem and every
 *         WP_Filesystem( call (all of its methods write), and SplFileObject,
 *         SplTempFileObject and ZipArchive. A method declaration with a
 *         writer's name (function link($slug)) is not a call. A string literal
 *         that is the first argument of call_user_func, call_user_func_array,
 *         array_map, array_walk, array_filter or usort and names a writer
 *         counts too. Each call, as normalised text, must equal an entry of
 *         $allowed below; an entry that no longer matches also fails.
 * INPUT:  the whole plugin root, recursively, except tests/ and vendor/ at the
 *         root, and .git/ and node_modules/ anywhere
 * OUTPUT: exit 0 when every writer is on the list.
 * RUN:    php tests/test-no-admin-copyright-write.php
 */

$root = dirname(__DIR__);
$files = [];
$skip_root = [ 'tests' => 1, 'vendor' => 1 ];
$skip_any = [ '.git' => 1, 'node_modules' => 1 ];
$walk = function (string $dir, bool $top) use (&$walk, &$files, $skip_root, $skip_any) {
    foreach (scandir($dir) as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $path = "$dir/$name";
        if (is_dir($path)) {
            if (isset($skip_any[$name]) || ($top && isset($skip_root[$name]))) { continue; }
            $walk($path, false);
        } elseif (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), [ 'php', 'phtml', 'inc' ], true)) {
            $files[] = $path;
        }
    }
};
$walk($root, true);
sort($files);

$writers = array_flip([
    'file_put_contents', 'fwrite', 'fputs', 'fputcsv', 'ftruncate', 'rename', 'copy', 'unlink', 'mkdir', 'rmdir',
    'wp_mkdir_p', 'wp_delete_file', 'wp_delete_file_from_directory', 'touch', 'chmod', 'symlink', 'link', 'tempnam', 'tmpfile',
    'move_uploaded_file', 'wp_handle_upload', 'wp_handle_sideload', 'media_handle_upload', 'media_handle_sideload', 'download_url',
    'unzip_file', 'copy_dir', 'wp_upload_bits', 'gzopen', 'gzwrite', 'wp_filesystem',
    'imagepng', 'imagejpeg', 'imagewebp', 'imagegif', 'imageavif', 'imagebmp', 'imagewbmp', 'imagexbm', 'imagegd', 'imagegd2',
    'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open',
    'put_contents', 'writeimage', 'writeimages',
]);   // lower case; a method call counts only for put_contents and writeImage(s); wp_filesystem is the WP_Filesystem( call
$classes = array_flip([ 'splfileobject', 'spltempfileobject', 'ziparchive' ]);

// file => [ normalised call => why it is allowed ]. Matched as exact text, once per entry.
$allowed = [
    'dwbible.php' => [
        'unlink($f)' => 'clear_sitemap_cache deletes the plugin\'s own data/cache/sitemap-*.xml; it writes no data file',
    ],
    'includes/class-dwbible-admin-export.php' => [
        "fopen('php://output', 'wb')" => 'the export streams to the browser (php://output), not to a file',
        'fwrite($out, $bible_name . "\n")' => 'writes to php://output, the download stream',
        'fwrite($out, "slug|book|chapter|verse|text\n")' => 'writes to php://output, the download stream',
        'fwrite($out, $line_out)' => 'writes to php://output, the download stream',
    ],
    'includes/class-dwbible-og-image.php' => [
        'wp_mkdir_p($dir)' => 'creates uploads/dwbible-og-cache/, the OG image cache',
        'unlink($p)' => 'purges files of uploads/dwbible-og-cache/ (the cache-purge button)',
        'imagepng($im, $cache_file)' => 'writes a PNG into uploads/dwbible-og-cache/',
        'imagepng($im)' => 'no file argument: streams the PNG to the browser',
    ],
];

$fails = 0;
function check(string $what, bool $ok, string $detail = '') {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    if (!$ok) { $fails++; }
}

/** The calls of one file that write: [ [ function, normalised text, line ], ... ]. */
function writer_calls(string $src, array $writers, array $classes): array {
    $tok = token_get_all($src);
    $n = count($tok);
    $text = function ($t) {
        if (!is_array($t)) { return $t; }
        return in_array($t[0], [ T_COMMENT, T_DOC_COMMENT ], true) ? ' ' : $t[1];
    };
    $norm = function (string $s) {
        $s = preg_replace('/\s+/', ' ', str_replace('@', '', $s));
        return trim(preg_replace([ '/\(\s+/', '/\s+\)/' ], [ '(', ')' ], $s));
    };
    $blank = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];
    $next = function (int $i) use ($tok, $n, $blank) {   // next token that is not blank or a comment
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tok[$j]) && in_array($tok[$j][0], $blank, true)) { continue; }
            return $j;
        }
        return -1;
    };
    $prev = function (int $i) use ($tok, $blank) {      // previous token that is not blank or a comment
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tok[$j]) && in_array($tok[$j][0], $blank, true)) { continue; }
            return $j;
        }
        return -1;
    };
    /** From the token $from, up to the bracket that closes the first '(' : [ text, [ argument texts ] ]. */
    $span = function (int $from) use ($tok, $n, $text) {
        $out = '';
        $args = [];
        $cur = '';
        $depth = 0;
        for ($j = $from; $j < $n; $j++) {
            $x = $text($tok[$j]);
            $out .= $x;
            if ($x === '(' || $x === '[' || $x === '{') {
                $depth++;
                if ($depth === 1) { continue; }
            } elseif ($x === ')' || $x === ']' || $x === '}') {
                if (--$depth === 0) { $args[] = trim($cur); break; }
            } elseif ($x === ',' && $depth === 1) {
                $args[] = trim($cur);
                $cur = '';
                continue;
            }
            if ($depth >= 1) { $cur .= $x; }
        }
        return [ $out, $args ];
    };
    $methods = [ 'put_contents' => 1, 'writeimage' => 1, 'writeimages' => 1 ];   // $wp_filesystem->put_contents(), Imagick
    $callbacks = [ 'call_user_func' => 1, 'call_user_func_array' => 1, 'array_map' => 1, 'array_walk' => 1, 'array_filter' => 1, 'usort' => 1 ];
    $names = [ T_STRING, T_NAME_FULLY_QUALIFIED ];
    $found = [];
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tok[$i])) { continue; }
        $kind = $tok[$i][0];
        $low = strtolower(ltrim($tok[$i][1], '\\'));
        $nx = $next($i);
        $paren = $nx >= 0 && $tok[$nx] === '(';
        $p = $prev($i);
        if ($p >= 0 && ($tok[$p] === '&' || (is_array($tok[$p]) && $tok[$p][1] === '&'))) { $p = $prev($p); }   // function &copy()
        $is_method = $p >= 0 && is_array($tok[$p]) && in_array($tok[$p][0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ], true);
        $declared = $p >= 0 && is_array($tok[$p]) && $tok[$p][0] === T_FUNCTION;   // a declaration, not a call
        if (in_array($kind, $names, true) && $paren && !$declared && (isset($writers[$low]) || $low === 'fopen') && (!$is_method || isset($methods[$low]))) {
            [ $out, $args ] = $span($i);
            $out = $norm($out);
            if ($low === 'fopen' && isset($args[1]) && preg_match('/^[\'"]r[bt]?[\'"]$/', $norm($args[1]))) { continue; }   // literal read mode
            $found[] = [ $low, $out, $tok[$i][2] ];
        } elseif (in_array($kind, $names, true) && isset($classes[$low]) && !$declared) {
            $found[] = [ $low, $tok[$i][1], $tok[$i][2] ];
        } elseif ($kind === T_VARIABLE && $tok[$i][1] === '$wp_filesystem') {   // every method of it writes
            $m = $nx >= 0 && is_array($tok[$nx]) && in_array($tok[$nx][0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ], true) ? $next($nx) : -1;
            $c = $m >= 0 ? $next($m) : -1;
            $out = $c >= 0 && $tok[$c] === '(' ? $norm($span($i)[0]) : '$wp_filesystem';
            $found[] = [ '$wp_filesystem', $out, $tok[$i][2] ];
        } elseif ($kind === T_CONSTANT_ENCAPSED_STRING && isset($writers[strtolower(trim($tok[$i][1], '\'"'))])
            && $p >= 0 && $tok[$p] === '(' && ($pp = $prev($p)) >= 0 && is_array($tok[$pp]) && isset($callbacks[strtolower(ltrim($tok[$pp][1], '\\'))])) {
            $found[] = [ 'string', $tok[$i][1], $tok[$i][2] ];   // call_user_func('unlink', ...), array_map('unlink', ...)
        }
    }
    return $found;
}


$seen = [];
foreach ($files as $f) {
    $rel = substr($f, strlen($root) + 1);
    $left = $allowed[$rel] ?? [];
    $bad = [];
    foreach (writer_calls((string) file_get_contents($f), $writers, $classes) as [ $fn, $call, $line ]) {
        $seen[$rel][$call] = ($seen[$rel][$call] ?? 0) + 1;
        if (isset($left[$call])) { unset($left[$call]); } else { $bad[] = "line $line: " . substr($call, 0, 100); }
    }
    check("$rel has no writer that is not on the list", !$bad, implode('; ', $bad));
}
foreach ($allowed as $rel => $entries) {
    foreach ($entries as $call => $why) {
        $count = $seen[$rel][$call] ?? 0;
        check("allowed writer is present exactly once: $rel: $call", $count === 1, "found $count times; remove or fix the entry");
    }
}

$main = (string) file_get_contents("$root/dwbible.php");
check('no Footers submenu is registered', strpos($main, 'dwbible_footers') === false);
$admin = (string) file_get_contents("$root/includes/class-dwbible-admin-settings.php");
check('the settings class has no footer save handler', strpos($admin, 'dwbible_footer_save_all') === false);

exit($fails ? 1 : 0);
