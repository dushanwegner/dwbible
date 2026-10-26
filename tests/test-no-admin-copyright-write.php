<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   Every filesystem writer in the plugin is on a short, reviewed list,
 *         and the Footers screen and its save handler are gone. A writer that
 *         is not on the list fails, wherever it points: under
 *         dwbible_data_dir(), under DWBIBLEDATA_DIR, through a helper, a
 *         variable, a wrapper or an argument. A new writer needs a reviewer
 *         to add it here, with the reason it is allowed.
 * WHY:    BJX27 made the public footers print data/<set>/copyright.md word
 *         for word, but wp-admin -> LP Bible -> Footers could still overwrite
 *         bible/ and bibel/ copyright.md on production, behind git's back
 *         (BJX33). The footer is changed in dwbibledata, reviewed in git.
 * HOW:    No WordPress. Tokenises the plugin's PHP source (comments are
 *         ignored) and collects every call to a function or method that
 *         writes, deletes or moves a file or an image (file_put_contents,
 *         fwrite, rename, copy, unlink, mkdir, wp_mkdir_p, touch, put_contents,
 *         imagepng/imagejpeg/imagewebp ..., fopen in anything but a literal
 *         read mode), plus SplFileObject and any string literal that names a
 *         writer (call_user_func('unlink', ...)). Each call, as normalised
 *         text, must equal an entry of $allowed below; an entry that no longer
 *         matches also fails. It does not follow a writer called through a
 *         name built at run time, or through a shell command.
 * INPUT:  dwbible.php, includes/ and tools/ (recursively)
 * OUTPUT: exit 0 when every writer is on the list.
 * RUN:    php tests/test-no-admin-copyright-write.php
 */

$root = dirname(__DIR__);
$files = [ "$root/dwbible.php" ];
foreach ([ 'includes', 'tools' ] as $dir) {
    if (!is_dir("$root/$dir")) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        if (strtolower($fi->getExtension()) === 'php') { $files[] = $fi->getPathname(); }
    }
}
sort($files);

$writers = array_flip([
    'file_put_contents', 'fwrite', 'fputs', 'fputcsv', 'ftruncate', 'rename', 'copy', 'unlink', 'mkdir', 'rmdir',
    'wp_mkdir_p', 'wp_delete_file', 'wp_delete_file_from_directory', 'touch', 'chmod', 'symlink', 'link', 'tempnam', 'tmpfile',
    'move_uploaded_file', 'wp_handle_upload', 'wp_handle_sideload', 'media_handle_upload', 'media_handle_sideload', 'download_url',
    'imagepng', 'imagejpeg', 'imagewebp', 'imagegif', 'imageavif', 'imagebmp', 'imagewbmp', 'imagexbm', 'imagegd', 'imagegd2',
    'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open',
    'put_contents', 'writeimage', 'writeimages',
]);   // lower case; a method call counts only for put_contents and writeImage(s)
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
    $next = function (int $i) use ($tok, $n) {   // next token that is not blank or a comment
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tok[$j]) && in_array($tok[$j][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true)) { continue; }
            return $j;
        }
        return -1;
    };
    $prev = function (int $i) use ($tok) {      // previous token that is not blank or a comment
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tok[$j]) && in_array($tok[$j][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true)) { continue; }
            return $j;
        }
        return -1;
    };
    $methods = [ 'put_contents' => 1, 'writeimage' => 1, 'writeimages' => 1 ];   // $wp_filesystem->put_contents(), Imagick
    $callbacks = [ 'call_user_func' => 1, 'call_user_func_array' => 1, 'array_map' => 1, 'array_walk' => 1, 'array_filter' => 1, 'usort' => 1 ];
    $found = [];
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tok[$i])) { continue; }
        $low = strtolower($tok[$i][1]);
        $paren = $next($i) >= 0 && $tok[$next($i)] === '(';
        $p = $prev($i);
        $is_method = $p >= 0 && is_array($tok[$p]) && in_array($tok[$p][0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ], true);
        if ($tok[$i][0] === T_STRING && $paren && (isset($writers[$low]) || $low === 'fopen') && (!$is_method || isset($methods[$low]))) {
            $out = '';
            $depth = 0;
            for ($j = $i; $j < $n; $j++) {
                $x = $text($tok[$j]);
                $out .= $x;
                if ($x === '(') { $depth++; } elseif ($x === ')' && --$depth === 0) { break; }
            }
            $out = $norm($out);
            if ($low === 'fopen' && preg_match('/,\s*[\'"]rb?[\'"]\)$/', $out)) { continue; }   // literal read mode
            $found[] = [ $low, $out, $tok[$i][2] ];
        } elseif ($tok[$i][0] === T_STRING && isset($classes[$low])) {
            $found[] = [ $low, $tok[$i][1], $tok[$i][2] ];
        } elseif ($tok[$i][0] === T_CONSTANT_ENCAPSED_STRING && isset($writers[strtolower(trim($tok[$i][1], '\'"'))])
            && $p >= 0 && $tok[$p] === '(' && ($pp = $prev($p)) >= 0 && is_array($tok[$pp]) && isset($callbacks[strtolower($tok[$pp][1])])) {
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
