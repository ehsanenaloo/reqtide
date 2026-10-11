<?php

declare(strict_types=1);

/**
 * Regenerates packages/redis/tests/Vectors/exact_int.json from the bcmath oracle.
 *
 *   php packages/redis/tools/generate-exact-int-vectors.php          write the file
 *   php packages/redis/tools/generate-exact-int-vectors.php --check  exit 1 when the committed file is stale
 *
 * Needs ext-bcmath (present in the Docker lanes). Development tool only; not shipped.
 */

use Enaxon\Reqtide\Redis\Tests\Support\ExactIntVectors;

$vendor = getenv('COMPOSER_VENDOR_DIR');
$vendor = is_string($vendor) && $vendor !== '' ? $vendor : dirname(__DIR__, 3) . '/vendor';
require $vendor . '/autoload.php';

if (!extension_loaded('bcmath')) {
    fwrite(STDERR, "generate-exact-int-vectors: ext-bcmath is required.\n");
    exit(2);
}

$file = dirname(__DIR__) . '/tests/Vectors/exact_int.json';
$text = ExactIntVectors::encode(ExactIntVectors::generate());

if (in_array('--check', $argv, true)) {
    if (!is_file($file) || file_get_contents($file) !== $text) {
        fwrite(STDERR, "generate-exact-int-vectors: {$file} is stale; run the generator without --check.\n");
        exit(1);
    }
    echo "generate-exact-int-vectors: up to date\n";
    exit(0);
}

if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0777, true)) {
    fwrite(STDERR, "generate-exact-int-vectors: cannot create the vectors directory.\n");
    exit(2);
}
file_put_contents($file, $text);
echo 'generate-exact-int-vectors: wrote ' . strlen($text) . " bytes to {$file}\n";
