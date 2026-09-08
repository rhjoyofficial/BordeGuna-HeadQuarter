<?php

namespace App\Gateway;

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Uncertain;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Maps a thrown exception onto the gateway's failure vocabulary (docs/48 D.3 step 10).
 *
 * The five commerce exceptions stay where they are, in App\Commerce\Exceptions. They are already
 * used by working connectors and moving them would be churn in code that has been proven against
 * three production systems. What M1 adds is one place that reads them, so a tool failure means the
 * same thing to the scheduler, to a screen and later to an agent.
 *
 * The default is Internal, not Refused. An exception nobody classified is a defect in HQ and should
 * look like one, rather than being quietly recorded as the far side saying no.
 */
final class FailureClassifier
{
    public function classify(Throwable $e): FailureClass
    {
        return match (true) {
            $e instanceof Uncertain => FailureClass::Uncertain,
            $e instanceof Unavailable, $e instanceof ConnectionException => FailureClass::Unavailable,
            $e instanceof Refused => FailureClass::Refused,
            $e instanceof Unsupported => FailureClass::Unsupported,
            $e instanceof ValidationFailed => FailureClass::ValidationFailed,
            default => FailureClass::Internal,
        };
    }
}
