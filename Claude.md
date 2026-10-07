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

## Decisiones de la fase 4
- Ver la tienda no requiere cuenta. Comprar sí: el checkout exige iniciar sesión.
- Un cliente es una sola cuenta para ambas tiendas (misma tabla customers),
  pero la sesión es por dominio: inicia sesión en cada tienda por separado.
- Guard "customer" con el modelo Customer para la tienda. El panel /admin
  sigue con el guard de users. No mezclar los dos.
- Registro con correo y contraseña, o con Google. Una cuenta de Google crea
  un Customer con google_id y sin contraseña.
- El carrito vive en la sesión (no hay tabla), con variante, cantidad y precio
  leído siempre de la base de datos. Nunca confiar en precios del navegador.
- Pago por transferencia: el pedido se aparta (status apartado) y vence a las
  48 horas (config shop.reserve_hours). El cliente paga y manda el comprobante
  por WhatsApp con su número de pedido.
- Apartar descuenta stock con StockService (tipo venta_web) dentro de una
  transacción con lockForUpdate.
- Sobre pedido: si la cantidad pedida supera el stock y el producto tiene
  allow_backorder, la parte sin stock va en una línea aparte con
  is_backorder = true y no descuenta stock.
- order_items guarda nombre, talla y precio del momento de la compra.
- Número de pedido legible por tienda (CP-00042, G5-00042), único. Prefijos
  en config/shop.php.
- Métodos de entrega: ver "Checkout: recoger y entrega local (fase 5.17c)",
  que sustituye la regla anterior de "si el costo está vacío no se ofrece".


## Decisiones de pedidos (fase 5)
- Transiciones válidas: apartado→pagado|cancelado; pagado→enviado|cancelado;
  enviado→entregado. Entregado y cancelado son finales. Solo
  OrderStatusService cambia el estado y registra order_status_histories.
- Cancelar un apartado: cliente, staff u owner. Cancelar un pedido pagado:
  solo owner; el cliente solo puede solicitarlo.
- Cancelar regresa el stock con StockService (tipo cancelacion) salvo que se
  indique lo contrario. Las líneas sobre pedido no tocan stock. El cupón
  recupera su uso.
- "Reactivar" solo aplica a pedidos cancelados por el sistema sin pago.
- Reembolsos manuales por transferencia; el sistema solo registra refunded_at.
- "Enviado" se muestra como "Listo para recoger" si el método es recoger y
  "En camino" en los demás.
- Los avisos al cliente son botones de WhatsApp con mensaje prellenado; no hay
  envío automático.
- Máximo de pedidos sin pagar por cliente: shop.max_open_reservations = 3.
- Cambios y devoluciones después de entregar se atienden por WhatsApp y se
  registran con un ajuste de stock manual (política pendiente con el cliente).

## Entrega a domicilio local (fase 5)
- Solo aplica al método local. La fecha y el horario los acuerda el admin
  con el cliente por WhatsApp; el cliente no elige fecha en el checkout.
- Programar entrega: pedido pagado o enviado, fecha de hoy en adelante.
- Registrar intento: solo pedidos enviados con método local. Resultados:
  entregado (pasa a entregado), no estaba, rechazó, dirección incorrecta
  u otro. Un intento fallido no cambia el estado: el pedido sigue enviado,
  se limpia la fecha y queda "por reprogramar".
- Con 2 intentos fallidos se ofrece cambiar el método a recoger en tienda.

## Venta de mostrador (fase 5)
- Canal mostrador: venta presencial. Nace como entregado y pagado, sin cliente
  obligatorio, método de entrega recoger, sin envío ni sobre pedido.
- Descuenta stock con StockService (tipo venta_mostrador), con lockForUpdate.
- Método de pago: efectivo, transferencia o tarjeta (orders.payment_method).
- Excepción a "entregado es final": el owner puede anular una venta de
  mostrador (error de captura), que regresa el stock. Solo canal mostrador.

## Checkout: recoger y entrega local (fase 5.17c)
- El checkout ofrece SOLO dos métodos, siempre visibles: "Recoger en tienda"
  y "Entrega local (Zihuatanejo)". No dependen de que haya un costo capturado.
- El envío nacional no se ofrece ni se acepta en pedidos nuevos (sin
  "Próximamente"): el servidor lo rechaza con error de validación. Se
  conservan stores.national_shipping_cost, DeliveryMethod::National y los
  pedidos históricos.
- Recoger en tienda: la tienda captura (solo owner) pickup_address,
  pickup_hours, pickup_map_url y pickup_notes. Se muestran en el checkout, en
  la página de gracias y en el detalle del pedido del cliente; sin capturar se
  dice "Te confirmamos dirección y horario por WhatsApp".
- Entrega local: stores.local_shipping_cost es opcional; null o 0 = "Gratis"
  (a convenir) y con costo se suma al total. El costo siempre sale de la base.
- El cliente da quién recibe, el punto de entrega (obligatorio) y referencias.
  El punto va en shipping_address (street). NO se le pide día ni hora: el dueño
  no siempre puede cumplirlos, así que la fecha y el horario los acuerda el
  admin por WhatsApp y los captura en delivery_scheduled_date y delivery_window.
- La entrega local no usa la libreta de direcciones del cliente.

## Forma de pago en la tienda en línea (fase 5)
- El checkout pregunta cómo se paga: transferencia (por defecto) o efectivo.
  Se guarda en orders.payment_method (PaymentMethod). Tarjeta no se acepta en
  la tienda en línea. Los pedidos sin dato se tratan como transferencia.
- Transferencia: igual que antes. Vence en shop.reserve_hours y el cliente manda
  su comprobante por WhatsApp con su número de pedido.
- Efectivo: se paga al recibir (entrega local) o al recoger. Si no lo confirman
  vence a las shop.cash_reserve_hours (72 h; transferencia sigue en 48 h) y
  orders:expire-unpaid lo cancela igual que a los demás (libera el stock).
  Cuenta para shop.max_open_reservations.
- Fecha de entrega en efectivo: al asignarla el admin, expires_at pasa al final
  de ese día, sin pasar de shop.cash_reserve_max_days (7) desde la creación.
  "Dar más tiempo" también aplica a efectivo (apartado, pagado o enviado) con
  ese mismo tope.
- Un pedido en efectivo ya confirmado (pagado o enviado) NO se cancela solo.
  Si pasa su fecha (expires_at) sin entregarse, orders:expire-unpaid avisa
  una sola vez al dueño ("Pedido en efectivo sin entregar") por la campana y
  por Telegram (orders.cash_overdue_notified_at); reprogramar o dar más
  tiempo vuelve a armar el aviso.
- Al confirmar un pedido en efectivo, "Marcar pagado" no pide referencia y
  solo lo deja listo para prepararse y enviarse: el dinero se cobra en la
  entrega. El aviso de WhatsApp lo dice así (no agradece un pago).

## Conteo de ventas (dashboard y reportes)
- Transferencia: cuenta como venta desde que está pagado (paid_at). La venta de
  mostrador cuenta al crearse. Los pedidos sin forma de pago (anteriores) se
  tratan como transferencia.
- Efectivo: cuenta como venta solo cuando está ENTREGADO (se cobra al entregar),
  en el día de delivered_at. Mientras esté apartado, confirmado o enviado
  aparece en "Por cobrar" con su monto.
- "Por cobrar" = apartados vigentes + pedidos en efectivo pagados o enviados.
- Los cancelados (y las ventas de mostrador anuladas) no cuentan en nada.
