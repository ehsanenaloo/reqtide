<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools', __DIR__ . '/packages'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3.0' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'native_function_invocation' => false,
        'phpdoc_align' => false,
        'phpdoc_summary' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/build/.php-cs-fixer.cache');
