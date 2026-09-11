<?php

declare(strict_types=1);

namespace App\Http\Requests\Shop;

use App\Models\ShippingZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What the customer tells us at checkout.
 *
 * ── A Ghanaian address, not a Western one ───────────────────────────────────
 *
 * Region, area and a landmark-style address line. There is no postcode field
 * because there is no postcode a courier in Bolgatanga will use; the phone
 * number is the thing that actually gets a parcel delivered, which is why it
 * is required for delivery and optional for collection.
 *
 * ── The region is validated against zones that are ACTUALLY served ──────────
 *
 * The list on the form is built from active zones with an active rate, and
 * the rule here checks the same thing — so a customer who edits the form
 * cannot select a region the foundation has no courier for and put the
 * checkout service in the position of refusing after the fact.
 *
 * ── Consent covers holding their details to fulfil the order ────────────────
 *
 * Act 843 needs a basis for storing a name, an address and a phone number.
 * The tick is that basis, and it is the ONLY tick: an order form that also
 * asks somebody to join a mailing list before it will sell them a mug is an
 * order form that sells fewer mugs.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $delivering = $this->input('fulfilment') === 'deliver';

        return [
            'customer_name' => ['required', 'string', 'max:191'],
            'customer_email' => ['required', 'string', 'email:rfc', 'max:191'],
            'customer_phone' => [
                $delivering ? 'required' : 'nullable',
                'string', 'max:20', 'regex:/^(\+?233|0)[2345][0-9]{8}$/',
            ],

            'fulfilment' => ['required', Rule::in(['deliver', 'collect'])],

            'delivery_region' => [$delivering ? 'required' : 'nullable', 'string', Rule::in(ShippingZone::REGIONS)],
            'delivery_area' => [$delivering ? 'required' : 'nullable', 'string', 'max:191'],
            'delivery_address' => [$delivering ? 'required' : 'nullable', 'string', 'max:255'],
            'delivery_notes' => ['nullable', 'string', 'max:500'],

            'consent' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_phone.regex' => __('That does not look like a Ghanaian number. Try 024 123 4567.'),
            'customer_phone.required' => __('The courier will call this number to find you.'),
            'consent.accepted' => __('We need your agreement to hold these details in order to fulfil the order.'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('fulfilment') === 'collect') {
                    if (ShippingZone::query()->active()->where('is_pickup', true)->doesntExist()) {
                        $validator->errors()->add('fulfilment', __('Collection is not available at the moment.'));
                    }

                    return;
                }

                if ($validator->errors()->has('delivery_region')) {
                    return;
                }

                $zone = ShippingZone::forRegion((string) $this->input('delivery_region'));

                if ($zone === null || $zone->rates()->where('is_active', true)->doesntExist()) {
                    $validator->errors()->add('delivery_region', __(
                        'We do not deliver to :region at the moment. Collection may be available instead.',
                        ['region' => $this->input('delivery_region')],
                    ));
                }
            },
        ];
    }

    public function isCollection(): bool
    {
        return $this->input('fulfilment') === 'collect';
    }
}
