<?php

namespace Tests\Support\Briefing;

use App\Briefing\BriefingNarrator;
use App\Briefing\EvidenceBundle;
use App\Briefing\Narration;
use Closure;

/**
 * A scripted narrator: it writes whatever the test tells it to, from the bundle it is handed. It
 * exists to prove that what gates the stored narrative is the unsourced-number check and not the
 * narrator's good behaviour. No model, no network.
 */
final class FakeNarrator implements BriefingNarrator
{
    public static int $calls = 0;

    public static ?string $lastTask = null;

    /** @param  Closure(EvidenceBundle): string  $script */
    public function __construct(private readonly Closure $script) {}

    public function narrate(string $trustedTask, EvidenceBundle $bundle): Narration
    {
        self::$calls++;
        self::$lastTask = $trustedTask;

        return new Narration(($this->script)($bundle), 'fake');
    }
}
