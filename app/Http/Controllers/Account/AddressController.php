<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Libreta de direcciones del cliente. Solo se tocan las direcciones propias: las de otros dan 404. */
class AddressController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->user('customer')->addresses()->create($this->validated($request));

        return to_route('account.show')->with('status', 'Dirección agregada.');
    }

    public function update(Request $request, int $address): RedirectResponse
    {
        $this->own($request, $address)->update($this->validated($request));

        return to_route('account.show')->with('status', 'Dirección actualizada.');
    }

    public function destroy(Request $request, int $address): RedirectResponse
    {
        $this->own($request, $address)->delete();

        return to_route('account.show')->with('status', 'Dirección borrada.');
    }

    private function own(Request $request, int $id): Address
    {
        return $request->user('customer')->addresses()->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'street' => ['required', 'string', 'max:255'],
            'neighborhood' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255'],
            'zip' => ['required', 'regex:/^\d{5}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'zip.regex' => 'El código postal debe tener 5 dígitos.',
        ], [
            'street' => 'calle y número',
            'neighborhood' => 'colonia',
            'city' => 'ciudad',
            'state' => 'estado',
            'zip' => 'código postal',
            'notes' => 'referencias',
        ]);

        $data['notes'] = filled($data['notes'] ?? null) ? trim($data['notes']) : null;

        return $data;
    }
}
