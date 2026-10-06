<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Support\AddressRules;
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
        return AddressRules::clean($request->validate(AddressRules::rules(), AddressRules::messages(), AddressRules::attributes()));
    }
}
