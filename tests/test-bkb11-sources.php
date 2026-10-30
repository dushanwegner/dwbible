<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   BKB11. Crampon's line says "Wikisource" and no licence; the French
 *         foot sets a no-break space before its colon ("Bibles sur cette page :");
 *         the chapter pager's label is in the page's language in de/es/fr/it.
 * WHY:    DW's go on PROPOSAL 11652 (30 Oct 11:56 UTC). The 1923 Crampon is
 *         public domain and fr.wikisource puts no licence on it, so
 *         "CC BY-SA" was wrong; French typography wants the space; the pager's
 *         aria-label was the one English word left on de/es/fr/it pages.
 * HOW:    No WordPress (tests/wp-stubs.php). The foot is rendered per
 *         language; the compiled .mo files are read with a small reader here,
 *         so a .po edited without its .mo fails.
 * INPUT:  data/bible-sources.json, languages/dwbible-*.mo
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-bkb11-sources.php
 */
require __DIR__ . '/wp-stubs.php';

$failures = 0; $passes = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failures, &$passes) {
    if ($ok) { $passes++; echo "  ok   $what\n"; return; }
    $failures++; echo "FAIL   $what", $detail !== '' ? " — $detail" : '', "\n";
};
$summary = function (string $lang): string {
    $html = DwBible_Sources::render(['latin', 'french'], $lang);
    return preg_match('#<summary class="dw-sources-foot__summary">(.*?)</summary>#s', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
};

// 1 · Crampon's line: "Wikisource", no licence words, in the file and on the page.
$file = (string) file_get_contents(dirname(__DIR__) . '/data/bible-sources.json');
$check('the sources file says no "CC BY"', stripos($file, 'CC BY') === false);
$check('Crampon\'s notice is "Crampon (1923) · [Wikisource](…)"',
    DwBible_Sources::notice('french') === 'Crampon (1923) · [Wikisource](https://fr.wikisource.org/wiki/Bible_Crampon_1923)',
    DwBible_Sources::notice('french'));
foreach (['en', 'de', 'es', 'fr', 'it'] as $lang) {
    $html = DwBible_Sources::render(['french'], $lang);
    $check("french/$lang: no licence words on the line", !preg_match('/CC BY|BY-SA|licen[cs]e|Lizenz|licencia|licenza/iu', strip_tags($html)), strip_tags($html));
}

// 2 · The colon: French "page :" with a no-break space; the others "page:" with none.
$nbsp = "\u{00A0}";
$check('fr: "Bibles sur cette page :" with a no-break space before the colon',
    strpos($summary('fr'), 'Bibles sur cette page' . $nbsp . ': Vulgata Clementina') === 0, $summary('fr'));
foreach (['en' => 'Bibles on this page', 'de' => 'Bibeln auf dieser Seite', 'es' => 'Biblias en esta página', 'it' => 'Bibbie in questa pagina'] as $lang => $lead) {
    $s = $summary($lang);
    $check("$lang: \"$lead:\" with no space before the colon", strpos($s, $lead . ': ') === 0 && strpos($s, $nbsp) === false, $s);
}

// 3 · The pager's label, translated in each compiled catalogue.
$mo = function (string $path): array {
    $b = (string) @file_get_contents($path);
    if (strlen($b) < 28) { return []; }
    $le = unpack('V', substr($b, 0, 4))[1] === 0x950412de;
    $u = function (int $at) use ($b, $le) { return unpack($le ? 'V' : 'N', substr($b, $at, 4))[1]; };
    [$n, $orig, $tran] = [$u(8), $u(12), $u(16)];
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $k = substr($b, $u($orig + 8 * $i + 4), $u($orig + 8 * $i));
        $out[$k] = substr($b, $u($tran + 8 * $i + 4), $u($tran + 8 * $i));
    }
    return $out;
};
$want = ['de_DE' => 'Kapitelnavigation', 'es_ES' => 'Navegación por capítulos', 'fr_FR' => 'Navigation entre les chapitres', 'it_IT' => 'Navigazione tra i capitoli'];
foreach ($want as $locale => $words) {
    $cat = $mo(dirname(__DIR__) . "/languages/dwbible-$locale.mo");
    $check("$locale: \"Chapter navigation\" is \"$words\"", ($cat['Chapter navigation'] ?? '') === $words, $cat['Chapter navigation'] ?? '(missing)');
    $check("$locale: the catalogue still holds \"Previous chapter\"", ($cat['Previous chapter'] ?? '') !== '');
}
$nav = (string) file_get_contents(dirname(__DIR__) . '/includes/class-dwbible-nav-helpers.php');
$check('the pager asks for "Chapter navigation" through the dwbible text domain', strpos($nav, "esc_attr__( 'Chapter navigation', 'dwbible' )") !== false);

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
