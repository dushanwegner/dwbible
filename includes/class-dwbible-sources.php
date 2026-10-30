<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WHAT:  The sources of the Bible editions a page shows, and the foot that
 *        declares them: one grey line naming the editions, which opens on a
 *        click (a native details/summary, so it works without the script and
 *        from the keyboard) into one line per edition: its source, and
 *        nothing else ("Martini · scrutatio.it").
 * WHY:   Every Bible page owes its reader the editions it prints and where
 *        their text was taken from. The site and the app named different
 *        sources, the app's from a hand-written list, and the site's line was
 *        an English rights file shown on German, Spanish, French and Italian
 *        pages (BKA23). DW: say as little as is morally possible (comment
 *        11469 holds the six lines). The old per-dataset copyright.md footer printed
 *        nothing on the interlinear pages: their combo slug (latin-bible) has
 *        no data folder of its own (q7734).
 * HOW:   One file, data/bible-sources.json: a snapshot of the factory's table
 *        of editions (GET /dwfactory/v1/lp/editions, field notice), written by
 *        tools/pull-bible-sources.py and never by hand. The app copies the
 *        same file, so both say the same words. EDITIONS holds only the
 *        dataset keys a slug can name; the names and notices are the file's.
 *        A notice is one line, the same in every language; a linked part is
 *        written [words](address). A file the plugin cannot read gives no
 *        foot at all.
 * TESTED BY: tests/test-sources-foot.php (the page), tests/test-sources-rights.php
 *        (the footer against the file), tests/test-sources-notices.php (the
 *        notice is the source and nothing else).
 */
class DwBible_Sources {

    /** The datasets a slug can name, in the file's order. */
    const EDITIONS = ['latin', 'bible', 'bibel', 'spanish', 'french', 'italian'];

    /** The file the notices come from, relative to the plugin. */
    const FILE = 'data/bible-sources.json';

    /** The page's words, by language. French sets a no-break space before its colon. */
    const WORDS = [
        'en' => ['label' => 'Sources', 'lead' => 'Bibles on this page', 'colon' => ':'],
        'de' => ['label' => 'Quellen', 'lead' => 'Bibeln auf dieser Seite', 'colon' => ':'],
        'es' => ['label' => 'Fuentes', 'lead' => 'Biblias en esta página', 'colon' => ':'],
        'fr' => ['label' => 'Sources', 'lead' => 'Bibles sur cette page', 'colon' => "\u{00A0}:"],
        'it' => ['label' => 'Fonti', 'lead' => 'Bibbie in questa pagina', 'colon' => ':'],
    ];

    /** @var array|null The file, read once per request. */
    private static $file = null;

    /** The editions the file holds, keyed by dataset: ['key', 'name', 'notice' => one line]. */
    public static function editions() {
        if (self::$file === null) {
            $path = dirname(__DIR__) . '/' . self::FILE;
            $json = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$file = (is_array($json) && isset($json['editions']) && is_array($json['editions'])) ? $json['editions'] : [];
        }
        return self::$file;
    }

    /**
     * The datasets a page slug shows, in its own order: 'latin-bible' is
     * latin and bible, 'bible' is bible. Unknown parts are dropped.
     */
    public static function datasets_for_slug($slug) {
        $slug = is_string($slug) ? trim($slug, "/ ") : '';
        if ($slug === '') { $slug = 'bible'; }
        $out = [];
        foreach (explode('-', $slug) as $ds) {
            if (in_array($ds, self::EDITIONS, true) && !in_array($ds, $out, true)) { $out[] = $ds; }
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
        $editions = self::editions();
        $names = [];
        $items = '';
        foreach ($datasets as $ds) {
            if (!in_array($ds, self::EDITIONS, true) || empty($editions[$ds]['name'])) { continue; }
            $e = $editions[$ds];
            $names[] = esc_html($e['name']);
            $items .= '<li class="dw-sources-foot__item" data-source="' . esc_attr($ds) . '">' . self::edition_line($e, $ds) . '</li>';
        }
        if ($items === '') { return ''; }
        return '<footer class="dw-sources-foot" aria-label="' . esc_attr($w['label']) . '">'
            . '<details class="dw-sources-foot__details">'
            . '<summary class="dw-sources-foot__summary">' . esc_html($w['lead']) . $w['colon'] . ' ' . implode(' · ', $names) . '</summary>'
            . '<ul class="dw-sources-foot__list">' . $items . '</ul>'
            . '</details>'
            . '</footer>';
    }

    /**
     * A dataset's source as the file writes it, links as [words](address);
     * '' when the file holds none. Public so a test can compare it with the
     * file itself.
     */
    public static function notice($ds) {
        $text = self::editions()[$ds]['notice'] ?? '';
        return is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';
    }

    /** A notice as HTML: its words escaped, each [words](https://…) a link. */
    public static function notice_html($notice) {
        $out = '';
        $at = 0;
        if (preg_match_all('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u', $notice, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $link) {
                $out .= esc_html(substr($notice, $at, $link[0][1] - $at));
                $out .= '<a href="' . esc_url($link[2][0]) . '" rel="noopener">' . esc_html($link[1][0]) . '</a>';
                $at = $link[0][1] + strlen($link[0][0]);
            }
        }
        return $out . esc_html(substr($notice, $at));
    }

    /** One edition's line: its source, which names it; the name alone when the file holds no source. */
    private static function edition_line(array $e, $ds) {
        $notice = self::notice($ds);
        if ($notice === '') {
            return '<span class="dw-sources-foot__name">' . esc_html($e['name']) . '</span>';
        }
        return '<span class="dw-sources-foot__rights">' . self::notice_html($notice) . '</span>';
    }
}
