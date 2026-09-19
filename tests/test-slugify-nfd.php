<?php
/**
 * WHAT:   A book name typed with Unicode NFD (decomposed) accents — "o" +
 *         combining diaeresis instead of precomposed "ö" — slugifies the same
 *         as its NFC form.
 * WHY:    slugify()'s German fold ('ö' => 'oe', etc.) matches only the
 *         precomposed byte sequence. An NFD "Ko\u{0308}nige" survives that
 *         strtr table untouched, the combining mark then gets stripped by the
 *         final [^a-z0-9-] cleanup, and the result is "konige" instead of
 *         "koenige" — a different, unmapped slug. "Römer", "Sprüche" and any
 *         umlaut book name fail the same way (quality loop tick 266). French
 *         and Spanish accents (é, è) happened to survive NFD because their
 *         fold target already equals "strip the combining mark", which is why
 *         44 prior area-2 passes never caught this.
 * HOW:    No WordPress. slugify() is lifted out of the real source file at
 *         runtime (never copied), so this tests what ships.
 * INPUT:  ../dwbible.php
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-slugify-nfd.php
 */

$root = dirname(__DIR__);

function lift(string $file, string $name): string {
    $src = file_get_contents($file);
    $at  = strpos($src, "function {$name}(");
    if ($at === false) { fwrite(STDERR, "cannot find {$name}() in {$file}\n"); exit(2); }
    $open = strpos($src, '{', $at);
    $depth = 0;
    for ($i = $open; $i < strlen($src); $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
    }
    return substr($src, $at, $i - $at + 1);
}

$slugify = lift("$root/dwbible.php", 'slugify');
eval("class DwBible_Plugin { public static {$slugify} }");

$pass = 0; $fail = 0;
function ok(bool $c, string $what): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$what}\n"; } else { $fail++; echo "  FAIL {$what}\n"; }
}

// [NFC form, NFD form, label]
$cases = [
    ["Könige", "Ko\u{0308}nige", "Könige"],
    ["Römer",  "Ro\u{0308}mer",  "Römer"],
    ["Sprüche", "Spru\u{0308}che", "Sprüche"],
    ["Genesis", "Genesis", "Genesis (ASCII control)"],
    ["Génesis", "Gene\u{0301}sis", "Génesis"],
];

echo "NFD accents slugify the same as their NFC form:\n";
foreach ($cases as [$nfc, $nfd, $label]) {
    $sNfc = DwBible_Plugin::slugify($nfc);
    $sNfd = DwBible_Plugin::slugify($nfd);
    ok($sNfc === $sNfd && $sNfc !== '', "{$label}: NFC \"{$sNfc}\" === NFD \"{$sNfd}\"");
}

echo $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
