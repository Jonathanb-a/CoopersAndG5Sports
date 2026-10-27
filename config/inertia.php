<?php

/*
|--------------------------------------------------------------------------
| Inertia
|--------------------------------------------------------------------------
|
| Solo lo que cambia frente a la configuración del paquete (el resto se toma de ahí). Las páginas
| viven en resources/js/pages, en minúsculas como en app.tsx, ssr.jsx y app.blade.php; el paquete
| busca js/Pages y en Linux, que distingue mayúsculas, no las encuentra. «testing» va completo
| porque Laravel combina la configuración del paquete solo en el primer nivel.
|
*/

return [

    'page_paths' => [
        resource_path('js/pages'),
    ],

    'testing' => [
        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],

];
