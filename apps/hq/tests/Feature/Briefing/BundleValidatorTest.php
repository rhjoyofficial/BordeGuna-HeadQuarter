<?php

use App\Briefing\BundleSection;
use App\Briefing\BundleValidator;
use App\Briefing\EvidenceBundle;
use App\Briefing\EvidenceItem;
use App\Briefing\EvidenceKind;
use App\Briefing\EvidenceScope;
use App\Briefing\ExecutiveBriefingContract;
use App\Briefing\Freshness;
use App\Briefing\Lineage;
use App\Briefing\SourceFreshness;
use Carbon\CarbonImmutable;

/*
 | The bundle validator (docs/80 C.1): completeness, provenance, freshness. Each case takes a bundle
 | that is complete and breaks exactly one thing, because a validator that passes a broken bundle is
 | the silent pass the instruction forbids.
 */

function validItem(string $id = 'orders.placed.store:laladia-com', array $with = []): EvidenceItem
{
    $now = CarbonImmutable::parse('2026-09-20 03:00', 'UTC');

    return new EvidenceItem(
        id: $id,
        kind: $with['kind'] ?? EvidenceKind::Metric,
        section: 'yesterday',
        label: 'x',
        value: $with['value'] ?? 2,
        unit: $with['unit'] ?? 'orders',
        scope: new EvidenceScope('laladia', null, 'laladia-com'),
        source: $with['source'] ?? ['system' => 'shop', 'read_model' => 'facts_orders', 'ref' => 'facts_orders:laladia-com'],
        asOf: array_key_exists('asOf', $with) ? $with['asOf'] : $now,
        freshness: $with['freshness'] ?? Freshness::measure($now, 1560, $now),
        lineage: array_key_exists('lineage', $with) ? $with['lineage'] : Lineage::ofRows('count_rows', 'facts_orders', [['id' => 1], ['id' => 2]], ['store' => 'laladia-com']),
    );
}

/** A complete bundle: every contract section present, one item, one fresh source. */
function validBundle(?array $items = null, ?array $sections = null, ?array $sources = null): EvidenceBundle
{
    $now = CarbonImmutable::parse('2026-09-20 03:00', 'UTC');
    $items ??= [validItem()];

    $sections ??= array_map(
        fn (string $key, string $title) => $key === 'yesterday'
            ? new BundleSection($key, $title, BundleSection::OK, null, array_map(fn ($i) => $i->id, $items))
            : new BundleSection($key, $title, BundleSection::EMPTY, 'Nothing was found.'),
        array_keys(ExecutiveBriefingContract::SECTIONS), ExecutiveBriefingContract::SECTIONS,
    );

    return new EvidenceBundle(ExecutiveBriefingContract::CAPABILITY, '2026-09-20', 'Asia/Dhaka', $now,
        ['date' => '2026-09-19', 'from' => 'a', 'to' => 'b'], ['date' => '2026-09-20', 'from' => 'b', 'to' => 'c'],
        ['business' => null, 'permitted_business_ids' => null],
        $sources ?? [new SourceFreshness('facts_orders:laladia-com', 'facts_orders', new EvidenceScope('laladia', null, 'laladia-com'), $now, Freshness::measure($now, 1560, $now), 2)],
        $sections, $items);
}

function faults(EvidenceBundle $bundle): array
{
    return app(BundleValidator::class)->validate($bundle)->codes();
}

it('passes a complete bundle, and has no third state', function () {
    $report = app(BundleValidator::class)->validate(validBundle());

    expect($report->state())->toBe('complete')->and($report->reasons)->toBe([]);
});

it('calls a bundle that does not exist degraded, and blocks narration', function () {
    $report = app(BundleValidator::class)->validate(null);

    expect($report->state())->toBe('degraded')->and($report->codes())->toBe(['bundle_missing'])->and($report->blocksNarration())->toBeTrue();
});

it('faults a missing section, a section that is silent, and an item nobody lists', function () {
    $without = array_values(array_filter(validBundle()->sections, fn ($s) => $s->key !== 'finance'));
    expect(faults(validBundle(null, $without)))->toBe(['section_missing']);

    $silent = array_map(fn ($s) => $s->key === 'finance' ? new BundleSection('finance', 'Finance', BundleSection::NOT_CONNECTED, ' ') : $s, validBundle()->sections);
    expect(faults(validBundle(null, $silent)))->toBe(['section_silent']);

    $claims = array_map(fn ($s) => $s->key === 'inventory' ? new BundleSection('inventory', 'Inventory', BundleSection::OK, null, []) : $s, validBundle()->sections);
    expect(faults(validBundle(null, $claims)))->toBe(['section_silent']);

    $ghost = array_map(fn ($s) => $s->key === 'inventory' ? new BundleSection('inventory', 'Inventory', BundleSection::OK, null, ['no.such.item']) : $s, validBundle()->sections);
    expect(faults(validBundle(null, $ghost)))->toBe(['section_item_unresolved']);

    $sections = validBundle()->sections;
    expect(faults(validBundle([validItem(), validItem('orphan')], $sections)))->toBe(['item_orphaned'])
        ->and(faults(validBundle([validItem(), validItem()])))->toContain('item_duplicate_id');
});

it('refuses the narrator\'s kinds as evidence', function () {
    foreach ([EvidenceKind::Inference, EvidenceKind::Recommendation] as $kind) {
        $report = app(BundleValidator::class)->validate(validBundle([validItem('x', ['kind' => $kind])]));
        expect($report->codes())->toBe(['narrator_kind_in_bundle'])->and($report->blocksNarration())->toBeTrue();
    }
});

it('faults an item without a source, a unit or an as_of', function () {
    expect(faults(validBundle([validItem('x', ['source' => ['system' => 'shop', 'read_model' => '', 'ref' => 'r']])])))->toBe(['item_without_source'])
        ->and(faults(validBundle([validItem('x', ['unit' => 'bananas'])])))->toBe(['item_unit_unknown'])
        ->and(faults(validBundle([validItem('x', ['asOf' => null])])))->toBe(['item_without_as_of']);
});

it('faults a metric with no lineage, and lineage that does not resolve or does not agree with the value', function () {
    expect(faults(validBundle([validItem('x', ['lineage' => null])])))->toBe(['metric_without_lineage'])
        ->and(faults(validBundle([validItem('x', ['lineage' => Lineage::ofItems('sum_of_items', [])])])))->toBe(['metric_without_lineage'])
        // A fact needs no lineage: it is read, not computed.
        ->and(faults(validBundle([validItem('x', ['kind' => EvidenceKind::Fact, 'lineage' => null])])))->toBe([])
        // The value says 2 and the lineage lists three rows.
        ->and(faults(validBundle([validItem('x', ['lineage' => Lineage::ofRows('count_rows', 'facts_orders', [['id' => 1], ['id' => 2], ['id' => 3]])])])))->toBe(['lineage_disagrees'])
        ->and(faults(validBundle([validItem('x', ['lineage' => Lineage::ofItems('sum_of_items', ['no.such.item'])])])))->toBe(['lineage_unresolved_item'])
        ->and(faults(validBundle([validItem('x', ['value' => 1, 'unit' => 'count', 'lineage' => Lineage::ofSources('count_degraded_sources', ['no:such'])])])))->toBe(['lineage_unresolved_source']);

    // A sum that is not the sum of its inputs.
    $right = validItem('total', ['value' => 5, 'lineage' => Lineage::ofItems('sum_of_items', ['a', 'b'])]);
    $wrong = validItem('total', ['value' => 6, 'lineage' => Lineage::ofItems('sum_of_items', ['a', 'b'])]);
    $rows = fn (int $n) => Lineage::ofRows('count_rows', 'facts_orders', array_map(fn ($i) => ['id' => $i], range(1, $n)));
    $a = validItem('a', ['value' => 2, 'lineage' => $rows(2)]);
    $b = validItem('b', ['value' => 3, 'lineage' => $rows(3)]);

    expect(faults(validBundle([$a, $b, $right])))->toBe([])
        ->and(faults(validBundle([$a, $b, $wrong])))->toBe(['lineage_disagrees']);

    // Money sums are checked in paisa, as they were computed.
    $m1 = validItem('m1', ['value' => '1060.40', 'unit' => 'BDT', 'lineage' => Lineage::ofRows('sum_gross', 'facts_orders', [['id' => 1]])]);
    $m2 = validItem('m2', ['value' => '2000.10', 'unit' => 'BDT', 'lineage' => Lineage::ofRows('sum_gross', 'facts_orders', [['id' => 2]])]);
    $sum = fn (string $v) => validItem('msum', ['value' => $v, 'unit' => 'BDT', 'lineage' => Lineage::ofItems('sum_of_items', ['m1', 'm2'])]);

    expect(faults(validBundle([$m1, $m2, $sum('3060.50')])))->toBe([])
        ->and(faults(validBundle([$m1, $m2, $sum('3060.00')])))->toBe(['lineage_disagrees']);
});

it('degrades on a stale source, a missing source and an as_of in the future, without blocking narration', function () {
    $now = CarbonImmutable::parse('2026-09-20 03:00', 'UTC');
    $scope = new EvidenceScope('laladia', null, 'laladia-com');
    $source = fn (?CarbonImmutable $asOf) => [new SourceFreshness('facts_orders:laladia-com', 'facts_orders', $scope, $asOf, Freshness::measure($asOf, 1560, $now))];

    $stale = app(BundleValidator::class)->validate(validBundle(null, null, $source($now->subHours(27))));
    expect($stale->codes())->toBe(['source_stale'])->and($stale->blocksNarration())->toBeFalse()
        ->and($stale->reasons[0]['message'])->toContain('1620 minutes old against a budget of 1560');

    expect(faults(validBundle(null, null, $source(null))))->toBe(['source_missing'])
        // Six hours in the future looked perfectly valid once (docs/45). It is not fresh.
        ->and(faults(validBundle(null, null, $source($now->addHours(6)))))->toBe(['source_as_of_in_future'])
        // Inside the budget, to the minute.
        ->and(faults(validBundle(null, null, $source($now->subMinutes(1560)))))->toBe([])
        ->and(faults(validBundle(null, null, $source($now->subMinutes(1561)))))->toBe(['source_stale'])
        ->and(faults(validBundle(null, null, [])))->toBe(['no_sources']);

    // An item that is stale on its own account (an alert the check stopped seeing) is named once.
    $item = validItem('alerts.open:x', ['kind' => EvidenceKind::Fact, 'lineage' => null, 'source' => ['system' => 'hq', 'read_model' => 'alerts', 'ref' => 'alerts'],
        'freshness' => Freshness::measure($now->subHour(), 15, $now)]);
    expect(faults(validBundle([$item])))->toBe(['item_stale']);
});

it('counts a section the caller could not read as a gap', function () {
    $sections = array_map(fn ($s) => $s->key === 'approvals' ? new BundleSection('approvals', 'Pending approvals', BundleSection::WITHHELD, 'The caller does not hold hq.approvals.view.') : $s, validBundle()->sections);
    $report = app(BundleValidator::class)->validate(validBundle(null, $sections));

    expect($report->codes())->toBe(['section_withheld'])->and($report->blocksNarration())->toBeFalse();
});
