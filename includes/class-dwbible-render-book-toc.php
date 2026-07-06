<?php
/**
 * DwBible_Book_TOC_Trait — Phase 6 of the claude.ai/design 2026-04-28 redesign.
 *
 * Inserts a book-level table-of-contents view at /{slug}/{book}/ (no chapter)
 * between the Bible index and the chapter reader. Currently the URL
 * /latin-bible/john/ falls through to render_multilingual_book() which
 * defaults the missing chapter to 1; with this trait the same URL renders
 * the design's Joannes.html-style TOC: eyebrow + display incipit + chapter
 * grid + adjacent-book pager.
 *
 * Design intent (Joannes.html):
 *   - "Liber {N}" eyebrow (mono sanguine, with the book's order from
 *     index.csv as the Roman numeral)
 *   - Massive italic display title (the book's display name)
 *   - "{Latin alt} · {N capita} · {N verses}" stat line
 *   - Translation pill (LN / EN / DE — same target as the chapter reader)
 *   - 3-column grid of chapter buttons 1..N each linking to
 *     /{slug}/{book}/{N}/
 *   - Previous-book / Next-book pager at the foot
 *
 * What this trait does NOT do (deliberate scope):
 *   - Per-chapter Latin titles or English summaries (no data source for
 *     these — they would need curation).
 *   - Famous-pericope list (same — needs curation).
 *   - Verse counts per chapter (cheap-ish — would need to parse each
 *     chapter to count <h3 class="verse"> elements; deferred).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_Book_TOC_Trait {

    /**
     * Render the book-level TOC page. Called from the router when
     * QV_CHAPTER is empty.
     *
     * @param string $url_book_slug   Raw book slug from the URL
     * @param string $slug_combo      Edition slug (e.g. "latin-bible")
     */
    public static function render_book_toc( $url_book_slug, $slug_combo ) {
        $url_book_slug = is_string( $url_book_slug ) ? $url_book_slug : '';
        $slug_combo    = is_string( $slug_combo ) ? trim( $slug_combo, "/ " ) : '';
        if ( $url_book_slug === '' || $slug_combo === '' ) {
            self::render_404();
            return;
        }

        // Use the FIRST dataset of the combo to read the book file.
        // /latin-bible/  → dataset "latin"
        // /bible/        → "bible"
        // /bibel/        → "bibel"
        // (For combos like latin-bible we read latin's HTML for the chapter
        //  list since chapter counts are equal across editions of the same
        //  book within Catholic versification.)
        $datasets = array_values( array_filter( array_map( 'trim', explode( '-', $slug_combo ) ) ) );
        if ( empty( $datasets ) ) {
            self::render_404();
            return;
        }
        $first_dataset = $datasets[0];

        // The URL slug is the LATIN canonical (actus-apostolorum) — resolve it (or any English /
        // vernacular form) to the internal data key so the dataset index lookup finds it. URL
        // building below keeps $url_book_slug (the Latin slug) so chapter links stay canonical.
        $internal_key = DwBible_Plugin::key_from_any_book_slug( $url_book_slug );
        $lookup_slug  = ( $internal_key !== null && $internal_key !== '' ) ? $internal_key : $url_book_slug;

        // Resolve the canonical book slug for the first dataset.
        $entry = self::get_book_entry_for_dataset( $first_dataset, $lookup_slug );
        if ( ! $entry ) {
            // Try the second dataset if combo and first failed.
            if ( count( $datasets ) > 1 ) {
                $entry = self::get_book_entry_for_dataset( $datasets[1], $lookup_slug );
                if ( $entry ) {
                    $first_dataset = $datasets[1];
                }
            }
        }
        if ( ! $entry ) {
            self::render_404();
            return;
        }

        $dir = self::html_dir_for_dataset( $first_dataset );
        if ( ! $dir ) {
            self::render_404();
            return;
        }

        // get_book_entry_for_dataset already returns filename with .html
        // (it parses index.csv which stores e.g. "50-john.html"), so just
        // concatenate. Defensive: append .html if it's missing.
        $fname = (string) $entry['filename'];
        if ( substr( $fname, -5 ) !== '.html' ) {
            $fname .= '.html';
        }
        $file = $dir . $fname;
        if ( ! file_exists( $file ) ) {
            self::render_404();
            return;
        }
        $html = file_get_contents( $file );
        if ( $html === false ) {
            self::render_404();
            return;
        }

        // Extract the chapter list. Format: <p class="chapters"><a href="#book-ch-1">1</a> ...</p>
        $chapter_count = 0;
        if ( preg_match( '~<p\s+class="chapters">(.*?)</p>~s', $html, $m ) ) {
            preg_match_all( '~<a[^>]*>(\d+)</a>~', $m[1], $links );
            $chapter_count = is_array( $links[1] ?? null ) ? count( $links[1] ) : 0;
        }
        if ( $chapter_count < 1 ) {
            // Fallback: count <h2 id="…-ch-N"> headings.
            preg_match_all( '~<h2[^>]*id="[^"]*-ch-(\d+)"~', $html, $hits );
            if ( ! empty( $hits[1] ) ) {
                $chapter_count = max( array_map( 'intval', $hits[1] ) );
            }
        }
        if ( $chapter_count < 1 ) {
            $chapter_count = 1;       // Pathological — render a single chapter link.
        }

        // Find prev/next book by `order` in index.csv of the first dataset.
        $prev_entry = null;
        $next_entry = null;
        $order = (int) ( $entry['order'] ?? 0 );
        $data_base = dwbible_data_dir();
        $index_file = $data_base . $first_dataset . '/html/index.csv';
        if ( ! file_exists( $index_file ) ) {
            $old = $data_base . $first_dataset . '_books_html/index.csv';
            if ( file_exists( $old ) ) {
                $index_file = $old;
            }
        }
        if ( file_exists( $index_file ) && ( $fh = fopen( $index_file, 'r' ) ) !== false ) {
            fgetcsv( $fh ); // header
            while ( ( $row = fgetcsv( $fh ) ) !== false ) {
                if ( ! is_array( $row ) || count( $row ) < 3 ) continue;
                $row_order = intval( $row[0] );
                $row_short = (string) $row[1];
                $row_display = isset( $row[2] ) ? (string) $row[2] : $row_short;
                $row_e = [
                    'order'        => $row_order,
                    'short_name'   => $row_short,
                    'display_name' => $row_display,
                    'slug'         => self::slugify( $row_short ),
                ];
                if ( $row_order === $order - 1 ) $prev_entry = $row_e;
                if ( $row_order === $order + 1 ) $next_entry = $row_e;
            }
            fclose( $fh );
        }

        // Compose the TOC HTML and pipe it through the same content filter as
        // a regular bible page so the chrome stays consistent.
        $book_url   = self::bible_url_for_slug_and_canonical_book( $slug_combo, $url_book_slug );
        $book_label = $entry['display_name'] !== '' ? $entry['display_name'] : $entry['short_name'];
        $book_label = html_entity_decode( $book_label, ENT_QUOTES, 'UTF-8' );

        // Translation switcher targets — single-language slugs map to /latin/,
        // /bible/, /bibel/ via the canonical dataset slugs.
        $lang_targets = self::compose_lang_targets_for_book( $slug_combo, $url_book_slug );

        // Head like the chapter reader: vernacular book name as the title, the
        // Latin name as the grey subtitle. $book_label is the FIRST dataset's
        // name (Latin for a latin-* combo); the vernacular is the LAST dataset
        // (latin-bibel → bibel). A single-dataset slug has no separate vernacular,
        // and a language-neutral name (Genesis == Genesis) skips the redundant
        // subtitle — same rule as the chapter page.
        $latin_name   = $book_label;
        $vern_name    = '';
        $vern_dataset = end( $datasets );
        if ( $vern_dataset !== $first_dataset ) {
            $vern_entry = self::get_book_entry_for_dataset( $vern_dataset, $lookup_slug );
            if ( $vern_entry ) {
                $vern_raw  = $vern_entry['display_name'] !== '' ? $vern_entry['display_name'] : $vern_entry['short_name'];
                $vern_name = html_entity_decode( $vern_raw, ENT_QUOTES, 'UTF-8' );
            }
        }
        $toc_title    = ( $vern_name !== '' ) ? $vern_name : $latin_name;
        $toc_subtitle = ( $latin_name !== '' && $latin_name !== $toc_title ) ? $latin_name : '';

        // The chapter field labels each row in the visitor's language ("Kapitel 1",
        // "Chapter 1", …). Latin ("Caput") is the fallback for the latin-only edition.
        $lang         = function_exists( 'dwi18n_current' ) ? dwi18n_current() : 'en';
        $chapter_word = [ 'de' => 'Kapitel', 'en' => 'Chapter', 'es' => 'Capítulo', 'fr' => 'Chapitre', 'it' => 'Capitolo', 'la' => 'Caput' ][ $lang ] ?? 'Chapter';

        ob_start();
        ?>
        <div class="dwbible dwbible-book-toc">
          <header class="dwlp-prayers-head">
            <a class="lp-eyebrow" href="<?php echo esc_url( home_url( '/' . trim( $slug_combo, '/' ) . '/' ) ); ?>"><?php echo esc_html( self::edition_display_label( $slug_combo ) ); ?></a>
            <h1 class="dwlp-prayers-head__title"><?php echo esc_html( $toc_title ); ?></h1>
            <?php if ( $toc_subtitle !== '' ): ?>
              <p class="dwlp-prayers-head__latin"><?php echo esc_html( $toc_subtitle ); ?></p>
            <?php endif; ?>
            <p class="dwbible-toc-meta">Liber <?php echo esc_html( self::int_to_roman( $order ?: 1 ) ); ?> &middot; <?php echo esc_html( $chapter_count ); ?> <?php echo $chapter_count === 1 ? 'caput' : 'capita'; ?></p>
          </header>

          <?php // Chapter field — the canonical LP indexed list (.lp-rowlist / .lp-row),
                // one localized "Chapter N" row per chapter. Stacked rows keep the
                // .lp-row__sub slot free for the future one-line chapter summaries. ?>
          <div class="lp-rowlist lp-rowlist--single dwbible-toc-field">
            <?php for ( $i = 1; $i <= $chapter_count; $i++ ): ?>
              <a class="lp-row lp-row--stacked" href="<?php echo esc_url( trailingslashit( $book_url ) . $i . '/' ); ?>">
                <span class="lp-row__body">
                  <span class="lp-row__term"><?php echo esc_html( $chapter_word . ' ' . $i ); ?></span>
                </span>
              </a>
            <?php endfor; ?>
          </div>

          <nav class="pager" aria-label="<?php echo esc_attr__( 'Adjacent books', 'dwbible' ); ?>">
            <?php if ( $prev_entry ): ?>
              <a class="pager__prev" href="<?php echo esc_url( self::bible_url_for_slug_and_canonical_book( $slug_combo, $prev_entry['slug'] ) ); ?>">
                <?php echo function_exists( 'dwtheme_chevron' ) ? dwtheme_chevron( 'left' ) : ''; ?>
                <span><?php echo esc_html( html_entity_decode( $prev_entry['display_name'], ENT_QUOTES, 'UTF-8' ) ); ?></span>
              </a>
            <?php else: ?><span></span><?php endif; ?>

            <?php if ( $next_entry ): ?>
              <a class="pager__next" href="<?php echo esc_url( self::bible_url_for_slug_and_canonical_book( $slug_combo, $next_entry['slug'] ) ); ?>">
                <span><?php echo esc_html( html_entity_decode( $next_entry['display_name'], ENT_QUOTES, 'UTF-8' ) ); ?></span>
                <?php echo function_exists( 'dwtheme_chevron' ) ? dwtheme_chevron( 'right' ) : ''; ?>
              </a>
            <?php else: ?><span></span><?php endif; ?>
          </nav>
        </div>
        <?php
        $body = ob_get_clean();

        status_header( 200 );
        header( 'Cache-Control: public, max-age=3600' );
        // TEMP DEBUG: skip output_with_theme to verify body is composed
        if ( isset( $_GET['__toc_raw'] ) ) {
            header( 'Content-Type: text/html' );
            echo '<!doctype html><html><body>' . $body . '</body></html>';
            exit;
        }
        self::output_with_theme(
            $book_label . ' — ' . self::edition_display_label( $slug_combo ),
            $body,
            'book-toc'
        );
    }

    /**
     * Display label for an edition slug. Plain — no curation.
     */
    private static function edition_display_label( $slug_combo ) {
        $map = [
            'latin'        => 'Biblia Sacra (Vulgata Clementina)',
            'bible'        => 'Douay-Rheims',
            'bibel'        => 'Menge',
            'latin-bible'  => 'Biblia Sacra (Vulgata Clementina)',
            'latin-bibel'  => 'Biblia Sacra (Vulgata Clementina)',
        ];
        return $map[ $slug_combo ] ?? ( 'Biblia Sacra' );
    }

    /**
     * Convert positive int to Roman numeral. Capped at 50 — books rarely
     * exceed Liber LXXIII (Apocalypse), and this is purely decorative.
     */
    private static function int_to_roman( $n ) {
        $n = (int) $n;
        if ( $n < 1 ) return '';
        $map = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD',
            100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL',
            10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];
        $out = '';
        foreach ( $map as $v => $sym ) {
            while ( $n >= $v ) { $out .= $sym; $n -= $v; }
        }
        return $out;
    }

    /**
     * Build the LN / EN / DE language switcher target map for the TOC.
     * Returns [code => url], where url === '' means "current edition,
     * mark as active".
     */
    private static function compose_lang_targets_for_book( $slug_combo, $url_book_slug ) {
        // Map combo → which lang is active.
        $active_map = [
            'latin'        => 'ln',
            'bible'        => 'en',
            'bibel'        => 'de',
            'latin-bible'  => 'en',     // combo's vernacular wins
            'latin-bibel'  => 'de',
        ];
        $active = $active_map[ $slug_combo ] ?? 'ln';

        $url_map = [
            'ln' => '/latin/' . $url_book_slug . '/',
            'en' => '/latin-bible/' . $url_book_slug . '/',
            'de' => '/latin-bibel/' . $url_book_slug . '/',
        ];

        $out = [];
        foreach ( [ 'ln', 'en', 'de' ] as $code ) {
            $out[ $code ] = ( $code === $active ) ? '' : home_url( $url_map[ $code ] );
        }
        return $out;
    }
}
