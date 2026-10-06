<?php

namespace App\Support;

/** Reglas de validación de una dirección (libreta de «Mi cuenta» y checkout). */
class AddressRules
{
    public const FIELDS = ['street', 'neighborhood', 'city', 'state', 'zip', 'notes'];

    /** @param  string  $prefix  por ejemplo "address." cuando la dirección va anidada */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'street' => ['required', 'string', 'max:255'],
            $prefix.'neighborhood' => ['required', 'string', 'max:255'],
            $prefix.'city' => ['required', 'string', 'max:255'],
            $prefix.'state' => ['required', 'string', 'max:255'],
            $prefix.'zip' => ['required', 'regex:/^\d{5}$/'],
            $prefix.'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public static function messages(string $prefix = ''): array
    {
        return [$prefix.'zip.regex' => 'El código postal debe tener 5 dígitos.'];
    }

    public static function attributes(string $prefix = ''): array
    {
        return [
            $prefix.'street' => 'calle y número',
            $prefix.'neighborhood' => 'colonia',
            $prefix.'city' => 'ciudad',
            $prefix.'state' => 'estado',
            $prefix.'zip' => 'código postal',
            $prefix.'notes' => 'referencias',
        ];
    }

    /** Deja solo los campos de la dirección, con las referencias vacías como null. */
    public static function clean(array $data): array
    {
        $address = array_intersect_key($data, array_flip(self::FIELDS));
        $address['notes'] = filled($address['notes'] ?? null) ? trim($address['notes']) : null;

        return $address;
    }
}
