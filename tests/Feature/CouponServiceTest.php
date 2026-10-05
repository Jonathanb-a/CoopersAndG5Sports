<?php

namespace Tests\Feature;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Store;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost']);
        $this->service = new CouponService;
    }

    private function coupon(Store $store, string $code, array $extra = []): Coupon
    {
        return Coupon::create([
            'store_id' => $store->id,
            'code' => $code,
            'type' => CouponType::Percent,
            'value' => 10,
            ...$extra,
        ]);
    }

    public function test_a_coppers_coupon_can_not_be_used_in_g5()
    {
        $this->coupon($this->coppers, 'SOLOCOPPERS');

        $inCoppers = $this->service->validate($this->coppers, 'SOLOCOPPERS', '1000.00');
        $inG5 = $this->service->validate($this->g5, 'SOLOCOPPERS', '1000.00');

        $this->assertTrue($inCoppers->valid);
        $this->assertFalse($inG5->valid);
        $this->assertSame('No aplica en esta tienda.', $inG5->reason);
        $this->assertSame('0.00', $inG5->discount());
        $this->assertSame('1000.00', $inG5->total());
    }

    public function test_the_same_code_in_both_stores_is_two_different_coupons()
    {
        $this->coupon($this->coppers, 'VERANO', ['value' => 10]);
        $this->coupon($this->g5, 'VERANO', ['value' => 20]);

        $this->assertSame('100.00', $this->service->validate($this->coppers, 'VERANO', '1000.00')->discount());
        $this->assertSame('200.00', $this->service->validate($this->g5, 'VERANO', '1000.00')->discount());
    }

    public function test_an_unknown_code_says_it_does_not_exist()
    {
        $result = $this->service->validate($this->coppers, 'NOEXISTE', '1000.00');

        $this->assertFalse($result->valid);
        $this->assertSame('El código no existe.', $result->reason);
        $this->assertFalse($this->service->validate($this->coppers, '   ', '1000.00')->valid);
        $this->assertFalse($this->service->validate($this->coppers, null, '1000.00')->valid);
    }

    public function test_an_expired_coupon_is_rejected()
    {
        $this->coupon($this->coppers, 'VENCIDO', ['ends_at' => now()->subMinute()]);

        $result = $this->service->validate($this->coppers, 'VENCIDO', '1000.00');

        $this->assertFalse($result->valid);
        $this->assertSame('Venció.', $result->reason);
        $this->assertSame('0.00', $result->discount());
    }

    public function test_an_inactive_coupon_is_rejected()
    {
        $this->coupon($this->coppers, 'APAGADO', ['is_active' => false]);

        $result = $this->service->validate($this->coppers, 'APAGADO', '1000.00');

        $this->assertFalse($result->valid);
        $this->assertSame('El cupón no está activo.', $result->reason);
    }

    public function test_a_coupon_without_uses_left_is_rejected()
    {
        $this->coupon($this->coppers, 'AGOTADO', ['max_uses' => 5, 'used_count' => 5]);
        $this->coupon($this->coppers, 'PASADO', ['max_uses' => 5, 'used_count' => 9]);

        $this->assertSame('Ya no tiene usos.', $this->service->validate($this->coppers, 'AGOTADO', '1000.00')->reason);
        $this->assertFalse($this->service->validate($this->coppers, 'PASADO', '1000.00')->valid);
    }

    public function test_a_coupon_that_has_not_started_yet_is_rejected()
    {
        $this->coupon($this->coppers, 'PRONTO', ['starts_at' => now()->addDay()]);

        $result = $this->service->validate($this->coppers, 'PRONTO', '1000.00');

        $this->assertFalse($result->valid);
        $this->assertSame('Todavía no está vigente.', $result->reason);
    }

    public function test_coupons_inside_their_dates_and_with_uses_left_are_valid()
    {
        $this->coupon($this->coppers, 'VIGENTE', ['starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'max_uses' => 5, 'used_count' => 4]);
        $this->coupon($this->coppers, 'SINLIMITE', ['max_uses' => null, 'used_count' => 9999]);

        $this->assertTrue($this->service->validate($this->coppers, 'VIGENTE', '500.00')->valid);
        $this->assertTrue($this->service->validate($this->coppers, 'SINLIMITE', '500.00')->valid);
    }

    public function test_the_code_is_case_and_space_insensitive()
    {
        $this->coupon($this->coppers, ' verano10 ');

        $this->assertSame('VERANO10', Coupon::withoutGlobalScopes()->first()->code);
        $this->assertTrue($this->service->validate($this->coppers, '  Verano10', '100.00')->valid);
    }

    public function test_a_percent_coupon_discounts_that_percentage_of_the_subtotal()
    {
        $this->coupon($this->coppers, 'DIEZ', ['type' => CouponType::Percent, 'value' => 10]);

        $result = $this->service->validate($this->coppers, 'DIEZ', '1700.00');

        $this->assertTrue($result->valid);
        $this->assertSame('1700.00', $result->subtotal());
        $this->assertSame('170.00', $result->discount());
        $this->assertSame('1530.00', $result->total());
    }

    public function test_a_percent_discount_is_rounded_to_the_cent()
    {
        $this->coupon($this->coppers, 'QUINCE', ['type' => CouponType::Percent, 'value' => 15]);
        $this->coupon($this->coppers, 'TERCIO', ['type' => CouponType::Percent, 'value' => 33.33]);

        // 15 % de 99.99 = 14.9985 → 15.00
        $this->assertSame('15.00', $this->service->validate($this->coppers, 'QUINCE', '99.99')->discount());
        $this->assertSame('84.99', $this->service->validate($this->coppers, 'QUINCE', '99.99')->total());
        // 33.33 % de 249.90 = 83.2917 → 83.29
        $this->assertSame('83.29', $this->service->validate($this->coppers, 'TERCIO', '249.90')->discount());
    }

    public function test_a_fixed_coupon_discounts_that_amount()
    {
        $this->coupon($this->coppers, 'CIEN', ['type' => CouponType::Fixed, 'value' => 100]);

        $result = $this->service->validate($this->coppers, 'CIEN', '650.50');

        $this->assertSame('100.00', $result->discount());
        $this->assertSame('550.50', $result->total());
    }

    public function test_the_discount_never_goes_above_the_subtotal_so_the_total_is_never_negative()
    {
        $this->coupon($this->coppers, 'GRANDE', ['type' => CouponType::Fixed, 'value' => 500]);
        $this->coupon($this->coppers, 'TODO', ['type' => CouponType::Percent, 'value' => 100]);
        // Un porcentaje de más de 100 no debería existir (el panel no lo deja), pero si se cuela tampoco pasa del subtotal.
        $this->coupon($this->coppers, 'RARO', ['type' => CouponType::Percent, 'value' => 250]);

        foreach (['GRANDE', 'TODO', 'RARO'] as $code) {
            $result = $this->service->validate($this->coppers, $code, '300.00');

            $this->assertTrue($result->valid, $code);
            $this->assertSame('300.00', $result->discount(), $code);
            $this->assertSame('0.00', $result->total(), $code);
        }

        $this->assertSame('300.00', $this->service->discount(Coupon::withoutGlobalScopes()->where('code', 'GRANDE')->first(), '300.00'));
    }

    public function test_the_subtotal_can_be_a_string_number_or_float()
    {
        $this->coupon($this->coppers, 'DIEZ', ['type' => CouponType::Percent, 'value' => 10]);

        foreach (['200.00', 200, 200.0] as $subtotal) {
            $this->assertSame('20.00', $this->service->validate($this->coppers, 'DIEZ', $subtotal)->discount());
        }
    }

    public function test_an_empty_cart_does_not_take_a_coupon()
    {
        $this->coupon($this->coppers, 'DIEZ');

        $result = $this->service->validate($this->coppers, 'DIEZ', '0.00');

        $this->assertFalse($result->valid);
        $this->assertSame('Agrega productos a tu carrito para usar un cupón.', $result->reason);
    }

    public function test_validating_and_applying_never_change_used_count()
    {
        $coupon = $this->coupon($this->coppers, 'DIEZ', ['max_uses' => 3, 'used_count' => 1]);

        $this->service->validate($this->coppers, 'DIEZ', '100.00');
        $this->service->apply($this->coppers, 'DIEZ', '100.00');
        $this->service->applied($this->coppers, '100.00');

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_an_applied_coupon_is_kept_in_the_session()
    {
        $this->coupon($this->coppers, 'DIEZ');

        $this->assertNull($this->service->applied($this->coppers, '1000.00'));

        $result = $this->service->apply($this->coppers, 'diez', '1000.00');

        $this->assertTrue($result->valid);
        $this->assertSame(['store_id' => $this->coppers->id, 'code' => 'DIEZ'], session(CouponService::SESSION_KEY));

        $applied = $this->service->applied($this->coppers, '1000.00');

        $this->assertTrue($applied->valid);
        $this->assertSame('100.00', $applied->discount());
        $this->assertSame('DIEZ', $applied->coupon->code);

        $this->service->remove();

        $this->assertNull(session(CouponService::SESSION_KEY));
        $this->assertNull($this->service->applied($this->coppers, '1000.00'));
    }

    public function test_the_applied_coupon_is_recalculated_when_the_subtotal_changes()
    {
        $this->coupon($this->coppers, 'DIEZ');
        $this->service->apply($this->coppers, 'DIEZ', '1000.00');

        $this->assertSame('100.00', $this->service->applied($this->coppers, '1000.00')->discount());
        $this->assertSame('25.00', $this->service->applied($this->coppers, '250.00')->discount());
    }

    public function test_the_applied_coupon_is_revalidated_every_time_and_dropped_when_it_stops_being_valid()
    {
        $cases = [
            'venció' => [['ends_at' => now()->addHour()], ['ends_at' => now()->subMinute()], 'Venció.'],
            'se apagó' => [[], ['is_active' => false], 'El cupón no está activo.'],
            'se agotó' => [['max_uses' => 2, 'used_count' => 1], ['used_count' => 2], 'Ya no tiene usos.'],
        ];

        foreach ($cases as $name => [$before, $change, $reason]) {
            $coupon = $this->coupon($this->coppers, 'CODIGO'.md5($name), $before);
            $this->assertTrue($this->service->apply($this->coppers, $coupon->code, '1000.00')->valid, $name);

            $coupon->update($change);
            $result = $this->service->applied($this->coppers, '1000.00');

            $this->assertFalse($result->valid, $name);
            $this->assertSame($reason, $result->reason, $name);
            $this->assertSame('0.00', $result->discount(), $name);
            $this->assertNull(session(CouponService::SESSION_KEY), $name);
            $this->assertNull($this->service->applied($this->coppers, '1000.00'), $name);
        }
    }

    public function test_a_deleted_coupon_is_dropped_from_the_session()
    {
        $coupon = $this->coupon($this->coppers, 'DIEZ');
        $this->service->apply($this->coppers, 'DIEZ', '1000.00');

        $coupon->delete();

        $this->assertSame('El código no existe.', $this->service->applied($this->coppers, '1000.00')->reason);
        $this->assertNull(session(CouponService::SESSION_KEY));
    }

    public function test_a_coupon_saved_for_one_store_is_not_applied_in_the_other()
    {
        $this->coupon($this->coppers, 'DIEZ');
        $this->service->apply($this->coppers, 'DIEZ', '1000.00');

        $this->assertNull($this->service->applied($this->g5, '1000.00'));
        $this->assertNull(session(CouponService::SESSION_KEY));
    }

    public function test_a_rejected_code_keeps_the_coupon_that_was_already_applied()
    {
        $this->coupon($this->coppers, 'DIEZ');
        $this->coupon($this->coppers, 'VENCIDO', ['ends_at' => now()->subDay()]);
        $this->service->apply($this->coppers, 'DIEZ', '1000.00');

        $result = $this->service->apply($this->coppers, 'VENCIDO', '1000.00');

        $this->assertFalse($result->valid);
        $this->assertSame('DIEZ', session(CouponService::SESSION_KEY)['code']);
    }

    public function test_applying_a_new_valid_code_replaces_the_previous_one()
    {
        $this->coupon($this->coppers, 'DIEZ');
        $this->coupon($this->coppers, 'VEINTE', ['value' => 20]);

        $this->service->apply($this->coppers, 'DIEZ', '1000.00');
        $this->service->apply($this->coppers, 'VEINTE', '1000.00');

        $this->assertSame('200.00', $this->service->applied($this->coppers, '1000.00')->discount());
    }

    public function test_a_g5_code_can_not_be_applied_from_coppers_even_if_typed_in_lowercase()
    {
        $this->coupon($this->g5, 'MUNDIAL');

        $result = $this->service->apply($this->coppers, 'mundial', '1000.00');

        $this->assertFalse($result->valid);
        $this->assertSame('No aplica en esta tienda.', $result->reason);
        $this->assertNull(session(CouponService::SESSION_KEY));
    }
}
