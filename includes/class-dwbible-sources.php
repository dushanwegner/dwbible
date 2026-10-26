<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WHAT:  The sources of the Bible editions a page shows, and the foot that
 *        declares them: one grey line naming the editions, which opens on a
 *        click (a native details/summary, so it works without the script and
 *        from the keyboard) into one line per edition, which says what that
 *        edition's data/<set>/copyright.md says.
 * WHY:   Every Bible page owes its reader the editions it prints and their
 *        rights. The old per-dataset copyright.md footer printed nothing on the
 *        interlinear pages, the only ones latinprayer.org serves: their combo
 *        slug (latin-bible) has no data folder of its own (q7734). Its
 *        replacement kept a hand-written copy of the facts, which said older,
 *        flatter things than the six rights files (public domain, with no
 *        reservation; a Gutenberg source the data no longer come from) (BJX27).
 * HOW:   The facts are read from the file at runtime (dwbible_data_dir(), the
 *        dwbibledata plugin), so there is no second copy to drift. EDITIONS
 *        holds only the dataset keys and the short names the closed line uses.
 *        A file the plugin cannot read gives the name and no claim at all.
 *        The files are written in English and are shown as written, marked
 *        lang="en" on a page in another language: a translation would be a
 *        second text making claims no file holds.
 * TESTED BY: tests/test-sources-foot.php (the page), tests/test-sources-rights.php
 *        (the footer against the six files).
 */
class DwBible_Sources {

    /** The editions, keyed by dataset: the short name the page uses. */
    const EDITIONS = [
        'latin'   => ['name' => 'Vulgata Clementina'],
        'bible'   => ['name' => 'Douay-Rheims'],
        'bibel'   => ['name' => 'Menge-Bibel'],
        'spanish' => ['name' => 'Scío de San Miguel'],
        'french'  => ['name' => 'Crampon'],
        'italian' => ['name' => 'Martini'],
    ];

    /** The page's words, by language. */
    const WORDS = [
        'en' => ['label' => 'Sources', 'lead' => 'Bibles on this page'],
        'de' => ['label' => 'Quellen', 'lead' => 'Bibeln auf dieser Seite'],
        'es' => ['label' => 'Fuentes', 'lead' => 'Biblias en esta página'],
        'fr' => ['label' => 'Sources', 'lead' => 'Bibles sur cette page'],
        'it' => ['label' => 'Fonti', 'lead' => 'Bibbie in questa pagina'],
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
            if (isset(self::EDITIONS[$ds]) && !in_array($ds, $out, true)) { $out[] = $ds; }
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
            $items .= '<li class="dw-sources-foot__item" data-source="' . esc_attr($ds) . '">' . self::edition_line($e, $ds, $lang) . '</li>';
        }
        if ($items === '') { return ''; }
        return '<footer class="dw-sources-foot" aria-label="' . esc_attr($w['label']) . '">'
            . '<details class="dw-sources-foot__details">'
            . '<summary class="dw-sources-foot__summary">' . esc_html($w['lead']) . ': ' . implode(' · ', $names) . '</summary>'
            . '<ul class="dw-sources-foot__list">' . $items . '</ul>'
            . '</details>'
            . '</footer>';
    }

    /**
     * What a dataset's copyright.md says: the file's text without its heading,
     * on one line. '' when the file is not there or holds nothing. Public so a
     * test can compare it with the file itself.
     */
    public static function rights_text($ds) {
        if (!isset(self::EDITIONS[$ds]) || !function_exists('dwbible_data_dir')) { return ''; }
        $path = rtrim(dwbible_data_dir(), '/') . '/' . $ds . '/copyright.md';
        $md = is_readable($path) ? (string) file_get_contents($path) : '';
        $md = preg_replace('/\A\s*#[^\n]*\n/', '', $md);
        return trim((string) preg_replace('/\s+/u', ' ', $md));
    }

    /** One edition's declaration: its name, then what its copyright.md says. */
    private static function edition_line(array $e, $ds, $lang) {
        $line = '<span class="dw-sources-foot__name">' . esc_html($e['name']) . '</span>';
        $rights = self::rights_text($ds);
        if ($rights !== '') {
            $line .= ' · <span class="dw-sources-foot__rights"' . ($lang !== 'en' ? ' lang="en"' : '') . '>' . esc_html($rights) . '</span>';
        }
        return $line;
    }
}
