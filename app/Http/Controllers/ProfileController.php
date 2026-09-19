<?php

namespace App\Http\Controllers;

use App\Rules\CambodianPhone;
use App\Rules\TelegramHandle;
use App\Support\Cambodia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $orders = $user->orders()
            ->orderBy('created_at', 'desc')
            ->get();

        return view('profile.index', [
            'user' => $user,
            'orders' => $orders,
            'provinces' => Cambodia::provinceOptions(),
        ]);
    }

    /**
     * Name and email — the identity half of the account.
     */
    public function updateAccount(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->update($validated);

        return back()->with('success', 'Account details updated.');
    }

    /**
     * The saved delivery address, so checkout can prefill it.
     */
    public function updateDelivery(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:30', new CambodianPhone],
            'telegram' => ['nullable', 'string', 'max:64', new TelegramHandle],
            'province' => ['nullable', 'string', Rule::in(array_keys(Cambodia::provinces()))],
            'district' => ['nullable', 'string', 'max:120'],
            'commune' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
        ], [
            'province.in' => 'Please choose a province from the list.',
        ]);

        Auth::user()->update([
            // Stored in one canonical shape, the same as on an order.
            'phone' => Cambodia::normalisePhone($validated['phone'] ?? null),
            'telegram' => Cambodia::normaliseTelegram($validated['telegram'] ?? null),
            'province' => $validated['province'] ?? null,
            'district' => $validated['district'] ?? null,
            'commune' => $validated['commune'] ?? null,
            'address' => $validated['address'] ?? null,
        ]);

        return back()->with('success', 'Delivery details saved. Checkout will fill these in for you.');
    }

    public function updatePicture(Request $request)
    {
        $request->validate([
            'profile_picture' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = Auth::user();

        // Delete old picture if it exists
        if ($user->profile_picture) {
            Storage::disk('public')->delete($user->profile_picture);
        }

        $path = $request->file('profile_picture')->store('profile_pictures', 'public');

        $user->update([
            'profile_picture' => $path,
        ]);

        return back()->with('success', 'Profile picture updated successfully.');
    }

    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $rules = ['password' => ['required', 'string', 'min:8', 'confirmed']];

        // Someone who only ever signed in with Google has no current password
        // to confirm — requiring one would lock them out of setting a first.
        if (! $user->usesGoogleOnly()) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $request->validate($rules);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return back()->with('success', 'Password updated successfully.');
    }
}