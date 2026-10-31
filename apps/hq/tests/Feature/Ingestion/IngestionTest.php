<?php

use App\Erp\Write\ErpDraftBill;
use App\Erp\Write\ErpDraftBillWriter;
use App\Erp\Write\Exceptions\ErpWriteRefused;
use App\Erp\Write\Exceptions\ErpWriteUnavailable;
use App\Erp\Write\FakeErpDraftBillWriter;
use App\Erp\Write\HttpErpDraftBillWriter;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Ingestion\Extraction\DocumentTextExtractor;
use App\Ingestion\Extraction\ExtractionVerifier;
use App\Ingestion\Extraction\RuleBasedBillExtractor;
use App\Ingestion\MailIngestor;
use App\Ingestion\Mailbox\Exceptions\MailboxRefused;
use App\Ingestion\Mailbox\Exceptions\MailboxUnavailable;
use App\Ingestion\Mailbox\FakeMailbox;
use App\Ingestion\Mailbox\GmailMailbox;
use App\Ingestion\Mailbox\GraphMailbox;
use App\Ingestion\Mailbox\MailboxConnector;
use App\Models\Business;
use App\Models\IngestedDocument;
use App\Models\IntegrationCredential;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Models\UserBusinessScope;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 | docs/144-email-ingestion and docs/144-erp-write-contract: the mailbox contract (Gmail and Graph against faked HTTP, and the fake),
 | extraction with a citation for every field, the draft-only ingestion, the review screen's scope, and the ERP draft bill: contract,
 | second approval, idempotency and the refusal of anything that is not a draft.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mine = Business::orderBy('id')->firstOrFail();
    $this->theirs = Business::where('id', '!=', $this->mine->id)->orderBy('id')->firstOrFail();
    $this->owner = $this->hqUser('owner');
});

/** A one-page PDF with the given lines of text, written by hand so no tool is needed to make the fixture. */
function m3Pdf(array $lines): string
{
    $text = "BT /F1 11 Tf 50 750 Td 14 TL\n";
    foreach ($lines as $line) {
        $text .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).") Tj T*\n";
    }
    $text .= 'ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }

    return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
}

// ── the mailbox contract ────────────────────────────────────────────────────────────────────────

function m3FakeMailHttp(string $mode): void
{
    $ok = $mode === 'ok';
    $answer = fn (array $body) => $ok ? Http::response($body, 200) : ($mode === 'refused' ? Http::response(['error' => 'no'], 401) : Http::response('down', 503));
    $pdf = base64_encode(m3Pdf(['Green Valley Traders', 'Invoice No: GV-1', 'Grand Total: BDT 100.00']));
    Http::fake([
        'gmail.googleapis.com/*/messages?*' => $answer(['messages' => [['id' => 'g1']]]),
        'gmail.googleapis.com/*/messages/g1/attachments/*' => $answer(['data' => strtr(base64_encode('Supplier: X'), '+/', '-_')]),
        'gmail.googleapis.com/*/messages/g1*' => $answer(['id' => 'g1', 'internalDate' => '1790000000000', 'payload' => ['headers' => [['name' => 'From', 'value' => 'a@s.test'], ['name' => 'Subject', 'value' => 'Bill']],
            'parts' => [['mimeType' => 'text/plain', 'body' => ['data' => strtr(base64_encode('see attached'), '+/', '-_')]], ['mimeType' => 'application/pdf', 'filename' => 'bill.pdf', 'body' => ['attachmentId' => 'att1']]]]]),
        'graph.microsoft.com/*/messages/*/attachments*' => $answer(['value' => [['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'bill.pdf', 'contentType' => 'application/pdf', 'contentBytes' => $pdf]]]),
        'graph.microsoft.com/*/mailFolders/inbox/messages*' => $answer(['value' => [['id' => 'm1', 'subject' => 'Bill', 'from' => ['emailAddress' => ['address' => 'a@s.test']], 'receivedDateTime' => '2026-10-20T08:00:00Z', 'body' => ['contentType' => 'text', 'content' => 'see attached']]]]),
    ]);
}

dataset('mailboxes', [
    'gmail' => [GmailMailbox::class, GmailMailbox::CREDENTIAL, true],
    'graph' => [GraphMailbox::class, GraphMailbox::CREDENTIAL, true],
    'fake' => [FakeMailbox::class, null, false],
]);

function m3WithMailCredential(?string $kind, Closure $fn): mixed
{
    if ($kind === null) {
        return $fn();
    }
    IntegrationCredential::firstOrCreate(['provider' => $kind, 'account_ref' => 'laladia'], ['credential_type' => 'token', 'payload' => ['value' => 'MAIL-TOKEN-777'], 'status' => 'active']);

    return app(CredentialVault::class)->openForAccounts([[$kind, 'laladia']], $fn);
}

it('returns messages with their attachments\' bytes, and has no way to write to the mailbox (contract)', function (string $class, ?string $kind, bool $real) {
    $real && m3FakeMailHttp('ok');
    $mailbox = app($class);
    expect($mailbox)->toBeInstanceOf(MailboxConnector::class);

    $batch = m3WithMailCredential($kind, fn () => $mailbox->fetch($real ? 'laladia' : 'laladia', null, 10));

    expect($batch->messages)->not->toBeEmpty()->and($batch->messages[0]->attachments)->not->toBeEmpty()->and($batch->messages[0]->attachments[0]->bytes)->not->toBe('');
    // Reads only: the interface has exactly one method that touches the mailbox, and it is fetch.
    expect(array_map(fn ($m) => $m->getName(), (new ReflectionClass(MailboxConnector::class))->getMethods()))->toBe(['key', 'fetch']);
})->with('mailboxes');

it('is refused as a refusal and reported down as unavailable, without the token in the message (contract)', function (string $class, ?string $kind, bool $real) {
    if (! $real) {
        expect(fn () => app($class)->fetch('refused', null, 5))->toThrow(MailboxRefused::class)
            ->and(fn () => app($class)->fetch('down', null, 5))->toThrow(MailboxUnavailable::class);

        return;
    }
    m3FakeMailHttp('refused');
    try {
        m3WithMailCredential($kind, fn () => app($class)->fetch('laladia', null, 5));
        $this->fail('expected a refusal');
    } catch (MailboxRefused $e) {
        expect($e->getMessage())->not->toContain('MAIL-TOKEN-777');
    }
    m3FakeMailHttp('down');
    expect(fn () => m3WithMailCredential($kind, fn () => app($class)->fetch('laladia', null, 5)))->toThrow(MailboxUnavailable::class);
})->with('mailboxes');

it('refuses before any request when no credential scope is open', function () {
    Http::fake();

    expect(fn () => app(GmailMailbox::class)->fetch('laladia', null, 5))->toThrow(\App\Commerce\Exceptions\Unsupported::class);
    Http::assertNothingSent();
});

it('returns only mail after the given moment', function () {
    $later = (new FakeMailbox)->fetch('laladia', new DateTimeImmutable('2026-10-20 12:00:00'), 10);

    expect($later->messages)->toHaveCount(1)->and($later->messages[0]->externalId)->toBe('fake-msg-2');
});

// ── extraction, with a citation for every field ─────────────────────────────────────────────────

it('reads a supplier bill, and cites the exact words each field came from', function () {
    $text = FakeMailbox::sampleBillText();
    $x = (new ExtractionVerifier)->verify((new RuleBasedBillExtractor)->extract($text), $text);

    expect($x['supplier']['value'])->toBe('Green Valley Traders')
        ->and($x['invoice_number']['value'])->toBe('GV-2026-0042')
        ->and($x['invoice_date']['value'])->toBe('2026-10-20')
        ->and($x['total'])->toMatchArray(['value' => '5300.00', 'currency' => 'BDT'])
        ->and($x['lines'])->toHaveCount(2)
        ->and($x['lines'][0]['line_total']['value'])->toBe('1800.00')
        ->and($x['warnings'])->toBe([]);

    // Every citation is the real text at the real offsets.
    foreach (['supplier', 'invoice_number', 'invoice_date', 'total'] as $name) {
        $c = $x[$name]['citation'];
        expect(substr($text, $c['start'], $c['end'] - $c['start']))->toBe($c['text']);
    }
});

it('drops a field it cannot prove against the source, and says so', function () {
    $text = FakeMailbox::sampleBillText();
    $x = (new RuleBasedBillExtractor)->extract($text);
    $x['total']['citation']['text'] = 'Grand Total: BDT 9,999.00'; // not what the document says there
    $x['lines'][0]['description']['citation']['start'] = 3;       // the wrong place

    $verified = (new ExtractionVerifier)->verify($x, $text);

    expect($verified['total'])->toBeNull()->and($verified['lines'])->toHaveCount(1)
        ->and(implode(' ', $verified['warnings']))->toContain('total could not be matched')->and(implode(' ', $verified['warnings']))->toContain('item line could not be matched');
});

it('warns when the lines and the total disagree, and never fixes it silently', function () {
    $text = "Acme\nInvoice No: A-1\nSalt 2 x 100.00 200.00\nTotal: BDT 250.00\n";
    $x = (new ExtractionVerifier)->verify((new RuleBasedBillExtractor)->extract($text), $text);

    expect($x['total']['value'])->toBe('250.00')->and(implode(' ', $x['warnings']))->toContain('add up to 200.00 but the document total is 250.00');
});

it('reads the text of a PDF and of plain text, and says nothing for an image', function () {
    $extractor = new DocumentTextExtractor;

    expect($extractor->text(m3Pdf(['Invoice No: PDF-9', 'Grand Total: BDT 12.50']), 'application/pdf'))->toContain('Invoice No: PDF-9')->toContain('Grand Total: BDT 12.50')
        ->and($extractor->text("hello\n", 'text/plain'))->toBe('hello')
        ->and($extractor->text("\x89PNG\r\n\x1A\n", 'image/png'))->toBeNull()
        ->and($extractor->text('not a pdf', 'application/pdf'))->toBeNull();
});

// ── ingestion: drafts only, scoped, idempotent ──────────────────────────────────────────────────

it('turns bill attachments into drafts, once, for the business whose mailbox it is', function () {
    m3SandboxConnectors(['connectors.mail_ingestion']);

    $first = app(MailIngestor::class)->ingest($this->mine);
    expect($first)->toMatchArray(['messages' => 2, 'attachments' => 2, 'drafts' => 2, 'rejected' => 0, 'provider' => 'sandbox']);

    $second = app(MailIngestor::class)->ingest($this->mine);
    expect($second['messages'])->toBe(0)->and(IngestedDocument::count())->toBe(2)->and(MailMessage::count())->toBe(2);

    $doc = IngestedDocument::orderBy('id')->first();
    expect($doc->business_id)->toBe($this->mine->id)->and($doc->status)->toBe('draft')->and($doc->extracted['invoice_number']['value'])->toBe('GV-2026-0042')
        ->and($doc->erp_draft_ref)->toBeNull()
        ->and(MailAttachment::where('business_id', '!=', $this->mine->id)->count())->toBe(0);
});

it('keeps a file it will not store out, with the reason, and makes no draft from it', function () {
    m3SandboxConnectors(['connectors.mail_ingestion']);
    $mailbox = new class implements MailboxConnector
    {
        public function key(): string
        {
            return 'fake_mailbox';
        }

        public function fetch(string $accountRef, ?DateTimeInterface $since, int $limit): \App\Ingestion\Mailbox\MailboxBatch
        {
            $mail = fn (string $id, array $files) => new \App\Ingestion\Mailbox\InboundMail($id, 'x@y.test', 'files', \Carbon\CarbonImmutable::parse('2026-10-22'), null, $files);

            return new \App\Ingestion\Mailbox\MailboxBatch([$mail('bad-1', [
                new \App\Ingestion\Mailbox\InboundAttachment('run.exe', 'application/pdf', "MZ\x90\x00".str_repeat("\0", 64)),
                new \App\Ingestion\Mailbox\InboundAttachment('scan.png', 'image/png', "\x89PNG\r\n\x1A\n".str_repeat("\0", 64)),
                new \App\Ingestion\Mailbox\InboundAttachment('note.txt', 'text/plain', 'just a hello, not a bill'),
            ])]);
        }
    };
    app()->bind(FakeMailbox::class, fn () => $mailbox);

    $result = app(MailIngestor::class)->ingest($this->mine);

    expect($result['drafts'])->toBe(0)->and($result['rejected'])->toBe(3)->and(IngestedDocument::count())->toBe(0);
    expect(MailAttachment::pluck('rejected_reason')->all())->toHaveCount(3)->each->not->toBeNull();
    expect(MailAttachment::where('filename', 'run.exe')->value('media_asset_id'))->toBeNull(); // never stored
});

it('refuses to read a mailbox that is not connected, naming what is missing', function () {
    expect(fn () => app(MailIngestor::class)->ingest($this->mine))->toThrow(MailboxRefused::class, 'No mailbox connector is ready');
});

it('polls through the gateway and records nothing for a business a restricted person may not use', function () {
    m3SandboxConnectors(['connectors.mail_ingestion']);
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->mine->id]);

    $denied = app(ToolGateway::class)->execute(ToolRequest::forOperator($restricted, 'mail.poll_inbox', ['business_slug' => $this->theirs->slug], $this->theirs->id));
    expect($denied->ok())->toBeFalse()->and(IngestedDocument::count())->toBe(0);

    $ok = app(ToolGateway::class)->execute(ToolRequest::forOperator($restricted, 'mail.poll_inbox', ['business_slug' => $this->mine->slug], $this->mine->id));
    expect($ok->ok())->toBeTrue()->and(IngestedDocument::where('business_id', $this->theirs->id)->count())->toBe(0);
});

// ── the review screen ───────────────────────────────────────────────────────────────────────────

it('lists and shows only the documents of the businesses a person may see, and reviews are audited', function () {
    m3SandboxConnectors(['connectors.mail_ingestion']);
    app(MailIngestor::class)->ingest($this->mine);
    $theirs = IngestedDocument::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->theirs->id, 'status' => 'draft', 'source_text' => 'secret text', 'extracted' => ['warnings' => []], 'extractor' => 'x']);
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->mine->id]);
    $mine = IngestedDocument::where('business_id', $this->mine->id)->firstOrFail();

    $this->actingAsHq($restricted)->get('/ingestion')->assertOk()->assertInertia(fn ($p) => $p->has('documents', 2));
    $this->actingAsHq($restricted)->get("/ingestion/{$theirs->uuid}")->assertNotFound();
    $this->actingAsHq($restricted)->post("/ingestion/{$theirs->uuid}/decide", ['decision' => 'reviewed'])->assertNotFound();
    $this->actingAsHq($restricted)->get("/ingestion/{$mine->uuid}")->assertOk()->assertInertia(fn ($p) => $p->component('ingestion/show')->where('document.extracted.invoice_number.citation.text', fn ($t) => str_contains($t, 'GV-2026-0042')));

    $this->actingAsHq($restricted)->post("/ingestion/{$mine->uuid}/decide", ['decision' => 'reviewed', 'note' => 'checked'])->assertRedirect();
    expect($mine->fresh()->status)->toBe('reviewed')->and($mine->fresh()->reviewed_by)->toBe($restricted->id)
        ->and(\App\Models\AuditLog::where('action', 'ingestion.reviewed')->count())->toBe(1);
    // Decided already: a second decision is refused.
    $this->actingAsHq($restricted)->post("/ingestion/{$mine->uuid}/decide", ['decision' => 'rejected'])->assertSessionHasErrors('decision');
    expect($theirs->fresh()->status)->toBe('draft');
});

// ── the ERP draft bill ──────────────────────────────────────────────────────────────────────────

function m3Bill(string $supplier = 'Green Valley Traders'): ErpDraftBill
{
    return new ErpDraftBill(7, $supplier, 'GV-1', '2026-10-20', 'BDT', '100.00', [['description' => 'Salt', 'quantity' => '2', 'unit_price' => '50.00', 'line_total' => '100.00']], (string) Str::uuid());
}

dataset('erp writers', [
    'http' => [HttpErpDraftBillWriter::class, true],
    'fake' => [FakeErpDraftBillWriter::class, false],
]);

function m3WithErpToken(bool $real, Closure $fn): mixed
{
    if (! $real) {
        return $fn();
    }
    IntegrationCredential::firstOrCreate(['provider' => HttpErpDraftBillWriter::CREDENTIAL, 'account_ref' => 'laladia'], ['credential_type' => 'token', 'payload' => ['value' => 'ERP-WRITE-TOKEN-5'], 'status' => 'active']);

    return app(CredentialVault::class)->openForAccounts([[HttpErpDraftBillWriter::CREDENTIAL, 'laladia']], $fn);
}

it('creates a draft with a reference, and the same key is the same draft (contract)', function (string $class, bool $real) {
    $real && Http::fake(['*purchase-bills/drafts' => Http::response(['data' => ['id' => 55, 'number' => 'PB-DRAFT-55', 'status' => 'draft']], 201)]);
    $writer = app($class);
    expect($writer)->toBeInstanceOf(ErpDraftBillWriter::class);

    $a = m3WithErpToken($real, fn () => $writer->createDraftBill('laladia', m3Bill(), 'key-1'));
    $b = m3WithErpToken($real, fn () => $writer->createDraftBill('laladia', m3Bill(), 'key-1'));

    expect($a->status)->toBe('draft')->and($a->reference)->not->toBe('')->and($b->reference)->toBe($a->reference);
    $real && Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', 'key-1') && $r->hasHeader('Authorization', 'Bearer ERP-WRITE-TOKEN-5') && $r['status'] === 'draft' && str_contains($r->url(), '/businesses/7/'));
})->with('erp writers');

it('refuses an answer that is not a draft, a rejected token and a refusal, and reports an unreachable ERP as unknown (contract)', function (string $class, bool $real) {
    $writer = app($class);
    if (! $real) {
        expect(fn () => $writer->createDraftBill('laladia', m3Bill('FAKE-REFUSE'), 'k'))->toThrow(ErpWriteRefused::class)
            ->and(fn () => $writer->createDraftBill('laladia', m3Bill('FAKE-DOWN'), 'k'))->toThrow(ErpWriteUnavailable::class);

        return;
    }
    $answer = Http::response(['data' => ['id' => 1, 'number' => 'PB-1', 'status' => 'posted']], 201);
    Http::fake(['*purchase-bills/drafts' => function () use (&$answer) { return $answer; }]);

    // A 2xx whose status is not draft is NOT a draft.
    expect(fn () => m3WithErpToken(true, fn () => $writer->createDraftBill('laladia', m3Bill(), 'k')))->toThrow(ErpWriteRefused::class, 'not a draft');
    foreach ([[401, ErpWriteRefused::class], [403, ErpWriteRefused::class], [422, ErpWriteRefused::class], [500, ErpWriteUnavailable::class], [429, ErpWriteUnavailable::class]] as [$status, $exception]) {
        $answer = Http::response(['message' => 'x'], $status);
        try {
            m3WithErpToken(true, fn () => $writer->createDraftBill('laladia', m3Bill(), 'k'));
            $this->fail("expected an exception for {$status}");
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf($exception)->and($e->getMessage())->not->toContain('ERP-WRITE-TOKEN-5');
        }
    }
})->with('erp writers');

it('will not build a bill that is not BDT, has no lines, or has an amount without two decimals', function () {
    $line = ['description' => 'x', 'quantity' => '1', 'unit_price' => '1.00', 'line_total' => '1.00'];

    expect(fn () => new ErpDraftBill(1, 'S', null, null, 'USD', '1.00', [$line], 'u'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ErpDraftBill(1, 'S', null, null, 'BDT', '1.00', [], 'u'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ErpDraftBill(1, 'S', null, null, 'BDT', '1', [$line], 'u'))->toThrow(InvalidArgumentException::class);
});

it('has no method to post, approve or pay a bill in the ERP', function () {
    expect(array_map(fn ($m) => $m->getName(), (new ReflectionClass(ErpDraftBillWriter::class))->getMethods()))->toBe(['key', 'createDraftBill']);
});

it('sends a reviewed document as a draft bill only after a second person approves, and only once', function () {
    m3SandboxConnectors(['connectors.mail_ingestion', 'connectors.erp_draft_bill']);
    app(MailIngestor::class)->ingest($this->mine);
    $doc = IngestedDocument::where('business_id', $this->mine->id)->orderBy('id')->firstOrFail();
    $args = ['business_slug' => $this->mine->slug, 'document_uuid' => $doc->uuid];

    // Not reviewed yet: refused, nothing asked.
    $tool = app(\App\Tools\Connectors\CreateDraftBillTool::class);
    $ctx = new \App\Tools\ToolContext('k', 'c', $this->mine->id);
    expect($tool->execute($args, $ctx)->ok())->toBeFalse();

    $doc->forceFill(['status' => IngestedDocument::REVIEWED])->save();
    $requested = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'erp.create_draft_bill', $args, $this->mine->id));
    expect($requested->status)->toBe(OutcomeStatus::AwaitingApproval)->and($doc->fresh()->status)->toBe('reviewed');

    // The requester may NOT decide it.
    $approval = \App\Models\Approval::findOrFail($requested->approvalId);
    expect($approval->requester_may_decide ?? false)->toBeFalse();
    $this->actingAsHq($this->owner)->post("/approvals/{$approval->id}/approve")->assertSessionHasErrors();
    expect($doc->fresh()->status)->toBe('reviewed');

    // The tool itself, as the approved action would run it: a draft, recorded, once.
    $result = $tool->execute($args, $ctx);
    expect($result->ok())->toBeTrue()->and($doc->fresh()->status)->toBe('sent_to_erp')->and($doc->fresh()->erp_draft_ref)->toStartWith('FAKE-DRAFT-');
    $again = $tool->execute($args, $ctx);
    expect($again->output)->toMatchArray(['already' => true, 'erp_draft_ref' => $doc->fresh()->erp_draft_ref]);
});

it('refuses to draft a document of another business', function () {
    m3SandboxConnectors(['connectors.erp_draft_bill']);
    $theirs = IngestedDocument::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->theirs->id, 'status' => 'reviewed', 'source_text' => 't', 'extracted' => ['warnings' => []], 'extractor' => 'x']);

    $result = app(\App\Tools\Connectors\CreateDraftBillTool::class)->execute(['business_slug' => $this->mine->slug, 'document_uuid' => $theirs->uuid], new \App\Tools\ToolContext('k', 'c', $this->mine->id));

    expect($result->ok())->toBeFalse()->and($theirs->fresh()->status)->toBe('reviewed');
});
