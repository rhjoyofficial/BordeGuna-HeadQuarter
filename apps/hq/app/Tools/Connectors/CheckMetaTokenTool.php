<?php

namespace App\Tools\Connectors;

use App\Ads\AdsCredential;
use App\Ads\Draft\MetaAdsDraftPlatform;
use App\Ads\Meta\MetaTokenInspector;
use App\Content\Publishing\FacebookPagePublisher;
use App\Content\Publishing\InstagramPublisher;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\OutcomeClass;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Support\Audit\Auditor;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use RuntimeException;

/**
 * `meta.check_token_health`: ask Meta whether one stored token is valid, when it expires and what it may do, and record
 * that on the credential's own row (validity, expiry, scopes, last verified) for the token health screen
 * (docs/144-meta-readiness). Read-only at Meta. The token is named by kind and account only; its value never leaves the vault.
 * Not business-scoped by tool (a Page token is stored by Page id), so it is the credentials permission holders' tool.
 */
final class CheckMetaTokenTool extends ConnectorTool
{
    /** The Meta token kinds that can be inspected. */
    public const KINDS = [FacebookPagePublisher::CREDENTIAL, InstagramPublisher::CREDENTIAL, 'whatsapp_access_token', AdsCredential::PROVIDER, MetaAdsDraftPlatform::CREDENTIAL];

    public function key(): string
    {
        return 'meta.check_token_health';
    }

    protected function title(): string
    {
        return 'Check a Meta token';
    }

    protected function purpose(): string
    {
        return 'Asks Meta whether a stored Meta token is valid, when it expires and which permissions it holds, and records that on the credential. Read-only at Meta.';
    }

    protected function flag(): string
    {
        return 'connectors.meta_health';
    }

    protected function permission(): string
    {
        return 'hq.tools.meta.health';
    }

    protected function businessScoped(): bool
    {
        return false;
    }

    protected function riskTier(): RiskTier
    {
        return RiskTier::R2;
    }

    protected function readWrite(): ReadWrite
    {
        return ReadWrite::Read;
    }

    protected function credentialSources(): array
    {
        $out = [MetaWebhookController::SECRET_PROVIDER => '='.MetaWebhookController::ACCOUNT_REF];
        foreach (self::KINDS as $kind) {
            $out[$kind] = 'args.account_ref';
        }

        return $out;
    }

    protected function arguments(): array
    {
        return [
            'kind' => ['type' => 'string', 'enum' => self::KINDS],
            'account_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
        ];
    }

    protected function required(): array
    {
        return ['kind', 'account_ref'];
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        $row = IntegrationCredential::query()->where('provider', $payload['kind'])->where('account_ref', $payload['account_ref'])->where('status', 'active')->first();
        if ($row === null) {
            return ToolResult::failed('No such token is stored.', ['connector_state' => 'credential_missing'], OutcomeClass::CredentialMissing);
        }
        // A token stored for one business is inspected only by someone who may use that business.
        if ($row->business_id !== null && $context->permittedBusinessIds !== null && ! in_array((int) $row->business_id, $context->permittedBusinessIds, true)) {
            return ToolResult::failed('That token belongs to a business outside what you may use.', [], OutcomeClass::ScopeDenied);
        }

        try {
            $info = app(MetaTokenInspector::class)->inspect((string) $payload['kind'], (string) $payload['account_ref']);
        } catch (RuntimeException $e) {
            return ToolResult::failed($e->getMessage(), ['connector_state' => 'not_inspected'], OutcomeClass::ConnectorError);
        }

        $row->forceFill([
            'scopes' => $info['scopes'], 'expires_at' => $info['expires_at'], 'last_verified_at' => now(),
            'status' => $info['valid'] ? 'active' : 'invalid',
            'meta' => array_merge((array) $row->meta, ['meta_health' => ['valid' => $info['valid'], 'type' => $info['type'], 'checked_at' => now()->toIso8601String()]]),
        ])->save();
        app(Auditor::class)->recordSystem('meta.token_checked', $row, null, ['kind' => $payload['kind'], 'valid' => $info['valid'], 'expires_at' => $info['expires_at']?->toIso8601String()]);

        return ToolResult::succeeded(['valid' => $info['valid'], 'expires_at' => $info['expires_at']?->toIso8601String(), 'scopes' => $info['scopes'], 'type' => $info['type']]);
    }
}
