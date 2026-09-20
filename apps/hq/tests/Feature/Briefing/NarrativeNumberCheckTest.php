<?php

use App\Briefing\BundleSection;
use App\Briefing\EvidenceBundle;
use App\Briefing\EvidenceItem;
use App\Briefing\EvidenceKind;
use App\Briefing\EvidenceScope;
use App\Briefing\ExecutiveBriefingContract;
use App\Briefing\Freshness;
use App\Briefing\Lineage;
use App\Briefing\NarrativeNumberCheck;
use Carbon\CarbonImmutable;

/*
 | The unsourced-number check (docs/48 M.1, docs/80 C.1), tested as an adversary would test it.
 |
 | The bundle below is built by hand so every expectation is about the check and nothing else. It
 | holds: 45 and 12 orders placed, their sum 57 as a group metric, a gross of 3,060.50 BDT, 3
 | deliveries, an approval that has waited 27 hours and is due at 14:30 Dhaka on 20 September, a SKU
 | with digits in it, and 1,250,000 BDT as a large figure. The briefing date is 20 September 2026 and
 | every item is as of 04:05 Dhaka that day.
 */

function numbersItem(string $id, int|string|bool|null $value, string $unit, ?string $subject = null, EvidenceKind $kind = EvidenceKind::Metric): EvidenceItem
{
    return new EvidenceItem(
        id: $id, kind: $kind, section: 'yesterday', label: $id, value: $value, unit: $unit, scope: EvidenceScope::group(),
        source: ['system' => 'shop', 'read_model' => 'facts_orders', 'ref' => 'facts_orders:laladia-com'],
        asOf: CarbonImmutable::parse('2026-09-20 04:05', 'Asia/Dhaka'), freshness: Freshness::live(),
        lineage: Lineage::ofRows('count_rows', 'facts_orders', []), subject: $subject,
    );
}

function numbersBundle(array $extra = []): EvidenceBundle
{
    $items = [
        numbersItem('orders.placed.store:laladia-com', 45, 'orders'),
        numbersItem('orders.placed.store:bionic-garden', 12, 'orders'),
        numbersItem('orders.placed.group', 57, 'orders'),
        numbersItem('orders.gross.group', '3060.50', 'BDT'),
        numbersItem('orders.gross.month', '1250000.00', 'BDT'),
        numbersItem('orders.delivered.group', 3, 'orders'),
        numbersItem('approvals.pending.waiting_hours:u1', 27, 'hours'),
        numbersItem('approvals.pending.due_at:u1', '2026-09-20T08:30:00+00:00', 'datetime', null, EvidenceKind::Fact),
        numbersItem('erp.low_stock.on_hand:laladia:PIC-HIL-V1', 4, 'units', 'PIC-HIL-V1', EvidenceKind::Fact),
        ...$extra,
    ];

    return new EvidenceBundle(
        capability: ExecutiveBriefingContract::CAPABILITY, briefingDate: '2026-09-20', timezone: 'Asia/Dhaka',
        generatedAt: CarbonImmutable::parse('2026-09-20 09:00', 'Asia/Dhaka'),
        yesterday: ['date' => '2026-09-19', 'from' => '2026-09-18T18:00:00+00:00', 'to' => '2026-09-19T18:00:00+00:00'],
        today: ['date' => '2026-09-20', 'from' => '2026-09-19T18:00:00+00:00', 'to' => '2026-09-20T18:00:00+00:00'],
        scope: ['business' => null, 'permitted_business_ids' => null], sources: [],
        sections: [new BundleSection('yesterday', 'Yesterday', BundleSection::OK, null, array_map(fn ($i) => $i->id, $items))],
        items: $items,
    );
}

function numbersCheck(string $narrative, ?EvidenceBundle $bundle = null): array
{
    return (new NarrativeNumberCheck)->check($narrative, $bundle ?? numbersBundle())->toArray();
}

function unsourcedFigures(string $narrative, ?EvidenceBundle $bundle = null): array
{
    return array_column(numbersCheck($narrative, $bundle)['unsourced'], 'figure');
}

it('passes a narrative whose every figure is an item, and says which item backs each', function () {
    $result = numbersCheck('LaLaDia placed 45 orders and Bionic 12, so the group placed 57 worth ৳3,060.50. 3 were delivered.');

    expect($result['passed'])->toBeTrue()
        ->and($result['rule'])->toBe('briefing.numbers.v1')
        ->and($result['figures_found'])->toBe(5)
        ->and(collect($result['figures'])->firstWhere('figure', '45')['matched_item_ids'])->toBe(['orders.placed.store:laladia-com'])
        ->and(collect($result['figures'])->firstWhere('normalised', '3060.5')['matched_item_ids'])->toBe(['orders.gross.group']);
});

it('names a figure that is off by one', function () {
    expect(unsourcedFigures('LaLaDia placed 46 orders.'))->toBe(['46'])
        ->and(unsourcedFigures('LaLaDia placed 44 orders.'))->toBe(['44']);
});

it('refuses a rounded figure, in every direction a narrator rounds', function () {
    expect(unsourcedFigures('Gross was about 3,060 taka.'))->toBe(['3,060 taka'])
        ->and(unsourcedFigures('Gross was 3,061.'))->toBe(['3,061'])
        ->and(unsourcedFigures('Gross was 3060.5 exactly, or 3,060.50.'))->toBe([])
        ->and(unsourcedFigures('Gross was 3,060.49.'))->toBe(['3,060.49'])
        ->and(unsourcedFigures('Roughly 3k in gross.'))->toBe(['3k'])
        ->and(unsourcedFigures('Gross of 3.1k.'))->toBe(['3.1k']);
});

it('refuses a figure made by adding two items, unless that sum is itself a metric', function () {
    // 45 + 3 = 48 is nowhere in the bundle. 45 + 12 = 57 is, because the collector made it a metric.
    expect(unsourcedFigures('In all, 48 orders were placed or delivered.'))->toBe(['48'])
        ->and(unsourcedFigures('In all, 57 orders were placed.'))->toBe([]);

    // Take the group metric away and the very same 57 is unsourced.
    $without = numbersBundle();
    $without = new EvidenceBundle($without->capability, $without->briefingDate, $without->timezone, $without->generatedAt, $without->yesterday,
        $without->today, $without->scope, [], $without->sections, array_values(array_filter($without->items, fn ($i) => $i->id !== 'orders.placed.group')));

    expect(unsourcedFigures('In all, 57 orders were placed.', $without))->toBe(['57']);
});

it('refuses a share or an average worked out from two items', function () {
    expect(unsourcedFigures('Bionic was 21% of the group.'))->toBe(['21%'])
        // Even a percentage whose digits happen to equal an item is refused: 12 orders is not 12 percent.
        ->and(unsourcedFigures('Bionic was 12% of the group.'))->toBe(['12%'])
        ->and(unsourcedFigures('Bionic was 12 percent of the group.'))->toBe(['12 percent'])
        ->and(unsourcedFigures('The average order was ৳53.69.'))->toBe(['৳53.69']);

    // A percent item makes the percentage quotable, and only as a percentage.
    $with = numbersBundle([numbersItem('orders.cancel_rate.group', '6.5', 'percent')]);
    expect(unsourcedFigures('The cancellation rate was 6.5%.', $with))->toBe([])
        ->and(unsourcedFigures('The cancellation rate was 6.5 শতাংশ.', $with))->toBe([]);
});

it('ties a currency figure to a BDT item, so a count cannot be quoted as money', function () {
    expect(unsourcedFigures('Gross was BDT 3,060.50, that is Tk 3060.50 or 3,060.50 টাকা.'))->toBe([])
        ->and(unsourcedFigures('Gross was ৳45.'))->toBe(['৳45'])
        ->and(unsourcedFigures('Gross was 57 taka.'))->toBe(['57 taka']);
});

it('reads Bangla digits, and holds them to the same rule', function () {
    expect(unsourcedFigures('লালাদিয়া গতকাল ৪৫টি অর্ডার পেয়েছে, মোট ৳৩,০৬০.৫০।'))->toBe([])
        ->and(unsourcedFigures('লালাদিয়া গতকাল ৪৬টি অর্ডার পেয়েছে।'))->toBe(['46'])
        ->and(unsourcedFigures('মোট ১২ লাখ ৫০ হাজার টাকা।'))->toBe(['12 লাখ', '50 হাজার টাকা'])
        ->and(unsourcedFigures('মোট ১২.৫ লাখ টাকা।'))->toBe([]);
});

it('reads both digit groupings and the scale words, and the product must still match exactly', function () {
    expect(unsourcedFigures('Month to date: 1,250,000 BDT.'))->toBe([])
        ->and(unsourcedFigures('Month to date: 12,50,000 BDT.'))->toBe([])
        ->and(unsourcedFigures('Month to date: 12.5 lakh taka, or 1.25 million.'))->toBe([])
        ->and(unsourcedFigures('Month to date: 12 lakh.'))->toBe(['12 lakh'])
        ->and(unsourcedFigures('Month to date: 0.125 crore.'))->toBe([])
        ->and(unsourcedFigures('Month to date: 1.3 million.'))->toBe(['1.3 million']);
});

it('finds a figure hidden inside a word, a code or an ordinal', function () {
    expect(unsourcedFigures('Orders reached a record46 yesterday.'))->toBe(['46'])
        // Both digit runs are read as figures. 3 happens to be an item; 2 is not, so 2 is named.
        ->and(array_column(numbersCheck('This is the 2nd strong day in Q3.')['figures'], 'figure'))->toBe(['2', '3'])
        ->and(unsourcedFigures('This is the 2nd strong day in Q3.'))->toBe(['2'])
        ->and(unsourcedFigures('This is the 5th strong day in Q8.'))->toBe(['5', '8'])
        ->and(unsourcedFigures('Support runs 24/7.'))->toBe(['24', '7'])
        ->and(unsourcedFigures('Compared with 2025, a good year.'))->toBe(['2025']);
});

it('has no whitelist for small counts: even one and two must be in the bundle', function () {
    expect(unsourcedFigures('Only 1 store is stale.'))->toBe(['1'])
        ->and(unsourcedFigures('The two shops did well.'))->toBe(['two'])
        ->and(unsourcedFigures('Twenty orders came late.'))->toBe(['Twenty'])
        ->and(unsourcedFigures('The three deliveries went out.'))->toBe([]) // 3 is an item
        ->and(unsourcedFigures('Hundreds of orders are waiting.'))->toBe(['Hundreds'])
        ->and(unsourcedFigures('One of the shops is quiet.'))->toBe([]); // "one" is not read as a figure
});

it('accepts a date or a time only when the bundle carries it', function () {
    expect(unsourcedFigures('Briefing for 20 September 2026, covering 2026-09-19. Data as of 04:05.'))->toBe([])
        ->and(unsourcedFigures('Covering September 19 and Sep 19, 2026.'))->toBe([])
        ->and(unsourcedFigures('The approval is due at 14:30, that is 2:30 pm, on 2026-09-20T14:30:00+06:00.'))->toBe([])
        ->and(unsourcedFigures('Covering 18 September 2026.'))->toBe(['18 September 2026'])
        ->and(unsourcedFigures('Covering 2026-09-17.'))->toBe(['2026-09-17'])
        ->and(unsourcedFigures('Data as of 04:06.'))->toBe(['04:06'])
        ->and(unsourcedFigures('Due at 2:30 am.'))->toBe(['2:30 am'])
        ->and(unsourcedFigures('Due at 2:30 p.m. today.'))->toBe([])
        ->and(unsourcedFigures('Covering 19 September 2025.'))->toBe(['19 September 2025'])
        // A date written a way the check does not read falls back to plain figures, and fails.
        ->and(unsourcedFigures('Covering 19/09/2026.'))->toBe(['19', '09', '2026']);
});

it('lets an identifier with digits be quoted whole, and only whole', function () {
    expect(unsourcedFigures('PIC-HIL-V1 is down to 4 on hand.'))->toBe([])
        ->and(unsourcedFigures('PIC-HIL-V2 is down to 4 on hand.'))->toBe(['2'])
        ->and(unsourcedFigures('XPIC-HIL-V1 is low.'))->toBe(['1']);

    // A subject that is only a number whitelists nothing.
    $sneaky = numbersBundle([numbersItem('x', 'warning', 'text', '9999', EvidenceKind::Fact)]);
    expect(unsourcedFigures('There were 9999 visits.', $sneaky))->toBe(['9999']);
});

it('never passes digits in a script it cannot read', function () {
    expect(unsourcedFigures('कुल ४५ ऑर्डर।'))->toBe(['४५'])
        ->and(unsourcedFigures('Total ٤٥ orders.'))->toBe(['٤٥']);
});

it('does not read a boolean or a text value as a number', function () {
    $bundle = numbersBundle([numbersItem('orders.band.outside.store:laladia-com', true, 'boolean'), numbersItem('alerts.open:x', 'warning', 'text', null, EvidenceKind::Fact)]);

    expect(unsourcedFigures('There is 1 anomaly.', $bundle))->toBe(['1']);
});

it('passes a narrative with no figure in it, and reports every unsourced figure, not just the first', function () {
    expect(numbersCheck('Nothing unusual happened yesterday.'))->toMatchArray(['passed' => true, 'figures_found' => 0]);

    $result = numbersCheck('LaLaDia placed 45 orders, up 9% on 41 the day before, worth ৳3,100.');
    expect(array_column($result['unsourced'], 'figure'))->toBe(['9%', '41', '৳3,100'])
        ->and($result['unsourced'][0]['why'])->toContain('percent')
        ->and($result['unsourced'][1]['excerpt'])->toContain('41 the day before');
});
