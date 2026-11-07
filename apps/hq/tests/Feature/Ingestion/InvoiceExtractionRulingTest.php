<?php

use App\Erp\Write\ErpDraftBill;
use App\Erp\Write\ErpDraftBillWriter;
use App\Ingestion\Extraction\BillChecks;
use App\Ingestion\Extraction\BillExtractor;
use App\Ingestion\Extraction\DocumentTextExtractor;
use App\Ingestion\Extraction\ExtractionVerifier;
use App\Ingestion\Extraction\ExtractorGate;
use App\Ingestion\Extraction\RuleBasedBillExtractor;
use App\Ingestion\MailIngestor;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\IngestedDocument;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 | Ruling 31 Oct 2026 item 10: deterministic extraction by default; any OCR/model extractor only behind its own flag AND a passed
 | evaluation gate; page and field-level location on every citation; arithmetic, totals, tax and currency checked deterministically;
 | mandatory field-by-field human review before the ERP draft; extraction can never post an invoice or authorise a payment.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->business = Business::orderBy('id')->firstOrFail();
});

function r2ModelExtractor(): BillExtractor
{
    return new class implements BillExtractor
    {
        public function key(): string
        {
            return 'model_v0';
        }

        public function extract(string $text): array
        {
            return (new RuleBasedBillExtractor)->extract($text);
        }
    };
}

it('reads every bill with the deterministic extractor unless a model extractor has BOTH its flag on and a passed evaluation', function () {
    $gate = app(ExtractorGate::class);
    $model = r2ModelExtractor();

    expect($gate->select($model))->toBeInstanceOf(RuleBasedBillExtractor::class);
    FeatureFlag::query()->create(['key' => ExtractorGate::FLAG, 'environment' => '*', 'enabled' => true]);
    expect($gate->select($model))->toBeInstanceOf(RuleBasedBillExtractor::class);
    config(['hq.ingestion.model_extraction.eval_passed_extractor' => 'another_model']);
    expect($gate->select($model))->toBeInstanceOf(RuleBasedBillExtractor::class);
    config(['hq.ingestion.model_extraction.eval_passed_extractor' => 'model_v0']);
    expect($gate->select($model))->toBe($model);
    FeatureFlag::query()->where('key', ExtractorGate::FLAG)->update(['enabled' => false]);
    expect($gate->select($model))->toBeInstanceOf(RuleBasedBillExtractor::class);

    // Shipped: no flag row, no passed extractor.
    expect(config('hq.ingestion.model_extraction.eval_passed_extractor'))->toBe('model_v0'); // set above in this test only
});

it('ships the model extraction flag without a row and the evaluation gate empty', function () {
    expect(FeatureFlag::query()->where('key', ExtractorGate::FLAG)->exists())->toBeFalse()
        ->and(config('hq.ingestion.model_extraction.eval_passed_extractor'))->toBeNull();
});

it('cites every field with its page and its exact character offsets, re-verifiable against the source', function () {
    $page1 = "Green Valley Traders\nInvoice No: GV-2026-0042\nDate: 2026-10-20";
    $page2 = "Mustard oil 5L 2 x 1750.00 3500.00\nGrand Total: BDT 3500.00";
    $source = $page1."\n".DocumentTextExtractor::PAGE_BREAK."\n".$page2;

    $x = (new ExtractionVerifier)->verify((new RuleBasedBillExtractor)->extract($source), $source);

    expect($x['invoice_number']['citation']['page'])->toBe(1)
        ->and($x['total']['citation']['page'])->toBe(2)
        ->and($x['lines'][0]['line_total']['citation']['page'])->toBe(2);
    foreach (['supplier', 'invoice_number', 'total'] as $field) {
        $c = $x[$field]['citation'];
        expect(substr($source, $c['start'], $c['end'] - $c['start']))->toBe($c['text']);
    }
    expect($x['checks']['passed'])->toBeTrue();
});

it('checks arithmetic, totals, tax and currency deterministically, and a failed check fails', function () {
    $c = fn (string $value) => ['value' => $value, 'citation' => ['text' => $value, 'start' => 0, 'end' => strlen($value)]];
    $line = fn (string $q, string $u, string $t) => ['description' => $c('x'), 'quantity' => $c($q), 'unit_price' => $c($u), 'line_total' => $c($t)];
    $base = ['total' => $c('3500.00') + ['currency' => 'BDT'], 'lines' => [$line('2', '1750.00', '3500.00')]];
    $checks = new BillChecks;

    expect($checks->run($base)['passed'])->toBeTrue()
        ->and($checks->run(['lines' => [$line('2', '1750.00', '3000.00')], 'total' => $c('3000.00') + ['currency' => 'BDT']])['passed'])->toBeFalse()
        ->and($checks->run(['total' => $c('3600.00') + ['currency' => 'BDT']] + $base)['passed'])->toBeFalse()
        ->and($checks->run(['total' => $c('3675.00') + ['currency' => 'BDT'], 'tax' => $c('175.00')] + $base)['passed'])->toBeTrue()
        ->and($checks->run(['total' => $c('3500.00') + ['currency' => 'EUR']] + $base)['passed'])->toBeFalse()
        ->and($checks->run(['total' => null, 'lines' => []])['passed'])->toBeFalse();
});

it('refuses to mark reviewed a document whose checks fail, however many fields the reviewer confirms', function () {
    $owner = $this->hqUser('owner');
    $c = fn (string $value) => ['value' => $value, 'citation' => ['text' => $value, 'start' => 0, 'end' => strlen($value)]];
    $bad = IngestedDocument::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->business->id, 'status' => 'draft', 'source_text' => 'x', 'extractor' => 'rule_based',
        'extracted' => ['supplier' => $c('S'), 'invoice_number' => null, 'invoice_date' => null, 'total' => $c('999.00') + ['currency' => 'BDT'],
            'lines' => [['description' => $c('x'), 'quantity' => $c('1'), 'unit_price' => $c('10.00'), 'line_total' => $c('10.00')]], 'warnings' => []]]);

    $this->actingAsHq($owner)->post("/ingestion/{$bad->uuid}/decide", ['decision' => 'reviewed', 'confirm' => IngestedDocument::REVIEW_FIELDS])
        ->assertSessionHasErrors('decision');
    expect($bad->fresh()->status)->toBe('draft');
    // Rejecting needs no confirmation.
    $this->actingAsHq($owner)->post("/ingestion/{$bad->uuid}/decide", ['decision' => 'rejected'])->assertSessionHasNoErrors();
});

it('can never post an invoice or authorise a payment: reading mail reaches no ERP, and the only ERP write is a draft', function () {
    Http::fake();
    m3SandboxConnectors(['connectors.mail_ingestion']);
    app(MailIngestor::class)->ingest($this->business);
    expect(IngestedDocument::query()->count())->toBeGreaterThan(0);
    Http::assertNothingSent();
    foreach (IngestedDocument::all() as $doc) {
        expect($doc->status)->toBe('draft');
    }

    // The writer contract has no post, approve or pay method, and the payload it carries is a draft with no payment field.
    expect(array_map(fn ($m) => $m->getName(), (new ReflectionClass(ErpDraftBillWriter::class))->getMethods()))->toBe(['key', 'createDraftBill']);
    $payload = (new ErpDraftBill(1, 'S', null, null, 'BDT', '1.00', [['description' => 'x', 'quantity' => '1', 'unit_price' => '1.00', 'line_total' => '1.00']], 'u'))->toPayload();
    expect($payload['status'])->toBe('draft');
    foreach (array_keys($payload) as $key) {
        expect($key)->not->toMatch('/pay|post|approv|settle|disburs/i');
    }

    // No ingestion or extraction code names the ERP writer, a payment or a posting.
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Ingestion'), FilesystemIterator::SKIP_DOTS)) as $file) {
        // Code only: comments are stripped, so a sentence saying what the code never does is not an offender.
        $source = implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents($file->getPathname()))));
        if (preg_match('/ErpDraftBillWriter|ErpRegistry|createDraftBill|payment|->post\w*Bill|approveBill/i', $source) === 1) {
            $offenders[] = $file->getFilename();
        }
    }
    expect($offenders)->toBe([]);
});
