# Copper's + Sports G5 — Plataforma multitienda

## Qué es
Una sola app Laravel y una sola base de datos para dos tiendas en línea:
- Copper's: ropa y moda, diseño fino.
- Sports G5: calzado y artículos deportivos, diseño futbolero.

## Stack
Laravel, Filament (panel en /admin), Inertia + React, Tailwind, MySQL.

## Reglas
- La tienda se detecta por el dominio de la petición y todo se filtra por store_id.
- Dominios locales: coppers.localhost y g5.localhost (puerto 8000).
- users = personal del panel (role: owner | staff). customers = clientes de la tienda.
  Son tablas y guards separados.
- Un producto pertenece a una sola tienda. Producto = modelo + color; las tallas
  son product_variants y el stock vive en la variante.
- Un pedido pertenece a una sola tienda.
- Dinero en decimal(10,2). Tallas como string. Estados y tipos como enums de PHP
  guardados como string.
- Nombres de tablas, columnas y código en inglés. Textos visibles en español.
- Haz solo lo que pide la tarea. No agregues funciones extra.

## Tablas
stores: id, name, slug, domain, theme, whatsapp_phone, telegram_chat_id, bank_info, is_active
users: id, name, email, password, role
store_user: store_id, user_id
customers: id, name, email, phone, password (nullable), google_id (nullable)
addresses: id, customer_id, street, neighborhood, city, state, zip, notes
brands: id, name, slug
categories: id, store_id, name, slug, size_type (calzado|ropa|kids|unica)
products: id, store_id, category_id, brand_id, name, slug, color, description,
  price, cost, allow_backorder, backorder_days, is_active
product_variants: id, product_id, size, sku, stock
product_images: id, product_id, path, position
stock_movements: id, product_variant_id, type (entrada|venta_web|venta_mostrador|
  ajuste|cancelacion), quantity, user_id, order_id, notes
coupons: id, store_id, code, type (percent|fixed), value, starts_at, ends_at,
  max_uses, used_count, is_active
orders: id, store_id, customer_id, number, channel (web|mostrador),
  status (apartado|pagado|enviado|entregado|cancelado),
  delivery_method (recoger|local|nacional), shipping_address (json), subtotal,
  discount, shipping_cost, total, coupon_id, expires_at, paid_at, delivered_at, notes
order_items: id, order_id, product_variant_id, product_name, size, unit_price,
  quantity, is_backorder
banners: id, store_id, image_path, title, link_url, position, is_active

## Decisiones de la fase 2
- El panel usa la multi-tenencia de Filament con Store como tenant.
  El owner ve todas las tiendas; el staff solo las de store_user.
- products y product_variants usan SoftDeletes.
- El stock nunca se edita directo: todo cambio pasa por StockService,
  que registra un stock_movement en la misma transacción.
- Semáforo por talla: 0 = rojo, 1 = amarillo, 2 o más = verde.
  Por producto (suma de tallas): 0 = rojo, 1 a 2 = amarillo, 3 o más = verde.
- products.cost solo lo ve y edita el owner.

- Todo texto visible va en español de México: etiquetas de Filament,
  enums con HasLabel, mensajes, páginas React y correos.

  ## Decisiones de la fase 3
- Diseño pensado primero para celular.
- Cada tienda tiene su tema en resources/js/Themes/{theme}. Los colores,
  tipografías y radios van en variables CSS, para cambiarlos sin tocar
  los componentes compartidos.
- Copper's: fino y elegante. Mucho espacio en blanco, paleta sobria,
  tipografía con serifa en títulos, animaciones sutiles.
- Sports G5: futbolero y enérgico. Fondo oscuro, un color de acento
  vivo, tipografía gruesa, animaciones más marcadas.
- La tienda solo muestra productos activos y no borrados.
- Etiquetas de producto: "Agotado" (stock 0 sin sobre pedido),
  "Sobre pedido" (stock 0 con allow_backorder) y "Última pieza" (stock 1).
- Animaciones con Motion, ligeras y respetando prefers-reduced-motion.

### Tema Copper's (theme: coppers)
Sensación: boutique, producto original, limpio y caro.
- Fondo #FAF7F2, superficie #FFFFFF, borde #E8E2D9.
- Texto #1C1C1C, texto secundario #6B6560.
- Acento cobre #B8692A (botones y enlaces), hover #9C5620.
- Detalle rosa #F08A8A solo en etiquetas chicas.
- Títulos: Cormorant Garamond. Texto: Inter.
- Esquinas casi rectas (2 a 4 px), sin sombras fuertes, líneas finas.
- Fotos grandes sobre fondo claro y mucho espacio entre secciones.
- Animaciones sutiles: aparecer y deslizar suave, nada que rebote.
- El logo va chico en el encabezado. No usar la letra de brocha en textos.

### Tema Sports G5 (theme: g5)
Sensación: cancha y calle, energía, grafiti.
- Fondo #0E0E10, superficie #1A1A1F, borde #2A2A31.
- Texto #F5F5F5, texto secundario #A3A3AD.
- Acento turquesa #35F0EE con texto negro encima.
- Secundarios: morado #A32CE0 y amarillo #FFD600 (ofertas y etiquetas).
- Títulos: Anton, en mayúsculas. Texto: Barlow.
- Esquinas marcadas, etiquetas inclinadas tipo calcomanía, manchas de
  pintura como fondo de secciones.
- Animaciones más marcadas: entradas rápidas y efecto al pasar el cursor.

### Ambos temas
- Contraste mínimo AA en textos y botones.
- Los logos van en public/brand/{theme}/logo.png.