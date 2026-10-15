<?php

declare(strict_types=1);

/**
 * Checks that the code blocks of the public documentation are real, and that the generated parts are current.
 *
 *   php tools/check-docs.php [file ...]
 *
 * Default files: README.md and .github/README.fa.md, and, where a docs/ folder exists, docs/README.md and docs/guides/*.html. A file ending in .md is read for fenced blocks,
 * a file ending in .html for `<pre><code class="language-...">` blocks. For both:
 *
 *  - `php` blocks must be a contiguous excerpt of one script in examples/NN-*.php;
 *  - `text` and `text-output` blocks must be a contiguous excerpt of one examples/expected/NN-*.out file;
 *  - the same two rules apply to docs/guides/redis.html and docs/guides/guzzle.html, but against packages/redis/examples
 *    and packages/guzzle/examples only;
 *  - `bash`, `sh` and `json` blocks are checked for what can be checked without running them: every repository
 *    path they name must exist, every `enaxon/reqtide...` name must be the name of the root package or of a package under
 *    packages/, and a git clone must name this repository. The commands themselves run in tools/run-examples.sh;
 *  - `api` blocks are checked as a group by `tools/build-docs-api.php --check`, which regenerates the API
 *    reference from src/ and compares.
 *
 * Two normalizations are applied to the examples before comparing: the leading file docblock is dropped, and the
 * autoloader line `require __DIR__ . '/autoload.php';` is read as `require __DIR__ . '/vendor/autoload.php';`,
 * which is what a project would write. Without file arguments the script also runs
 * `tools/build-docs-api.php --check` and `tools/build-docs-index.php --check`, so a stale generated file fails.
 * The script checks text, not behavior: `tools/run-examples.sh` is what executes the examples and compares their
 * output.
 *
 * Exit status: 0 when every check passes, 1 on any mismatch, 2 on a usage or setup error.
 */

$root = dirname(__DIR__);

/** @return string file contents with LF line endings */
$read = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "cannot read {$path}\n");
        exit(2);
    }

    return str_replace("\r\n", "\n", $content);
};

/**
 * @return array{0: list<string>, 1: list<string>} the example scripts (with and without their file docblock) and the
 *         recorded outputs found below a directory that holds `examples/NN-*.php` and `examples/expected/NN-*.out`
 */
$loadExamples = static function (string $base) use ($read): array {
    $scripts = glob($base . '/examples/[0-9][0-9]-*.php') ?: [];
    $outputs = glob($base . '/examples/expected/[0-9][0-9]-*.out') ?: [];
    if ($scripts === [] || $outputs === []) {
        fwrite(STDERR, "no examples found under {$base}/examples\n");
        exit(2);
    }
    $codeSources = [];
    foreach ($scripts as $script) {
        $source = $read($script);
        $withoutDocblock = preg_replace('~\A(<\?php\n\ndeclare\(strict_types=1\);\n\n)/\*\*.*?\*/\n\n~s', '$1', $source, 1) ?? $source;
        foreach ([$source, $withoutDocblock] as $variant) {
            $codeSources[] = str_replace("require __DIR__ . '/autoload.php';", "require __DIR__ . '/vendor/autoload.php';", $variant);
        }
    }

    return [$codeSources, array_map($read, $outputs)];
};

[$codeSources, $outputSources] = $loadExamples($root);

// The guides of the two adapter packages are checked against the examples of their own package only, so a block in one
// of them can never pass by matching an example of the core package, and the other way round.
$packageGuides = ['redis.html' => 'packages/redis', 'guzzle.html' => 'packages/guzzle'];
$packageSources = [];
foreach ($packageGuides as $guide => $directory) {
    $packageSources[$guide] = $loadExamples($root . '/' . $directory);
}

$composer = json_decode($read($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$packageName = (string) ($composer['name'] ?? '');
$knownPackages = [$packageName];
foreach (glob($root . '/packages/*/composer.json') ?: [] as $manifest) {
    $knownPackages[] = (string) (json_decode($read($manifest), true, 512, JSON_THROW_ON_ERROR)['name'] ?? '');
}
$homepage = (string) ($composer['homepage'] ?? '');

$explicit = array_slice($argv, 1);
$files = $explicit;
if ($files === []) {
    $files = [$root . '/README.md', $root . '/.github/README.fa.md'];
    if (is_file($root . '/docs/README.md')) {
        $files = array_merge($files, [$root . '/docs/README.md'], glob($root . '/docs/guides/*.html') ?: []);
    }
}

/**
 * @return list<array{language: string, body: string, line: int}> the fenced or <pre><code> blocks of a file
 */
$blocksOf = static function (string $file, string $text): array {
    $blocks = [];
    if (str_ends_with($file, '.html')) {
        preg_match_all('~<pre\b[^>]*><code(?: class="language-([\w-]+)")?>(.*?)</code></pre>~s', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $block) {
            $body = html_entity_decode(strip_tags($block[2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $blocks[] = [
                'language' => $block[1][0] ?? '',
                'body' => rtrim($body, "\n"),
                'line' => substr_count(substr($text, 0, (int) $block[0][1]), "\n") + 1,
            ];
        }

        return $blocks;
    }
    preg_match_all('~```([\w-]*)\n(.*?)```~s', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $block) {
        $blocks[] = [
            'language' => $block[1][0],
            'body' => rtrim($block[2][0], "\n"),
            'line' => substr_count(substr($text, 0, (int) $block[0][1]), "\n") + 1,
        ];
    }

    return $blocks;
};

$checked = 0;
$mismatches = 0;
foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "not a file: {$file}\n");
        exit(2);
    }
    $text = $read($file);
    $label = basename(dirname($file)) . '/' . basename($file);
    [$fileCode, $fileOutput] = $packageSources[basename($file)] ?? [$codeSources, $outputSources];
    foreach ($blocksOf($file, $text) as $block) {
        $language = $block['language'];
        $body = $block['body'];
        $problem = null;
        switch ($language) {
            case 'php':
            case 'text':
            case 'text-output':
                $sources = $language === 'php' ? $fileCode : $fileOutput;
                ++$checked;
                $found = false;
                foreach ($sources as $source) {
                    if (str_contains($source, $body)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $problem = "{$language} block is not an excerpt of an example";
                }
                break;
            case 'bash':
            case 'sh':
            case 'json':
                ++$checked;
                if (preg_match_all('~(?<![\w/.-])((?:tools|examples|docs|benchmarks|src|packages)/[\w./-]+)~', $body, $paths)) {
                    foreach ($paths[1] as $path) {
                        if (!file_exists($root . '/' . rtrim($path, '.'))) {
                            $problem = "{$language} block names a path that does not exist: {$path}";
                            break;
                        }
                    }
                }
                if ($problem === null && preg_match_all('~\b([a-z0-9-]+/reqtide[\w-]*)~', $body, $names)) {
                    foreach ($names[1] as $name) {
                        if (!in_array($name, $knownPackages, true) && !str_contains($body, 'github.com/' . $name)) {
                            $problem = "{$language} block names a package that is not composer.json's: {$name}";
                            break;
                        }
                    }
                }
                if ($problem === null && preg_match('~git clone (\S+)~', $body, $clone) && rtrim($clone[1], '/') !== $homepage . '.git' && rtrim($clone[1], '/') !== $homepage) {
                    $problem = "{$language} block clones {$clone[1]}, which is not the repository in composer.json";
                }
                break;
            default:
                break; // api blocks are checked as a group below; other tags are not checked
        }
        if ($problem !== null) {
            ++$mismatches;
            fwrite(STDERR, "MISMATCH {$label}:{$block['line']} ({$problem})\n");
        }
    }
}

$generated = 0;
if ($explicit === []) {
    foreach (['build-docs-api.php', 'build-docs-index.php'] as $tool) {
        if (!is_file(__DIR__ . '/' . $tool)) {
            continue;
        }
        ++$generated;
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $tool) . ' --check 2>&1';
        $lines = [];
        $status = 0;
        exec($command, $lines, $status);
        if ($status !== 0) {
            ++$mismatches;
            fwrite(STDERR, 'MISMATCH ' . implode(' ', $lines) . "\n");
        }
    }
}

echo "check-docs: {$checked} block(s) checked, {$generated} generated file(s) checked, {$mismatches} mismatch(es)\n";
exit($mismatches === 0 ? 0 : 1);
