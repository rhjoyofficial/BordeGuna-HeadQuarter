<?php

namespace App\Http\Controllers\Observe;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Observe\Alerts\AlertEngine;
use App\Observe\Autonomy\AutonomyEvidence;
use App\Observe\Dashboards\AiHealthDashboard;
use App\Observe\Dashboards\ControlPlaneDashboard;
use App\Observe\Dashboards\CostDashboard;
use App\Observe\Dashboards\DashboardWindow;
use App\Observe\Dashboards\PlatformDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The four Phase 2 dashboards and the alert record (docs/48 N.3 and N.5, docs/78 B.2), and the
 * autonomy evidence view (docs/80 C.3).
 *
 * ⛔ Read-only. There is no action on any of these pages: a switch is changed on its own screen, an
 * alert clears when its condition does, and nothing here can start, stop or re-tool anything.
 */
final class ObserveController extends Controller
{
    public function aiHealth(Request $request, AiHealthDashboard $dashboard): Response
    {
        return Inertia::render('observe/ai-health', $dashboard->build($this->window($request), $request->user()));
    }

    public function cost(Request $request, CostDashboard $dashboard): Response
    {
        return Inertia::render('observe/cost', $dashboard->build($this->window($request), $request->user()));
    }

    public function controlPlane(Request $request, ControlPlaneDashboard $dashboard): Response
    {
        return Inertia::render('observe/control-plane', $dashboard->build($this->window($request), $request->user()));
    }

    /**
     * What autonomy would rest on, per agent and dimension (docs/80 C.3). It reads and recounts; it
     * creates no grant, changes no level and touches no evaluation row.
     */
    public function autonomyEvidence(AutonomyEvidence $evidence): Response
    {
        return Inertia::render('observe/autonomy-evidence', $evidence->build());
    }

    public function platform(PlatformDashboard $dashboard): Response
    {
        return Inertia::render('observe/platform', $dashboard->build());
    }

    public function alerts(AlertEngine $engine): Response
    {
        $row = fn (Alert $a) => [
            'id' => $a->id,
            'rule' => $a->rule,
            'fingerprint' => $a->fingerprint,
            'severity' => $a->severity,
            'subject' => $a->subject,
            'body' => $a->body,
            'context' => $a->context,
            'paged_by' => $a->paged_by,
            'occurrences' => $a->occurrences,
            'first_seen_at' => $a->first_seen_at->toIso8601String(),
            'last_seen_at' => $a->last_seen_at->toIso8601String(),
            'notified_at' => $a->notified_at?->toIso8601String(),
            'resolved_at' => $a->resolved_at?->toIso8601String(),
        ];

        return Inertia::render('observe/alerts', [
            'open' => Alert::open()
                ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
                ->orderBy('first_seen_at')
                ->get()->map($row)->all(),
            'resolved' => Alert::query()
                ->whereNotNull('resolved_at')
                ->where('resolved_at', '>=', now()->subDays(7))
                ->latest('resolved_at')
                ->limit(50)
                ->get()->map($row)->all(),
            'rules' => array_map(fn ($rule) => ['key' => $rule->key(), 'paged_by' => $rule->pagedBy()], $engine->rules()),
        ]);
    }

    private function window(Request $request): DashboardWindow
    {
        return DashboardWindow::from($request->query('window'));
    }
}
