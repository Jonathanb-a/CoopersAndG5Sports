<?php

namespace Tests\Feature\Admin;

use App\Enums\CouponType;
use App\Enums\UserRole;
use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Coupons\Pages\EditCoupon;
use App\Filament\Resources\Coupons\Pages\ListCoupons;
use App\Models\Coupon;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CouponResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost']);
    }

    private function panelAs(UserRole $role, Store $store): User
    {
        $user = User::factory()->create(['role' => $role]);

        if ($role === UserRole::Staff) {
            $user->stores()->attach($store->id);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);

        return $user;
    }

    private function coupon(Store $store, string $code, array $extra = []): Coupon
    {
        return Coupon::create(['store_id' => $store->id, 'code' => $code, 'type' => CouponType::Percent, 'value' => 10, ...$extra]);
    }

    private function formData(array $extra = []): array
    {
        return ['code' => 'VERANO', 'type' => CouponType::Percent->value, 'value' => 15, 'is_active' => true, ...$extra];
    }

    public function test_a_coupon_created_in_g5_belongs_to_g5_and_the_code_is_saved_in_uppercase()
    {
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => ' mundial10 ', 'value' => 10, 'max_uses' => 50, 'ends_at' => now()->addMonth()]))
            ->call('create')
            ->assertHasNoFormErrors();

        $coupon = Coupon::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->g5->id, $coupon->store_id);
        $this->assertSame('MUNDIAL10', $coupon->code);
        $this->assertSame(CouponType::Percent, $coupon->type);
        $this->assertSame('10.00', $coupon->value);
        $this->assertSame(50, $coupon->max_uses);
        $this->assertSame(0, $coupon->used_count);
        $this->assertTrue($coupon->is_active);
    }

    public function test_a_fixed_amount_coupon_can_be_created()
    {
        $this->panelAs(UserRole::Owner, $this->coppers);

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['type' => CouponType::Fixed->value, 'value' => 250.5]))
            ->call('create')
            ->assertHasNoFormErrors();

        $coupon = Coupon::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(CouponType::Fixed, $coupon->type);
        $this->assertSame('250.50', $coupon->value);
    }

    public function test_the_code_is_unique_per_store_even_in_a_different_case()
    {
        $this->coupon($this->coppers, 'VERANO');
        $this->panelAs(UserRole::Staff, $this->coppers);

        foreach (['VERANO', 'verano', ' Verano '] as $code) {
            Livewire::test(CreateCoupon::class)
                ->fillForm($this->formData(['code' => $code]))
                ->call('create')
                ->assertHasFormErrors(['code' => 'unique']);
        }

        $this->assertSame(1, Coupon::withoutGlobalScopes()->count());
    }

    public function test_the_same_code_can_exist_in_the_other_store()
    {
        $this->coupon($this->coppers, 'VERANO');
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => 'verano']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Coupon::withoutGlobalScopes()->where('code', 'VERANO')->count());
    }

    public function test_the_code_only_accepts_letters_numbers_and_dashes()
    {
        $this->panelAs(UserRole::Owner, $this->coppers);

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => 'CON ESPACIO']))
            ->call('create')
            ->assertHasFormErrors(['code' => 'regex']);

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => 'VERANO-2026_B']))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_the_value_and_dates_are_validated()
    {
        $this->panelAs(UserRole::Owner, $this->coppers);

        // Un porcentaje no puede pasar de 100; un monto fijo sí puede ser mayor.
        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['value' => 101]))
            ->call('create')
            ->assertHasFormErrors(['value']);

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => 'GRANDE', 'type' => CouponType::Fixed->value, 'value' => 500]))
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => 'CERO', 'value' => 0]))
            ->call('create')
            ->assertHasFormErrors(['value']);

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => 'REVES', 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDay()]))
            ->call('create')
            ->assertHasFormErrors(['ends_at']);

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => 'USOS', 'max_uses' => 0]))
            ->call('create')
            ->assertHasFormErrors(['max_uses']);
    }

    public function test_used_count_is_read_only_in_the_panel()
    {
        $coupon = $this->coupon($this->coppers, 'VERANO', ['used_count' => 4]);
        $this->panelAs(UserRole::Owner, $this->coppers);

        Livewire::test(EditCoupon::class, ['record' => $coupon->getRouteKey()])
            ->assertFormSet(['used_count' => 4])
            ->assertFormFieldDisabled('used_count')
            ->fillForm(['used_count' => 99, 'value' => 20])
            ->call('save')
            ->assertHasNoFormErrors();

        $coupon->refresh();

        $this->assertSame(4, $coupon->used_count);
        $this->assertSame('20.00', $coupon->value);
    }

    public function test_the_list_only_shows_the_current_stores_coupons()
    {
        $mine = $this->coupon($this->g5, 'MIO');
        $theirs = $this->coupon($this->coppers, 'AJENO');
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(ListCoupons::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_coupon_can_be_turned_off_and_deleted()
    {
        $coupon = $this->coupon($this->g5, 'MIO');
        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(EditCoupon::class, ['record' => $coupon->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($coupon->fresh()->is_active);

        Livewire::test(ListCoupons::class)->callTableAction('delete', $coupon);

        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    public function test_the_coupons_page_is_scoped_to_the_tenant()
    {
        $this->panelAs(UserRole::Staff, $this->g5);

        $this->get('/admin/g5/coupons')->assertOk()->assertSee('Cupones');
        $this->get('/admin/coppers/coupons')->assertNotFound();
    }

    public function test_a_staff_member_can_not_edit_a_coupon_of_the_other_store()
    {
        $theirs = $this->coupon($this->coppers, 'AJENO');
        $this->panelAs(UserRole::Staff, $this->g5);

        $this->get("/admin/g5/coupons/{$theirs->id}/edit")->assertNotFound();
    }
}
