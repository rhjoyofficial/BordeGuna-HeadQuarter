<?php

namespace App\Http\Controllers\Connectors;

use App\Models\Business;
use App\Models\IngestedDocument;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review screen for documents HQ read from the supplier mailbox (docs/144-email-ingestion). Everything here is a
 * DRAFT: a person reads each field beside the text it came from, then marks it reviewed or rejects it. Sending a reviewed
 * one to the ERP is a separate request that waits for a second person (`erp.create_draft_bill`).
 */
final class IngestionController extends ConnectorController
{
    public function index(Request $request, BusinessScope $scope): Response
    {
        $user = $request->user();
        $documents = IngestedDocument::query()->visibleTo($user)->with('business:id,name,slug')->with('message:id,from_address,subject,received_at')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')->limit(100)->get();

        return Inertia::render('ingestion/index', [
            'documents' => $documents->map(fn (IngestedDocument $d) => [
                'uuid' => $d->uuid, 'status' => $d->status, 'business' => $d->business?->name, 'from' => $d->message?->from_address, 'subject' => $d->message?->subject,
                'received_at' => $d->message?->received_at?->toIso8601String(), 'supplier' => $d->extracted['supplier']['value'] ?? null,
                'invoice_number' => $d->extracted['invoice_number']['value'] ?? null, 'total' => $d->extracted['total']['value'] ?? null,
                'warnings' => count($d->extracted['warnings'] ?? []), 'created_at' => $d->created_at?->toIso8601String(),
            ])->all(),
            'businesses' => Business::query()->when($scope->all($user) !== null, fn ($q) => $q->whereIn('id', (array) $scope->all($user)))->orderBy('name')->get(['slug', 'name'])->all(),
            'filters' => ['status' => $request->string('status')->toString() ?: null],
            'can' => ['review' => $user->can('hq.ingestion.review'), 'poll' => $user->can('hq.tools.mail.ingest')],
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $d = $this->find($request, $uuid);
        $d->load('business:id,name,slug', 'message:id,from_address,subject,received_at');

        return Inertia::render('ingestion/show', [
            'document' => [
                'uuid' => $d->uuid, 'status' => $d->status, 'business' => $d->business?->name, 'business_slug' => $d->business?->slug,
                'from' => $d->message?->from_address, 'subject' => $d->message?->subject, 'received_at' => $d->message?->received_at?->toIso8601String(),
                'extractor' => $d->extractor, 'extracted' => $d->extracted, 'source_text' => $d->source_text, 'review_note' => $d->review_note,
                'erp_draft_ref' => $d->erp_draft_ref, 'erp_draft_error' => $d->erp_draft_error,
            ],
            'can' => ['review' => $request->user()->can('hq.ingestion.review'), 'send' => $request->user()->can('hq.tools.erp.draft_bill')],
        ]);
    }

    public function poll(Request $request): RedirectResponse
    {
        $data = $request->validate(['business_slug' => ['required', 'string', 'max:64']]);
        $business = $this->scopedBusiness($request->user(), $data['business_slug']);

        return $this->outcomeResponse($this->runTool($request->user(), 'mail.poll_inbox', $data, $business), 'poll', 'mailbox-polled');
    }

    public function decide(Request $request, string $uuid): RedirectResponse
    {
        $d = $this->find($request, $uuid);
        $data = $request->validate(['decision' => ['required', 'in:reviewed,rejected'], 'note' => ['nullable', 'string', 'max:500']]);
        if (! in_array($d->status, [IngestedDocument::DRAFT, IngestedDocument::ERP_FAILED], true)) {
            throw ValidationException::withMessages(['decision' => 'This document has already been decided.']);
        }

        $before = $d->status;
        $d->forceFill(['status' => $data['decision'], 'reviewed_by' => $request->user()->getKey(), 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null])->save();
        app(Auditor::class)->record('ingestion.'.$data['decision'], $d, ['status' => $before], ['status' => $d->status], $data['note'] ?? null, context: ['business_id' => $d->business_id]);

        return back()->with('status', 'ingestion-'.$data['decision']);
    }

    public function sendToErp(Request $request, string $uuid): RedirectResponse
    {
        $d = $this->find($request, $uuid);
        $business = $this->scopedBusiness($request->user(), (string) Business::query()->whereKey($d->business_id)->value('slug'));

        return $this->outcomeResponse($this->runTool($request->user(), 'erp.create_draft_bill', ['business_slug' => $business->slug, 'document_uuid' => $d->uuid], $business), 'erp', 'draft-bill-requested');
    }

    /** A document of another business does not exist for a person limited to theirs. */
    private function find(Request $request, string $uuid): IngestedDocument
    {
        $d = IngestedDocument::query()->visibleTo($request->user())->where('uuid', $uuid)->first();
        abort_if($d === null, 404);

        return $d;
    }
}
