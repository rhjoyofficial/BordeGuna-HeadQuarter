<?php

namespace App\Ai;

use App\Gateway\Validation\ArgumentValidator;

/**
 * Makes a model's reply conform to a schema, or fail.
 *
 * The contract is deliberately narrow: **one repair attempt**, then failure.
 *
 * ⚠ HQ never patches a malformed object into shape. Filling in a missing field with a default, or
 * dropping one that does not validate, produces an object that looks real and is partly invented,
 * and it then flows onward as though a model had said it. One repair, with the actual validation
 * errors handed back, is a fair chance to correct a formatting slip. A second failure is a signal
 * about the prompt or the model, not something to paper over.
 *
 * The same `ArgumentValidator` the Tool Gateway uses on tool arguments validates here, so a schema
 * behaves identically whether it guards a tool call or a model reply.
 */
final class StructuredOutput
{
    public function __construct(private readonly ArgumentValidator $validator) {}

    /**
     * @param  array<string, mixed>  $schema
     * @return array{valid: bool, data: array<string, mixed>|null, message: string|null}
     */
    public function check(?array $decoded, array $schema): array
    {
        if ($decoded === null) {
            return ['valid' => false, 'data' => null, 'message' => 'The reply was not a JSON object.'];
        }

        $result = $this->validator->validate($decoded, $schema);

        return [
            'valid' => $result->valid,
            'data' => $result->valid ? $result->normalised : null,
            'message' => $result->valid ? null : $result->message(),
        ];
    }

    /**
     * The follow-up turn that asks a model to fix its own output.
     *
     * It restates the requirement rather than only reporting the error, because a model that got the
     * shape wrong once has already demonstrated that the original instruction did not land.
     *
     * @return list<array{role: string, content: string}>
     */
    public function repairTurns(array $originalMessages, string $badReply, string $error, array $schema): array
    {
        return array_merge($originalMessages, [
            ['role' => 'assistant', 'content' => $badReply === '' ? '(empty reply)' : $badReply],
            ['role' => 'user', 'content' => implode("\n", [
                'That reply did not match the required schema.',
                "Validation error: {$error}",
                '',
                'Reply with a single JSON object and nothing else: no prose before or after it, and no code fence.',
                'It must satisfy this JSON Schema exactly:',
                json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ])],
        ]);
    }
}
