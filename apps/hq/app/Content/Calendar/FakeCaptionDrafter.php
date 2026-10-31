<?php

namespace App\Content\Calendar;

/**
 * The sandbox caption drafter (docs/145-a): deterministic, no model, no network. It writes the entry's
 * idea under the brand name and the first line of the product-information playbook, which is enough to
 * walk drafting, checks and review end to end. Bound only by a test or a staging release.
 */
final class FakeCaptionDrafter implements CaptionDrafter
{
    /** @var list<CaptionBrief> */
    public array $briefs = [];

    public function __construct(private readonly ?string $fixed = null) {}

    public function name(): string
    {
        return 'sandbox';
    }

    public function draft(CaptionBrief $brief): ?string
    {
        $this->briefs[] = $brief;
        if ($this->fixed !== null) {
            return $this->fixed;
        }

        $info = '';
        foreach ($brief->playbooks as $p) {
            if ($p['kind'] === 'product_information') {
                $info = trim((string) strtok($p['body'], "\n"));
                break;
            }
        }

        return trim($brief->brand.': '.$brief->idea.($info !== '' ? "\n".$info : ''));
    }
}
