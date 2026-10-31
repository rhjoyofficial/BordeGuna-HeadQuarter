<?php

namespace App\Erp\Write;

use App\Erp\Write\Exceptions\ErpWriteRefused;
use App\Erp\Write\Exceptions\ErpWriteUnavailable;
use App\Gateway\Credentials\CredentialVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Creates the draft over the ERP's HQ write API (the contract is docs/144-erp-write-contract.md):
 * `POST {erp}/api/hq/v1/businesses/{erp_business_id}/purchase-bills/drafts` with `Authorization: Bearer <write token>`,
 * `Idempotency-Key`, `X-Correlation-Id`, JSON body `ErpDraftBill::toPayload()`; the ERP answers 201 (created) or 200
 * (the same idempotency key again) with `{"data": {"id": ..., "number": ..., "status": "draft"}}`.
 *
 * ⛔ An answer whose status is not `draft` is refused, even a 2xx: HQ never claims a draft the ERP did not make.
 * ⚠ A timeout or a 5xx is `ErpWriteUnavailable`, an UNKNOWN outcome: the tool records `uncertain` and the same
 * idempotency key makes the retry safe. The token is read only from the open vault scope and never appears in a message.
 *
 * Credential kind `erp_write_token`, account: the business slug. This endpoint does not exist in the ERP yet; HQ
 * ships the contract, the ERP session builds it (docs/144-erp-write-contract.md section 6).
 */
final class HttpErpDraftBillWriter implements ErpDraftBillWriter
{
    public const PROVIDER = 'erp_http';

    public const CREDENTIAL = 'erp_write_token';

    public function __construct(private readonly CredentialVault $vault) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function createDraftBill(string $accountRef, ErpDraftBill $bill, string $idempotencyKey): ErpDraftBillReceipt
    {
        $token = $this->vault->secret(self::CREDENTIAL, $accountRef);
        $url = rtrim((string) config('hq.erp.base_url'), '/').str_replace('{business}', (string) $bill->erpBusinessId,
            (string) config('hq.erp.write.draft_bill_path', '/api/hq/v1/businesses/{business}/purchase-bills/drafts'));

        try {
            $response = Http::withToken($token)->acceptJson()->timeout((int) config('hq.erp.timeout_seconds', 15))
                ->withHeaders(['Idempotency-Key' => $idempotencyKey, 'X-Correlation-Id' => substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $idempotencyKey) ?? '', 0, 128)])
                ->post($url, $bill->toPayload());
        } catch (ConnectionException) {
            throw new ErpWriteUnavailable('erp: the ERP could not be reached; the outcome is unknown.');
        } catch (Throwable $e) {
            throw new ErpWriteUnavailable('erp: '.$e::class);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new ErpWriteUnavailable("erp answered {$status}; the outcome is unknown.");
        }
        if ($status === 401 || $status === 403) {
            throw new ErpWriteRefused('erp: the write token was rejected.');
        }
        if ($status !== 200 && $status !== 201) {
            throw new ErpWriteRefused("erp refused the draft ({$status}).");
        }
        $reference = $response->json('data.number') ?? $response->json('data.id');
        if (($response->json('data.status') ?? null) !== 'draft' || (! is_string($reference) && ! is_int($reference))) {
            throw new ErpWriteRefused('erp: the answer is not a draft bill, so HQ does not treat it as one.');
        }

        return new ErpDraftBillReceipt((string) $reference, 'draft');
    }
}
