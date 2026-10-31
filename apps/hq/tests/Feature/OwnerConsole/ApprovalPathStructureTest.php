<?php

use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Http\Controllers\Approvals\ApprovalController;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\FeatureFlag;
use App\OwnerConsole\Flag;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Tools\CustomerMessageTool;

/*
 | ChatGPT ruling 31 Oct 2026, items 2 and 3 (pre-freeze section B: "No direct one-click approval route or handler" and
 | "No multi-message approval authorization").
 |
 | 2. Batch approval is SEQUENCE ONLY: one explicit approval per message through the one approve action, then "next".
 |    The multi-approval executor, its endpoint and its table are gone; no route or handler approves more than one
 |    approval per request.
 | 3. One-click approval is REMOVED: no approve-in-place action, route, handler, flag or UI. A console decision card only
 |    navigates to the full approval page.
 */

/** Parks one customer-message approval (an R4, customer-facing stand-in) and returns it. */
function apsMessage(string $text, $user): Approval
{
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($user, 'test.customer_message', ['text' => $text]));
    expect($outcome->status->pending())->toBeTrue();

    return Approval::findOrFail($outcome->approvalId);
}

/** Every PHP file under a directory, as path => source. */
function apsSources(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (in_array($file->getExtension(), ['php', 'ts', 'tsx'], true)) {
            $out[str_replace('\\', '/', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }
    }

    return $out;
}

/** A PHP file's code without its comments, so a docblock naming a class is not mistaken for a use of it. */
function apsCode(string $path): string
{
    return implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents($path))));
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    CustomerMessageTool::$sent = [];
    CustomerMessageTool::$requesterMayDecide = true;
    CustomerMessageTool::$tier = RiskTier::R4;
    app(ToolRegistry::class)->register(CustomerMessageTool::class);
    app(ToolAvailability::class)->forget();
    $this->asker = $this->hqUser('owner');
    $this->owner = $this->hqUser('owner');
});

it('has exactly one approving route, and its handler approves exactly one approval', function () {
    // Every route whose handler can reach the executor.
    $approving = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            continue;
        }
        [$class, $method] = explode('@', $action);
        if (! class_exists($class) || ! method_exists($class, $method)) {
            continue;
        }
        $ref = new ReflectionMethod($class, $method);
        $body = implode('', array_slice(file($ref->getFileName()), $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        if (str_contains($body, 'approveAndExecute(')) {
            $approving[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }
    expect($approving)->toBe(['POST approvals/{approval}/approve']);

    // In all of app/, only that controller calls the executor.
    $callers = collect(apsSources(app_path()))->filter(fn ($src) => str_contains($src, '->approveAndExecute('))->keys()
        ->map(fn ($path) => substr($path, strpos($path, '/app/') + 1))->values()->all();
    expect($callers)->toBe(['app/Http/Controllers/Approvals/ApprovalController.php']);

    // And it calls it once, on the one route-bound approval, with no loop around it.
    $ref = new ReflectionMethod(ApprovalController::class, 'approve');
    $body = implode('', array_slice(file($ref->getFileName()), $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    expect(substr_count($body, 'approveAndExecute('))->toBe(1)
        ->and($body)->not->toMatch('/\b(foreach|for|while)\s*\(|array_map\(|->each\(|->map\(/')
        ->and($ref->getNumberOfParameters())->toBe(2)
        ->and((string) $ref->getParameters()[1]->getType())->toBe(Approval::class);
});

it('has no multi-approval route, executor, table or flag left', function () {
    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'approvals') && array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->map(fn ($r) => $r->uri())->values()->all();
    // Every approval write is about ONE route-bound approval.
    expect($writes)->each->toStartWith('approvals/{approval}/');

    expect(file_exists(app_path('OwnerConsole/Batch/ApprovalActs.php')))->toBeFalse()
        ->and(file_exists(app_path('Models/ApprovalBatch.php')))->toBeFalse()
        ->and(Schema::hasTable('approval_batches'))->toBeFalse()
        ->and(Route::has('approvals.batch.store'))->toBeFalse()
        ->and(Route::has('approvals.quick'))->toBeFalse();

    foreach (apsSources(app_path()) + apsSources(resource_path('js')) + apsSources(base_path('routes')) as $path => $src) {
        expect($src)->not->toContain('quick-approve', $path)
            ->and($src)->not->toContain('one_click_approve', $path)
            ->and($src)->not->toContain('ONE_CLICK', $path);
    }
});

it('approves only the route-bound approval, whatever else a request carries', function () {
    $a = apsMessage('first', $this->asker);
    $b = apsMessage('second', $this->asker);

    $this->actingAsHq($this->owner)->post("/approvals/{$a->id}/approve", [
        'items' => [['id' => $b->id, 'payload_hash' => $b->payload_hash]], 'ids' => [$b->id], 'approval_ids' => [$b->id],
    ])->assertRedirect();

    expect($a->fresh()->status->value)->toBe('consumed')->and($b->fresh()->status->value)->toBe('pending')
        ->and(CustomerMessageTool::$sent)->toBe(['first']);
});

it('keeps the batch overview read-only: it lists in full and links each message to its own page, and approves nothing', function () {
    FeatureFlag::query()->where('key', Flag::BATCH_APPROVAL)->update(['enabled' => true]);
    $a = apsMessage('first', $this->asker);
    $b = apsMessage('second', $this->asker);

    $this->actingAsHq($this->owner)->get('/approvals/batch')->assertOk()->assertInertia(fn ($page) => $page
        ->component('approvals/batch')->has('messages', 2)
        ->where('messages.0.href', "/approvals/{$a->id}?queue=messages")->where('messages.0.payload.text', 'first'));
    $this->actingAsHq($this->owner)->post('/approvals/batch', ['items' => [['id' => $a->id, 'payload_hash' => $a->payload_hash]]])->assertStatus(405);
    expect($a->fresh()->status->value)->toBe('pending')->and($b->fresh()->status->value)->toBe('pending')->and(CustomerMessageTool::$sent)->toBe([]);

    $page = file_get_contents(resource_path('js/pages/approvals/batch.tsx'));
    expect($page)->not->toMatch('/router\.(post|put|patch|delete)|type="checkbox"|useForm/');
    $controller = apsCode(app_path('Http/Controllers/OwnerConsole/BatchApprovalController.php'));
    expect($controller)->not->toMatch('/ApprovalExecutor|approveAndExecute|ToolGateway|->execute\(/');
});

it('offers the next message only after the current one is decided, and deciding one never decides the next', function () {
    FeatureFlag::query()->where('key', Flag::BATCH_APPROVAL)->update(['enabled' => true]);
    $a = apsMessage('first', $this->asker);
    $b = apsMessage('second', $this->asker);

    $this->actingAsHq($this->owner)->get("/approvals/{$a->id}?queue=messages")
        ->assertInertia(fn ($page) => $page->where('queue.next_href', null)->where('queue.remaining', 1));

    $this->actingAsHq($this->owner)->post("/approvals/{$a->id}/approve")->assertRedirect();
    expect(AuditLog::where('action', 'approvals.batch')->exists())->toBeFalse();
    $this->actingAsHq($this->owner)->get("/approvals/{$a->id}?queue=messages")
        ->assertInertia(fn ($page) => $page->where('queue.next_href', "/approvals/{$b->id}?queue=messages"));

    expect($b->fresh()->status->value)->toBe('pending')->and(CustomerMessageTool::$sent)->toBe(['first']);

    // A failed or refused message does not authorize another: rejecting b leaves nothing else touched.
    $this->actingAsHq($this->owner)->post("/approvals/{$b->id}/reject", ['reason' => 'no'])->assertRedirect();
    expect(CustomerMessageTool::$sent)->toBe(['first']);

    // With the overview switched off there is no queue at all.
    FeatureFlag::query()->where('key', Flag::BATCH_APPROVAL)->update(['enabled' => false]);
    $this->actingAsHq($this->owner)->get("/approvals/{$a->id}?queue=messages")->assertInertia(fn ($page) => $page->where('queue', null));
});

it('gives console decision cards no way to approve: no hash, no payload, no action, only the link to the full approval', function () {
    $a = apsMessage('first', $this->asker);

    $this->actingAsHq($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('console.decisions.cards.0.id', $a->id)
        ->where('console.decisions.cards.0.href', "/approvals/{$a->id}")
        ->missing('console.decisions.cards.0.quick')
        ->missing('console.decisions.cards.0.payload')
        ->missing('console.decisions.cards.0.payload_hash'));

    // No console source posts anywhere but /ask, and none names an approval decision.
    foreach (apsSources(resource_path('js/components/console')) + [resource_path('js/pages/dashboard.tsx') => file_get_contents(resource_path('js/pages/dashboard.tsx'))] as $path => $src) {
        preg_match_all('/\.(post|put|patch|delete)\(\s*[\'"`]([^\'"`]+)/', $src, $m);
        expect(array_unique($m[2]))->each->toBe('/ask');
        expect($src)->not->toMatch('#/approvals/[^\'"`]*/(approve|reject|quick)#');
    }
    expect(file_get_contents(app_path('Observe/Console/ConsoleReader.php')))->not->toMatch('/payload_hash|ApprovalExecutor|approveAndExecute|quick/');
});
