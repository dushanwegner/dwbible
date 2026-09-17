<?php
/**
 * WHAT:    passage_teaser() names a book by its CITATION name (data/book_names.json),
 *          the name every other surface quotes. Needs WordPress:
 *          `wp eval-file tests/test-passage-teaser-names.php`.
 * WHY:     The Mass page builds each reading's row from this teaser. It took the
 *          book name from the vernacular edition's own title, so the English page
 *          printed "4 Kings 4:25-38" — the Douay-Rheims count — beside "2 Kings" in
 *          the calendar JSON, the app and the MCP, and "4 Kings" on the LATIN page
 *          too (quality loop tick 138). Book names follow modern literature
 *          (DW, 2026-09-17); an edition's own title stays on its own pages.
 * OUTPUT:  PASS/FAIL per case; exits 1 on any failure.
 */

$failures = 0;
$check = function ( string $what, string $got, string $want ) use ( &$failures ) {
    $ok = $got === $want;
    if ( ! $ok ) $failures++;
    echo ( $ok ? 'PASS' : 'FAIL' ), "  $what", $ok ? '' : ": got '$got', want '$want'", "\n";
};
$book = fn( string $slug, int $ch, string $v, string $lang ) => (string) DwBible_Plugin::passage_teaser( $slug, $ch, $v, $lang )['book'];

// The four books whose count is a convention, in English and Latin.
$check( 'en 4-kings (Lent, Thursday after IV Sunday)', $book( '4-kings', 4, '25-38', 'en' ), '2 Kings' );
$check( 'en 3-kings (Lent, Tuesday after II Sunday)', $book( '3-kings', 17, '8-16', 'en' ), '1 Kings' );
$check( 'en 1-kings-samuel', $book( '1-kings-samuel', 3, '10', 'en' ), '1 Samuel' );
$check( 'en 2-kings-samuel', $book( '2-kings-samuel', 7, '12', 'en' ), '2 Samuel' );
$check( 'la 4-kings (not the English edition title)', $book( '4-kings', 4, '25-38', 'la' ), '2 Regum' );
// What must not change: the names that already agreed.
$check( 'de 4-kings unchanged', $book( '4-kings', 4, '25-38', 'de' ), '2 Könige' );
$check( 'en isaias unchanged (a Douay spelling, not a count)', $book( 'isaias', 9, '6', 'en' ), 'Isaias' );
$check( 'it ecclesiasticus unchanged', $book( 'ecclesiasticus', 45, '1-6', 'it' ), 'Siracide' );

echo $failures === 0 ? "\nAll passage-teaser book names pass.\n" : "\n{$failures} FAILED.\n";
if ( $failures ) exit( 1 );
