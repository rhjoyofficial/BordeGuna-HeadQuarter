<?php

namespace App\Ai\Providers;

use App\Gateway\Credentials\CredentialVault;
use App\Models\AiModel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI's readiness call (docs/81 C.7), the counterpart of `AnthropicCheck`: one tiny Responses API
 * request to OpenAI's own low row with the vault's key, opened in a vault scope and never printed, and
 * the answer returned untouched for `App\Ai\ProviderCheck` to classify.
 *
 * An active low row is preferred, else any OpenAI low row, so the key can be verified BEFORE
 * `hq:ai:provider` moves traffic to it. 16 output tokens, no reasoning (the call proves the key and the
 * account, not the model's judgement), and `store: false` like every HQ call.
 */
final class OpenAiCheck implements ReadinessCall
{
    public function __construct(private readonly CredentialVault $vault) {}

    public function provider(): string
    {
        return OpenAiProvider::PROVIDER;
    }

    public function modelId(): ?string
    {
        $id = AiModel::query()->where('provider', OpenAiProvider::PROVIDER)->where('tier', 'low')
            ->orderByDesc('is_active')->orderBy('priority')->orderBy('id')->value('model_id');

        return $id === null ? null : (string) $id;
    }

    public function send(): Response
    {
        $model = $this->modelId();

        return $this->vault->openForAccounts(
            [[OpenAiProvider::CREDENTIAL_PROVIDER, OpenAiProvider::ACCOUNT_REF]],
            fn () => Http::withToken($this->vault->secret(OpenAiProvider::CREDENTIAL_PROVIDER, OpenAiProvider::ACCOUNT_REF))
                ->acceptJson()
                ->timeout(60)
                ->post(OpenAiProvider::ENDPOINT, [
                    'model' => $model,
                    'input' => 'Reply with the single word ok.',
                    'max_output_tokens' => 16,
                    'store' => false,
                    'reasoning' => ['effort' => 'none'],
                ]),
        );
    }
}
