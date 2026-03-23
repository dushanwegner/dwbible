<?php
/**
 * TheBible — JSON API Trait
 *
 * Serves pre-generated, self-documenting JSON files for AI consumption.
 * Each JSON file contains a _meta object describing what it is, which
 * translation/book/chapter it represents, and how to navigate to related
 * content — making the Catholic Bible fully accessible to AI agents.
 *
 * Also serves llms.txt and llms-full.txt (the AI entry-point documents).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait TheBible_JSON_API_Trait {

    /**
     * Serve a pre-generated JSON file based on current query vars.
     *
     * Route resolution:
     *   - No book, no chapter → data/{slug}/json/index.json
     *   - Book, no chapter    → data/{slug}/json/{book}/index.json
     *   - Book + chapter      → data/{slug}/json/{book}/{chapter}.json
     */
    private static function serve_json_file() {
        $slug = get_query_var( self::QV_SLUG );
        if ( ! is_string( $slug ) || $slug === '' ) {
            $slug = 'bible';
        }

        $book    = get_query_var( self::QV_BOOK );
        $chapter = get_query_var( self::QV_CHAPTER );

        // Sanitize inputs: only allow safe characters
        $slug    = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $slug ) );
        $book    = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $book ) );
        $chapter = preg_replace( '/[^0-9]/', '', $chapter );

        // Build file path
        $base = plugin_dir_path( __FILE__ ) . '../data/' . $slug . '/json/';

        if ( ! empty( $book ) && ! empty( $chapter ) ) {
            // Chapter file: data/{slug}/json/{book}/{chapter}.json
            $file = $base . $book . '/' . $chapter . '.json';
        } elseif ( ! empty( $book ) ) {
            // Book index: data/{slug}/json/{book}/index.json
            $file = $base . $book . '/index.json';
        } else {
            // Translation index: data/{slug}/json/index.json
            $file = $base . 'index.json';
        }

        if ( ! file_exists( $file ) ) {
            status_header( 404 );
            header( 'Content-Type: application/json; charset=UTF-8' );
            header( 'Access-Control-Allow-Origin: *' );
            echo json_encode( [
                'error'   => 'Not found',
                'message' => 'The requested Bible content was not found.',
                'help'    => 'See https://latinprayer.org/llms.txt for API documentation.',
            ] );
            exit;
        }

        // Serve the static file with appropriate headers
        status_header( 200 );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
        header( 'Cache-Control: public, max-age=86400' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Powered-By: Latin Prayer (latinprayer.org)' );
        readfile( $file );
        exit;
    }

    /**
     * Serve the llms.txt or llms-full.txt AI documentation file.
     *
     * @param string $variant 'llms' or 'llms-full'
     */
    private static function serve_llms_txt( $variant = 'llms' ) {
        $filename = ( $variant === 'llms-full' ) ? 'llms-full.txt' : 'llms.txt';
        $file = plugin_dir_path( __FILE__ ) . '../data/' . $filename;

        if ( ! file_exists( $file ) ) {
            status_header( 404 );
            header( 'Content-Type: text/plain; charset=UTF-8' );
            echo "Not found. See https://latinprayer.org/ for more information.\n";
            exit;
        }

        status_header( 200 );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Cache-Control: public, max-age=86400' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $file );
        exit;
    }
}
