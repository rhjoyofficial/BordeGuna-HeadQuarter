<?php

namespace App\Ai;

use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ModelResponse;
use App\Ai\ToolCalls\ProposedToolCall;
use App\Ai\ToolCalls\ToolCallNormaliser;
use App\Gateway\Redaction\Redactor;
use App\Models\AiRun;
use App\Models\AiRunReply;
use Throwable;

/**
 * Writes the model's raw structured reply beside its run (docs/90 A.1 step 1), before the runtime
 * reads anything from it.
 *
 * What is kept: the text as the model wrote it, with the value of any secret-named key masked by the
 * gateway's own `Redactor` (the same list it masks in tool arguments) and every other key, value and
 * ordering preserved; the decoded object; the normalised tool calls, kept SEPARATELY so a reader can
 * put the two side by side; provider, model, prompt version, attempt, correlation and run identity;
 * why the reply stopped.
 *
 * ⛔ Never an authorization source. This class only writes. No decision in HQ reads `ai_run_replies`,
 *    and `ModelReplyCaptureTest` fails if one starts to.
 *
 * ⚠ A failure to record must never fail the model call it records: the reply is evidence about the
 *    call, and losing the evidence is bad, but turning a good reply into a failed run because the
 *    evidence table was unavailable would be worse. The failure is logged and the call goes on.
 */
class ModelReplyRecorder
{
    public function __construct(private readonly Redactor $redactor) {}

    public function record(AiRun $run, ModelRequest $request, ModelResponse $response, string $kind): ?AiRunReply
    {
        try {
            $decoded = $response->json();
            $masked = $decoded === null ? null : $this->redactor->redact($decoded);

            $reply = AiRunReply::create([
                'ai_run_id' => $run->id,
                'correlation_id' => $run->correlation_id,
                'agent_key' => $run->agent_key,
                'provider' => $request->model->provider,
                'model' => $request->model->model_id,
                'prompt_version' => $run->prompt_version,
                'attempt' => (int) ($run->attempt ?? 1),
                'kind' => $kind,
                'stop_reason' => substr($response->stopReason, 0, 32),
                // The masked object re-encoded when the text decoded, so the stored text carries every
                // key the model wrote and no secret; the text itself when it did not decode.
                'raw_text' => $masked === null ? $this->maskFreeText($response->text) : (string) json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'raw_json' => $masked,
                'normalised' => $decoded === null ? null : [
                    'tool_calls' => array_map(static fn (ProposedToolCall $c) => $c->toArray(), ToolCallNormaliser::fromReply($masked)),
                    'status' => $decoded['status'] ?? null,
                ],
            ]);

            $run->forceFill(['output_ref' => 'ai_run_reply:'.$reply->id])->save();

            return $reply;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * A reply that is not a JSON object cannot be masked by key. It is kept as written: the model is
     * never handed a credential (the vault opens one around a request and closes it after), so its
     * free text cannot carry one HQ knows; what it could carry is the operator's own words, which the
     * run already holds.
     */
    private function maskFreeText(string $text): string
    {
        return $text;
    }
}
