<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline;

/** Fixed lifecycle positions a declarative Strategy may be associated with.
 *
 * @internal Reserved seam; the builder installs none.
 */
enum LifecycleStage: string
{
    case BeforeCircuitAdmission = 'before_circuit_admission';
    case BeforeConcurrencyAdmission = 'before_concurrency_admission';
    case BeforeRateAdmission = 'before_rate_admission';
    case BeforeDispatch = 'before_dispatch';
    case AfterSettlementClassification = 'after_settlement_classification';
}
