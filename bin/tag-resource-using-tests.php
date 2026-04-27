<?php
declare(strict_types=1);

/**
 * Scans tests/unit and tags test classes/methods whose effective source
 * (method body + setUp + transitively-called helpers within the same class)
 * shows they actually interact with one of three external resources:
 *
 *   - built-in PHP server          @group requires-server
 *   - Chromedriver                 @group requires-chromedriver
 *   - MySQL server                 @group requires-mysql-server
 *
 * A resource "trigger" is matched only when the corresponding
 * instantiation + start/connect pair appears together AND no exclusion
 * pattern (e.g. setClassMock(Process::class) neutralising the spawn)
 * appears in the combined source.
 *
 * Run from repo root: `php bin/tag-resource-using-tests.php [--dry-run]`
 */

$root = dirname(__DIR__);
$testsDir = $root . '/tests/unit';
$dryRun = in_array('--dry-run', $argv, true);

// Each trigger is:
//   ['patterns' => [positive-regexes...], 'not' => [trigger-specific-negative-regexes...]]
// A trigger fires when all patterns match AND none of its 'not' patterns match.
// Category-wide 'excludes' apply in addition to per-trigger 'not'.
$categories = [
    'requires-server' => [
        'triggers' => [
            ['patterns' => ['/\bnew\s+PhpBuiltInServer\s*\(/', '/->start\s*\(/']],
            ['patterns' => ['/\bnew\s+BuiltInServerController\s*\(/', '/->onModuleInit\s*\(/']],
        ],
        'excludes' => [
            '/setClassMock\s*\(\s*Process::class/',
            '/setClassMock\s*\(\s*PhpBuiltInServer::class/',
            '/PhpBuiltInServerMock::class/',
            // Composer::binDir replaced with a mock bins dir => real PHP binary not used.
            '/setMethodReturn\s*\(\s*Composer::class\s*,\s*[\'"]binDir[\'"]/',
        ],
    ],
    'requires-chromedriver' => [
        'triggers' => [
            ['patterns' => ['/\bnew\s+Chromedriver\s*\(/', '/->start\s*\(/']],
            ['patterns' => ['/\bnew\s+ChromeDriverController\s*\(/', '/->onModuleInit\s*\(/']],
            // Installer actually downloads chromedriver. Error-path tests short-circuit
            // via expectException before the download, so exclude them per-trigger.
            [
                'patterns' => ['/\bnew\s+ChromedriverInstaller\s*\(/', '/->install\s*\(/'],
                'not' => ['/\$this->expectException\s*\(/'],
            ],
        ],
        'excludes' => [
            '/setClassMock\s*\(\s*Process::class/',
            '/setClassMock\s*\(\s*ChromeDriver::class/',
            '/setClassMock\s*\(\s*Chromedriver::class/',
            '/setMethodReturn\s*\(\s*Composer::class\s*,\s*[\'"]binDir[\'"]/',
        ],
    ],
    'requires-mysql-server' => [
        'triggers' => [
            ['patterns' => ['/\bnew\s+PDO\s*\(\s*["\']mysql:/']],
            ['patterns' => ['/\bnew\s+mysqli\s*\(/']],
            ['patterns' => ['/\bnew\s+MysqlServer\s*\(/', '/->start\s*\(/']],
            ['patterns' => ['/\bnew\s+MysqlServerController\s*\(/', '/->start\s*\(/']],
            ['patterns' => ['/\bnew\s+MysqlServerController\s*\(/', '/->onModuleInit\s*\(/']],
            // MysqlDatabase is a higher-level class that opens a real PDO when any
            // DB-touching method is invoked, including cascaded installs via
            // fastScaffold()->configure($db)->install(...), WPLoader _initialize,
            // and LoadSandbox->load(). Exclude short-circuit paths:
            //   * dump import errors thrown before connect (DUMP_FILE_NOT_EXIST / NOT_READABLE)
            //   * fopen mocked → import bails before connect
            //   * install() with InstallationException::INVALID_* / STATE_* → param/state validation
            //     error, thrown before the DB is touched.
            // updateOption/getOption are intentionally NOT listed — they also exist on
            // Installation state objects (Configured::updateOption throws STATE_CONFIGURED
            // before any connect) and cause false positives.
            [
                'patterns' => [
                    '/\bnew\s+MysqlDatabase\s*\(/',
                    '/->(?:getPDO|create|drop|exists|query|exec|useDb|import|dump|install|_initialize|load)\s*\(/',
                ],
                'not' => [
                    '/expectExceptionCode\s*\(\s*DbException::(?:DUMP_FILE_NOT_EXIST|DUMP_FILE_NOT_READABLE)\b/',
                    '/setFunctionReturn\s*\(\s*[\'"]fopen[\'"]/',
                    // Validation errors are thrown by Installation before any DB connect.
                    // Match the literal class constant anywhere in the body (catches
                    // both `expectExceptionCode(InstallationException::INVALID_*)` and
                    // tests that store the codes in a $badInputs array). STATE_* codes
                    // are NOT excluded — STATE_SINGLE/MULTISITE/EMPTY are thrown after
                    // a successful install (DB was definitely touched).
                    '/InstallationException::INVALID_\w+/',
                ],
            ],
            // DbExport / DbImport commands always connect to MySQL when run.
            [
                'patterns' => [
                    '/\bnew\s+MysqlDatabase\s*\(/',
                    '/\bnew\s+(?:DbExport|DbImport)\s*\(/',
                    '/->run\s*\(/',
                ],
            ],
            // `new Single(...)` / `new Multisite(...)` constructors probe the DB via
            // isInstalled() → $db->exists(), so they connect even when the test does
            // not otherwise call an install/query method. Exclude filesystem-level
            // error paths that short-circuit before the DB check.
            [
                'patterns' => [
                    '/\bnew\s+MysqlDatabase\s*\(/',
                    '/\bnew\s+(?:Single|Multisite)\s*\(/',
                ],
                'not' => [
                    // Filesystem-level pre-DB errors thrown by the constructor:
                    //   ROOT_DIR_NOT_FOUND — root dir does not exist
                    //   STATE_EMPTY — wp-load.php is missing (detected via filesystem, no DB)
                    // Tests that install first and then unlink wp-load.php are tagged by
                    // the separate install-cascade trigger, so excluding these two here
                    // is safe.
                    '/expectExceptionCode\s*\(\s*InstallationException::(?:ROOT_DIR_NOT_FOUND|STATE_EMPTY)\b/',
                ],
            ],
        ],
        'excludes' => [
            '/setClassMock\s*\(\s*Process::class/',
            '/setClassMock\s*\(\s*PDO::class/',
            '/setClassMock\s*\(\s*MysqlServer::class/',
            '/setClassMock\s*\(\s*MysqlDatabase::class/',
        ],
    ],
];

/** @return array{methods: list<array{name:string,isTest:bool,isSetup:bool,docStart:?int,docEnd:?int,sigLine:int,body:string,indent:string}>, classSigLine:int, classDocStart:?int, classDocEnd:?int, lines:list<string>} */
function analyzeFile(string $path): array
{
    $src = file_get_contents($path);
    $lines = preg_split('/(?<=\n)/', $src);
    $tokens = token_get_all($src);

    $methods = [];
    $classSigLine = 0;
    $classDocStart = null;
    $classDocEnd = null;

    $pendingDoc = null;
    $pendingDocLines = null;
    $pendingAttrTest = false;
    $n = count($tokens);

    $classFound = false;

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];

        if (is_array($tok) && $tok[0] === T_DOC_COMMENT) {
            $docText = $tok[1];
            $docLine = $tok[2];
            $docLineCount = substr_count($docText, "\n");
            $pendingDoc = $docText;
            $pendingDocLines = [$docLine, $docLine + $docLineCount];
            continue;
        }

        if (is_array($tok) && $tok[0] === T_ATTRIBUTE) {
            // PHP 8 attribute; skip to matching ']'
            $depth = 1;
            $j = $i + 1;
            $hasTest = false;
            while ($j < $n && $depth > 0) {
                $t = $tokens[$j];
                if ($t === '[') {
                    $depth++;
                } elseif ($t === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif (is_array($t) && $t[0] === T_STRING && $t[1] === 'Test') {
                    $hasTest = true;
                }
                $j++;
            }
            if ($hasTest) {
                $pendingAttrTest = true;
            }
            $i = $j;
            continue;
        }

        if (is_array($tok) && $tok[0] === T_WHITESPACE) {
            continue;
        }

        if (is_array($tok) && $tok[0] === T_CLASS && !$classFound) {
            $classSigLine = $tok[2];
            $classFound = true;
            if ($pendingDoc !== null) {
                $classDocStart = $pendingDocLines[0];
                $classDocEnd = $pendingDocLines[1];
            }
            $pendingDoc = null;
            $pendingDocLines = null;
            $pendingAttrTest = false;
            continue;
        }

        if (is_array($tok) && $tok[0] === T_FUNCTION) {
            $funcLine = $tok[2];
            // locate name
            $j = $i + 1;
            $name = null;
            while ($j < $n) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_STRING) {
                    $name = $t[1];
                    break;
                }
                if ($t === '(') {
                    break;
                }
                $j++;
            }

            if ($name !== null) {
                // find body (skip past params, return type, then '{')
                $k = $j + 1;
                $depth = 0;
                $bodyStart = null;
                while ($k < $n) {
                    $t = $tokens[$k];
                    if ($t === '{') {
                        $bodyStart = $k;
                        $depth = 1;
                        $k++;
                        break;
                    }
                    if ($t === ';') {
                        // abstract / interface method
                        $bodyStart = null;
                        break;
                    }
                    $k++;
                }
                $body = '';
                if ($bodyStart !== null) {
                    while ($k < $n && $depth > 0) {
                        $t = $tokens[$k];
                        if ($t === '{') {
                            $depth++;
                            $body .= '{';
                        } elseif ($t === '}') {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }
                            $body .= '}';
                        } elseif (is_array($t)) {
                            $body .= $t[1];
                        } else {
                            $body .= $t;
                        }
                        $k++;
                    }
                }

                $isTest = str_starts_with($name, 'test')
                    || ($pendingDoc !== null && preg_match('/@test\b/', $pendingDoc))
                    || $pendingAttrTest;

                $isSetup = in_array($name, ['setUp', 'setUpBeforeClass', '_before', '_beforeSuite', '_beforeClass'], true)
                    || ($pendingDoc !== null && preg_match('/@before\b/', $pendingDoc));

                // indent of signature line
                $sigLineContent = $lines[$funcLine - 1] ?? '';
                preg_match('/^(\s*)/', $sigLineContent, $im);
                $indent = $im[1] ?? '    ';

                $methods[] = [
                    'name' => $name,
                    'isTest' => (bool)$isTest,
                    'isSetup' => (bool)$isSetup,
                    'docStart' => $pendingDoc !== null ? $pendingDocLines[0] : null,
                    'docEnd' => $pendingDoc !== null ? $pendingDocLines[1] : null,
                    'docText' => $pendingDoc,
                    'sigLine' => $funcLine,
                    'body' => $body,
                    'indent' => $indent,
                ];
                $pendingDoc = null;
                $pendingDocLines = null;
                $pendingAttrTest = false;
                $i = $k;
                continue;
            }
        }

        // Any non-doc, non-attribute, non-whitespace token that isn't a qualifier
        // resets pending doc/attribute.
        if (is_array($tok) && in_array($tok[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
            continue;
        }
        // doc / attribute remain pending across whitespace and visibility only
    }

    return [
        'methods' => $methods,
        'classSigLine' => $classSigLine,
        'classDocStart' => $classDocStart,
        'classDocEnd' => $classDocEnd,
        'lines' => $lines,
    ];
}

function findCalledMethods(string $body): array
{
    $calls = [];
    if (preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $body, $m)) {
        foreach ($m[1] as $name) {
            $calls[$name] = true;
        }
    }
    if (preg_match_all('/(?:self|static)::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $body, $m)) {
        foreach ($m[1] as $name) {
            $calls[$name] = true;
        }
    }
    return array_keys($calls);
}

function effectiveSource(array $methods, string $methodName, array &$visited = []): string
{
    if (isset($visited[$methodName])) {
        return '';
    }
    $visited[$methodName] = true;
    $byName = [];
    foreach ($methods as $m) {
        $byName[$m['name']] = $m;
    }
    if (!isset($byName[$methodName])) {
        return '';
    }
    $src = $byName[$methodName]['body'];
    foreach (findCalledMethods($src) as $called) {
        $src .= "\n" . effectiveSource($methods, $called, $visited);
    }
    return $src;
}

function matchesAnyTrigger(string $src, array $triggers): bool
{
    foreach ($triggers as $trigger) {
        $patterns = $trigger['patterns'] ?? [];
        $not = $trigger['not'] ?? [];
        $all = true;
        foreach ($patterns as $re) {
            if (!preg_match($re, $src)) {
                $all = false;
                break;
            }
        }
        if (!$all) {
            continue;
        }
        $vetoed = false;
        foreach ($not as $re) {
            if (preg_match($re, $src)) {
                $vetoed = true;
                break;
            }
        }
        if (!$vetoed) {
            return true;
        }
    }
    return false;
}

function matchesAnyExclusion(string $src, array $excludes): bool
{
    foreach ($excludes as $re) {
        if (preg_match($re, $src)) {
            return true;
        }
    }
    return false;
}

function classify(array $fileData, array $categories): array
{
    $methods = $fileData['methods'];

    // effective source = setup bodies concatenated
    $setupSrc = '';
    foreach ($methods as $m) {
        if ($m['isSetup']) {
            $visited = [];
            $setupSrc .= "\n" . effectiveSource($methods, $m['name'], $visited);
        }
    }

    $classDecisions = [];   // category => bool (tag at class level)
    $methodDecisions = [];  // category => methodName => bool

    foreach ($categories as $cat => $cfg) {
        $classDecisions[$cat] = false;
        $methodDecisions[$cat] = [];

        // Does setup itself trigger (without exclusion)?
        $setupTriggers = matchesAnyTrigger($setupSrc, $cfg['triggers']);
        $setupExcludes = matchesAnyExclusion($setupSrc, $cfg['excludes']);

        if ($setupTriggers && !$setupExcludes) {
            $classDecisions[$cat] = true;
            continue;
        }

        foreach ($methods as $m) {
            if (!$m['isTest']) {
                continue;
            }
            $visited = [];
            $combined = $setupSrc . "\n" . effectiveSource($methods, $m['name'], $visited);
            if (matchesAnyTrigger($combined, $cfg['triggers']) && !matchesAnyExclusion($combined, $cfg['excludes'])) {
                $methodDecisions[$cat][$m['name']] = true;
            }
        }

        // If all test methods got tagged for this category, promote to class-level.
        $testMethods = array_filter($methods, fn($m) => $m['isTest']);
        $testCount = count($testMethods);
        if ($testCount > 0 && count($methodDecisions[$cat]) === $testCount) {
            $classDecisions[$cat] = true;
            $methodDecisions[$cat] = [];
        }
    }

    return [$classDecisions, $methodDecisions];
}

function insertGroupInDocblock(string $docText, string $group, string $indent): string
{
    if (preg_match('/@group\s+' . preg_quote($group, '/') . '\b/', $docText)) {
        return $docText;
    }
    $pos = strrpos($docText, '*/');
    if ($pos === false) {
        return $docText;
    }
    $upTo = substr($docText, 0, $pos);
    $lineStart = strrpos($upTo, "\n");
    if ($lineStart === false) {
        $closingIndent = '';
    } else {
        $closingIndent = substr($upTo, $lineStart + 1);
    }
    $insertion = "* @group {$group}\n{$closingIndent}";
    return $upTo . $insertion . substr($docText, $pos);
}

function createDocblock(string $group, string $indent): string
{
    return "$indent/**\n$indent * @group $group\n$indent */\n";
}

function applyEdits(string $path, array $fileData, array $classDecisions, array $methodDecisions, bool $dryRun): array
{
    $lines = $fileData['lines'];
    $methods = $fileData['methods'];
    $classSigLine = $fileData['classSigLine'];
    $classDocStart = $fileData['classDocStart'];
    $classDocEnd = $fileData['classDocEnd'];

    $edits = [];
    $added = [];

    foreach ($classDecisions as $cat => $yes) {
        if (!$yes) {
            continue;
        }
        $added[] = ['class', $cat];

        if ($classDocStart !== null) {
            $docText = implode('', array_slice($lines, $classDocStart - 1, $classDocEnd - $classDocStart + 1));
            $indent = '';
            if (preg_match('/^(\s*)/', $lines[$classDocStart - 1], $im)) {
                $indent = $im[1];
            }
            $newDoc = insertGroupInDocblock($docText, $cat, $indent);
            $edits[] = [$classDocStart - 1, $classDocEnd - $classDocStart + 1, $newDoc];
        } else {
            $indent = '';
            if (preg_match('/^(\s*)/', $lines[$classSigLine - 1], $im)) {
                $indent = $im[1];
            }
            $edits[] = [$classSigLine - 1, 0, createDocblock($cat, $indent)];
        }
    }

    foreach ($methodDecisions as $cat => $names) {
        foreach ($names as $name => $yes) {
            if (!$yes) {
                continue;
            }
            $m = null;
            foreach ($methods as $meth) {
                if ($meth['name'] === $name) {
                    $m = $meth;
                    break;
                }
            }
            if ($m === null) {
                continue;
            }
            $added[] = ['method:' . $name, $cat];
            $indent = $m['indent'];
            if ($m['docStart'] !== null) {
                $docText = implode('', array_slice($lines, $m['docStart'] - 1, $m['docEnd'] - $m['docStart'] + 1));
                $newDoc = insertGroupInDocblock($docText, $cat, $indent);
                $edits[] = [$m['docStart'] - 1, $m['docEnd'] - $m['docStart'] + 1, $newDoc];
            } else {
                $edits[] = [$m['sigLine'] - 1, 0, createDocblock($cat, $indent)];
            }
        }
    }

    // Merge edits that affect the same line range (e.g., same docblock for 2 categories)
    // Sort by start line ascending. Merge sequential edits at same position.
    usort($edits, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

    // Merge replacements targeting the exact same range (both modifying same docblock)
    $merged = [];
    foreach ($edits as $e) {
        if (!empty($merged)) {
            $last = &$merged[count($merged) - 1];
            if ($last[0] === $e[0] && $last[1] === $e[1] && $e[1] > 0) {
                // Re-apply: insert each @group into the last replacement's doc text
                // Re-extract which group(s) the current edit adds by diffing with original
                // Easier: apply the diff textually using the same rule.
                $origDoc = implode('', array_slice($fileData['lines'], $e[0], $e[1]));
                // figure out which group the replacement text added:
                $prevText = $last[2];
                $currText = $e[2];
                if (preg_match_all('/@group\s+(\S+)/', $currText, $m)) {
                    foreach ($m[1] as $g) {
                        if (!str_contains($prevText, "@group $g")) {
                            $indent = '';
                            if (preg_match('/^(\s*)\*/', $prevText, $im)) {
                                $indent = $im[1];
                            } elseif (preg_match('/^(\s*)/', $prevText, $im)) {
                                $indent = rtrim($im[1], " \t");
                            }
                            // use indent from first non-empty line
                            $firstLine = explode("\n", $prevText)[0] ?? '';
                            if (preg_match('/^(\s*)/', $firstLine, $im)) {
                                $indent = $im[1];
                            }
                            $prevText = insertGroupInDocblock($prevText, $g, $indent);
                        }
                    }
                    $last[2] = $prevText;
                }
                continue;
            }
            // Same insert line, no replacement (both new docblocks at same line)
            if ($last[0] === $e[0] && $last[1] === 0 && $e[1] === 0) {
                // Merge two docblock inserts into one
                $combinedGroups = [];
                foreach ([$last[2], $e[2]] as $txt) {
                    if (preg_match_all('/@group\s+(\S+)/', $txt, $m)) {
                        foreach ($m[1] as $g) {
                            $combinedGroups[$g] = true;
                        }
                    }
                }
                $indent = '';
                if (preg_match('/^(\s*)/', $last[2], $im)) {
                    $indent = $im[1];
                }
                $doc = "$indent/**\n";
                foreach (array_keys($combinedGroups) as $g) {
                    $doc .= "$indent * @group $g\n";
                }
                $doc .= "$indent */\n";
                $last[2] = $doc;
                continue;
            }
        }
        $merged[] = $e;
    }

    // Apply in reverse order
    usort($merged, fn($a, $b) => $b[0] <=> $a[0]);
    foreach ($merged as [$start, $len, $repl]) {
        array_splice($lines, $start, $len, [$repl]);
    }

    $newContent = implode('', $lines);

    if (!$dryRun) {
        file_put_contents($path, $newContent);
    }

    return $added;
}

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($rii as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        $files[] = $f->getPathname();
    }
}
sort($files);

$summary = [
    'requires-server' => ['class' => [], 'method' => 0],
    'requires-chromedriver' => ['class' => [], 'method' => 0],
    'requires-mysql-server' => ['class' => [], 'method' => 0],
];

foreach ($files as $file) {
    $fileData = analyzeFile($file);
    if ($fileData['classSigLine'] === 0) {
        continue;
    }
    [$classDecisions, $methodDecisions] = classify($fileData, $categories);

    $anyClass = in_array(true, $classDecisions, true);
    $anyMethod = false;
    foreach ($methodDecisions as $names) {
        if (!empty($names)) {
            $anyMethod = true;
            break;
        }
    }

    if (!$anyClass && !$anyMethod) {
        continue;
    }

    $rel = substr($file, strlen($root) + 1);
    echo $rel . "\n";
    foreach ($classDecisions as $cat => $yes) {
        if ($yes) {
            echo "  CLASS -> $cat\n";
            $summary[$cat]['class'][] = $rel;
        }
    }
    foreach ($methodDecisions as $cat => $names) {
        foreach ($names as $name => $_) {
            echo "  {$name}() -> $cat\n";
            $summary[$cat]['method']++;
        }
    }

    applyEdits($file, $fileData, $classDecisions, $methodDecisions, $dryRun);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "SUMMARY" . ($dryRun ? " (dry-run)" : "") . "\n";
foreach ($summary as $cat => $s) {
    echo "  $cat: " . count($s['class']) . " classes, " . $s['method'] . " methods\n";
}
