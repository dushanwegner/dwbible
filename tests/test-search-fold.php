<?php
/**
 * WHAT:   /bible-search.json lets a Latin word typed in the classical spelling
 *         ("uirtus", "eius") find what the Clementine prints ("virtus", "ejus"),
 *         while a word typed as the Clementine prints it ("virtus", "vae") finds
 *         exactly what it found before — and no other language changes.
 * WHY:    Only j/i was folded: "uirtus" found 0 verses where "virtus" found 46
 *         (factory q582, dwbible#25).
 * HOW:    No WordPress. The fold and the two match helpers the endpoint calls are
 *         lifted out of the real source files at runtime (never copied), then run
 *         over the real Latin corpus in ../dwbibledata, verse by verse, the way
 *         serve_search_json() scans it.
 * INPUT:  includes/class-dwbible-agent-api.php, dwbible.php,
 *         ../dwbibledata/data/latin/json/
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-search-fold.php
 */

$root = dirname(__DIR__);

/** One method's source, by name, through its closing brace. */
function lift(string $file, string $name): string {
    $src = file_get_contents($file);
    if (!preg_match('/(?:private|public|protected)?\s*static\s+function\s+' . preg_quote($name, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        fwrite(STDERR, "cannot find {$name}() in {$file}\n"); exit(2);
    }
    $at = strpos($src, 'function', $m[0][1]);
    $depth = 0;
    for ($i = strpos($src, '{', $at); $i < strlen($src); $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
    }
    return substr($src, $at, $i - $at + 1);
}

/** One class constant holding an array literal, by name. */
function lift_const_array(string $file, string $name): string {
    $src = file_get_contents($file);
    $at  = strpos($src, "const {$name} = [");
    if ($at === false) { fwrite(STDERR, "cannot find const {$name}\n"); exit(2); }
    $depth = 0;
    for ($i = strpos($src, '[', $at); $i < strlen($src); $i++) {
        if ($src[$i] === '[') { $depth++; }
        elseif ($src[$i] === ']') { $depth--; if ($depth === 0) { break; } }
    }
    return substr($src, $at, $i - $at + 1) . ';';
}

$api    = "$root/includes/class-dwbible-agent-api.php";
$plugin = "$root/dwbible.php";
$consts = '';
foreach (['AGENT_SEARCH_EN_SPELLING', 'AGENT_SEARCH_ES_SPELLING', 'AGENT_SEARCH_IT_SPELLING', 'AGENT_SEARCH_DE_SPELLING'] as $c) {
    $consts .= ' ' . lift_const_array($api, $c);
}
eval('class Fold { ' . $consts
    . ' public static ' . lift($api, 'agent_search_normalize')
    . ' public static ' . lift($api, 'agent_search_u_spelled')
    . ' public static ' . lift($api, 'agent_search_verse_matches')
    . ' public static ' . lift($plugin, 'search_normalize')
    . ' private static ' . lift($plugin, 'repair_mojibake') . ' }');

$pass = 0; $fail = 0;
function ok(bool $c, string $what): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$what}\n"; } else { $fail++; echo "  FAIL {$what}\n"; }
}
function tokens(string $q, string $lang): array {
    return array_values(array_filter(preg_split('/\s+/', Fold::agent_search_normalize($q, $lang))));
}

echo "which words are read as u-for-v:\n";
ok(Fold::agent_search_u_spelled(['uirtus'], 'la') === [true], 'la "uirtus" (u, no v): yes');
ok(Fold::agent_search_u_spelled(['virtus'], 'la') === [false], 'la "virtus" (the Clementine spelling): no');
ok(Fold::agent_search_u_spelled(['dei'], 'la') === [false], 'la "dei" (no u at all): no — nothing to fold');
ok(Fold::agent_search_u_spelled(['uirtus', 'dei', 'vox'], 'la') === [true, false, false], 'la, three words: decided per word');
foreach (['en' => 'thou', 'de' => 'und', 'es' => 'su', 'fr' => 'sur', 'it' => 'suo'] as $lang => $w) {
    ok(Fold::agent_search_u_spelled([$w], $lang) === [false], "{$lang} \"{$w}\": no — only Latin has one letter for u and v");
}
ok(tokens('Ejus', 'la') === ['eius'], 'the j/i fold is unchanged (control)');
ok(tokens('neighbour', 'en') === tokens('neighbor', 'en'), 'the English spelling fold is unchanged (control)');

// The real corpus, scanned the way serve_search_json() scans it.
$dir = dirname($root) . '/dwbibledata/data/latin/json/';
if (!is_dir($dir)) { fwrite(STDERR, "no Latin corpus at $dir\n"); exit(2); }
$verses = [];
foreach (glob($dir . '*/*.json') as $file) {
    $data = json_decode((string) file_get_contents($file), true);
    foreach ($data['verses'] ?? [] as $v) { $verses[] = Fold::agent_search_normalize((string) $v['text'], 'la'); }
}
$count = static function (string $q) use ($verses): int {
    $t = tokens($q, 'la'); $u = Fold::agent_search_u_spelled($t, 'la'); $n = 0;
    foreach ($verses as $hay) { if (Fold::agent_search_verse_matches($hay, $t, $u)) { $n++; } }
    return $n;
};
// What the endpoint answered before q582: every word a plain substring.
$before = static function (string $q) use ($verses): int {
    $t = tokens($q, 'la'); $n = 0;
    foreach ($verses as $hay) {
        foreach ($t as $w) { if (strpos($hay, $w) === false) { continue 2; } }
        $n++;
    }
    return $n;
};

echo "over the real Latin corpus (" . count($verses) . " verses):\n";
$virtus = $count('virtus'); $uirtus = $count('uirtus');
ok($virtus > 0 && $uirtus === $virtus, "\"uirtus\" finds what \"virtus\" finds ({$uirtus} / {$virtus}; it found " . $before('uirtus') . ' before)');
ok($count('Uerbum caro factum est') === 1, '"Uerbum caro factum est" finds John 1:14');
ok($count('uirtus Dei') === $count('virtus Dei') && $count('virtus Dei') > 0, '"uirtus Dei" finds what "virtus Dei" finds (' . $count('virtus Dei') . ')');
ok($count('eius') === $count('ejus') && $count('ejus') > 0, '"eius" finds what "ejus" finds (' . $count('ejus') . ') (control)');
echo "a word typed as the Clementine prints it finds exactly what it found before:\n";
foreach (['virtus', 'vae', 'via', 'vis', 'vos', 'vita', 'voluit', 'servus', 'Verbum caro'] as $q) {
    ok($count($q) === $before($q), "\"{$q}\": {$count($q)} = {$before($q)} before");
}
echo "a u-spelled word gains only what u-spelling cannot tell apart:\n";
$uoluit = $count('uoluit'); $voluit = $count('voluit'); $volvit = $count('volvit');
ok($uoluit >= $voluit && $uoluit >= $volvit && $uoluit <= $count('voluit') + $count('volvit'),
   "\"uoluit\" finds {$uoluit}: voluit {$voluit} and volvit {$volvit}, which u-spelling writes alike");
foreach (['deus', 'sua', 'eius', 'dominus'] as $q) {
    $now = $count($q); $was = $before($q);
    ok($now >= $was && $now - $was <= max(10, intdiv($was, 50)), "\"{$q}\" (no v, already Clementine): {$now}, was {$was}");
}

echo "\npassed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
