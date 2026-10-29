<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * WHAT:   The Bible foot says each edition's source in the page's language,
 *         and nothing but the source: the edition, its translator or editor,
 *         its print, and where the digital text was taken from. No legal
 *         doubt, no hedge, no apology (BKA23, DW: "Just say the source,
 *         fullstop!").
 * WHY:    The foot showed an English rights file, marked lang="en", on pages
 *         in four other languages, and the files reasoned about copyright in
 *         the reader's face.
 * HOW:    No WordPress (tests/wp-stubs.php). Each dataset is rendered in each
 *         language; the expected words are written here, not read from the
 *         plugin or the file.
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

// One phrase per language that only that language's notice holds.
$says = [
    'latin'   => ['en' => 'edited by Alberto Colunga', 'de' => 'herausgegeben von Alberto Colunga', 'es' => 'edición de Alberto Colunga', 'fr' => 'éditée par Alberto Colunga', 'it' => 'a cura di Alberto Colunga'],
    'bible'   => ['en' => "in Richard Challoner's revision", 'de' => 'in der Revision von Richard Challoner', 'es' => 'en la revisión de Richard Challoner', 'fr' => 'dans la révision de Richard Challoner', 'it' => 'nella revisione di Richard Challoner'],
    'bibel'   => ['en' => 'translated by Hermann Menge', 'de' => 'übersetzt von Hermann Menge', 'es' => 'traducida por Hermann Menge', 'fr' => 'traduite par Hermann Menge', 'it' => 'tradotta da Hermann Menge'],
    'spanish' => ['en' => 'Old Testament: transcribed by Latin Prayer', 'de' => 'Altes Testament: von Latin Prayer abgeschrieben', 'es' => 'Antiguo Testamento: transcrito por Latin Prayer', 'fr' => 'Ancien Testament : transcrit par Latin Prayer', 'it' => 'Antico Testamento: trascritto da Latin Prayer'],
    'french'  => ['en' => 'revised edition of 1923', 'de' => 'revidierte Ausgabe von 1923', 'es' => 'edición revisada de 1923', 'fr' => 'édition révisée de 1923', 'it' => 'edizione riveduta del 1923'],
    'italian' => ['en' => 'Digital text: scrutatio.it', 'de' => 'Digitaler Text: scrutatio.it', 'es' => 'Texto digital: scrutatio.it', 'fr' => 'Texte numérique : scrutatio.it', 'it' => 'Testo digitale: scrutatio.it'],
];
// Words of legal doubt, hedge or apology, in the five languages.
$doubt = '/public domain|gemeinfrei|dominio público|domaine public|pubblico dominio|copyright|urheberrecht|derechos|droits d|diritti|not so in all|no source we found|we found|reconcil|believe|may still|possibly|probably|unclear|uncertain|sorry|vielleicht|wahrscheinlich|unklar|draft|entwurf|provisional|vorläufig|provisoire|provisorio|provvisori/iu';

foreach ($says as $ds => $byLang) {
    foreach ($byLang as $lang => $phrase) {
        $html = $line($ds, $lang);
        $t = $text($html);
        $check("$ds/$lang: the line says '$phrase'", strpos($t, $phrase) !== false, "got '$t'");
        $check("$ds/$lang: the line is not marked as another language", strpos($html, 'lang=') === false, $html);
        $check("$ds/$lang: no legal doubt, hedge, apology or draft label", !preg_match($doubt, $t, $mm), $mm[0] ?? '');
    }
}

// A page language the file does not hold falls back to English, not to nothing.
$t = $text($line('bibel', 'la'));
$check("bibel/la: falls back to the English notice", strpos($t, 'translated by Hermann Menge') !== false, "got '$t'");

echo "\npassed $passes, failed $failures\n";
exit($failures ? 1 : 0);
