<?php

declare(strict_types=1);

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Shop\CourierService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The courier's portal: the deliveries in their hands, and the four things
 * they can do to one from a phone at the door.
 *
 * Plain pages and forms, no script required: a rider's phone on a slow
 * connection in the rain is the design condition. Every route is behind
 * `can:deliveries.courier`, and every delivery is looked up through the
 * courier's own scope, so somebody else's parcel is a 404.
 */
class CourierController extends Controller
{
    public function __construct(private readonly CourierService $service) {}

    public function index(Request $request): View
    {
        $courier = $request->user();

        $active = Delivery::query()->forCourier($courier)->active()->with('order')
            ->orderByRaw("CASE status WHEN 'out_for_delivery' THEN 0 WHEN 'picked_up' THEN 1 WHEN 'assigned' THEN 2 ELSE 3 END")
            ->orderBy('assigned_at')
            ->get();

        $done = Delivery::query()->forCourier($courier)->whereIn('status', [Delivery::STATUS_DELIVERED, Delivery::STATUS_CANCELLED])->with('order')
            ->latest('delivered_at')->limit(20)->get();

        return view('courier.index', ['active' => $active, 'done' => $done]);
    }

    public function show(Request $request, Delivery $delivery): View
    {
        $delivery = $this->mine($request, $delivery);

        return view('courier.show', [
            'delivery' => $delivery,
            'order' => $delivery->order,
            'address' => $this->service->addressLine($delivery->order),
            'reasons' => $this->failureReasons(),
        ]);
    }

    public function pickedUp(Request $request, Delivery $delivery): RedirectResponse
    {
        $delivery = $this->mine($request, $delivery);

        return $this->attempt(fn () => $this->service->pickedUp($delivery, $request->user()), $delivery, __('Marked as picked up. The customer has been told it is on its way.'));
    }

    public function outForDelivery(Request $request, Delivery $delivery): RedirectResponse
    {
        $delivery = $this->mine($request, $delivery);

        return $this->attempt(fn () => $this->service->outForDelivery($delivery, $request->user()), $delivery, __('Marked as out for delivery.'));
    }

    public function delivered(Request $request, Delivery $delivery): RedirectResponse
    {
        $delivery = $this->mine($request, $delivery);

        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'photo' => [(bool) setting('courier.require_photo', false) ? 'required' : 'nullable', 'image', 'max:8192'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return $this->attempt(
            fn () => $this->service->delivered(
                $delivery,
                $request->user(),
                $data['recipient_name'],
                $data['note'] ?? null,
                $request->file('photo'),
                isset($data['lat']) ? (float) $data['lat'] : null,
                isset($data['lng']) ? (float) $data['lng'] : null,
            ),
            $delivery,
            __('Delivered. Thank you — the customer has been told.'),
            route('courier.index'),
        );
    }

    public function failed(Request $request, Delivery $delivery): RedirectResponse
    {
        $delivery = $this->mine($request, $delivery);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'detail' => ['nullable', 'string', 'max:1000'],
        ]);

        $reason = trim($data['reason'].(filled($data['detail'] ?? null) ? ' — '.$data['detail'] : ''));

        return $this->attempt(fn () => $this->service->failed($delivery, $request->user(), $reason), $delivery, __('Recorded. The office has been told.'));
    }

    /** The proof photograph, for staff who may see the delivery. */
    public function proof(Request $request, Delivery $delivery): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user && ($user->can('deliveries.view') || $delivery->courier_id === $user->getKey()), 403);
        abort_unless($delivery->hasProofPhoto() && Storage::disk(CourierService::PROOF_DISK)->exists((string) $delivery->proof_photo_path), 404);

        return Storage::disk(CourierService::PROOF_DISK)->response((string) $delivery->proof_photo_path);
    }

    private function mine(Request $request, Delivery $delivery): Delivery
    {
        abort_unless($delivery->courier_id === $request->user()?->getKey(), 404);

        return $delivery->load('order');
    }

    /** @param  callable(): void  $step */
    private function attempt(callable $step, Delivery $delivery, string $done, ?string $to = null): RedirectResponse
    {
        try {
            $step();
        } catch (RuntimeException $e) {
            return redirect()->route('courier.show', $delivery)->withErrors(['delivery' => $e->getMessage()]);
        }

        return redirect($to ?? route('courier.show', $delivery))->with('status', $done);
    }

    /** @return array<int, string> */
    private function failureReasons(): array
    {
        $configured = setting('courier.failure_reasons');

        $reasons = is_array($configured)
            ? array_values(array_filter(array_map(fn ($r): string => trim((string) $r), $configured)))
            : [];

        return $reasons !== [] ? $reasons : [
            __('Nobody at the address'),
            __('Could not find the address'),
            __('Customer not reachable by phone'),
            __('Customer asked for another day'),
            __('Refused the parcel'),
        ];
    }
}
