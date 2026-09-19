<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Rules\CambodianPhone;
use App\Rules\TelegramHandle;
use App\Support\Cambodia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The customer's saved delivery addresses.
 *
 * Phone and Telegram are stored in the same canonical shape checkout uses, so
 * "012 345 678" and "+85512345678" are one number wherever they were typed.
 */
class AddressController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->validated($request);

        $address = Auth::user()->addresses()->create($data + [
            // The first one saved has nothing to compete with.
            'is_default' => Auth::user()->addresses()->count() === 0,
        ]);

        if ($request->boolean('is_default')) {
            $address->makeDefault();
        }

        return back()->with('success', 'Address saved.');
    }

    public function update(Request $request, Address $address)
    {
        $this->authorise($address);

        $address->update($this->validated($request));

        if ($request->boolean('is_default')) {
            $address->makeDefault();
        }

        return back()->with('success', 'Address updated.');
    }

    public function destroy(Address $address)
    {
        $this->authorise($address);

        $wasDefault = $address->is_default;
        $address->delete();

        // Never leave the customer with addresses but no default, or
        // checkout has nothing to reach for.
        if ($wasDefault) {
            Auth::user()->addresses()->first()?->makeDefault();
        }

        return back()->with('success', 'Address removed.');
    }

    public function makeDefault(Address $address)
    {
        $this->authorise($address);

        $address->makeDefault();

        return back()->with('success', $address->label.' is now your default address.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', new CambodianPhone],
            'telegram' => ['nullable', 'string', 'max:64', new TelegramHandle],
            'province' => ['required', 'string', Rule::in(array_keys(Cambodia::provinces()))],
            'district' => ['required', 'string', 'max:120'],
            'commune' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:500'],
        ], [
            'province.in' => 'Please choose a province from the list.',
        ]);

        $data['phone'] = Cambodia::normalisePhone($data['phone']);
        $data['telegram'] = Cambodia::normaliseTelegram($data['telegram'] ?? null);

        return $data;
    }

    protected function authorise(Address $address): void
    {
        if ($address->user_id !== Auth::id()) {
            abort(403);
        }
    }
}
