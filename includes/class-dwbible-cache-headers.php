<?php
/**
 * DwBible — the cache header of a response that is the same for every reader.
 *
 * WHAT  DwBible_Cache_Headers::send_public( $max_age ) sends
 *       `Cache-Control: public, max-age=<max_age>` to a reader who is not
 *       logged in, and nocache_headers() to one who is.
 * WHY   q7733: the Bible's chapter and index pages sent `public, max-age=86400`
 *       whoever asked, and the Cloudflare rule "bible cache" stores what the
 *       origin marks public, cookie or not. So a page DW opened logged in (the
 *       admin bar, his name, four nonces) was kept at the edge for a day and
 *       served to every visitor. A logged-in render is never the same for every
 *       reader. Every place in the plugin that marks a response public calls
 *       this, so a new place cannot forget the login:
 *       tests/test-cache-headers-logged-in.php fails on a raw `public` header
 *       anywhere else in the plugin.
 * HOW   nocache_headers() sends `no-cache, must-revalidate, max-age=0, no-store,
 *       private` and an Expires of 1984; under /biblia/ the edge answers that
 *       with BYPASS (measured 2026-10-15 on `?q=`, which has always sent it).
 *       When WordPress cannot say whether anyone is logged in, the answer is
 *       the private one. For a reader who is not logged in the header is
 *       exactly the one each place sent before.
 * LOAD  Each file that calls it requires this file itself (require_once), so a
 *       deploy by `git pull` cannot leave OPcache holding a caller whose helper
 *       was never loaded.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DwBible_Cache_Headers {

    /**
     * Where a header line goes: header() unless a test listens.
     *
     * @var callable|null
     */
    public static $emit = null;

    /**
     * Mark this response cacheable for everyone — unless a user is logged in.
     *
     * @param int $max_age Seconds a cache may keep it (what the place sent before).
     * @return bool True when `public` was sent, false when nocache_headers() was.
     */
    public static function send_public( $max_age ) {
        if ( self::reader_may_be_logged_in() ) {
            if ( function_exists( 'nocache_headers' ) ) {
                nocache_headers();
            } else {
                self::emit( 'Cache-Control: private, no-store' );
            }
            return false;
        }
        self::emit( 'Cache-Control: public, max-age=' . (int) $max_age );
        return true;
    }

    /**
     * True when a user is logged in, and when WordPress cannot say.
     *
     * @return bool
     */
    private static function reader_may_be_logged_in() {
        return ! function_exists( 'is_user_logged_in' ) || is_user_logged_in();
    }

    /**
     * @param string $line One header line.
     */
    private static function emit( $line ) {
        if ( is_callable( self::$emit ) ) {
            call_user_func( self::$emit, $line );
            return;
        }
        header( $line );
    }
}
