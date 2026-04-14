<?php

declare(strict_types=1);

$write = in_array('--write', $argv, true);
$reportFile = __DIR__ . '/../var/_output/report.xml';

if (!file_exists($reportFile)) {
    fwrite(STDERR, "Report file not found: $reportFile\n");
    exit(1);
}

$xml = new SimpleXMLElement(file_get_contents($reportFile));

$classData = [];

foreach ($xml->testsuite->testcase as $tc) {
    $class = (string)$tc['class'];
    $file = (string)$tc['file'];
    $time = (float)$tc['time'];
    $rawName = (string)$tc['name'];
    $method = preg_replace('/ with data set .*$/s', '', $rawName);

    if (!isset($classData[$class])) {
        $classData[$class] = ['file' => $file, 'methods' => []];
    }
    if (!isset($classData[$class]['methods'][$method])) {
        $classData[$class]['methods'][$method] = 0.0;
    }
    if ($time > $classData[$class]['methods'][$method]) {
        $classData[$class]['methods'][$method] = $time;
    }
}

const FAST_THRESHOLD = 0.1;
const SLOW_THRESHOLD = 1.0;

function classify(float $time): string
{
    if ($time < FAST_THRESHOLD) {
        return 'fast';
    }
    if ($time >= SLOW_THRESHOLD) {
        return 'slow';
    }
    return 'normal';
}

$filesChanged = 0;
$classTagsAdded = 0;
$methodTagsAdded = 0;

foreach ($classData as $class => $data) {
    $file = $data['file'];
    $methods = $data['methods'];

    $buckets = array_map('classify', $methods);
    $uniqueBuckets = array_unique(array_values($buckets));

    if (count($uniqueBuckets) === 1 && $uniqueBuckets[0] === 'fast') {
        $verdict = 'uniform-fast';
    } elseif (count($uniqueBuckets) === 1 && $uniqueBuckets[0] === 'slow') {
        $verdict = 'uniform-slow';
    } elseif (count($uniqueBuckets) === 1 && $uniqueBuckets[0] === 'normal') {
        $verdict = 'uniform-normal';
    } else {
        $verdict = 'mixed';
    }

    if ($verdict === 'uniform-normal') {
        continue;
    }

    $original = file_get_contents($file);
    $content = $original;

    $content = stripFastSlowGroupLines($content);

    $shortClass = ltrim(strrchr($class, '\\') ?: ('\\' . $class), '\\');

    if ($verdict === 'uniform-fast') {
        $content = insertClassGroupTag($content, 'fast', $shortClass);
        $classTagsAdded++;
    } elseif ($verdict === 'uniform-slow') {
        $content = insertClassGroupTag($content, 'slow', $shortClass);
        $classTagsAdded++;
    } else {
        foreach ($methods as $method => $time) {
            $bucket = classify($time);
            if ($bucket === 'normal') {
                continue;
            }
            $content = insertMethodGroupTag($content, $method, $bucket);
            $methodTagsAdded++;
        }
    }

    if ($content !== $original) {
        $filesChanged++;
        if ($write) {
            file_put_contents($file, $content);
        } else {
            echo "  [dry-run] Would modify: $file\n";
        }
    }
}

echo "Files changed:        $filesChanged\n";
echo "Class tags added:     $classTagsAdded\n";
echo "Method tags added:    $methodTagsAdded\n";

function stripFastSlowGroupLines(string $content): string
{
    return preg_replace('/^[ \t]*\*[ \t]*@group[ \t]+(fast|slow)[ \t]*\r?\n/m', '', $content);
}

function findDocblockBefore(string $content, int $targetPos): ?array
{
    $before = substr($content, 0, $targetPos);

    $closePos = strrpos($before, '*/');
    if ($closePos === false) {
        return null;
    }

    $openPos = strrpos(substr($before, 0, $closePos + 1), '/**');
    if ($openPos === false) {
        return null;
    }

    $between = substr($before, $closePos + 2, $targetPos - $closePos - 2);
    if (preg_match('/[^\s]/', $between)) {
        return null;
    }

    return [
        'docblock' => substr($content, $openPos, $closePos + 2 - $openPos),
        'start'    => $openPos,
        'end'      => $closePos + 2,
    ];
}

function insertClassGroupTag(string $content, string $group, string $shortClass): string
{
    $escapedClass = preg_quote($shortClass, '/');
    $classPattern = '/([ \t]*)((?:(?:abstract|final)[ \t]+)*class[ \t]+' . $escapedClass . '(?:[ \t]|{|\n|\r))/m';
    if (!preg_match($classPattern, $content, $classMatch, PREG_OFFSET_CAPTURE)) {
        return $content;
    }

    $classPos = $classMatch[0][1];
    $classIndent = $classMatch[1][0];

    $info = findDocblockBefore($content, $classPos);

    if ($info !== null) {
        $docblock = $info['docblock'];
        $newDocblock = insertGroupIntoDocblock($docblock, $group, $classIndent);
        return substr($content, 0, $info['start']) . $newDocblock . substr($content, $info['end']);
    }

    $newDocblock = $classIndent . "/**\n" . $classIndent . " * @group " . $group . "\n" . $classIndent . " */\n";
    return substr($content, 0, $classPos) . $newDocblock . substr($content, $classPos);
}

function insertMethodGroupTag(string $content, string $methodName, string $group): string
{
    $escapedMethod = preg_quote($methodName, '/');
    $funcPattern = '/([ \t]*)((?:(?:public|protected|private|final|abstract|static)[ \t]+)*function[ \t]+' . $escapedMethod . '[ \t]*\()/m';

    if (!preg_match($funcPattern, $content, $funcMatch, PREG_OFFSET_CAPTURE)) {
        return $content;
    }

    $funcPos = $funcMatch[0][1];
    $funcIndent = $funcMatch[1][0];

    $info = findDocblockBefore($content, $funcPos);

    if ($info !== null) {
        $docblock = $info['docblock'];
        $newDocblock = insertGroupIntoDocblock($docblock, $group, $funcIndent);
        return substr($content, 0, $info['start']) . $newDocblock . substr($content, $info['end']);
    }

    $newDocblock = $funcIndent . "/**\n" . $funcIndent . " * @group " . $group . "\n" . $funcIndent . " */\n";
    return substr($content, 0, $funcPos) . $newDocblock . substr($content, $funcPos);
}

function insertGroupIntoDocblock(string $docblock, string $group, string $indent): string
{
    $newLine = $indent . ' * @group ' . $group;
    return preg_replace('/(\r?\n)([ \t]*\*\/)$/', '$1' . $newLine . '$1$2', $docblock);
}
