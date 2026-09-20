<?php

namespace App\Console\Commands;

use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Execution\Requester;
use App\Execution\RequesterKind;
use App\Models\AiRun;
use App\Models\Business;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Put one question to an agent and print everything the run produced.
 *
 * This is how the shadow period is actually driven: Joy asks a real question against real production
 * data, the run is recorded, and he reads it. There is no other way to start a run in M5 - no HTTP
 * endpoint, no chat surface - and that is deliberate. `Ask HQ` is M6, and shipping an operator chat
 * surface before seven days of reviewed shadow runs would put the agent in front of the person it
 * has not yet earned the right to answer.
 */
class AgentAsk extends Command
{
    protected $signature = 'hq:agent:ask
        {agent : the agent key, e.g. executive or analytics}
        {question* : the question, in words}
        {--business= : limit the question to one business slug}
        {--as= : email of the HQ user who is asking; the run is narrowed to what that person AND the enabling user may do}';

    protected $description = 'Ask an agent a question and record the run.';

    public function handle(AgentRunner $runner): int
    {
        $agentKey = (string) $this->argument('agent');
        $question = implode(' ', (array) $this->argument('question'));

        $businessId = null;
        if (($slug = $this->option('business')) !== null) {
            $businessId = Business::where('slug', $slug)->value('id');
            if ($businessId === null) {
                $this->error("HQ has no business with the slug [{$slug}].");

                return self::FAILURE;
            }
        }

        // ADR-047: a shell names nobody, so by default the run is resolved `as enabled` (the agent's
        // enabling user is the accountable person, and nothing is added to their authority). `--as`
        // names the person asking, and the run is then the intersection of the two.
        $asker = null;
        if (($email = $this->option('as')) !== null) {
            $asker = User::query()->where('email', $email)->first();
            if ($asker === null) {
                $this->error("No HQ user has the email [{$email}].");

                return self::FAILURE;
            }
        }

        try {
            $run = $runner->run(new AgentRunRequest(
                agentKey: $agentKey,
                question: $question,
                trigger: 'console',
                operator: $asker,
                businessId: $businessId,
                requester: $asker === null
                    ? Requester::asEnabled(RequesterKind::Console, 'hq:agent:ask with no --as')
                    : Requester::human($asker, 'hq:agent:ask --as'),
            ));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        // ⚠ Stated FIRST and every time. A shadow answer that scrolls past without its label is an
        // answer somebody acts on, and the whole of A0 is that nobody should yet.
        if ($run->shadow) {
            $this->line('<bg=yellow;fg=black> SHADOW </> This run is at autonomy '.strtoupper($run->autonomy_level->value)
                .', so what follows is NOT HQ\'s answer. It is a proposal for review.');
            $this->line('<comment>Bound by:</comment> '.$run->autonomy_reason);
            $this->newLine();
        }

        $this->line("<comment>Run</comment> {$run->uuid}  <comment>status</comment> {$run->status}"
            .($run->stop_reason === null ? '' : " ({$run->stop_reason})")
            ."  <comment>turns</comment> {$run->turns}  <comment>tools</comment> {$run->tool_calls_count}"
            ."  <comment>took</comment> {$run->duration_ms} ms");

        $cost = AiRun::where('correlation_id', $run->correlation_id)->sum('estimated_cost_usd');
        $this->line('<comment>Model</comment> '.($run->model ?? 'none').'  <comment>cost</comment> $'.number_format((float) $cost, 4)
            .'  <comment>prompt</comment> '.($run->prompt_version ?? 'none'));

        $this->newLine();

        if ($run->answer !== null) {
            $this->line($run->answer);
        } elseif ($run->error !== null) {
            $this->line('<comment>No answer.</comment> '.$run->error);
        }

        $this->evidence($run);
        $this->proposals($run);

        return self::SUCCESS;
    }

    private function evidence($run): void
    {
        $rows = [];
        foreach ((array) $run->evidence as $entry) {
            if (($entry['kind'] ?? '') === 'claim') {
                $rows[] = [substr((string) $entry['statement'], 0, 70), $entry['source'] ?? '-', $entry['as_of'] ?? '-'];
            }
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->line('<comment>Claims and where each came from</comment>');
        $this->table(['statement', 'source', 'as of'], $rows);
    }

    private function proposals($run): void
    {
        $rows = [];
        foreach ((array) $run->proposals as $entry) {
            $rows[] = [$entry['kind'] ?? '-', $entry['tool'] ?? '-', substr((string) ($entry['reason'] ?? ''), 0, 80)];
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->line('<comment>What it wanted to do and could not</comment>');
        $this->table(['kind', 'tool', 'reason'], $rows);
    }
}
