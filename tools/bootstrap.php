<?php

declare(strict_types=1);

$vendor = getenv('COMPOSER_VENDOR_DIR');
$vendor = is_string($vendor) && $vendor !== '' ? $vendor : dirname(__DIR__) . '/vendor';

require $vendor . '/autoload.php';
