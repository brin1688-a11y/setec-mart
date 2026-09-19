<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Cambodia;
use Illuminate\Http\Request;

/**
 * What delivery costs, and how long it takes.
 *
 * These used to live in config/cambodia.php, so changing a price meant
 * editing a file and deploying. They are saved to settings now; the config
 * file stays as the fallback, which is what a fresh install runs on.
 */
class DeliveryController extends Controller
{
    public function edit()
    {
        return view('admin.delivery.edit', [
            'zones' => Cambodia::zones(),
            'defaults' => config('cambodia.delivery.zones', []),
            // preserveKeys, or the province names are replaced by 0, 1, 2…
            'provincesByZone' => collect(Cambodia::provinces())
                ->groupBy('zone', preserveKeys: true)
                ->map(fn ($group) => $group->keys()->sort()->values()->all()),
            'customised' => Setting::get('delivery.zones') !== null,
        ]);
    }

    public function update(Request $request)
    {
        $keys = array_keys(config('cambodia.delivery.zones', []));

        $data = $request->validate([
            'zones' => ['required', 'array'],
            'zones.*.fee' => ['required', 'numeric', 'min:0', 'max:999'],
            // Empty means "never free here", which is a real choice — the
            // provinces are priced that way.
            'zones.*.free_over' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'zones.*.eta' => ['required', 'string', 'max:60'],
            'zones.*.eta_days' => ['required', 'integer', 'min:0', 'max:60'],
        ], [
            'zones.*.fee.required' => 'Every zone needs a delivery price.',
            'zones.*.eta.required' => 'Tell the customer how long each zone takes.',
        ]);

        $saved = [];

        foreach ($keys as $key) {
            $zone = $data['zones'][$key] ?? null;

            if (! $zone) {
                continue;
            }

            $free = $zone['free_over'] ?? null;

            $saved[$key] = [
                'fee' => round((float) $zone['fee'], 2),
                // Stored as null rather than 0: "free over $0" would make
                // everything free, which is not what an empty box means.
                'free_over' => ($free === null || $free === '') ? null : round((float) $free, 2),
                'eta' => trim($zone['eta']),
                'eta_days' => (int) $zone['eta_days'],
            ];
        }

        Setting::put('delivery.zones', $saved);

        return back()->with('success', 'Delivery charges updated. They apply to new orders from now on.');
    }

    /**
     * Put every zone back to what the application ships with.
     */
    public function reset()
    {
        Setting::query()->where('key', 'delivery.zones')->delete();
        Setting::flushCache();

        return back()->with('success', 'Delivery charges are back to the defaults.');
    }
}
