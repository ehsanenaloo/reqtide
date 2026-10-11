<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline\Internal;

/**
 * Control signal: the candidate retry was stopped before dispatch (budget, allowance or commit decision) and
 * the previous attempt's business result remains the logical result.
 *
 * @internal
 */
final class RetryDeclined extends \RuntimeException {}
