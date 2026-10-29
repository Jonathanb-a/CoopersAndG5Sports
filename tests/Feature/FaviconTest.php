<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cada tienda muestra su propio logo en la pestaña del navegador. */
class FaviconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
    }

    public function test_each_store_links_its_own_icon_and_not_the_others()
    {
        foreach (['coppers' => 'g5', 'g5' => 'coppers'] as $theme => $other) {
            $html = $this->get("http://{$theme}.localhost/")->assertOk()->getContent();

            $this->assertStringContainsString("/brand/{$theme}/favicon-32.png", $html);
            $this->assertStringContainsString("/brand/{$theme}/favicon-192.png", $html);
            $this->assertMatchesRegularExpression('/<link rel="apple-touch-icon" href="[^"]*\/brand\/'.$theme.'\/favicon-192\.png"/', $html);
            $this->assertStringNotContainsString("/brand/{$other}/favicon", $html);
        }
    }

    public function test_the_icon_is_also_on_the_themed_error_page_and_inner_pages()
    {
        $this->get('http://g5.localhost/no-existe')->assertNotFound()->assertSee('/brand/g5/favicon-32.png', false);
        $this->get('http://coppers.localhost/login')->assertOk()->assertSee('/brand/coppers/favicon-32.png', false);
    }

    public function test_the_icon_files_exist_and_are_small_square_pngs()
    {
        foreach (['coppers', 'g5'] as $theme) {
            foreach ([32, 192] as $size) {
                $path = public_path("brand/{$theme}/favicon-{$size}.png");

                $this->assertFileExists($path);
                $info = getimagesize($path);
                $this->assertSame('image/png', $info['mime']);
                $this->assertSame([$size, $size], [$info[0], $info[1]]);
            }
        }
    }
}
