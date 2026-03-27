<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DwBible_QA {
    private static function get_interlinear_qa_cases_from_file() {
        $root = trailingslashit(dirname(__FILE__, 2));
        $file = $root . 'interlinear-regression-urls.txt';
        if (!file_exists($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if (!is_array($lines) || empty($lines)) {
            return null;
        }
        $cases = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $url = $line;
            $label = $url;
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $label = trim($path, '/');
                if ($label === '') {
                    $label = $url;
                }
            }
            $cases[] = [
                'label' => $label,
                'url' => $url,
            ];
        }
        return $cases;
    }

    private static function get_interlinear_qa_cases() {
        $base = home_url('/');
        $cases = [];

        $cases[] = [
            'label' => 'Good alignment: Job 31 (Deutsch + Latin)',
            'url' => $base . 'bibel-latin/hiob/31/',
        ];
        $cases[] = [
            'label' => 'Good alignment: Job 31 (English + Latin)',
            'url' => $base . 'bible-latin/job/31/',
        ];

        $cases[] = [
            'label' => 'Mismatch: Daniel 13 (Deutsch + Latin) — should show notices (no 404)',
            'url' => $base . 'bibel-latin/daniel/13/',
        ];
        $cases[] = [
            'label' => 'Reference: Daniel 13 (English single)',
            'url' => $base . 'bible/daniel/13/',
        ];
        $cases[] = [
            'label' => 'Contained (German): Additions to Daniel ch 1 (Susanna) single',
            'url' => $base . 'bibel/zusaetze-daniel/1/',
        ];
        $cases[] = [
            'label' => 'Contained (German): Additions to Daniel ch 2 (Bel and the Dragon) single',
            'url' => $base . 'bibel/zusaetze-daniel/2/',
        ];

        $cases[] = [
            'label' => 'Contained (German): Additions to Esther ch 1 single',
            'url' => $base . 'bibel/zusaetze-esther/1/',
        ];
        $cases[] = [
            'label' => 'Contained (German): Prayer of Manasseh ch 1 single',
            'url' => $base . 'bibel/gebet-manasse/1/',
        ];

        return $cases;
    }

    private static function interlinear_qa_self_test($cases) {
        $errors = [];
        if (!is_array($cases) || empty($cases)) {
            $errors[] = 'No cases defined.';
            return $errors;
        }
        foreach ($cases as $i => $c) {
            if (!is_array($c)) {
                $errors[] = 'Case #' . intval($i) . ' is not an array.';
                continue;
            }
            $label = $c['label'] ?? null;
            $url = $c['url'] ?? null;
            if (!is_string($label) || trim($label) === '') {
                $errors[] = 'Case #' . intval($i) . ' has no label.';
            }
            if (!is_string($url) || trim($url) === '') {
                $errors[] = 'Case #' . intval($i) . ' has no url.';
                continue;
            }
            if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
                $errors[] = 'Case #' . intval($i) . ' url is not absolute.';
            }
        }
        return $errors;
    }

    public static function render_interlinear_qa_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $cases = self::get_interlinear_qa_cases_from_file();
        $using_file = true;
        if (!is_array($cases) || empty($cases)) {
            $cases = self::get_interlinear_qa_cases();
            $using_file = false;
        }
        $errors = self::interlinear_qa_self_test($cases);

        echo '<div class="wrap">';
        echo '<h1>Interlinear QA</h1>';

        if ($using_file) {
            echo '<p><em>Source:</em> interlinear-regression-urls.txt</p>';
        } else {
            echo '<p><em>Source:</em> built-in fallback list (interlinear-regression-urls.txt missing/unreadable)</p>';
        }

        if (!empty($errors)) {
            echo '<div class="notice notice-error"><p><strong>Self test failed:</strong> ' . esc_html(implode(' | ', $errors)) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p><strong>Self test:</strong> OK</p></div>';
        }

        echo '<p><button type="button" class="button button-primary" id="dwbible-open-all">Open all in new tabs (2s delay)</button></p>';
        echo '<ol id="dwbible-qa-list">';
        foreach ($cases as $c) {
            $label = is_array($c) && isset($c['label']) ? (string)$c['label'] : '';
            $url = is_array($c) && isset($c['url']) ? (string)$c['url'] : '';
            if ($label === '' || $url === '') continue;
            echo '<li><a class="dwbible-qa-link" target="_blank" rel="noopener noreferrer" href="' . esc_url($url) . '">' . esc_html($label) . '</a><br><code>' . esc_html($url) . '</code></li>';
        }
        echo '</ol>';

        echo '<script>';
        echo '(function(){';
        echo 'var btn=document.getElementById("dwbible-open-all");';
        echo 'if(!btn) return;';
        echo 'btn.addEventListener("click",function(){';
        echo 'var links=document.querySelectorAll("#dwbible-qa-list a.dwbible-qa-link");';
        echo 'var delay=2000;';
        echo 'for(var i=0;i<links.length;i++){' ;
        echo '(function(u,idx){ setTimeout(function(){ try{ window.open(u,"_blank"); }catch(e){} }, idx*delay); })(links[i].href,i);';
        echo '}';
        echo '});';
        echo '})();';
        echo '</script>';

        echo '</div>';
    }
}
