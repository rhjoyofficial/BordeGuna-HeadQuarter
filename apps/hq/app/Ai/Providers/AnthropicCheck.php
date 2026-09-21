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
 *
 * ⚠ docs/81 C.7: the model is read from ANTHROPIC's own low row. It used to be "the active low row
 * with the best priority", which with a second provider registered could send an OpenAI model id to
 * Anthropic and record the 404 as the state of a good key. An active row is preferred, else any
 * Anthropic low row, so the key can be checked while another provider serves.
 */
final class AnthropicCheck implements ReadinessCall
{
    public function __construct(private readonly CredentialVault $vault) {}

    public function provider(): string
    {
        return AnthropicProvider::PROVIDER;
    }

    public function modelId(): ?string
    {
        $id = AiModel::query()->where('provider', AnthropicProvider::PROVIDER)->where('tier', 'low')
            ->orderByDesc('is_active')->orderBy('priority')->orderBy('id')->value('model_id');

        return $id === null ? null : (string) $id;
    }

    public function send(): Response
    {
        $model = $this->modelId();

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
