<?php

namespace App\Http\Controllers\Connectors;

use App\Connectors\Capabilities;
use App\Courier\ParcelTimeline;
use App\Models\Business;
use App\Models\CourierParcel;
use App\Models\FactsOrder;
use App\Support\Authorization\BusinessScope;
use App\Support\Http\ListPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Where parcels are (docs/144-courier-connectors): the Parcels screen, the order view, and "track this one now". */
final class ParcelController extends ConnectorController
{
    public function index(Request $request, BusinessScope $scope): Response
    {
        $user = $request->user();
        $parcels = $scope->restrict(CourierParcel::query(), $user)->with('events')->with('business:id,name,slug')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('last_event_at')->orderByDesc('id');
        $page = ListPage::paginate($parcels, $request);
        $parcels = $page->getCollection();

        return Inertia::render('parcels/index', [
            'pagination' => $page->meta(),
            'parcels' => $parcels->map(fn (CourierParcel $p) => ParcelTimeline::row($p) + ['business' => $p->business?->name])->all(),
            'businesses' => Business::query()->when($scope->all($user) !== null, fn ($q) => $q->whereIn('id', (array) $scope->all($user)))->orderBy('name')->get(['slug', 'name'])->all(),
            'providers' => array_keys(Capabilities::COURIER_TRACK_BY_PROVIDER),
            'filters' => ['status' => $request->string('status')->toString() ?: null],
        ]);
    }

    public function track(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_slug' => ['required', 'string', 'max:64'],
            'courier' => ['required', 'string', Rule::in(array_keys(Capabilities::COURIER_TRACK_BY_PROVIDER))],
            'consignment_ref' => ['required', 'string', 'min:3', 'max:96', 'regex:/^[A-Za-z0-9._\-]+$/'],
        ]);
        $business = $this->scopedBusiness($request->user(), $data['business_slug']);

        return $this->outcomeResponse($this->runTool($request->user(), 'courier.track_parcel', $data, $business), 'consignment_ref', 'parcel-tracked');
    }

    /** One order and its parcels. An order of another business does not exist for a person limited to theirs. */
    public function order(Request $request, FactsOrder $order, BusinessScope $scope): Response
    {
        abort_unless($scope->allows($request->user(), (int) $order->business_id) && $order->business_id !== null, 404);
        $order->load('store:id,name');

        return Inertia::render('orders/show', [
            'order' => [
                'id' => $order->id, 'ref' => $order->order_ref, 'status' => $order->status, 'payment_status' => $order->payment_status, 'gross' => (string) $order->gross,
                'placed_at' => $order->placed_at?->toIso8601String(), 'delivered_at' => $order->delivered_at?->toIso8601String(), 'store' => $order->store?->name,
                'district' => $order->district, 'thana' => $order->thana, 'customer_id' => $order->customer_id,
            ],
            'parcels' => ParcelTimeline::forOrders([$order->id], (int) $order->business_id)[$order->id] ?? [],
        ]);
    }
}
