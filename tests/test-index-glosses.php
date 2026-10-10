<?php
/**
 * WHAT:   The index page's grey gloss beside each book — and its aria-label and
 *         its search words, which carry the same name — is the book's OWN name
 *         in the paired edition, found BY CANONICAL KEY, never by order number.
 * WHY:    build_index_html looked the gloss up by the primary dataset's order
 *         number in a table built from the SECONDARY dataset's order numbers.
 *         The Italian dataset numbers ~27 books differently from the Latin one,
 *         so /it/biblia/ read "Iob / Primo dei Maccabei", "Proverbia / Giobbe",
 *         "Malachias / Aggeo", and typing "giobbe" found Proverbs (q586).
 * HOW:    No WordPress: tests/wp-stubs.php loads the real plugin. The expected
 *         name is read straight from the dataset's own index.csv, the book
 *         identified by its short_name, never by position.
 * INPUT:  ../dwbibledata/data/<dataset>/html/index.csv (or DWBIBLEDATA_DIR)
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-index-glosses.php
 */
require __DIR__ . '/wp-stubs.php';

$failures = 0; $passes = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failures, &$passes) {
    if ($ok) { $passes++; echo "  ok   $what\n"; return; }
    $failures++; echo "FAIL   $what", $detail !== '' ? " — $detail" : '', "\n";
};

/** The dataset's own name for every book, by canonical key, from its csv. */
$own_names = function (string $dataset): array {
    $out = [];
    $fh = fopen(dwbible_data_dir() . $dataset . '/html/index.csv', 'r');
    fgetcsv($fh, 0, ',', '"', '');
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (count($r) < 4) { continue; }
        $key = DwBible_Plugin::key_from_any_book_slug(DwBible_Plugin::slugify($r[1]));
        if ($key !== null) { $out[$key] = $r[2] !== '' ? $r[2] : $r[1]; }
    }
    fclose($fh);
    return $out;
};

/** The index page's rows: name, alt, aria, search, and the book key of its URL. */
$rows_for = function (string $slug): array {
    $GLOBALS['dwtest_query_vars'] = [DwBible_Plugin::QV_SLUG => $slug];
    $m = new ReflectionMethod('DwBible_Plugin', 'build_index_html');
    $html = $m->invoke(null);
    preg_match_all('#<a href="([^"]*)" class="dwbible-tile[^"]*" data-search="([^"]*)" data-verses="[^"]*" aria-label="([^"]*)"><span class="dwbible-tile-name[^"]*">(.*?)</span>(?:<span class="dwbible-tile-alt[^"]*">(.*?)</span>)?</a>#', $html, $mm, PREG_SET_ORDER);
    $rows = [];
    foreach ($mm as $r) {
        $slugpart = basename(rtrim($r[1], '/'));
        $rows[] = [
            'key'    => DwBible_Plugin::key_from_any_book_slug($slugpart),
            'search' => html_entity_decode($r[2], ENT_QUOTES),
            'aria'   => html_entity_decode($r[3], ENT_QUOTES),
            'name'   => html_entity_decode($r[4], ENT_QUOTES),
            'alt'    => html_entity_decode($r[5] ?? '', ENT_QUOTES),
        ];
    }
    return $rows;
};

// Every localized index pairs the Latin primary with one vernacular dataset.
foreach (['italian', 'bible', 'bibel', 'spanish', 'french'] as $ds) {
    $want = $own_names($ds);
    $rows = $rows_for('latin-' . $ds);
    $check("latin-$ds: the page lists 73 books", count($rows) === 73, 'got ' . count($rows));
    $bad = [];
    foreach ($rows as $r) {
        $w = $want[$r['key']] ?? null;
        // The gloss is shown only when it differs from the Latin name beyond
        // punctuation; when it is not shown it must not be a DIFFERENT book's.
        $norm = fn($s) => strtolower(preg_replace('/[^a-z0-9]+/i', '', $s));
        if ($r['alt'] !== '') {
            if ($r['alt'] !== $w) { $bad[] = "{$r['name']}: shows '{$r['alt']}', its own is '$w'"; }
            if ($r['aria'] !== $r['name'] . ' / ' . $w) { $bad[] = "{$r['name']}: aria-label '{$r['aria']}'"; }
        } elseif ($w !== null && $norm($w) !== $norm($r['name'])) {
            $bad[] = "{$r['name']}: gloss '$w' dropped";
        }
    }
    $check("latin-$ds: every book's gloss is its own name in $ds (" . count($bad) . ' wrong)', !$bad, implode('; ', array_slice($bad, 0, 5)));
}

// The three the entry names, by their words.
$it = [];
foreach ($rows_for('latin-italian') as $r) { $it[$r['key']] = $r; }
$by_name = [];
foreach ($it as $r) { $by_name[$r['name']] = $r; }
$check('it: Iob is glossed "Giobbe"', ($by_name['Iob']['alt'] ?? '') === 'Giobbe', $by_name['Iob']['alt'] ?? 'missing');
$check('it: Proverbia is glossed "Proverbi"', ($by_name['Proverbia']['alt'] ?? '') === 'Proverbi', $by_name['Proverbia']['alt'] ?? 'missing');
$check('it: Malachias is glossed "Malachia"', ($by_name['Malachias']['alt'] ?? '') === 'Malachia', $by_name['Malachias']['alt'] ?? 'missing');
$tok = fn(string $n) => explode(' ', $by_name[$n]['search'] ?? '');
$check('it: typing "giobbe" does not find Proverbia', !in_array('giobbe', $tok('Proverbia'), true));
$check('it: Iob does not carry "primodeimaccabei"', !in_array('primodeimaccabei', $tok('Iob'), true));
$check('it: Iob still carries "giobbe"', in_array('giobbe', $tok('Iob'), true));

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
