<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupProduction extends Command
{
    public const MIN_PASSWORD_LENGTH = 12;

    protected $signature = 'app:setup-produccion';

    protected $description = 'Crea el owner (ADMIN_EMAIL, ADMIN_PASSWORD) y las dos tiendas con sus dominios, sin datos de ejemplo. Se puede repetir.';

    public function handle(): int
    {
        $email = trim((string) config('shop.admin_email'));
        $password = (string) config('shop.admin_password');
        // ResolveStore compara con el host en minúsculas.
        $stores = collect(config('shop.stores'))
            ->map(fn (array $data) => [...$data, 'domain' => strtolower(trim((string) $data['domain']))])
            ->all();

        $errors = [];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Define ADMIN_EMAIL con un correo válido.';
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = 'ADMIN_PASSWORD debe tener al menos '.self::MIN_PASSWORD_LENGTH.' caracteres.';
        }
        foreach ($stores as $slug => $data) {
            if (blank($data['domain'])) {
                $errors[] = 'Define '.strtoupper($slug).'_DOMAIN con el dominio de '.$data['name'].'.';
            }
        }
        $domains = array_filter(array_column($stores, 'domain'));
        if (count($domains) !== count(array_unique($domains))) {
            $errors[] = 'Cada tienda necesita un dominio distinto.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->line('No se guardó nada.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $password, $stores) {
            foreach ($stores as $slug => $data) {
                $store = Store::firstOrNew(['slug' => $slug]);
                $isNew = ! $store->exists;

                // Una tienda existente solo actualiza su dominio: nombre, tema, estado y datos que
                // el owner ya capturó en el panel se conservan.
                if ($isNew) {
                    $store->fill(['name' => $data['name'], 'theme' => $data['theme'], 'is_active' => true]);
                }
                $store->domain = $data['domain'];
                $store->save();

                $this->line(($isNew ? 'Tienda creada: ' : 'Tienda actualizada: ').$store->name.' ('.$store->domain.')');
            }

            $owner = User::where('email', $email)->first();
            if ($owner === null) {
                User::create(['name' => 'Administrador', 'email' => $email, 'password' => $password, 'role' => UserRole::Owner]);
                $this->line('Owner creado: '.$email);
            } else {
                // No se cambia la contraseña de una cuenta que ya existe.
                $owner->update(['role' => UserRole::Owner]);
                $this->line('El owner '.$email.' ya existía; su contraseña no se cambió.');
            }
        });

        $this->info('Listo.');

        return self::SUCCESS;
    }
}
