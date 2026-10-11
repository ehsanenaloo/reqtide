<?php

declare(strict_types=1);

/**
 * Writes build/mutation/phpunit.xml for `composer mutation`: the Core and Testing tests only, with absolute paths.
 *
 * Infection copies this file with its paths unchanged, and PHPUnit then drops coverage for a source directory that is
 * written with "..". The adapter suites are left out because they start child processes and servers, which makes the
 * initial run slow and fragile under coverage.
 */

$root = dirname(__DIR__);
$directory = $root . '/build/mutation';
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "cannot create {$directory}\n");
    exit(1);
}

$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/12.0/phpunit.xsd"
         bootstrap="{$root}/tools/bootstrap.php"
         colors="true"
         cacheDirectory="{$root}/build/.phpunit.cache"
         failOnWarning="true"
         failOnRisky="true"
         failOnDeprecation="true"
         failOnNotice="true"
         beStrictAboutOutputDuringTests="true"
         executionOrder="random"
         displayDetailsOnTestsThatTriggerWarnings="true">
    <testsuites>
        <testsuite name="core">
            <directory>{$root}/tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>{$root}/src</directory>
        </include>
    </source>
</phpunit>
XML;

file_put_contents($directory . '/phpunit.xml', $xml . "\n");
echo "wrote build/mutation/phpunit.xml\n";
