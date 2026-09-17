<?php
/**
 * WHAT:   agent_normalize_get_keys() folds $_GET keys to lowercase before the
 *         agent-API endpoints read any parameter.
 * WHY:    `Lang=de` and `Typography=clean` are as plausible an agent's guess
 *         as the lowercase form, and unlike a truly MISNAMED name
 *         (AGENT_MISNAMED_PARAMS), a differently-cased REAL name should just
 *         work rather than silently fall back to the default — measured live
 *         on production, `?q=Colossians+3:17&Lang=de` answered "text in la,
 *         en" instead of German (quality loop tick 198).
 * HOW:    No WordPress. The method is lifted out of the real source file at
 *         runtime (never copied), so this tests what ships.
 * INPUT:  includes/class-dwbible-agent-api.php
 * OUTPUT: exit 0 when every assertion passes.
 * RUN:    php tests/test-agent-param-case.php
 */

$src = file_get_contents(dirname(__DIR__) . '/includes/class-dwbible-agent-api.php');

/** Lift one method's source by name (through its closing brace). */
function lift(string $src, string $name): string {
    $at = strpos($src, "function {$name}(");
    if ($at === false) { fwrite(STDERR, "cannot find {$name}()\n"); exit(2); }
    $depth = 0;
    for ($i = strpos($src, '{', $at); $i < strlen($src); $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
    }
    return substr($src, $at, $i - $at + 1);
}

eval('class Agent { public static ' . lift($src, 'agent_normalize_get_keys') . ' }');

$pass = 0; $fail = 0;
function ok(bool $c, string $what): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$what}\n"; } else { $fail++; echo "  FAIL {$what}\n"; }
}

echo "a differently-cased real name is folded to its lowercase form:\n";
foreach ([
    [['Lang' => 'de'], ['lang' => 'de']],
    [['LANG' => 'de', 'Typography' => 'clean'], ['lang' => 'de', 'typography' => 'clean']],
    [['Q' => 'John 3:16', 'Numbering' => 'Hebrew'], ['q' => 'John 3:16', 'numbering' => 'Hebrew']],
    [['Book' => 'John', 'Limit' => '5', 'Offset' => '0'], ['book' => 'John', 'limit' => '5', 'offset' => '0']],
] as [$in, $want]) {
    $got = Agent::agent_normalize_get_keys($in);
    ok($got === $want, 'input ' . json_encode($in) . ' -> ' . json_encode($got));
}

echo "already-lowercase input is untouched:\n";
$plain = ['lang' => 'de', 'q' => 'Gen 1:1'];
ok(Agent::agent_normalize_get_keys($plain) === $plain, 'lowercase keys pass through unchanged');

echo "a collision keeps the FIRST-SEEN value (PHP array order):\n";
$collide = ['lang' => 'de', 'Lang' => 'en'];
ok(Agent::agent_normalize_get_keys($collide) === ['lang' => 'de'], 'lang= wins over a later Lang= for the same key');

echo "values are untouched, only keys are folded:\n";
ok(Agent::agent_normalize_get_keys(['Q' => 'Mixed Case Query'])['q'] === 'Mixed Case Query',
   'the value keeps its own case');

echo "\n{$pass} passed / {$fail} failed\n";
exit($fail ? 1 : 0);
