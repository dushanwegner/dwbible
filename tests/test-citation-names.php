<?php
/**
 * WHAT:   Every book name this site PRINTS as a citation resolves back to the
 *         book it names.
 * WHY:    An answer carries a citation per language, and a consumer's obvious
 *         next move is to quote one back. That did not work: the matcher reads
 *         URL slugs and the search vocabulary, which for Italian follow
 *         Martini's Vulgate naming, while the citations are printed in modern
 *         Italian. Nineteen of the 73 Italian citations and two accented
 *         Spanish ones did not resolve — a closed loop the site opened itself.
 * HOW:    No WordPress. The two functions under test are lifted out of the real
 *         source files at runtime (never copied), so this tests what ships.
 * INPUT:  ../dwbible.php, includes/class-dwbible-agent-api.php,
 *         ../dwbibledata/data/book_names.json
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-citation-names.php
 */

$root = dirname(__DIR__);

/** Lift one method's source out of a file, by name, and return its body text. */
function lift(string $file, string $name): string {
    $src = file_get_contents($file);
    $at  = strpos($src, "function {$name}(");
    if ($at === false) { fwrite(STDERR, "cannot find {$name}() in {$file}\n"); exit(2); }
    $open = strpos($src, '{', $at);
    $depth = 0;
    for ($i = $open; $i < strlen($src); $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
    }
    return substr($src, $at, $i - $at + 1);
}

$slugify = lift("$root/dwbible.php", 'slugify');
$lookup  = lift("$root/includes/class-dwbible-agent-api.php", 'key_from_citation_name');

// The real book_names.json, from the sibling data plugin.
$namesFile = dirname($root) . '/dwbibledata/data/book_names.json';
if (!file_exists($namesFile)) { fwrite(STDERR, "no book_names.json at $namesFile\n"); exit(2); }
$BOOKS = json_decode(file_get_contents($namesFile), true)['books'] ?? [];
if (!$BOOKS) { fwrite(STDERR, "book_names.json has no books\n"); exit(2); }

eval("class DwBible_Plugin { public static \$books = []; public static {$slugify}
    private static function agent_book_names_table(): array { return self::\$books; }
    public static {$lookup} }");
DwBible_Plugin::$books = $BOOKS;

$fails = 0;
function check(string $what, bool $ok, string $detail = '') {
    global $fails;
    if ($ok) { echo "ok   $what\n"; }
    else { $fails++; echo "FAIL $what" . ($detail ? " — $detail" : '') . "\n"; }
}

// ── every printed citation name resolves to its own book ────────────────────
$checked = 0; $bad = [];
foreach ($BOOKS as $key => $names) {
    foreach ((array) $names as $lang => $name) {
        if (!is_string($name) || trim($name) === '') { continue; }
        $checked++;
        $got = DwBible_Plugin::key_from_citation_name($name);
        if ($got !== $key) { $bad[] = "$lang '$name' -> " . var_export($got, true) . " (want $key)"; }
    }
}
check("all $checked printed citation names resolve to their own book",
      empty($bad), implode('; ', array_slice($bad, 0, 5)));

// ── the names that were REFUSED in production before this existed ───────────
$was_broken = [
    '1 Samuele' => '1-kings-samuel', '2 Samuele' => '2-kings-samuel',
    '1 Cronache' => '1-paralipomenon', '2 Cronache' => '2-paralipomenon',
    '1 Maccabei' => '1-machabees',    '2 Maccabei' => '2-machabees',
    '1 Corinzi' => '1-corinthians',   '2 Corinzi' => '2-corinthians',
    '1 Pietro' => '1-peter',          '2 Pietro' => '2-peter',
    '1 Giovanni' => '1-john',         '2 Giovanni' => '2-john', '3 Giovanni' => '3-john',
    '1 Tessalonicesi' => '1-thessalonians', '2 Tessalonicesi' => '2-thessalonians',
];
foreach ($was_broken as $name => $want) {
    check("'$name' resolves to $want", DwBible_Plugin::key_from_citation_name($name) === $want,
          var_export(DwBible_Plugin::key_from_citation_name($name), true));
}

// ── accents and punctuation must not matter ─────────────────────────────────
check("an accented Spanish name resolves ('Nahún')",
      DwBible_Plugin::key_from_citation_name('Nahún') === DwBible_Plugin::key_from_citation_name('Nahum'),
      'accented and bare must agree');
check("a compound English name resolves whether or not the slash survived",
      DwBible_Plugin::key_from_citation_name('2. Kings / Samuel')
      === DwBible_Plugin::key_from_citation_name('2 Kings Samuel'));

// ── junk is refused, not guessed ────────────────────────────────────────────
foreach (['', '   ', 'Book of Mormon', 'zzzz', '42'] as $junk) {
    check("junk is refused: '" . $junk . "'", DwBible_Plugin::key_from_citation_name($junk) === null,
          var_export(DwBible_Plugin::key_from_citation_name($junk), true));
}

// ── AMBIGUITY REFUSES: the rule that matters if the data ever changes ───────
// The map is a function-static, so it cannot be rebuilt in this process.
// Assert the RULE itself on the same inputs the method would see.
DwBible_Plugin::$books = ['genesis' => ['la' => 'Ambiguum'], 'exodus' => ['la' => 'Ambiguum']];
$seen = [];
foreach (DwBible_Plugin::$books as $k => $n) {
    $s = DwBible_Plugin::slugify($n['la']);
    if (!array_key_exists($s, $seen)) { $seen[$s] = $k; }
    elseif ($seen[$s] !== $k) { $seen[$s] = false; }
}
check("two books printing one name map to false, not to either book",
      $seen['ambiguum'] === false, var_export($seen, true));

echo "\n" . ($fails ? "failed: $fails\n" : "passed: every citation name resolves\n");
exit($fails ? 1 : 0);
