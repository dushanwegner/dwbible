<?php
/**
 * TheBible — AI Accessibility Admin Page
 *
 * Dashboard showing all AI-facing features: llms.txt, JSON API endpoints,
 * sitemaps, robots.txt directives, JSON-LD, and copy-ready URLs for
 * submission to Google Search Console and other indexing services.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TheBible_Admin_AI {

    /**
     * Render the AI accessibility admin page.
     */
    public static function render_page() {
        $site_url = site_url();
        // Hardcoded with www — must match the Google Search Console property.
        $prod_url = 'https://www.latinprayer.org';

        // Count content
        $data_dir   = plugin_dir_path( __FILE__ ) . '../data/';
        $book_count = 0;
        foreach ( [ 'bible', 'bibel', 'latin' ] as $ds ) {
            $dir = $data_dir . $ds . '/html/';
            if ( is_dir( $dir ) ) {
                $book_count = max( $book_count, count( glob( $dir . '*.html' ) ) );
            }
        }
        $prayer_count = wp_count_posts( 'dw_prayer' );
        $prayer_count = isset( $prayer_count->publish ) ? (int) $prayer_count->publish : 0;
        $saint_count  = wp_count_posts( 'dw_saint' );
        $saint_count  = isset( $saint_count->publish ) ? (int) $saint_count->publish : 0;

        ?>
        <div class="wrap">
            <h1>AI Accessibility</h1>
            <p>Everything this site offers to AI systems — crawlers, search agents, and tool-use AI.</p>

            <style>
                .thebible-ai-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
                @media (max-width: 1200px) { .thebible-ai-grid { grid-template-columns: 1fr; } }
                .thebible-ai-card { background: #fff; border: 1px solid #c3c4c7; padding: 20px; border-radius: 4px; }
                .thebible-ai-card h2 { margin-top: 0; padding: 0; font-size: 1.2em; }
                .thebible-ai-card h3 { font-size: 1em; margin: 1.2em 0 0.4em; }
                .thebible-ai-urls { background: #f0f0f1; padding: 12px 16px; border-radius: 3px; font-family: monospace; font-size: 12px; line-height: 1.8; word-break: break-all; position: relative; margin: 8px 0; }
                .thebible-ai-urls a { text-decoration: none; }
                .thebible-ai-copy-btn { position: absolute; top: 8px; right: 8px; padding: 4px 10px; font-size: 11px; cursor: pointer; background: #2271b1; color: #fff; border: none; border-radius: 3px; }
                .thebible-ai-copy-btn:hover { background: #135e96; }
                .thebible-ai-copy-btn.copied { background: #00a32a; }
                .thebible-ai-stat { display: inline-block; background: #f0f0f1; padding: 4px 10px; border-radius: 3px; margin: 2px 4px 2px 0; font-size: 13px; }
                .thebible-ai-stat strong { color: #1d2327; }
                .thebible-ai-check { color: #00a32a; font-weight: bold; }
                .thebible-ai-table { width: 100%; border-collapse: collapse; margin: 8px 0; }
                .thebible-ai-table th, .thebible-ai-table td { text-align: left; padding: 6px 10px; border-bottom: 1px solid #f0f0f1; font-size: 13px; }
                .thebible-ai-table th { font-weight: 600; color: #50575e; }
                .thebible-ai-full { grid-column: 1 / -1; }
            </style>

            <!-- Content Overview -->
            <div class="thebible-ai-grid">

                <div class="thebible-ai-card">
                    <h2>Content Available to AI</h2>
                    <p>
                        <span class="thebible-ai-stat"><strong><?php echo $book_count; ?></strong> books × 3 translations</span>
                        <span class="thebible-ai-stat"><strong><?php echo $prayer_count; ?></strong> prayers × 5 languages</span>
                        <span class="thebible-ai-stat"><strong><?php echo $saint_count; ?></strong> saints</span>
                    </p>
                    <table class="thebible-ai-table">
                        <tr><th>Translation</th><th>Slug</th><th>Language</th></tr>
                        <tr><td>Douay-Rheims</td><td><code>bible</code></td><td>English</td></tr>
                        <tr><td>Clementine Vulgate</td><td><code>latin</code></td><td>Latin</td></tr>
                        <tr><td>Menge</td><td><code>bibel</code></td><td>German</td></tr>
                    </table>
                    <p style="margin-bottom:0">All content is <strong>public domain</strong>, served with <strong>CORS enabled</strong>, <strong>no authentication</strong>, and <strong>no rate limiting</strong>.</p>
                </div>

                <div class="thebible-ai-card">
                    <h2>AI Features Active</h2>
                    <table class="thebible-ai-table">
                        <tr><td><span class="thebible-ai-check">&#10003;</span> <code>llms.txt</code> + <code>llms-full.txt</code></td><td>AI entry-point docs</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> JSON API (chapter + single verse)</td><td>Machine-readable content</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> JSON-LD structured data</td><td>Schema.org on every page</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> BreadcrumbList schema</td><td>Bible &gt; Book &gt; Chapter</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> <code>&lt;link rel="alternate"&gt;</code></td><td>JSON version of each page</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> <code>&lt;link rel="help"&gt;</code></td><td>Points to llms.txt</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> robots.txt (17 AI bots)</td><td>All crawlers allowed</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> Server-rendered HTML</td><td>No JS dependency</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> Page-specific <code>&lt;title&gt;</code></td><td>E.g. "Genesis 1 (Vulgate)"</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> Citation fields in JSON</td><td>Pre-formatted attribution</td></tr>
                        <tr><td><span class="thebible-ai-check">&#10003;</span> Cross-references</td><td>Same verse in other translations</td></tr>
                    </table>
                </div>

                <!-- Sitemaps for Submission -->
                <div class="thebible-ai-card thebible-ai-full">
                    <h2>Sitemaps — Submit to Google Search Console</h2>
                    <p>Copy these URLs and submit them at <a href="https://search.google.com/search-console/sitemaps" target="_blank" rel="noopener">Google Search Console &gt; Sitemaps</a>.</p>

                    <h3>Sitemap Index (submitting this one is usually enough)</h3>
                    <?php self::render_url_block( $prod_url . '/sitemap-index.xml', 'sitemap-index' ); ?>

                    <h3>Individual Sitemaps</h3>
                    <?php
                    $sitemaps = [
                        'bible-sitemap-bible.xml'  => 'English Bible (Douay-Rheims) — all books, chapters, verses',
                        'bible-sitemap-latin.xml'  => 'Latin Bible (Clementine Vulgate) — all books, chapters, verses',
                        'bible-sitemap-bibel.xml'  => 'German Bible (Menge) — all books, chapters, verses',
                        'sitemap-prayers.xml'      => 'All prayers with lastmod dates',
                        'sitemap-saints.xml'       => 'All saints with lastmod dates',
                    ];
                    foreach ( $sitemaps as $file => $desc ) {
                        self::render_url_block( $prod_url . '/' . $file, 'sm-' . $file, $desc );
                    }
                    ?>
                </div>

                <!-- JSON API -->
                <div class="thebible-ai-card">
                    <h2>JSON API Endpoints</h2>

                    <h3>Discovery</h3>
                    <?php self::render_url_block( $prod_url . '/llms.txt', 'llms' ); ?>
                    <?php self::render_url_block( $prod_url . '/llms-full.txt', 'llms-full' ); ?>
                    <?php self::render_url_block( $prod_url . '/bible-index.json', 'unified-index' ); ?>

                    <h3>URL Patterns</h3>
                    <table class="thebible-ai-table">
                        <tr><th>Pattern</th><th>Returns</th></tr>
                        <tr><td><code>/{slug}/index.json</code></td><td>Translation index</td></tr>
                        <tr><td><code>/{slug}/{book}/index.json</code></td><td>Book index</td></tr>
                        <tr><td><code>/{slug}/{book}/{ch}.json</code></td><td>Full chapter</td></tr>
                        <tr><td><code>/{slug}/{book}/{ch}/{v}.json</code></td><td>Single verse</td></tr>
                        <tr><td><code>/{slug}/{book}/{ch}/{v1}-{v2}.json</code></td><td>Verse range</td></tr>
                    </table>

                    <h3>Example: Single Verse</h3>
                    <?php self::render_url_block( $prod_url . '/latin/john/3/16.json', 'example-verse' ); ?>
                </div>

                <!-- robots.txt -->
                <div class="thebible-ai-card">
                    <h2>robots.txt — AI Crawlers</h2>
                    <p>17 AI bots explicitly allowed. <a href="<?php echo esc_url( $site_url . '/robots.txt' ); ?>" target="_blank">View robots.txt</a></p>

                    <h3>Retrieval Bots (cite content in AI answers)</h3>
                    <table class="thebible-ai-table">
                        <?php
                        $retrieval = [
                            'ChatGPT-User'      => 'OpenAI: user-requested fetch',
                            'OAI-SearchBot'     => 'OpenAI: ChatGPT search',
                            'Claude-User'       => 'Anthropic: user-requested fetch',
                            'Claude-SearchBot'  => 'Anthropic: search indexing',
                            'PerplexityBot'     => 'Perplexity: indexing',
                            'Perplexity-User'   => 'Perplexity: user retrieval',
                            'DuckAssistBot'     => 'DuckDuckGo AI',
                            'Applebot-Extended' => 'Siri / Apple Intelligence',
                            'Amazonbot'         => 'Amazon Alexa',
                        ];
                        foreach ( $retrieval as $bot => $desc ) {
                            echo '<tr><td><code>' . esc_html( $bot ) . '</code></td><td>' . esc_html( $desc ) . '</td></tr>';
                        }
                        ?>
                    </table>

                    <h3>Training Bots (content enters model weights)</h3>
                    <table class="thebible-ai-table">
                        <?php
                        $training = [
                            'GPTBot'            => 'OpenAI model training',
                            'ClaudeBot'         => 'Anthropic model training',
                            'Google-Extended'   => 'Gemini training',
                            'GoogleOther'       => 'Google non-search',
                            'anthropic-ai'      => 'Anthropic legacy',
                            'cohere-ai'         => 'Cohere models',
                            'meta-externalagent' => 'Meta AI',
                            'CCBot'             => 'Common Crawl',
                        ];
                        foreach ( $training as $bot => $desc ) {
                            echo '<tr><td><code>' . esc_html( $bot ) . '</code></td><td>' . esc_html( $desc ) . '</td></tr>';
                        }
                        ?>
                    </table>
                </div>

                <!-- Quick Links -->
                <div class="thebible-ai-card thebible-ai-full">
                    <h2>Test Links (Local)</h2>
                    <p>Verify everything works on this environment:</p>
                    <table class="thebible-ai-table">
                        <?php
                        $tests = [
                            '/llms.txt'                     => 'AI documentation (short)',
                            '/llms-full.txt'                => 'AI documentation (full)',
                            '/robots.txt'                   => 'robots.txt with AI directives',
                            '/bible-index.json'             => 'Unified index (all translations)',
                            '/bible/genesis/1.json'         => 'Chapter JSON (English)',
                            '/latin/genesis/1/1.json'       => 'Single verse JSON (Latin)',
                            '/bible/john/3/16.json'         => 'John 3:16 (English)',
                            '/latin/john/3/16.json'         => 'John 3:16 (Latin)',
                            '/bible/genesis/1/1-3.json'     => 'Verse range JSON',
                            '/bible/genesis/1/99.json'      => 'Error response (verse not found)',
                            '/sitemap-index.xml'            => 'Sitemap index',
                            '/sitemap-prayers.xml'          => 'Prayer sitemap',
                            '/sitemap-saints.xml'           => 'Saint sitemap',
                            '/bible-sitemap-bible.xml'      => 'English Bible sitemap',
                            '/bible/genesis/1'              => 'HTML page (check &lt;title&gt;, JSON-LD)',
                            '/latin/psalms/23'              => 'Latin Psalm 23 (HTML)',
                        ];
                        foreach ( $tests as $path => $desc ) {
                            $url = $site_url . $path;
                            echo '<tr>';
                            echo '<td><a href="' . esc_url( $url ) . '" target="_blank"><code>' . esc_html( $path ) . '</code></a></td>';
                            echo '<td>' . esc_html( $desc ) . '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </table>
                </div>

            </div><!-- .thebible-ai-grid -->

            <script>
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.thebible-ai-copy-btn');
                if (!btn) return;
                e.preventDefault();
                var text = btn.getAttribute('data-copy');
                if (!text) return;
                navigator.clipboard.writeText(text).then(function() {
                    btn.textContent = 'Copied!';
                    btn.classList.add('copied');
                    setTimeout(function() {
                        btn.textContent = 'Copy';
                        btn.classList.remove('copied');
                    }, 2000);
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Render a single URL with copy button.
     */
    private static function render_url_block( $url, $id, $desc = '' ) {
        echo '<div class="thebible-ai-urls">';
        echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a>';
        if ( $desc !== '' ) {
            echo '<br><span style="color:#50575e;font-size:11px">' . esc_html( $desc ) . '</span>';
        }
        echo '<button class="thebible-ai-copy-btn" data-copy="' . esc_attr( $url ) . '">Copy</button>';
        echo '</div>';
    }
}
