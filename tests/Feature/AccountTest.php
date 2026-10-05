<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Customer;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Auth\CustomerAuthTestCase;

class AccountTest extends CustomerAuthTestCase
{
    private function address(Customer $customer, array $extra = []): Address
    {
        return $customer->addresses()->create([
            'street' => 'Av. Palmas 12',
            'neighborhood' => 'Centro',
            'city' => 'Zihuatanejo',
            'state' => 'Guerrero',
            'zip' => '40880',
            ...$extra,
        ]);
    }

    private function addressData(array $extra = []): array
    {
        return [
            'street' => 'Calle Hidalgo 5',
            'neighborhood' => 'La Madera',
            'city' => 'Zihuatanejo',
            'state' => 'Guerrero',
            'zip' => '40880',
            'notes' => 'Portón negro',
            ...$extra,
        ];
    }

    public function test_guests_are_sent_to_login_from_the_account_pages()
    {
        $this->get(self::COPPERS.'/mi-cuenta')->assertRedirect(self::COPPERS.'/login');
        $this->patch(self::COPPERS.'/mi-cuenta', ['name' => 'X'])->assertRedirect(self::COPPERS.'/login');
        $this->post(self::COPPERS.'/mi-cuenta/direcciones', $this->addressData())->assertRedirect(self::COPPERS.'/login');
        $this->delete(self::COPPERS.'/mi-cuenta/direcciones/1')->assertRedirect(self::COPPERS.'/login');
    }

    public function test_my_account_shows_my_data_and_only_my_addresses()
    {
        $ana = $this->customer(['name' => 'Ana', 'phone' => '7551234567']);
        $luis = $this->customer(['name' => 'Luis']);
        $mine = $this->address($ana);
        $this->address($luis, ['street' => 'Calle de Luis 9']);

        $this->actingAs($ana, 'customer')->get(self::G5.'/mi-cuenta')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('account')
                ->where('store.slug', 'g5')
                ->where('customer', ['name' => 'Ana', 'email' => $ana->email, 'phone' => '7551234567', 'has_password' => true, 'has_google' => false])
                ->has('addresses', 1)
                ->where('addresses.0.id', $mine->id)
                ->where('addresses.0.street', 'Av. Palmas 12')
                ->missing('addresses.0.customer_id'));
    }

    public function test_a_customer_can_not_see_or_edit_another_customers_addresses()
    {
        $ana = $this->customer();
        $luis = $this->customer();
        $hers = $this->address($ana, ['street' => 'Calle de Ana 1']);

        $this->actingAs($luis, 'customer');

        // No las ve...
        $this->get(self::COPPERS.'/mi-cuenta')->assertInertia(fn (Assert $page) => $page->where('addresses', []));

        // ...ni las edita...
        $this->put(self::COPPERS.'/mi-cuenta/direcciones/'.$hers->id, $this->addressData(['street' => 'Robada']))->assertNotFound();
        $this->assertSame('Calle de Ana 1', $hers->fresh()->street);

        // ...ni las borra.
        $this->delete(self::COPPERS.'/mi-cuenta/direcciones/'.$hers->id)->assertNotFound();
        $this->assertDatabaseHas('addresses', ['id' => $hers->id, 'customer_id' => $ana->id]);
    }

    public function test_a_customer_can_add_edit_and_delete_their_addresses()
    {
        $ana = $this->customer();
        $this->actingAs($ana, 'customer');

        $this->post(self::COPPERS.'/mi-cuenta/direcciones', $this->addressData())
            ->assertRedirect(self::COPPERS.'/mi-cuenta')
            ->assertSessionHas('status', 'Dirección agregada.');

        $address = $ana->addresses()->firstOrFail();
        $this->assertSame('Calle Hidalgo 5', $address->street);
        $this->assertSame('Portón negro', $address->notes);

        $this->put(self::COPPERS.'/mi-cuenta/direcciones/'.$address->id, $this->addressData(['street' => 'Calle Juárez 8', 'notes' => '']))
            ->assertRedirect(self::COPPERS.'/mi-cuenta');

        $this->assertSame('Calle Juárez 8', $address->fresh()->street);
        $this->assertNull($address->fresh()->notes);

        $this->delete(self::COPPERS.'/mi-cuenta/direcciones/'.$address->id)->assertRedirect(self::COPPERS.'/mi-cuenta');
        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
    }

    public function test_an_address_always_belongs_to_the_logged_in_customer()
    {
        $ana = $this->customer();
        $luis = $this->customer();

        $this->actingAs($ana, 'customer')
            ->post(self::COPPERS.'/mi-cuenta/direcciones', $this->addressData(['customer_id' => $luis->id]))
            ->assertRedirect();

        $this->assertSame(1, $ana->addresses()->count());
        $this->assertSame(0, $luis->addresses()->count());

        $address = $ana->addresses()->first();
        $this->put(self::COPPERS.'/mi-cuenta/direcciones/'.$address->id, $this->addressData(['customer_id' => $luis->id]));

        $this->assertSame($ana->id, $address->fresh()->customer_id);
    }

    public function test_address_fields_are_validated()
    {
        $this->actingAs($this->customer(), 'customer');

        $this->post(self::COPPERS.'/mi-cuenta/direcciones', [])
            ->assertSessionHasErrors(['street', 'neighborhood', 'city', 'state', 'zip']);

        foreach (['4088', '408800', 'abcde', '40 80'] as $zip) {
            $this->post(self::COPPERS.'/mi-cuenta/direcciones', $this->addressData(['zip' => $zip]))
                ->assertSessionHasErrors(['zip' => 'El código postal debe tener 5 dígitos.']);
        }

        $this->assertSame(0, Address::count());
    }

    public function test_the_same_account_shows_the_same_addresses_in_both_stores()
    {
        $ana = $this->customer();
        $this->address($ana);

        $this->actingAs($ana, 'customer');

        $this->get(self::COPPERS.'/mi-cuenta')->assertInertia(fn (Assert $page) => $page->has('addresses', 1));
        $this->get(self::G5.'/mi-cuenta')->assertInertia(fn (Assert $page) => $page->has('addresses', 1));
    }

    public function test_a_customer_can_update_name_and_phone_but_not_the_email()
    {
        $ana = $this->customer(['email' => 'ana@example.com']);
        $this->actingAs($ana, 'customer');

        $this->patch(self::COPPERS.'/mi-cuenta', ['name' => 'Ana María', 'phone' => '755 123 4567', 'email' => 'otra@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Datos guardados.');

        $ana->refresh();

        $this->assertSame('Ana María', $ana->name);
        $this->assertSame('755 123 4567', $ana->phone);
        $this->assertSame('ana@example.com', $ana->email);

        $this->patch(self::COPPERS.'/mi-cuenta', ['name' => 'Ana María', 'phone' => ''])->assertSessionHasNoErrors();
        $this->assertNull($ana->fresh()->phone);

        $this->patch(self::COPPERS.'/mi-cuenta', ['name' => '', 'phone' => '123'])->assertSessionHasErrors(['name', 'phone']);
    }

    public function test_the_password_needs_the_current_one_to_change()
    {
        $ana = $this->customer();
        $this->actingAs($ana, 'customer');

        $this->put(self::COPPERS.'/mi-cuenta/contrasena', ['current_password' => 'mal', 'password' => 'nueva-password-1', 'password_confirmation' => 'nueva-password-1'])
            ->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password', $ana->fresh()->password));

        $this->put(self::COPPERS.'/mi-cuenta/contrasena', ['current_password' => 'password', 'password' => 'nueva-password-1', 'password_confirmation' => 'nueva-password-1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Contraseña actualizada.');
        $this->assertTrue(Hash::check('nueva-password-1', $ana->fresh()->password));
    }

    public function test_a_google_account_can_create_its_first_password_without_the_current_one()
    {
        $ana = $this->customer(['password' => null, 'google_id' => 'g-1']);
        $this->actingAs($ana, 'customer');

        $this->get(self::COPPERS.'/mi-cuenta')
            ->assertInertia(fn (Assert $page) => $page->where('customer.has_password', false)->where('customer.has_google', true));

        $this->put(self::COPPERS.'/mi-cuenta/contrasena', ['password' => 'primera-password-1', 'password_confirmation' => 'primera-password-1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('primera-password-1', $ana->fresh()->password));
        $this->assertSame('g-1', $ana->fresh()->google_id);
    }
}
