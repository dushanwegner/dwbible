<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   The Bible foot says each edition's source in one short line, the
 *         same on every page language, and nothing else: no editor, printer,
 *         translator's parts or "Digitaler Text"; no legal doubt, no hedge,
 *         no apology. Crampon's "Wikisource" links to its source.
 * WHY:    DW: "Just say the source, fullstop!" and "say as little as is
 *         morally possible"; the six lines are the ones he approved (BKA23,
 *         comment 11469). The foot showed an English rights file, marked
 *         lang="en", on pages in four other languages.
 * HOW:    No WordPress (tests/wp-stubs.php). Each dataset is rendered in each
 *         page language; the expected lines are written here, not read from
 *         the plugin or the file.
 * INPUT:  data/bible-sources.json
 * OUTPUT: ok/FAIL per case; exit 1 on any failure.
 * RUN:    php tests/test-sources-notices.php
 */
require __DIR__ . '/wp-stubs.php';

$failures = 0; $passes = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failures, &$passes) {
    if ($ok) { $passes++; echo "  ok   $what\n"; return; }
    $failures++; echo "FAIL   $what", $detail !== '' ? " — $detail" : '', "\n";
};
$line = function (string $ds, string $lang): string {
    $html = DwBible_Sources::render([$ds], $lang);
    preg_match('#<li class="dw-sources-foot__item" data-source="' . $ds . '">(.*?)</li>#s', $html, $m);
    return $m[1] ?? '';
};
$text = function (string $html): string { return html_entity_decode(strip_tags($html), ENT_QUOTES); };

// The six lines DW approved (comment 11469), word for word.
$says = [
    'latin'   => 'Vulgata Clementina (1946) · CrossWire',
    'bible'   => 'Douay-Rheims (Challoner) · CrossWire',
    'bibel'   => 'Menge-Bibel · René Hamburger',
    'spanish' => 'Scío de San Miguel · CrossWire',
    'french'  => 'Crampon (1923) · Wikisource',
    'italian' => 'Martini · scrutatio.it',
];
// What the line no longer says: editors, printers, the work's own parts, the digital text's wording.
$more = '/Colunga|Turrado|Editorial|Madrid|Hermann|Abschnitte|Sirach|Passages|Testament|Digital|Digitaler|Texto digital|numérique|Testo digitale|module|Modul|edited|translated|übersetzt|1793|sacredbible/u';
// Words of legal doubt, hedge or apology, in the five languages.
$doubt = '/public domain|gemeinfrei|dominio público|domaine public|pubblico dominio|copyright|urheberrecht|derechos|droits d|diritti|not so in all|no source we found|we found|reconcil|believe|may still|possibly|probably|unclear|uncertain|sorry|vielleicht|wahrscheinlich|unklar|draft|entwurf|provisional|vorläufig|provisoire|provisorio|provvisori/iu';

foreach ($says as $ds => $want) {
    foreach (['en', 'de', 'es', 'fr', 'it', 'la'] as $lang) {
        $html = $line($ds, $lang);
        $t = $text($html);
        $check("$ds/$lang: the line is '$want'", $t === $want, "got '$t'");
        $check("$ds/$lang: the line is not marked as another language", strpos($html, 'lang=') === false, $html);
        $check("$ds/$lang: no editor, printer, parts or digital-text wording", !preg_match($more, $t, $mm), $mm[0] ?? '');
        $check("$ds/$lang: no legal doubt, hedge, apology or draft label", !preg_match($doubt, $t, $mm), $mm[0] ?? '');
    }
}

// Crampon: "Wikisource" links to the text it was taken from; no other line links.
// No "CC BY-SA": the 1923 text is public domain and Wikisource puts no licence on it (BKB11).
$html = $line('french', 'de');
$check('french: "Wikisource" links to fr.wikisource.org/wiki/Bible_Crampon_1923',
    strpos($html, '<a href="https://fr.wikisource.org/wiki/Bible_Crampon_1923" rel="noopener">Wikisource</a>') !== false, $html);
$check('french: no "CC BY-SA"', stripos($html, 'CC BY') === false, $html);
foreach (['latin', 'bible', 'bibel', 'spanish', 'italian'] as $ds) {
    $check("$ds: no link", strpos($line($ds, 'en'), '<a ') === false);
}

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
