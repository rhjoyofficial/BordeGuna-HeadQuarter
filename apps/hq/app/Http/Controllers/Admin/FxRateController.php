<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FxRate;
use App\Support\Authorization\GroupWideAuthority;
use App\Support\Http\ListPage;
use App\Support\Money\FxRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Ruling 1 Nov 2026: the owner's USD to BDT rate. The screen shows the current rate, whether it is usable, and every rate ever set
 * (append-only history, newest first, with the shared pagination bar). Setting a new rate is a person's act: the route needs
 * `hq.fx.manage` and `group.wide:hq.fx.manage`, this controller asserts the authority again, and `FxRates::setRate()` a third
 * time, as for Pause All AI. 2FA comes from the route group.
 */
final class FxRateController extends Controller
{
    // FxRates is resolved per action, never held on the controller: a cached controller instance must not keep a remembered rate.
    public function __construct(private readonly GroupWideAuthority $authority) {}

    public function index(Request $request, FxRates $fx): Response
    {
        $current = $fx->latest();
        $page = ListPage::paginate(FxRate::query()->with('setBy:id,name,email')->latest('id'), $request);

        return Inertia::render('fx-rate/index', [
            'current' => $current === null ? null : $this->row($current) + ['state' => $fx->state($current)],
            'refusal' => $fx->refusal(),
            'history' => $page->through(fn (FxRate $rate) => $this->row($rate)),
            'max_validity_days' => FxRates::MAX_VALIDITY_DAYS,
            'switch' => $this->authority->screenProps($request->user(), FxRates::PERMISSION),
        ]);
    }

    public function store(Request $request, FxRates $fx): RedirectResponse
    {
        $this->authority->assert($request->user(), FxRates::PERMISSION);

        $data = $request->validate([
            'reference_rate' => ['required', 'string', 'max:20'],
            'reserve_rate' => ['required', 'string', 'max:20'],
            'source' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'as_of' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500', 'regex:/\S/'],
        ]);

        try {
            $fx->setRate($request->user(), $data['reference_rate'], $data['reserve_rate'], $data['source'], $data['as_of'], $data['valid_until'], $data['reason']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['fx_rate' => $e->getMessage()]);
        }

        return back()->with('status', 'fx-rate-set');
    }

    /** @return array<string, mixed> */
    private function row(FxRate $rate): array
    {
        return FxRates::summary($rate) + [
            'reason' => $rate->reason,
            'set_by' => $rate->setBy?->name ?? $rate->setBy?->email,
            'created_at' => $rate->created_at?->toIso8601String(),
        ];
    }
}
