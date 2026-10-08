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

## Programar recogida en tienda (fase 5)
- Pedidos de método "recoger" en estado apartado o pagado (en efectivo, pagado
  es "confirmado") tienen la acción "Programar recogida": fecha obligatoria
  (hoy o futura) y hora o rango opcional. Reusa delivery_scheduled_date y
  delivery_window; el método de entrega decide qué significan.
- En efectivo, al programar la recogida expires_at pasa al final de ese día,
  con el tope de shop.cash_reserve_max_days. En transferencia no cambia.
- Queda en order_status_histories como anotación con quién la hizo.
- El cliente ve "Recoge el [fecha] · [hora]" junto con la dirección y el
  horario de la tienda (gracias y "Mis pedidos"), mientras el pedido siga
  abierto. La acción ofrece un botón de WhatsApp (OrderWhatsAppMessage) con
  número de pedido, fecha y dirección de la tienda.
- Si pasa la fecha y el pedido en efectivo no se entregó, aplica el mismo aviso
  "Pedido en efectivo sin entregar" (campana y Telegram); no se cancela solo.


## Uso del grafo y de las pruebas
- Antes de explorar el código, consulta graphify-out/GRAPH_REPORT.md o usa
  `graphify query` para ubicar archivos. Actualiza el grafo al terminar una
  tarea grande. graphify-out/ no se sube a Git.
- Mientras se prueba: corre solo los tests relacionados (--filter). La suite
  completa y npm run build, al final de cada tarea grande o fase.
- Las migraciones nuevas se crean, pero NO se corren: el dueño del proyecto
  corre php artisan migrate en su MySQL.
- Las tablas de arriba son una guía inicial; la fuente de verdad son las
  migraciones y los modelos.

## Aislamiento entre tiendas
- Todo modelo con store_id usa el trait BelongsToStore (global scope).
  Cualquier consulta que se salte el scope (withoutGlobalScopes, DB::table,
  SQL crudo) debe justificarse y llevar prueba.
- Un cliente o empleado de una tienda nunca ve ni toca datos de la otra,
  aunque cambie un ID en la URL. products.cost solo lo ve el owner.
- Los servicios que escriben (OrderStatusService, OrderCancellationService,
  OrderReservationService, CancellationRequestService, OrderDeliveryService)
  bloquean el pedido sin el scope de tienda, así que validan con App\Support\StoreGuard
  después del bloqueo: User (staff u owner) debe poder acceder a la tienda del pedido
  (canAccessTenant); Customer debe ser el dueño del pedido y estar en la tienda de la
  petición actual (ResolveStore); el sistema es el actor explícito SystemActor::System
  (solo los comandos que recorren todas las tiendas a propósito, como
  orders:expire-unpaid). Un actor null o desconocido NO pasa: lanza
  CrossStoreAccessException (subclase de InvalidOrderTransitionException) con un
  mensaje que no revela el pedido, igual que cualquier otro acceso no permitido.
- StockService::change con un pedido exige que la variante y el pedido sean de la misma
  tienda; sin pedido no cambia nada. Un servicio nuevo que bloquee pedidos con
  withoutGlobalScopes debe llamar a StoreGuard::authorize.
- Marcas (brands): se comparten entre las dos tiendas a propósito (no tienen
  store_id). Cualquier empleado puede crearlas; solo el owner las edita o las
  borra, y una marca con productos (de cualquier tienda, también en la papelera)
  no se borra.
- La categoría de un producto debe ser de la tienda del panel: se valida en el
  servidor aunque inventen el ID por Livewire.
- Un código de cupón de la otra tienda recibe la misma respuesta que uno que no
  existe ("El código no es válido.").
- products.cost está en $hidden: no sale en arreglos ni JSON del modelo. El
  formulario de edición se lo pasa solo al owner (EditProduct).
- PanelTenancyTest recorre los modelos con store_id: cada uno debe usar
  BelongsToStore y estar cubierto por un recurso de Filament con tenant (o por un
  filtro explícito anotado en esa prueba). Un modelo nuevo con store_id sin eso
  rompe la prueba.

## Avisos por Telegram al dueño (fase 5.21a)
- Bot de Telegram vía HTTP client; no hay paquetes extra. Variables:
  TELEGRAM_BOT_TOKEN y TELEGRAM_OWNER_CHAT_ID (en .env, nunca en el código ni
  en logs; si falla se registra con el token como [token]).
- Si faltan las variables, no se manda ni se encola nada. Un aviso fallido
  nunca rompe un pedido, una cancelación ni un movimiento de stock.
- Avisos: pedido nuevo (solo canal web, con forma de pago), solicitud de
  cancelación, modelo agotado (todas las tallas en 0, una vez por
  agotamiento) y pedido en efectivo sin entregar. La venta de mostrador no
  avisa. Ningún mensaje lleva costos.

## WhatsApp
- Solo enlaces wa.me con mensaje prellenado (clase WhatsAppLink y
  OrderWhatsAppMessage); sin API automática. El número sale de la tienda del
  pedido. Sin número configurado no se muestra el botón.
- El botón "Tengo una duda" está siempre visible en el detalle del pedido.

## Recuperar contraseña de clientes (fase 5.22b)
- Solo clientes (guard customer, broker "customers", tabla
  customer_password_reset_tokens). El broker y el login del panel /admin no se
  tocan; un token de uno no sirve en el otro.
- Flujo: "¿Olvidaste tu contraseña?" en el login -> correo -> correo con enlace
  -> contraseña nueva. Las páginas usan el tema de la tienda desde la que se pide.
- El correo (CustomerResetPassword) sale en cola y fija la tienda al crearse
  (dominio con esquema y puerto, nombre y tema), porque el trabajo en cola ya no
  tiene la petición. El enlace abre el dominio de esa misma tienda; el HTML
  usa los colores del tema (StoreMailTheme) y el logo de public/brand/{tema}/
  logo.png si existe, o el nombre de la tienda. Local: MAIL_MAILER=log.
- Siempre el mismo mensaje al pedir el enlace ("Si el correo existe, te
  enviamos un enlace…"), exista o no la cuenta. Si el envío falla se registra
  en el log y la pantalla responde igual.
- Límites: 5 por minuto por correo+IP y 30 por hora por IP al pedir el enlace;
  10 por minuto por IP al guardar la contraseña nueva. El enlace vence a los 60
  minutos, es de un solo uso y uno nuevo anula el anterior.
- Al restablecer o cambiar la contraseña se cierran las demás sesiones del
  cliente (AuthenticateCustomerSession: huella de la contraseña en la sesión) y
  se renueva el remember_token. Solo se quita el inicio de sesión: el carrito se
  conserva. El AuthenticateSession de Laravel no sirve aquí: usa el guard del panel.
- Cuentas de Google (sin contraseña) usan el mismo flujo para definir una; el
  correo lo dice. El flujo no revela si la cuenta existe ni cómo se creó.
