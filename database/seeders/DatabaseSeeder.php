<?php

namespace Database\Seeders;

use App\Enums\SizeType;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Safe to run more than once.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            throw new RuntimeException('Define ADMIN_EMAIL y ADMIN_PASSWORD en .env antes de correr el seeder.');
        }

        $stores = [
            'coopers' => Store::updateOrCreate(
                ['slug' => 'coopers'],
                ['name' => 'Coopers', 'domain' => 'coopers.localhost', 'theme' => 'coopers', 'is_active' => true],
            ),
            'g5' => Store::updateOrCreate(
                ['slug' => 'g5'],
                ['name' => 'G5 Esports', 'domain' => 'g5.localhost', 'theme' => 'g5', 'is_active' => true],
            ),
        ];

        User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Administrador', 'password' => $password, 'role' => UserRole::Owner],
        );

        // Staff de prueba: usan la misma contraseña que el owner (ADMIN_PASSWORD).
        $staff = [
            'coopers' => ['name' => 'Staff Coopers', 'email' => 'staff@coopers.localhost'],
            'g5' => ['name' => 'Staff G5', 'email' => 'staff@g5.localhost'],
        ];

        foreach ($staff as $slug => $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                ['name' => $data['name'], 'password' => $password, 'role' => UserRole::Staff],
            );
            $user->stores()->syncWithoutDetaching([$stores[$slug]->id]);
        }

        $brands = [];
        foreach (['Nike', 'Adidas', 'On'] as $name) {
            $brands[$name] = Brand::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }

        $catalog = [
            'coopers' => [
                'categories' => [
                    ['name' => 'Camisas', 'slug' => 'camisas', 'size_type' => SizeType::Clothing],
                    ['name' => 'Pantalones', 'slug' => 'pantalones', 'size_type' => SizeType::Clothing],
                ],
                'products' => [
                    [
                        'category' => 'camisas', 'brand' => null, 'name' => 'Camisa Oxford', 'slug' => 'camisa-oxford',
                        'color' => 'Azul', 'description' => 'Camisa Oxford de algodón, corte regular.',
                        'price' => 899.00, 'cost' => 450.00,
                        'variants' => ['S' => 8, 'M' => 3, 'L' => 0],
                    ],
                    [
                        'category' => 'pantalones', 'brand' => null, 'name' => 'Pantalón Chino', 'slug' => 'pantalon-chino',
                        'color' => 'Beige', 'description' => 'Pantalón chino de gabardina, corte slim.',
                        'price' => 1099.00, 'cost' => 560.00,
                        'variants' => ['30' => 5, '32' => 0, '34' => 2],
                    ],
                ],
            ],
            'g5' => [
                'categories' => [
                    ['name' => 'Calzado', 'slug' => 'calzado', 'size_type' => SizeType::Footwear],
                    ['name' => 'Jerseys', 'slug' => 'jerseys', 'size_type' => SizeType::Clothing],
                ],
                'products' => [
                    [
                        'category' => 'calzado', 'brand' => 'Nike', 'name' => 'Nike Mercurial Vapor', 'slug' => 'nike-mercurial-vapor',
                        'color' => 'Negro', 'description' => 'Tachones de fútbol para césped natural.',
                        'price' => 2499.00, 'cost' => 1500.00,
                        'variants' => ['26' => 4, '27' => 0, '28' => 7],
                    ],
                    [
                        'category' => 'jerseys', 'brand' => 'Adidas', 'name' => 'Jersey Adidas Entrenamiento', 'slug' => 'jersey-adidas-entrenamiento',
                        'color' => 'Rojo', 'description' => 'Jersey ligero de entrenamiento.',
                        'price' => 799.00, 'cost' => 400.00,
                        'variants' => ['S' => 6, 'M' => 0, 'L' => 3],
                    ],
                ],
            ],
        ];

        foreach ($catalog as $slug => $data) {
            $store = $stores[$slug];

            $categories = [];
            foreach ($data['categories'] as $category) {
                $categories[$category['slug']] = $store->categories()->firstOrCreate(
                    ['slug' => $category['slug']],
                    ['name' => $category['name'], 'size_type' => $category['size_type']],
                );
            }

            foreach ($data['products'] as $item) {
                $product = Product::withTrashed()->firstOrCreate(
                    ['store_id' => $store->id, 'slug' => $item['slug']],
                    [
                        'category_id' => $categories[$item['category']]->id,
                        'brand_id' => $item['brand'] ? $brands[$item['brand']]->id : null,
                        'name' => $item['name'],
                        'color' => $item['color'],
                        'description' => $item['description'],
                        'price' => $item['price'],
                        'cost' => $item['cost'],
                    ],
                );

                foreach ($item['variants'] as $size => $stock) {
                    $product->variants()->withTrashed()->firstOrCreate(
                        ['size' => (string) $size],
                        ['sku' => Str::upper($item['slug']).'-'.$size, 'stock' => $stock],
                    );
                }
            }
        }
    }
}
