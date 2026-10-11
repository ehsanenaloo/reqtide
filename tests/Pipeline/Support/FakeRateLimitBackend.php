<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Admission\RateLimitInspection;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\State\RateLimitBackend;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\PositiveInt;

final class FakeRateLimitBackend extends FakeBackend implements RateLimitBackend
{
    /** @var list<AdmissionDecision> consumed in order; an empty queue accepts */
    public array $script = [];

    public ?\Throwable $failure = null;

    public int $consumed = 0;

    /** @var list<int> */
    public array $costs = [];

    public function consume(RateLimitPolicy $policy, PositiveInt $cost, CommandId $command): AdmissionDecision
    {
        $this->enter('rate.consume');
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $decision = array_shift($this->script) ?? new AdmissionDecision(AdmissionStatus::Accepted, 'accepted', null, null);
        if ($decision->status === AdmissionStatus::Accepted) {
            ++$this->consumed;
            $this->costs[] = $cost->value;
        }

        return $decision;
    }

    public function inspect(RateLimitPolicy $policy, PositiveInt $requestedCost): RateLimitInspection
    {
        $this->enter('rate.inspect');

        throw new \LogicException('The pipeline never inspects the rate limit.');
    }
}
