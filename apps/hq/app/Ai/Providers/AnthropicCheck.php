<?php

namespace App\Ai\Providers;

use App\Gateway\Credentials\CredentialVault;
use App\Models\AiModel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The model provider's 8-token readiness call (docs/54's bypass test; docs/77 E.1).
 *
 * It lives in the driver layer because only the driver layer names a provider endpoint (the M4
 * invariant in AiSafetyPostureTest). It sends one tiny request with the vault's key, opened in a
 * vault scope and never printed, and returns the provider's answer untouched. What the answer MEANS
 * is `App\Ai\ProviderCheck`'s business.
 */
final class AnthropicCheck
{
    public function __construct(private readonly CredentialVault $vault) {}

    public function send(): Response
    {
        $model = AiModel::query()->where('tier', 'low')->where('is_active', true)->orderBy('priority')->value('model_id');

        return $this->vault->openForAccounts(
            [[AnthropicProvider::CREDENTIAL_PROVIDER, AnthropicProvider::ACCOUNT_REF]],
            fn () => Http::withHeaders([
                'x-api-key' => $this->vault->secret(AnthropicProvider::CREDENTIAL_PROVIDER, AnthropicProvider::ACCOUNT_REF),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 8,
                'messages' => [['role' => 'user', 'content' => 'Reply with the single word ok.']],
            ]),
        );
    }
}
