<?php

namespace App\Agents;

use RuntimeException;

/**
 * The agents' prompts, as versioned files on disk (docs/48 M5, I.1).
 *
 * ## Why files and not rows
 *
 * A prompt is behaviour. It belongs in the repository, in a diff, in a review, and in the same
 * release as the tests that were run against it. A prompt in a database is behaviour that can change
 * without a deploy, without a reviewer, and without any evaluation run noticing - which is precisely
 * the failure `evaluation_runs.prompt_version` exists to catch.
 *
 * ## The version stamp is a hash, deliberately
 *
 * `executive.v1+3f2a91c4` is the declared version AND a digest of the exact bytes that were sent.
 * A hand-maintained version number is a number somebody forgets to bump, and the first time it is
 * forgotten every evaluation run after it is attributed to the wrong prompt. Here, editing a single
 * character of either file changes the stamp, so a run can always say what it actually ran on.
 *
 * The core file is included in every agent's digest because changing a shared rule changes every
 * agent's behaviour, and a stamp that did not move would hide exactly that.
 */
final class PromptLibrary
{
    private const CORE = 'hq-core.v1';

    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(private readonly ?string $directory = null) {}

    /**
     * The complete system prompt for a role: the shared rules, then the role's own.
     *
     * Order matters. The shared rules come first so the role text is read as a specialisation of
     * them rather than as a replacement, and so the long stable block sits at the front where the
     * provider's prompt cache can hold it (see AnthropicProvider::complete).
     */
    public function system(string $role): string
    {
        return $this->read(self::CORE)."\n\n---\n\n".$this->read($this->roleFile($role));
    }

    /** The stamp written to `ai_runs.prompt_version` and `agent_runs.prompt_version`. */
    public function version(string $role): string
    {
        $digest = substr(hash('sha256', $this->system($role)), 0, 8);

        return $this->roleFile($role).'+'.$digest;
    }

    public function has(string $role): bool
    {
        return is_file($this->path($this->roleFile($role)));
    }

    /** @return list<string> role keys that have a prompt file, for the completeness test */
    public function roles(): array
    {
        $found = [];

        foreach (glob($this->path('*.md')) ?: [] as $file) {
            $name = basename($file, '.md');
            if ($name === self::CORE) {
                continue;
            }
            // `executive.v1` -> `executive`
            $found[] = str_contains($name, '.') ? substr($name, 0, strrpos($name, '.')) : $name;
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * The newest file for a role.
     *
     * ⚠ Version selection is by filename, so `executive.v2.md` supersedes `executive.v1.md` the
     * moment it exists. That is intentional: adding a file is the deploy, and leaving the old one in
     * place keeps the history readable and lets an evaluation run be re-read against what it named.
     */
    private function roleFile(string $role): string
    {
        $candidates = glob($this->path($role.'.v*.md')) ?: [];

        if ($candidates === []) {
            throw new RuntimeException(
                "No prompt file for agent role [{$role}]. Expected something like resources/prompts/{$role}.v1.md. "
                .'An agent with no prompt is not a misconfiguration to work around; it has no instructions at all.'
            );
        }

        usort($candidates, static fn (string $a, string $b) => strnatcmp($a, $b));

        return basename(end($candidates), '.md');
    }

    private function read(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $path = $this->path($name.'.md');

        if (! is_file($path)) {
            throw new RuntimeException("Prompt file [{$name}.md] is missing from ".$this->directory().'.');
        }

        return $this->cache[$name] = trim((string) file_get_contents($path));
    }

    private function path(string $file): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$file;
    }

    private function directory(): string
    {
        return $this->directory ?? resource_path('prompts');
    }
}
