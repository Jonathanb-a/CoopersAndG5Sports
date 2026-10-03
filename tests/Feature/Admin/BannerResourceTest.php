<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\Tenancy\EditStoreProfile;
use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Filament\Resources\Banners\Pages\ListBanners;
use App\Models\Banner;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BannerResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

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

    private function banner(Store $store, string $title, int $position, bool $active = true): Banner
    {
        Storage::disk('public')->put("banners/{$title}.jpg", 'x');

        return Banner::create([
            'store_id' => $store->id,
            'image_path' => "banners/{$title}.jpg",
            'title' => $title,
            'position' => $position,
            'is_active' => $active,
        ]);
    }

    public function test_a_banner_created_in_g5_belongs_to_g5_and_goes_to_the_end()
    {
        $this->banner($this->g5, 'uno', 1);
        $this->banner($this->g5, 'dos', 2);
        $this->banner($this->coppers, 'ajeno', 9);
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'image_path' => UploadedFile::fake()->image('nuevo.jpg', 1600, 700),
                'title' => 'Mundial',
                'link_url' => 'https://example.com/mundial',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $banner = Banner::withoutGlobalScopes()->where('title', 'Mundial')->firstOrFail();

        $this->assertSame($this->g5->id, $banner->store_id);
        $this->assertSame(3, $banner->position);   // 2 + 1: la posición 9 de Copper's no cuenta
        $this->assertTrue($banner->is_active);
        Storage::disk('public')->assertExists($banner->image_path);
        $this->assertStringStartsWith('banners/', $banner->image_path);
    }

    public function test_the_image_is_required_and_the_link_must_be_a_url_or_a_store_path()
    {
        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(CreateBanner::class)
            ->fillForm(['title' => 'Sin imagen'])
            ->call('create')
            ->assertHasFormErrors(['image_path' => 'required']);

        Livewire::test(CreateBanner::class)
            ->fillForm(['image_path' => UploadedFile::fake()->image('a.jpg'), 'link_url' => 'javascript:alert(1)'])
            ->call('create')
            ->assertHasFormErrors(['link_url' => 'regex']);

        foreach (['/categorias/calzado', 'https://example.com', 'http://example.com/x'] as $link) {
            Livewire::test(CreateBanner::class)
                ->fillForm(['image_path' => UploadedFile::fake()->image('a.jpg'), 'link_url' => $link])
                ->call('create')
                ->assertHasNoFormErrors();
        }
    }

    public function test_the_list_only_shows_the_current_stores_banners_and_can_be_reordered()
    {
        $first = $this->banner($this->g5, 'uno', 1);
        $second = $this->banner($this->g5, 'dos', 2);
        $third = $this->banner($this->g5, 'tres', 3);
        $theirs = $this->banner($this->coppers, 'ajeno', 1);
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(ListBanners::class)
            ->assertCanSeeTableRecords([$first, $second, $third], inOrder: true)
            ->assertCanNotSeeTableRecords([$theirs])
            ->call('reorderTable', [$third->id, $first->id, $second->id])
            ->assertCanSeeTableRecords([$third, $first, $second], inOrder: true);

        $this->assertSame([1, 2, 3], [$third->fresh()->position, $first->fresh()->position, $second->fresh()->position]);
    }

    public function test_a_banner_can_be_turned_off_and_deleting_it_removes_its_image()
    {
        $banner = $this->banner($this->g5, 'uno', 1);
        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(EditBanner::class, ['record' => $banner->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($banner->fresh()->is_active);

        $path = $banner->image_path;
        Storage::disk('public')->assertExists($path);

        $banner->delete();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_banners_page_is_scoped_to_the_tenant()
    {
        $this->panelAs(UserRole::Staff, $this->g5);

        $this->get('/admin/g5/banners')->assertOk()->assertSee('Banners');
        $this->get('/admin/coppers/banners')->assertNotFound();
    }

    public function test_the_owner_can_edit_the_about_text_in_store_profile()
    {
        $this->panelAs(UserRole::Owner, $this->coppers);

        Livewire::test(EditStoreProfile::class)
            ->assertSee('Quiénes somos')
            ->fillForm(['name' => "Copper's", 'about_text' => 'Boutique de moda desde 2010.', 'is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Boutique de moda desde 2010.', $this->coppers->fresh()->about_text);
    }
}
