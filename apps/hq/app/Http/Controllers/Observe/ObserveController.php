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
use App\Observe\Dashboards\PostureDashboard;
use App\Support\Authorization\BusinessScope;
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

    /**
     * The exact activation posture on one page (docs/88 A point 7): switches, role restrictions, shadow
     * readiness, gate evidence, model provider and credential readiness, capability state, what is
     * uncertain or dead, and the audit rows behind the switches. It reads; it changes nothing.
     */
    public function posture(Request $request, PostureDashboard $dashboard): Response
    {
        return Inertia::render('observe/posture', $dashboard->build($request->user()));
    }

    public function platform(Request $request, PlatformDashboard $dashboard, BusinessScope $scope): Response
    {
        // docs/136: queues, events, credentials and retention across every business; group-wide by nature.
        abort_if($scope->isRestricted($request->user()), 403, 'This screen is group-wide and your access is limited to some businesses.');

        return Inertia::render('observe/platform', $dashboard->build());
    }

    public function alerts(Request $request, AlertEngine $engine, BusinessScope $scope): Response
    {
        // docs/136: an alert carries no business; the screen is group-wide by nature, so a person whose
        // access is limited to some businesses is refused it with the reason (ADR-035 section 4, fail closed).
        abort_if($scope->isRestricted($request->user()), 403, 'This screen is group-wide and your access is limited to some businesses.');

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
