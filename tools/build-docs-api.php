<?php

declare(strict_types=1);

/**
 * Generates the signature listing of docs/guides/api-reference.html from the real classes in src/, packages/redis/src
 * and packages/guzzle/src, and checks it.
 *
 *   php tools/build-docs-api.php            rewrite the generated region of the API reference page
 *   php tools/build-docs-api.php --check    exit 1 when the page differs from what the sources produce (no write)
 *
 * The page keeps hand-written prose outside the markers `<!-- api:begin -->` and `<!-- api:end -->`. Everything
 * between them is produced here by reflection, in a fixed order, so the output is deterministic and a changed
 * signature in src/ makes `--check` (and tools/check-docs.php, which calls it) fail until the page is regenerated.
 * The one-line descriptions below are written by hand; signatures, enum cases and public properties are not.
 * Members marked @internal are left out.
 *
 * Exit status: 0 when written or in sync, 1 when `--check` finds a difference, 2 on a usage or setup error.
 */

require __DIR__ . '/docs-highlight.php';

$root = dirname(__DIR__);
$page = $root . '/docs/guides/api-reference.html';

// The longest prefix comes first: the two adapter packages live below the namespace of Core.
spl_autoload_register(static function (string $class) use ($root): void {
    $roots = [
        'Enaxon\\Reqtide\\Redis\\' => $root . '/packages/redis/src/',
        'Enaxon\\Reqtide\\Guzzle\\' => $root . '/packages/guzzle/src/',
        'Enaxon\\Reqtide\\' => $root . '/src/',
    ];
    foreach ($roots as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

/**
 * Groups of the public surface. Each class is `Namespace\ClassName` below Enaxon\Reqtide, with a one-line description.
 *
 * @var list<array{id: string, title: string, intro: string, classes: array<string, string>}> $groups
 */
$groups = require __DIR__ . '/build-docs-api.data.php';

/** Shortens fully qualified names in a type or default-value string. */
$short = static fn(string $text): string => preg_replace('~\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+([A-Za-z_][A-Za-z0-9_]*)~', '$1', $text) ?? $text;

/** PHP 8.5 reflects `self` by class name; the declaring class is printed as `self` so the output is identical on every PHP version. */
$typeOf = static function (?ReflectionType $type, ?string $owner = null) use ($short): string {
    if ($type === null) {
        return '';
    }
    $text = (string) $type;
    if ($owner !== null) {
        $text = preg_replace('~(?<![A-Za-z0-9_\\\\])\\\\?' . preg_quote($owner, '~') . '(?![A-Za-z0-9_\\\\])~', 'self', $text) ?? $text;
    }

    return $short($text);
};

$defaultOf = static function (ReflectionParameter $parameter) use ($short): string {
    if (!$parameter->isDefaultValueAvailable()) {
        return '';
    }
    if ($parameter->isDefaultValueConstant()) {
        return ' = ' . $short((string) $parameter->getDefaultValueConstantName());
    }
    $value = $parameter->getDefaultValue();
    if ($value instanceof UnitEnum) {
        return ' = ' . $short($value::class) . '::' . $value->name;
    }
    if (is_object($value)) {
        return ' = new ' . $short($value::class) . '()';
    }
    if ($value === null) {
        return ' = null';
    }
    if (is_bool($value)) {
        return ' = ' . ($value ? 'true' : 'false');
    }
    if (is_array($value)) {
        return $value === [] ? ' = []' : ' = ' . str_replace("\n", ' ', var_export($value, true));
    }
    if (is_string($value)) {
        return " = '" . addcslashes($value, "'\\") . "'";
    }

    return ' = ' . var_export($value, true);
};

$parametersOf = static function (ReflectionFunctionAbstract $function) use ($typeOf, $defaultOf): array {
    $out = [];
    foreach ($function->getParameters() as $parameter) {
        $prefix = '';
        if ($parameter instanceof ReflectionParameter && $parameter->isPromoted()) {
            $declaring = $parameter->getDeclaringClass();
            $property = $declaring?->getProperty($parameter->getName());
            $visibility = match (true) {
                $property === null || $property->isPublic() => 'public',
                $property->isProtected() => 'protected',
                default => 'private',
            };
            $prefix = $visibility . ' ' . ($declaring !== null && $property !== null && !$declaring->isReadOnly() && $property->isReadOnly() ? 'readonly ' : '');
        }
        $type = $typeOf($parameter->getType(), $function instanceof ReflectionMethod ? $function->getDeclaringClass()->getName() : null);
        $out[] = $prefix . ($type === '' ? '' : $type . ' ') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName() . $defaultOf($parameter);
    }

    return $out;
};

$methodLine = static function (ReflectionMethod $method) use ($parametersOf, $typeOf): string {
    $parameters = $parametersOf($method);
    $head = 'public ' . ($method->isStatic() ? 'static ' : '') . 'function ' . $method->getName() . '(';
    $return = $method->getName() === '__construct' ? '' : ': ' . ($typeOf($method->getReturnType(), $method->getDeclaringClass()->getName()) ?: 'void');
    $one = $head . implode(', ', $parameters) . ')' . $return;
    if (strlen($one) <= 76 || $parameters === []) {
        return $one;
    }

    return $head . "\n    " . implode(",\n    ", $parameters) . ",\n)" . $return;
};

$isInternal = static function (ReflectionMethod|ReflectionProperty|ReflectionClass $member): bool {
    $doc = $member->getDocComment();

    return is_string($doc) && str_contains($doc, '@internal');
};

$escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$slug = static fn(string $class): string => strtolower(trim(preg_replace('~[^A-Za-z0-9]+~', '-', $class) ?? $class, '-'));

$html = [];
$problems = [];
$seen = [];
foreach ($groups as $group) {
    $html[] = '<h2 id="' . $group['id'] . '">' . $escape($group['title']) . '<a class="anchor" href="#' . $group['id'] . '" aria-label="Link to this section">#</a></h2>';
    $html[] = '<p>' . $group['intro'] . '</p>';
    foreach ($group['classes'] as $name => [$description, $methodNotes]) {
        $fqcn = 'Enaxon\\Reqtide\\' . $name;
        if (isset($seen[$fqcn])) {
            $problems[] = "{$fqcn} is listed twice";
        }
        $seen[$fqcn] = true;
        if (!class_exists($fqcn) && !interface_exists($fqcn) && !enum_exists($fqcn)) {
            $problems[] = "{$fqcn} does not exist in src/ or in the adapter packages";
            continue;
        }
        $reflection = new ReflectionClass($fqcn);
        if ($isInternal($reflection)) {
            $problems[] = "{$fqcn} is @internal and must not be documented";
            continue;
        }
        $kind = match (true) {
            $reflection->isEnum() => 'enum',
            $reflection->isInterface() => 'interface',
            $reflection->isFinal() && $reflection->isReadOnly() => 'final readonly class',
            $reflection->isFinal() => 'final class',
            $reflection->isAbstract() => 'abstract class',
            default => 'class',
        };
        $lines = [];
        $heading = $kind . ' ' . $reflection->getShortName();
        if ($reflection->isEnum()) {
            $backing = (new ReflectionEnum($fqcn))->getBackingType();
            if ($backing !== null) {
                $heading .= ': ' . $backing;
            }
        }
        $implements = array_map(static fn(string $i): string => $short($i), array_values(array_filter(
            $reflection->getInterfaceNames(),
            static fn(string $i): bool => str_starts_with($i, 'Enaxon\\') && !in_array($i, array_merge(...array_map(
                static fn(ReflectionClass $p): array => $p->getInterfaceNames(),
                array_filter([$reflection->getParentClass() ?: null]),
            )), true),
        )));
        $parent = $reflection->getParentClass();
        if ($parent !== false && str_starts_with($parent->getName(), 'Enaxon\\')) {
            $heading .= ' extends ' . $parent->getShortName();
        }
        if ($implements !== [] && !$reflection->isInterface()) {
            $heading .= ' implements ' . implode(', ', $implements);
        } elseif ($implements !== [] && $reflection->isInterface()) {
            $heading .= ' extends ' . implode(', ', $implements);
        }
        $lines[] = $heading;

        if ($reflection->isEnum()) {
            foreach ($reflection->getReflectionConstants() as $constant) {
                if (!$constant->isEnumCase() || !$constant->isPublic()) {
                    continue;
                }
                $case = new ReflectionEnumBackedCase($fqcn, $constant->getName());
                $lines[] = '    case ' . $constant->getName() . ($case instanceof ReflectionEnumBackedCase ? ' = ' . var_export($case->getBackingValue(), true) : '') . ';';
            }
        } else {
            foreach ($reflection->getReflectionConstants() as $constant) {
                if ($constant->isPublic() && $constant->getDeclaringClass()->getName() === $fqcn) {
                    $lines[] = '    public const ' . $constant->getName() . ' = ' . $short(var_export($constant->getValue(), true)) . ';';
                }
            }
        }
        // Public properties that are not promoted by the constructor.
        $promoted = [];
        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->isPromoted()) {
                    $promoted[$parameter->getName()] = true;
                }
            }
        }
        if (!$reflection->isEnum()) {
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $fqcn || isset($promoted[$property->getName()]) || $isInternal($property)) {
                    continue;
                }
                $lines[] = '    public ' . ($property->isReadOnly() && !$reflection->isReadOnly() ? 'readonly ' : '') . ($typeOf($property->getType(), $fqcn) !== '' ? $typeOf($property->getType(), $fqcn) . ' ' : '') . '$' . $property->getName() . ';';
            }
        }
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $fqcn || $isInternal($method)) {
                continue;
            }
            if (str_starts_with($method->getName(), '__') && $method->getName() !== '__construct') {
                continue;
            }
            if ($reflection->isEnum() && in_array($method->getName(), ['cases', 'from', 'tryFrom'], true)) {
                continue;
            }
            $line = $methodLine($method);
            $lines[] = '    ' . str_replace("\n", "\n    ", $line) . ';';
        }
        $block = [];
        foreach ($lines as $i => $line) {
            $block[] = $i === 0 ? $line . ' {' : $line;
        }
        $block[] = '}';
        foreach (array_keys($methodNotes) as $noted) {
            if (!$reflection->hasMethod($noted) || $isInternal($reflection->getMethod($noted))) {
                $problems[] = "{$fqcn}::{$noted} is described in build-docs-api.data.php but is not a public method";
            }
        }
        $html[] = '<h3 id="' . $slug($name) . '"><code>' . $escape($reflection->getShortName()) . '</code> <small>' . $escape($reflection->getNamespaceName()) . '</small></h3>';
        $html[] = '<p>' . $description . '</p>';
        $pre = '<pre tabindex="0" role="group" aria-label="Signatures of ' . $escape($reflection->getShortName()) . '" data-api="' . $escape($fqcn) . '"><code class="language-api">' . docsHighlightPhp(implode("\n", $block)) . '</code></pre>';
        if ($reflection->isEnum() && count($lines) > 14) {
            $html[] = '<details><summary>Show the ' . (count($lines) - 1) . ' cases</summary>' . $pre . '</details>';
        } else {
            $html[] = $pre;
        }
        if ($methodNotes !== []) {
            $items = [];
            foreach ($methodNotes as $methodName => $note) {
                $items[] = '<dt><code>' . $escape($methodName) . '()</code></dt><dd>' . $note . '</dd>';
            }
            $html[] = '<dl class="api-methods">' . implode('', $items) . '</dl>';
        }
    }
}
if ($problems !== []) {
    fwrite(STDERR, "build-docs-api: " . implode("\n", $problems) . "\n");
    exit(2);
}

$generated = implode("\n", $html);
$begin = '<!-- api:begin -->';
$end = '<!-- api:end -->';
$current = is_file($page) ? (string) file_get_contents($page) : '';
$current = str_replace("\r\n", "\n", $current);
$start = strpos($current, $begin);
$stop = strpos($current, $end);
if ($start === false || $stop === false || $stop < $start) {
    fwrite(STDERR, "build-docs-api: the markers {$begin} and {$end} are missing from {$page}\n");
    exit(2);
}
$next = substr($current, 0, $start + strlen($begin)) . "\n" . $generated . "\n" . substr($current, $stop);

if (in_array('--check', $argv, true)) {
    if ($next !== $current) {
        fwrite(STDERR, "build-docs-api: docs/guides/api-reference.html is out of date with the sources. Run: php tools/build-docs-api.php\n");
        exit(1);
    }
    echo 'build-docs-api: the API reference matches the sources (' . count($seen) . " types)\n";
    exit(0);
}
file_put_contents($page, $next);
echo 'build-docs-api: wrote ' . count($seen) . " types to docs/guides/api-reference.html\n";
