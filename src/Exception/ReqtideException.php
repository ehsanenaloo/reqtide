<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Exception;

/**
 * Marker for every exception raised by Reqtide itself.
 *
 * Original business exceptions raised by a protected operation are never wrapped in
 * this type; they keep their own class.
 */
interface ReqtideException extends \Throwable {}
