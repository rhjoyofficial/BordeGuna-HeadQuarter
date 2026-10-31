<?php

/*
 | Test-only child process for MediaBudgetTest's concurrency test (ruling 31 Oct item 12). Boots the application against the
 | database named in the environment, waits until a shared start instant so every child reserves at the same moment, then makes
 | ONE reservation and prints OK or REFUSED. Never part of the application: it lives under tests/ and is run by the test only.
 |
 | Usage: php reserve-probe.php <business_id> <amount_bdt> <reference> <start_at_unix_float>
 */

use App\Media\Budget\MediaBudgetRefused;
use App\Media\Budget\MediaBudgets;
use App\Media\Budget\MediaCost;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $businessId, $amount, $reference, $startAt] = $argv;

$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    app(MediaBudgets::class)->reserve((int) $businessId, 'video', 'probe', 'test.probe', $reference, new MediaCost($amount, 'BDT', 'probe'));
    echo 'OK';
} catch (MediaBudgetRefused) {
    echo 'REFUSED';
}
