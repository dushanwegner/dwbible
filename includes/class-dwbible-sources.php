<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WHAT:  The sources of the Bible editions a page shows, and the foot that
 *        declares them: one grey line naming the editions, which opens on a
 *        click (a native details/summary, so it works without the script and
 *        from the keyboard) into one line per edition — its name, translator,
 *        year, status and source with a link.
 * WHY:   Every Bible page owes its reader the editions it prints and their
 *        rights. The old per-dataset copyright.md footer printed nothing on the
 *        interlinear pages, the only ones latinprayer.org serves: their combo
 *        slug (latin-bible) has no data folder of its own (q7734).
 * HOW:   EDITIONS is the ONE place the facts live, keyed by dataset, so a later
 *        sources entity (Bibles and missals; its own factory entry) can take
 *        them over without touching the pages. A fact the six copyright.md
 *        notes of dwbibledata and DW's word do not hold is left out, never made
 *        up. The class names are those of a general sources foot, not of a
 *        Bible-only one. The look lives with the site's design (the old Latin
 *        word foot's place and form).
 * TESTED BY: tests/test-sources-foot.php.
 */
class DwBible_Sources {

    /**
     * The editions, keyed by dataset. 'name' is the edition's own title as the
     * page names it; the rest are facts. A translated fact is an array keyed by
     * language, 'en' its fallback. '' = the fact is not held.
     */
    const EDITIONS = [
        'latin' => [
            'name'       => 'Vulgata Clementina',
            'translator' => '',
            'year'       => '1592',
            'status'     => [
                'en' => 'public domain',
                'de' => 'gemeinfrei',
                'es' => 'dominio público',
                'fr' => 'domaine public',
                'it' => 'pubblico dominio',
            ],
            'source'     => [
                'en' => 'the Sixto-Clementine Vulgate, the Latin Bible of the Roman Rite',
                'de' => 'die Sixto-Clementinische Vulgata, die lateinische Bibel des Römischen Ritus',
                'es' => 'la Vulgata sixto-clementina, la Biblia latina del rito romano',
                'fr' => 'la Vulgate sixto-clémentine, la Bible latine du rite romain',
                'it' => 'la Vulgata sisto-clementina, la Bibbia latina del rito romano',
            ],
            'url'        => '',
        ],
        'bible' => [
            'name'       => 'Douay-Rheims',
            'translator' => '',
            'year'       => '1752',
            'status'     => [
                'en' => 'public domain',
                'de' => 'gemeinfrei',
                'es' => 'dominio público',
                'fr' => 'domaine public',
                'it' => 'pubblico dominio',
            ],
            'source'     => [
                'en' => 'Project Gutenberg',
            ],
            'url'        => 'https://www.gutenberg.org/ebooks/8300',
        ],
        'bibel' => [
            'name'       => 'Menge-Bibel',
            'translator' => 'Hermann Menge (1841–1939)',
            'year'       => '',
            'status'     => [
                'en' => 'public domain in Germany since 2010',
                'de' => 'in Deutschland seit 2010 gemeinfrei',
                'es' => 'dominio público en Alemania desde 2010',
                'fr' => 'domaine public en Allemagne depuis 2010',
                'it' => 'pubblico dominio in Germania dal 2010',
            ],
            'source'     => [
                'en' => 'René Hamburger’s edition (CC0), from the online text of the Deutsche Bibelgesellschaft, with its permission',
                'de' => 'die Ausgabe von René Hamburger (CC0), nach dem Online-Text der Deutschen Bibelgesellschaft, mit deren Erlaubnis',
                'es' => 'la edición de René Hamburger (CC0), del texto en línea de la Deutsche Bibelgesellschaft, con su permiso',
                'fr' => 'l’édition de René Hamburger (CC0), d’après le texte en ligne de la Deutsche Bibelgesellschaft, avec son autorisation',
                'it' => 'l’edizione di René Hamburger (CC0), dal testo online della Deutsche Bibelgesellschaft, con il suo permesso',
            ],
            'url'        => 'https://github.com/renehamburger/Menge-Bibel',
        ],
        'spanish' => [
            'name'       => 'Scío de San Miguel',
            'translator' => 'Felipe Scío de San Miguel (1738–1796)',
            'year'       => '1793',
            'status'     => [
                'en' => 'public domain everywhere',
                'de' => 'überall gemeinfrei',
                'es' => 'dominio público en todas partes',
                'fr' => 'domaine public partout',
                'it' => 'pubblico dominio ovunque',
            ],
            'source'     => [
                'en' => 'La Biblia Vulgata Latina traducida en español, the bilingual edition of 1793, adapted to the Vulgate’s verses',
                'de' => 'La Biblia Vulgata Latina traducida en español, die zweisprachige Ausgabe von 1793, an die Verse der Vulgata angepasst',
                'es' => 'La Biblia Vulgata Latina traducida en español, edición bilingüe de 1793, adaptada a los versículos de la Vulgata',
                'fr' => 'La Biblia Vulgata Latina traducida en español, l’édition bilingue de 1793, adaptée aux versets de la Vulgate',
                'it' => 'La Biblia Vulgata Latina traducida en español, l’edizione bilingue del 1793, adattata ai versetti della Vulgata',
            ],
            'url'        => '',
        ],
        'french' => [
            'name'       => 'Crampon',
            'translator' => 'Augustin Crampon (1826–1894)',
            'year'       => '1923',
            'status'     => [
                'en' => 'public domain',
                'de' => 'gemeinfrei',
                'es' => 'dominio público',
                'fr' => 'domaine public',
                'it' => 'pubblico dominio',
            ],
            'source'     => [
                'en' => 'La Sainte Bible, the revised edition of 1923',
                'de' => 'La Sainte Bible, die revidierte Ausgabe von 1923',
                'es' => 'La Sainte Bible, la edición revisada de 1923',
                'fr' => 'La Sainte Bible, édition révisée de 1923',
                'it' => 'La Sainte Bible, l’edizione riveduta del 1923',
            ],
            'url'        => '',
        ],
        'italian' => [
            'name'       => 'Martini',
            'translator' => 'Antonio Martini (1720–1809)',
            'year'       => '1769–1781',
            'status'     => [
                'en' => 'public domain',
                'de' => 'gemeinfrei',
                'es' => 'dominio público',
                'fr' => 'domaine public',
                'it' => 'pubblico dominio',
            ],
            'source'     => [
                'en' => 'La Sacra Bibbia, translated by Mons. Antonio Martini, Archbishop of Florence',
                'de' => 'La Sacra Bibbia, übersetzt von Mons. Antonio Martini, Erzbischof von Florenz',
                'es' => 'La Sacra Bibbia, traducida por Mons. Antonio Martini, arzobispo de Florencia',
                'fr' => 'La Sacra Bibbia, traduite par Mgr Antonio Martini, archevêque de Florence',
                'it' => 'La Sacra Bibbia, tradotta da Mons. Antonio Martini, Arcivescovo di Firenze',
            ],
            'url'        => '',
        ],
    ];

    /** The page's words, by language. */
    const WORDS = [
        'en' => ['label' => 'Sources', 'lead' => 'Bibles on this page', 'translator' => 'translated by', 'source' => 'source'],
        'de' => ['label' => 'Quellen', 'lead' => 'Bibeln auf dieser Seite', 'translator' => 'übersetzt von', 'source' => 'Quelle'],
        'es' => ['label' => 'Fuentes', 'lead' => 'Biblias en esta página', 'translator' => 'traducida por', 'source' => 'fuente'],
        'fr' => ['label' => 'Sources', 'lead' => 'Bibles sur cette page', 'translator' => 'traduite par', 'source' => 'source'],
        'it' => ['label' => 'Fonti', 'lead' => 'Bibbie in questa pagina', 'translator' => 'tradotta da', 'source' => 'fonte'],
    ];

    /**
     * The datasets a page slug shows, in its own order: 'latin-bible' is
     * latin and bible, 'bible' is bible. Unknown parts are dropped.
     */
    public static function datasets_for_slug($slug) {
        $slug = is_string($slug) ? trim($slug, "/ ") : '';
        if ($slug === '') { $slug = 'bible'; }
        $out = [];
        foreach (explode('-', $slug) as $ds) {
            if (isset(self::EDITIONS[$ds]) && !in_array($ds, $out, true) && count($out) < 1) { $out[] = $ds; }
        }
        return $out;
    }

    /** The page language a slug implies ('latin-bibel' is de), 'en' when it implies none. */
    public static function lang_for_slug($slug) {
        $lang = function_exists('dwbible_i18n_lang_for_slug') ? dwbible_i18n_lang_for_slug((string) $slug) : '';
        return isset(self::WORDS[$lang]) ? $lang : 'en';
    }

    /** The foot for the page a slug names; '' when it shows no edition this class knows. */
    public static function render_for_slug($slug) {
        return self::render(self::datasets_for_slug($slug), self::lang_for_slug($slug));
    }

    /**
     * The foot: one grey summary line naming the editions; opened, one line per
     * edition. Each line carries data-source="<dataset>" for the later entity.
     */
    public static function render(array $datasets, $lang) {
        $lang = isset(self::WORDS[$lang]) ? $lang : 'en';
        $w = self::WORDS[$lang];
        $names = [];
        $items = '';
        foreach ($datasets as $ds) {
            if (!isset(self::EDITIONS[$ds])) { continue; }
            $e = self::EDITIONS[$ds];
            $names[] = esc_html($e['name']);
            $items .= '<li class="dw-sources-foot__item" data-source="' . esc_attr($ds) . '">' . self::edition_line($e, $lang, $w) . '</li>';
        }
        if ($items === '') { return ''; }
        return '<footer class="dw-sources-foot" aria-label="' . esc_attr($w['label']) . '">'
            . '<details class="dw-sources-foot__details">'
            . '<summary class="dw-sources-foot__summary">' . esc_html($w['lead']) . ': ' . implode(' · ', $names) . '</summary>'
            . '<ul class="dw-sources-foot__list">' . $items . '</ul>'
            . '</details>'
            . '</footer>';
    }

    /** One edition's declaration: name, translator, year · status · source (linked). */
    private static function edition_line(array $e, $lang, array $w) {
        $head = '<span class="dw-sources-foot__name">' . esc_html($e['name']) . '</span>';
        if ($e['translator'] !== '') { $head .= ', ' . esc_html($w['translator'] . ' ' . $e['translator']); }
        if ($e['year'] !== '') { $head .= ', ' . esc_html($e['year']); }
        $parts = [$head];
        $status = self::pick($e['status'], $lang);
        if ($status !== '') { $parts[] = esc_html($status); }
        $source = self::pick($e['source'], $lang);
        if ($source !== '') {
            $text = esc_html($source);
            if ($e['url'] !== '') {
                $text = '<a class="dw-sources-foot__link" href="' . esc_url($e['url']) . '">' . $text . '</a>';
            }
            $parts[] = esc_html($w['source']) . ': ' . $text;
        } elseif ($e['url'] !== '') {
            $parts[] = esc_html($w['source']) . ': <a class="dw-sources-foot__link" href="' . esc_url($e['url']) . '">' . esc_html($e['url']) . '</a>';
        }
        return implode(' · ', $parts);
    }

    /** A translated fact in the page's language, English when it has none there. */
    private static function pick($fact, $lang) {
        if (is_string($fact)) { return $fact; }
        if (!is_array($fact)) { return ''; }
        return (string) ($fact[$lang] ?? $fact['en'] ?? '');
    }
}
