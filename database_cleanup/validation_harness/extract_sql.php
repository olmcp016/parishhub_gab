<?php
// Extracts SQL statement strings from the ParishHub PHP source using the PHP tokenizer.
// Concatenation chains ("SELECT ..." . $x . " WHERE ...") are merged, with non-literal
// pieces replaced by a marker so the statement shape is preserved for EXPLAIN.
$root = $argv[1];
$out = $argv[2];
$results = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (substr($path, -4) !== '.php') continue;
    if (preg_match('#/(vendor|node_modules|\.git|test-output)/#', $path)) continue;
    $rel = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
    $tokens = token_get_all(file_get_contents($path));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        [$text, $line, $consumed] = readStringish($tokens, $i);
        if ($text === null) continue;
        // follow concatenation chain
        $j = $i + $consumed;
        while (true) {
            $k = skipWs($tokens, $j);
            if ($k >= $n || $tokens[$k] !== '.') break;
            $k = skipWs($tokens, $k + 1);
            [$next, , $c] = readStringish($tokens, $k);
            if ($next !== null) { $text .= $next; $j = $k + $c; continue; }
            // non-literal operand: consume a simple expression up to the next '.' at depth 0
            $depth = 0; $m = $k;
            while ($m < $n) {
                $t = $tokens[$m];
                if ($t === '(' || $t === '[') $depth++;
                elseif ($t === ')' || $t === ']') { if ($depth === 0) break; $depth--; }
                elseif ($depth === 0 && ($t === '.' || $t === ',' || $t === ';')) break;
                $m++;
            }
            $text .= ' /*EXPR*/ ';
            $j = $m;
        }
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|WITH)\b/i', $text)) {
            $results[] = ['file' => $rel, 'line' => $line, 'sql' => $text];
        }
        $i = $j - 1;
    }
}
file_put_contents($out, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
fwrite(STDOUT, count($results) . " SQL strings extracted\n");

function skipWs(array $t, int $i): int {
    $n = count($t);
    while ($i < $n && is_array($t[$i]) && in_array($t[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $i++;
    return $i;
}

/** Returns [string|null, line, tokensConsumed] for a literal string (single, double, interpolated, heredoc). */
function readStringish(array $t, int $i): array {
    $n = count($t);
    if ($i >= $n) return [null, 0, 0];
    $tok = $t[$i];
    if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
        $raw = $tok[1];
        $q = $raw[0];
        $body = substr($raw, 1, -1);
        $body = $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
        return [$body, $tok[2], 1];
    }
    if ($tok === '"' || (is_array($tok) && $tok[0] === T_START_HEREDOC)) {
        $endTok = $tok === '"' ? '"' : T_END_HEREDOC;
        $line = is_array($tok) ? $tok[2] : 0;
        $s = ''; $k = $i + 1; $depth = 0;
        while ($k < $n) {
            $x = $t[$k];
            if ($depth === 0 && ($x === $endTok || (is_array($x) && $x[0] === $endTok))) break;
            if (is_array($x) && $x[0] === T_ENCAPSED_AND_WHITESPACE && $depth === 0) { $s .= $x[1]; if (!$line) $line = $x[2]; }
            elseif (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) { $depth++; if ($depth === 1) $s .= ' /*EXPR*/ '; }
            elseif ($x === '}' && $depth > 0) { $depth--; }
            elseif ($depth === 0 && is_array($x) && $x[0] === T_VARIABLE) { $s .= ' /*EXPR*/ '; }
            $k++;
        }
        return [stripcslashes($s), $line, $k - $i + 1];
    }
    return [null, 0, 0];
}
