<?php

namespace App\Outcomes;

/** A ledger row either asserts its claim or retracts the row it supersedes (docs/107 section 6). */
enum OutcomeState: string
{
    case Asserted = 'asserted';
    case Retracted = 'retracted';
}
