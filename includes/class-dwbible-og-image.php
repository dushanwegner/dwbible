<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DwBible_OG_Image {
    private static function u_strlen($s) {
        if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
        $arr = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($arr) ? count($arr) : strlen((string)$s);
    }

    private static function u_substr($s, $start, $len = null) {
        if (function_exists('mb_substr')) return $len === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $len, 'UTF-8');
        $arr = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($arr)) return '';
        $slice = array_slice($arr, $start, $len === null ? null : $len);
        return implode('', $slice);
    }

    private static function fit_text_to_area($text, $max_w, $max_h, $font_file, $max_font_size, $min_font_size = 12, $use_ttf_hint = false, $prefix = '', $suffix = '', $line_height_factor = 1.35) {
        $font_size = max($min_font_size, (int)$max_font_size);
        // Only add non-empty prefix/suffix
        $add_prefix = ($prefix !== '');
        $add_suffix = ($suffix !== '');

        // Try decreasing font size until it fits
        while ($font_size >= $min_font_size) {
            $full = ($add_prefix ? $prefix : '') . $text . ($add_suffix ? $suffix : '');
            $h = self::measure_text_block($full, $max_w, $font_file, $font_size, $line_height_factor);
            if ($h <= $max_h) return [ $font_size, $full ];
            $font_size -= 2;
        }
        // Still too tall: truncate text with ellipsis at min size, preserving suffix (closing quote)
        $ellipsis = ($use_ttf_hint ? '…' : '...');
        $low = 0; $high = self::u_strlen($text);
        $best_body = '';
        while ($low <= $high) {
            $mid = (int) floor(($low + $high)/2);
            $cand_body = self::u_substr($text, 0, $mid) . $ellipsis;
            $cand_full = ($add_prefix ? $prefix : '') . $cand_body . ($add_suffix ? $suffix : '');
            $h = self::measure_text_block($cand_full, $max_w, $font_file, $min_font_size, $line_height_factor);
            if ($h <= $max_h) { $best_body = $cand_body; $low = $mid + 1; } else { $high = $mid - 1; }
        }
        if ($best_body === '') { $best_body = $ellipsis; }
        return [ $min_font_size, ($add_prefix ? $prefix : '') . $best_body . ($add_suffix ? $suffix : '') ];
    }

    private static function hex_to_color($im, $hex) {
        $hex = trim((string)$hex);
        if (preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m)) {
            $rgb = $m[1];
            $r = hexdec(substr($rgb,0,2));
            $g = hexdec(substr($rgb,2,2));
            $b = hexdec(substr($rgb,4,2));
            return imagecolorallocate($im, $r, $g, $b);
        }
        return imagecolorallocate($im, 0, 0, 0);
    }

    private static function og_cache_dir() {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'dwbible-og-cache/';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        return $dir;
    }

    public static function og_cache_purge() {
        $dir = self::og_cache_dir();
        if (!is_dir($dir)) return 0;
        $count = 0;
        $it = @scandir($dir);
        if (!$it) return 0;
        foreach ($it as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . $f;
            if (is_file($p)) { @unlink($p); $count++; }
        }
        return $count;
    }

    private static function draw_text_block($im, $text, $x, $y, $max_w, $font_file, $font_size, $color, $max_bottom=null, $align='left', $line_height_factor = 1.35) {
        $use_ttf = (is_string($font_file) && $font_file !== '' && function_exists('imagettfbbox') && function_exists('imagettftext') && file_exists($font_file));
        if (! $use_ttf) {
            $font = 5;
            $cw = imagefontwidth($font);
            $ch = imagefontheight($font);
            $line_h = max($ch, (int) floor($ch * $line_height_factor));
            $max_chars = max(1, (int) floor($max_w / $cw));
            $words = preg_split('/\s+/', $text);
            $line = '';
            $used_h = 0;
            foreach ($words as $wrd) {
                $try = $line === '' ? $wrd : ($line . ' ' . $wrd);
                if (strlen($try) > $max_chars) {
                    $draw_x = $x;
                    if ($align === 'right') {
                        $line_w = strlen($line) * $cw;
                        $draw_x = $x + max(0, $max_w - $line_w);
                    }
                    imagestring($im, $font, $draw_x, $y + $used_h, $line, $color);
                    $used_h += $line_h;
                    if ($max_bottom !== null && ($y + $used_h + $ch) > $max_bottom) return $used_h;
                    $line = $wrd;
                } else {
                    $line = $try;
                }
            }
            if ($line !== '') {
                $draw_x = $x;
                if ($align === 'right') {
                    $line_w = strlen($line) * $cw;
                    $draw_x = $x + max(0, $max_w - $line_w);
                }
                imagestring($im, $font, $draw_x, $y + $used_h, $line, $color);
                $used_h += $line_h;
            }
            return $used_h;
        }
        $line_h = (int) floor($font_size * $line_height_factor);
        $words = preg_split('/\s+/', $text);
        $line = '';
        $used_h = 0;
        foreach ($words as $wrd) {
            $try = $line === '' ? $wrd : ($line . ' ' . $wrd);
            $box = imagettfbbox($font_size, 0, $font_file, $try);
            $width = abs($box[2]-$box[0]);
            if ($width > $max_w) {
                $line_box = imagettfbbox($font_size, 0, $font_file, $line);
                $line_w = abs($line_box[2]-$line_box[0]);
                $draw_x = ($align === 'right') ? ($x + max(0, $max_w - $line_w)) : $x;
                imagettftext($im, $font_size, 0, $draw_x, $y + $used_h + $line_h, $color, $font_file, $line);
                $used_h += $line_h;
                if ($max_bottom !== null && ($y + $used_h + $line_h) > $max_bottom) return $used_h;
                $line = $wrd;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $line_box = imagettfbbox($font_size, 0, $font_file, $line);
            $line_w = abs($line_box[2]-$line_box[0]);
            $draw_x = ($align === 'right') ? ($x + max(0, $max_w - $line_w)) : $x;
            imagettftext($im, $font_size, 0, $draw_x, $y + $used_h + $line_h, $color, $font_file, $line);
            $used_h += $line_h;
        }
        return $used_h;
    }

    private static function measure_text_block($text, $max_w, $font_file, $font_size, $line_height_factor = 1.35) {
        $use_ttf = (is_string($font_file) && $font_file !== '' && function_exists('imagettfbbox') && function_exists('imagettftext') && file_exists($font_file));
        if (! $use_ttf) {
            $font = 5;
            $cw = imagefontwidth($font);
            $ch = imagefontheight($font);
            $line_h = max($ch, (int) floor($ch * $line_height_factor));
            $max_chars = max(1, (int) floor($max_w / $cw));
            $words = preg_split('/\s+/', $text);
            $line = '';
            $used_h = 0;
            foreach ($words as $wrd) {
                $try = $line === '' ? $wrd : ($line . ' ' . $wrd);
                if (strlen($try) > $max_chars) {
                    $used_h += $line_h;
                    $line = $wrd;
                } else {
                    $line = $try;
                }
            }
            if ($line !== '') { $used_h += $line_h; }
            return $used_h;
        }
        $line_h = (int) floor($font_size * $line_height_factor);
        $words = preg_split('/\s+/', $text);
        $line = '';
        $used_h = 0;
        foreach ($words as $wrd) {
            $try = $line === '' ? $wrd : ($line . ' ' . $wrd);
            $box = imagettfbbox($font_size, 0, $font_file, $try);
            $width = abs($box[2]-$box[0]);
            if ($width > $max_w) {
                $used_h += $line_h;
                $line = $wrd;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') { $used_h += $line_h; }
        return $used_h;
    }

    private static function maybe_read_local_upload_url($url) {
        if (!is_string($url) || $url === '') {
            return '';
        }
        $uploads = wp_get_upload_dir();
        if (empty($uploads['baseurl']) || empty($uploads['basedir'])) {
            return '';
        }
        $baseurl = rtrim((string) $uploads['baseurl'], '/');
        $basedir = rtrim((string) $uploads['basedir'], '/');
        if ($baseurl === '' || $basedir === '') {
            return '';
        }
        if (strpos($url, $baseurl . '/') !== 0) {
            return '';
        }
        $candidate = $basedir . substr($url, strlen($baseurl));
        if (!is_string($candidate) || $candidate === '' || !file_exists($candidate)) {
            return '';
        }
        return (string) @file_get_contents($candidate);
    }

    public static function render() {
        $enabled = get_option('dwbible_og_enabled', '1');
        if ($enabled !== '1' && $enabled !== 1) { status_header(404); exit; }
        if (!function_exists('imagecreatetruecolor')) { status_header(500); exit; }

        $download = isset($_GET['dwbible_og_download']) && $_GET['dwbible_og_download'];

        $book_slug = get_query_var(DwBible_Plugin::QV_BOOK);
        $ch = absint( get_query_var( DwBible_Plugin::QV_CHAPTER ) );
        $vf = absint( get_query_var( DwBible_Plugin::QV_VFROM ) );
        $vt = absint( get_query_var( DwBible_Plugin::QV_VTO ) );
        if (!$book_slug || !$ch || !$vf) { status_header(400); exit; }
        if (!$vt || $vt < $vf) { $vt = $vf; }

        $entry = DwBible_Plugin::get_book_entry_by_slug($book_slug);
        if (!$entry) { status_header(404); exit; }
        $book_label = isset($entry['display_name']) && $entry['display_name'] !== '' ? $entry['display_name'] : DwBible_Plugin::pretty_label($entry['short_name']);
        $ref = $book_label . ' ' . $ch . ':' . ($vf === $vt ? $vf : ($vf . '-' . $vt));
        $text = DwBible_Plugin::extract_verse_text($entry, $ch, $vf, $vt);
        if ($text === '') { status_header(404); exit; }

        // Friendly download filename
        $safe_book = sanitize_title($book_label);
        if (!is_string($safe_book) || $safe_book === '') { $safe_book = 'bible'; }
        $safe_ref = $ch . '-' . $vf . ($vt > $vf ? ('-' . $vt) : '');
        $download_filename = $safe_book . '-' . $safe_ref . '.png';
        // Strip any trailing/leading invisible control/mark characters that may render as boxes near quotes
        $text = preg_replace('/^[\p{Cf}\p{Cc}\p{Mn}\p{Me}]+|[\p{Cf}\p{Cc}\p{Mn}\p{Me}]+$/u', '', (string)$text);
        $text = trim($text);

        $w = max(100, intval(get_option('dwbible_og_width', 1200)));
        $h = max(100, intval(get_option('dwbible_og_height', 630)));
        $bg = (string) get_option('dwbible_og_bg_color', '#111111');
        $fg = (string) get_option('dwbible_og_text_color', '#ffffff');
        // Resolve font: prefer explicit path; otherwise try to map an uploaded URL to a local path under uploads
        $font_file = (string) get_option('dwbible_og_font_ttf', '');
        if ($font_file === '' || !file_exists($font_file)) {
            $font_url = (string) get_option('dwbible_og_font_url', '');
            if ($font_url !== '') {
                $uploads = wp_get_upload_dir();
                if (!empty($uploads['baseurl']) && !empty($uploads['basedir'])) {
                    $baseurl = rtrim($uploads['baseurl'], '/');
                    $basedir = rtrim($uploads['basedir'], '/');
                    if (strpos($font_url, $baseurl.'/') === 0) {
                        $candidate = $basedir . substr($font_url, strlen($baseurl));
                        if (file_exists($candidate)) { $font_file = $candidate; }
                    }
                }
            }
        }
        // Font sizes: main (max, auto-fit) and reference (exact). Fallback to legacy if unset
        $font_size_legacy = intval(get_option('dwbible_og_font_size', 40));
        $font_main = max(8, intval(get_option('dwbible_og_font_size_main', $font_size_legacy?:40)));
        $font_ref  = max(8, intval(get_option('dwbible_og_font_size_ref',  $font_size_legacy?:40)));
        $font_min_main = max(8, intval(get_option('dwbible_og_min_font_size_main', 18)));
        $bg_url = (string) get_option('dwbible_og_background_image_url', '');

        // Read style options needed for hashing and layout
        $pad_x_opt = intval(get_option('dwbible_og_padding_x', 50));
        $pad_top_opt = intval(get_option('dwbible_og_padding_top', 50));
        $pad_bottom_opt = intval(get_option('dwbible_og_padding_bottom', 50));
        $min_gap_opt = (int) get_option('dwbible_og_min_gap', 16);
        $bg_url_opt = (string) get_option('dwbible_og_background_image_url','');
        $qL_opt_hash = (string) get_option('dwbible_og_quote_left','«');
        $qR_opt_hash = (string) get_option('dwbible_og_quote_right','»');
        $logo_url_opt = (string) get_option('dwbible_og_icon_url','');
        $logo_side_opt = (string) get_option('dwbible_og_logo_side','left');
        $logo_max_w_opt = (int) get_option('dwbible_og_icon_max_w', 160);
        $logo_dx_opt = (int) get_option('dwbible_og_logo_pad_adjust_x', (int)get_option('dwbible_og_logo_pad_adjust',0));
        $logo_dy_opt = (int) get_option('dwbible_og_logo_pad_adjust_y', 0);
        $lh_main_opt = (string) get_option('dwbible_og_line_height_main','1.35');

        // Build a cache key from the request and relevant style options
        $cache_parts = [
            'book' => $book_slug,
            'ch' => $ch,
            'vf' => $vf,
            'vt' => $vt,
            'w' => $w,
            'h' => $h,
            'bg' => $bg,
            'fg' => $fg,
            'font' => $font_file ?: $font_url,
            'font_main' => $font_main,
            'font_ref' => $font_ref,
            'min_main' => $font_min_main,
            'bg_url' => $bg_url_opt,
            'qL' => $qL_opt_hash,
            'qR' => $qR_opt_hash,
            'pad_x' => $pad_x_opt,
            'pad_top' => $pad_top_opt,
            'pad_bottom' => $pad_bottom_opt,
            'gap' => $min_gap_opt,
            'logo' => $logo_url_opt,
            'logo_side' => $logo_side_opt,
            'logo_w' => $logo_max_w_opt,
            'logo_dx' => $logo_dx_opt,
            'logo_dy' => $logo_dy_opt,
            'lh_main' => $lh_main_opt,
            'text' => $text,
            'ref' => $ref,
        ];
        $hash = substr(sha1(wp_json_encode($cache_parts)), 0, 16);
        $cache_dir = self::og_cache_dir();
        $cache_file = $cache_dir . 'og-' . $hash . '.png';
        $nocache = isset($_GET['dwbible_og_nocache']) && $_GET['dwbible_og_nocache'];
        if (!$nocache && is_file($cache_file)) {
            nocache_headers();
            status_header(200);
            header('Content-Type: image/png');
            if ($download) {
                header("Content-Disposition: attachment; filename=\"" . $download_filename . "\"; filename*=UTF-8''" . rawurlencode($download_filename));
            }
            readfile($cache_file);
            exit;
        }

        $im = imagecreatetruecolor($w, $h);
        $bgc = self::hex_to_color($im, $bg);
        imagefilledrectangle($im, 0, 0, $w, $h, $bgc);
        imagealphablending($im, true);
        imagesavealpha($im, true);

        if ($bg_url) {
            $resp = wp_remote_get($bg_url, ['timeout' => 5]);
            $blob = is_wp_error($resp) ? '' : wp_remote_retrieve_body($resp);
            if ($blob) {
                $bg_img = imagecreatefromstring($blob);
                if ($bg_img) {
                    $bw = imagesx($bg_img); $bh = imagesy($bg_img);
                    $scale = max($w/$bw, $h/$bh);
                    $nw = (int) floor($bw*$scale); $nh = (int) floor($bh*$scale);
                    $dst = imagecreatetruecolor($w, $h);
                    imagecopyresampled($dst, $bg_img, 0 - (int) floor(($nw-$w)/2), 0 - (int) floor(($nh-$h)/2), 0, 0, $nw, $nh, $bw, $bh);
                    imagedestroy($bg_img);
                    imagedestroy($im);
                    $im = $dst;
                    imagealphablending($im, true);
                    imagesavealpha($im, true);
                    $overlay = imagecolorallocatealpha($im, 0, 0, 0, 80);
                    imagefilledrectangle($im, 0, 0, $w, $h, $overlay);
                }
            }
        }

        $fgc = self::hex_to_color($im, $fg);
        // Configurable padding (separate) and min gap (defaults used at registration)
        $pad_x = intval(get_option('dwbible_og_padding_x', 50));
        $pad_top = intval(get_option('dwbible_og_padding_top', 50));
        $pad_bottom = intval(get_option('dwbible_og_padding_bottom', 50));
        $min_gap = max(0, intval(get_option('dwbible_og_min_gap', 16)));
        $x = $pad_x; $y = $pad_top;

        // Icon configuration (simplified: always bottom). User chooses logo side; source uses opposite.
        $icon_url = (string) get_option('dwbible_og_icon_url','');
        $logo_side = (string) get_option('dwbible_og_logo_side','left');
        if ($logo_side !== 'right') { $logo_side = 'left'; }
        $logo_pad_adjust = intval(get_option('dwbible_og_logo_pad_adjust', 0));
        $logo_pad_adjust_x = intval(get_option('dwbible_og_logo_pad_adjust_x', $logo_pad_adjust));
        $logo_pad_adjust_y = intval(get_option('dwbible_og_logo_pad_adjust_y', 0));
        $icon_max_w = max(0, intval(get_option('dwbible_og_icon_max_w', 160)));
        $line_h_main = floatval(get_option('dwbible_og_line_height_main', '1.35'));
        // Sanity check: line height should be a factor (1.0-3.0), not pixels
        if ($line_h_main < 1.0 || $line_h_main > 3.0) { $line_h_main = 1.35; }
        $icon_im = null; $icon_w = 0; $icon_h = 0;
        if ($icon_url) {
            $blob = self::maybe_read_local_upload_url($icon_url);
            if ($blob === '') {
                $resp = wp_remote_get($icon_url, ['timeout' => 5]);
                $blob = is_wp_error($resp) ? '' : wp_remote_retrieve_body($resp);
            }
            if ($blob) {
                $tmp = @imagecreatefromstring($blob);
                if ($tmp) {
                    $iw = imagesx($tmp); $ih = imagesy($tmp);
                    if ($iw > 0 && $ih > 0) {
                        $scale = 1.0;
                        $maxw = max(1, min($icon_max_w > 0 ? $icon_max_w : $w, $w - 2*$pad_x));
                        if ($iw > $maxw) { $scale = $maxw / $iw; }
                        $tw = (int) floor($iw * $scale);
                        $th = (int) floor($ih * $scale);
                        $icon_w = $tw; $icon_h = $th;
                        $icon_im = imagecreatetruecolor($tw, $th);
                        imagealphablending($icon_im, false);
                        imagesavealpha($icon_im, true);
                        imagecopyresampled($icon_im, $tmp, 0,0,0,0, $tw,$th, $iw,$ih);
                        imagedestroy($tmp);
                    } else {
                        imagedestroy($tmp);
                    }
                }
            }
        }

        $use_ttf = (is_string($font_file) && $font_file !== '' && function_exists('imagettfbbox') && function_exists('imagettftext') && file_exists($font_file));
        // Outer quotation marks for OG images; wrapping is delegated to clean_verse_quotes().
        $qL = '»';
        $qR = '«';

        $ref_size = $font_ref;
        // Force bottom placement; align opposite of logo side
        $refpos = 'bottom';
        $refalign = ($logo_side === 'left') ? 'right' : 'left';

        // Let the shared cleaner handle whitespace and inner quote normalization only
        $text_clean = DwBible_Plugin::clean_verse_text_for_output($text, false);
        // For OG images we want exactly one visible pair of outer quotes. Strip any
        // leading/trailing guillemets and surrounding spaces, then wrap once.
        $text_clean = preg_replace('/^[«»‹›\s]+/u', '', (string)$text_clean);
        $text_clean = preg_replace('/[«»‹›\s]+$/u', '', (string)$text_clean);
        $text_clean = $qL . $text_clean . $qR;

        // Always-bottom layout
        // 1) Compute reference block height at bottom padding
        $ref_h = self::measure_text_block($ref, $w - 2*$pad_x, $font_file, $ref_size);
        $bottom_for_ref = $h - $pad_bottom - $ref_h;
        // 2) Draw main verse text above the reference with min gap
        $avail_h = ($bottom_for_ref - $min_gap) - $y;
        $use_ttf = (is_string($font_file) && $font_file !== '' && function_exists('imagettfbbox') && function_exists('imagettftext') && file_exists($font_file));
        // text_clean is already wrapped in qL/qR by clean_verse_text_for_output(),
        // so do not add another pair here via prefix/suffix.
        list($fit_size, $fit_text) = self::fit_text_to_area($text_clean, $w - 2*$pad_x, $avail_h, $font_file, $font_main, $font_min_main, $use_ttf, '', '', max(1.0, $line_h_main));
        self::draw_text_block($im, $fit_text, $x, $y, $w - 2*$pad_x, $font_file, $fit_size, $fgc, $bottom_for_ref - $min_gap, 'left', max(1.0, $line_h_main));
        // 3) Draw logo (if any) at bottom on chosen side with adjusted padding
        if ($icon_im) {
            $logo_pad_x = max(0, $pad_x + $logo_pad_adjust_x);
            $logo_pad_y = max(0, $pad_bottom + $logo_pad_adjust_y);
            $iy = $h - $logo_pad_y - $icon_h;
            $ix = ($logo_side === 'right') ? ($w - $logo_pad_x - $icon_w) : $logo_pad_x;
            imagecopy($im, $icon_im, $ix, $iy, 0, 0, $icon_w, $icon_h);
        }
        // 4) Draw reference at bottom, aligned opposite of logo side
        self::draw_text_block($im, $ref, $x, $bottom_for_ref, $w - 2*$pad_x, $font_file, $ref_size, $fgc, null, $refalign);

        // Save to cache and stream
        @imagepng($im, $cache_file);
        nocache_headers();
        status_header(200);
        header('Content-Type: image/png');
        if ($download) {
            header("Content-Disposition: attachment; filename=\"" . $download_filename . "\"; filename*=UTF-8''" . rawurlencode($download_filename));
        }
        if (is_file($cache_file)) { readfile($cache_file); } else { imagepng($im); }
        imagedestroy($im);
        exit;
    }
}
