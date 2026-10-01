<?php
/**
 * WHAT:   typography=clean drops the space before : ; ! ? in the Latin (and every
 *         other) text, and leaves it in French, where it is orthography.
 * WHY:    The option treated the French rule as damage: Psalm 23:1-6 in French
 *         had 3 such spaces as printed and 0 with typography=clean, so correct
 *         French was handed out looking wrong (factory q588, dwbible#19).
 * HOW:    No WordPress. agent_clean_typography() is lifted out of the real source
 *         at runtime (never copied); the three call sites are checked to pass
 *         the text's language, since a forgotten argument silently means "not
 *         French" again. Then it runs over the real French corpus in dwbibledata.
 * INPUT:  includes/class-dwbible-agent-api.php, includes/class-dwbible-json-api.php,
 *         ../dwbibledata/data/{french,latin}/json/
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-typography-clean.php
 */

$root = dirname(__DIR__);
$api  = "$root/includes/class-dwbible-agent-api.php";
$src  = file_get_contents($api);
$at   = strpos($src, 'public static function agent_clean_typography(');
if ($at === false) { fwrite(STDERR, "cannot find agent_clean_typography()\n"); exit(2); }
$at = strpos($src, 'function', $at);
$depth = 0;
for ($i = strpos($src, '{', $at); $i < strlen($src); $i++) {
    if ($src[$i] === '{') { $depth++; }
    elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
}
eval('class T { public static ' . substr($src, $at, $i - $at + 1) . ' }');

$pass = 0; $fail = 0;
function ok(bool $c, string $what): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$what}\n"; } else { $fail++; echo "  FAIL {$what}\n"; }
}

echo "the rule:\n";
$la = 'non est servus, neque liber : non est masculus';
ok(T::agent_clean_typography($la, 'la') === 'non est servus, neque liber: non est masculus', 'Latin: the space before ":" goes');
$fr = "L'Éternel est mon berger : je ne manquerai de rien ; il me fait reposer !";
ok(T::agent_clean_typography($fr, 'fr') === $fr, 'French: kept as printed');
ok(T::agent_clean_typography("Vraiment\u{00A0}? Oui\u{00A0}!", 'fr') === "Vraiment\u{00A0}? Oui\u{00A0}!", 'French: a no-break space kept too');
foreach (['en', 'de', 'es', 'it'] as $lang) {
    ok(T::agent_clean_typography('a : b ; c ! d ?', $lang) === 'a: b; c! d?', "{$lang}: the space goes, as before");
}

echo "every caller says which language the text is in:\n";
$calls = [];
foreach ([$api, "$root/includes/class-dwbible-json-api.php"] as $file) {
    preg_match_all('/agent_clean_typography\(\s*([^;]*?)\)\s*;/', file_get_contents($file), $m);
    foreach ($m[1] as $args) { $calls[] = basename($file) . ': ' . trim($args); }
}
ok(count($calls) === 3, count($calls) . ' call sites (bible-ref, bible-search, the verse JSON)');
foreach ($calls as $c) {
    ok(substr_count($c, ',') >= 1 && strpos($c, 'lang') !== false, "passes a language — {$c}");
}

echo "over the real corpus:\n";
$data = dirname($root) . '/dwbibledata/data/';
$count = static function (string $ds, string $lang) use ($data): array {
    $before = 0; $after = 0;
    foreach (glob($data . $ds . '/json/psalms/22.json') as $f) {
        foreach (json_decode((string) file_get_contents($f), true)['verses'] ?? [] as $v) {
            $before += preg_match_all('/[ \x{00A0}\x{202F}][;:!?]/u', $v['text']);
            $after  += preg_match_all('/[ \x{00A0}\x{202F}][;:!?]/u', T::agent_clean_typography($v['text'], $lang));
        }
    }
    return [$before, $after];
};
[$b, $a] = $count('french', 'fr');
ok($b > 0 && $a === $b, "French Psalm 22 (Vulgate; Hebrew 23): {$b} spaces before : ; ! ? as printed, {$a} after clean");
[$b, $a] = $count('latin', 'la');
ok($b > 0 && $a === 0, "Latin Psalm 22 (control): {$b} as printed, {$a} after clean");

echo "\npassed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
