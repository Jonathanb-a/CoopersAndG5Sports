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
- Los logos van en public/brand/{theme}/logo.png (PNG transparente). En el
  encabezado su alto sale del tema (.store-logo): Copper's 52 px / 76 px en
  escritorio; G5 48 px / 64 px con contorno blanco fijo (sus letras son oscuras).
  En el correo, G5 lo pone sobre un recuadro claro (StoreMailTheme logo_bg).

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
- Suite rápida: `php artisan test --parallel --processes=8` (unos 30 s; en serie son
  unos 2 min). Cada proceso usa su propia base SQLite en memoria, así que no se
  mezclan datos (paratest está en require-dev). Una clase: `php artisan test --filter=NombreDeLaClase`
  (sin --parallel, que no rinde para pocas pruebas).
- Las pruebas nunca esperan ni llaman a internet: tests/TestCase.php hace Sleep::fake() y
  Http::preventStrayRequests(), y phpunit.xml deja vacíos TELEGRAM_BOT_TOKEN y
  TELEGRAM_OWNER_CHAT_ID (el .env tiene el bot real: antes cada pedido web de prueba
  le mandaba un aviso de verdad al dueño y tardaba ~1 s). Una prueba que necesite HTTP
  lo declara con Http::fake(); una que dependa del tiempo, con Sleep::fake o Carbon.
- Las migraciones nuevas se crean, pero NO se corren: el dueño del proyecto
  corre php artisan migrate en su MySQL.
- Las tablas de arriba son una guía inicial; la fuente de verdad son las
  migraciones y los modelos.
- NUNCA cierres procesos con taskkill por nombre ni mates todos los node.exe (ni
  php.exe): eso tumba el servidor de desarrollo del dueño. Si hay que detener
  algo, solo por el PID del proceso que tú iniciaste (guárdalo al arrancarlo).
- Los servidores y las bases temporales de revisión (SQLite, capturas, scripts)
  se crean fuera del repositorio y se borran al terminar. El servidor del dueño
  (puerto 8000) y su MySQL no se usan para pruebas.

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

## Dashboard de ventas del panel (fase 5.21b)
- Dashboard de Filament por tienda (tenant): cada tienda ve solo lo suyo. Filtro
  de periodo (SalesPeriod): hoy, 7 días, 30 días (por defecto), este mes.
- Cifras en SalesReport / InventoryReport con consultas agregadas (sin cargar
  pedidos en memoria), siguiendo "Conteo de ventas". Tarjetas: ventas, pedidos,
  ticket promedio (con flecha y % contra el periodo anterior) y por cobrar (foto
  de hoy, sin comparación). La utilidad y los costos solo los ve el owner.
- Gráficas: ventas por día, ventas por canal, ventas por entrega y 5 productos
  más vendidos por piezas. Listas: últimos 8 pedidos, apartados por vencer
  (12 h), alertas de stock y pedidos que requieren acción.
- "Requieren acción" (OrdersNeedingAction): solicitud de cancelación, entrega a
  domicilio sin fecha, recogida sin fecha (pagado), pagado sin enviar y efectivo
  vencido sin entregar.
- Índices de orders para el dashboard: (store_id, status, paid_at) y
  (store_id, created_at).

## Animaciones (fases 6.1 y 6.2)
- Paquete: motion (motion/react). Cada tema define su propio MotionSet en
  resources/js/Themes/{tema}/motion.ts y lo entrega en el contexto del tema
  (motionSet). Los componentes compartidos solo leen esos valores (useMotion) y no
  llevan números propios; un tema sin motionSet no recibe estas animaciones. Las
  secciones opcionales del MotionSet (tag, heading, banner, button, backdrop y los
  extras de card y addToCart) son del carácter de un tema: si no las define, ese
  efecto no existe (Copper's no las tiene).
- Base común (los dos temas): aparición al hacer scroll en secciones y rejillas con
  escalonado topado, tarjeta de producto (zoom de la foto y cambio a la segunda
  foto, second_image_url), encabezado que se recoge al bajar y aparece al subir,
  cajón/mini carrito deslizante (CSS con variables --motion-* sacadas de motion.ts),
  confirmación "Agregado" en el botón de agregar y fundido breve entre páginas.
- Copper's (Themes/coppers/motion.ts): fino. Entradas de 300 a 500 ms, ease-out
  suave, desplazamientos de 8 a 16 px, nada que rebote, escalonado de 60 ms.
- Sports G5 (Themes/g5/motion.ts): enérgico. Entradas de 180 a 320 ms, easing con
  empuje corto (overshoot de ~1.25), desplazamientos de 16 a 32 px, escalonado de
  40 ms. Además, solo en G5: tarjeta con leve inclinación y elevación, foto que se
  acerca más (1.08) y etiqueta tipo calcomanía que se endereza; etiquetas con
  entrada tipo sello (scale y rotate, CSS .g5-stamp); títulos de sección con corte
  (suben desde abajo de su caja; los bloques que los envuelven llevan `bare`);
  banner del home con entrada más dinámica (Reveal emphasis="banner"); pulso del
  botón principal al pasar el cursor (.store-pulse); "Agregado" con pop de escala;
  y deriva lenta de las manchas de pintura del hero (.store-paint-drift, una sola
  capa ::before, solo transform; el pie no se mueve).
- Reglas: respetar prefers-reduced-motion (sin movimiento: no se envuelve nada ni
  hay hover, solo cambios instantáneos); animar solo opacity y transform (y las
  propiedades scale y rotate); nada que provoque reflujo ni saltos de layout (CLS);
  sin animar sombras ni filtros nuevos y con un solo elemento de fondo en
  movimiento; en celular, versión ligera (menos desplazamiento, sin escalonado, sin
  hover, sin sello, sin banner escalado y sin movimiento continuo de fondo);
  ninguna animación en checkout, carrito, pedidos, "Mi cuenta", login, registro ni
  recuperar contraseña (quietPaths). No se agregan animaciones fuera de esta lista.
- Regla: TODO contenido animado termina visible aunque algo falle (fase 6.3c). El
  contenido se renderiza visible (así sale en el HTML); el estado oculto se pone
  solo en el navegador, justo antes de pintar, y solo cuando ya hay con qué
  revelarlo (useEntrance; para lo que entra con scroll, cuando existe
  IntersectionObserver). Al terminar, o si la animación falla, se quitan los
  estilos. Nunca `initial` oculto de Motion en el render, ni el useInView de Motion
  (lanza error sin observer): se usa useSeen. Un texto desplazado dentro de un
  overflow-hidden no «entra en pantalla» para el observer: se observa la CAJA, no
  el texto (así se quedaban sin revelar los títulos de G5). La caja del corte deja
  aire arriba para los acentos de las mayúsculas.
- Títulos grandes: tamaño fluido con clamp() y vw, text-balance y break-words;
  nunca una sola línea de tamaño fijo.
- Carrusel de banners (BannerCarousel): avance automático SIN controles (ni
  flechas, ni puntos, ni pausa). Tiempos en MotionSet.carousel: 5 s por banner y
  transición de 0.5 a 0.8 s (fundido en Copper's, deslizamiento en G5), solo
  opacity y transform. Se pausa con el cursor encima, mientras el dedo toca, con el
  foco dentro y con la pestaña oculta; en celular se cambia deslizando. Con
  prefers-reduced-motion no avanza (queda el primero, con swipe). Un solo banner:
  imagen fija sin carrusel. Sin banners: no pinta nada. Solo el primero carga con
  prioridad; caja 16:10 en celular y 21:9 desde tableta; título con degradado.
- Pruebas del frontend: vitest (npm test, resources/js/**/*.test.tsx), por tema:
  rangos de los valores, movimiento reducido, celular, rutas de pagar, el otro tema
  sin cambios y el render del layout completo.

## Reglas para celular (fase 6.3)
- Revisión hecha con Playwright en 360, 390, 414 y 768 px sobre las dos tiendas
  (home, catálogo, categoría, producto, carrito, checkout, gracias, login,
  registro, recuperar contraseña, Mis pedidos y su detalle, Mi cuenta): sin scroll
  horizontal en ninguna. Repetir la revisión cuando se cambie el layout.
- Tamaño táctil: todo botón, enlace suelto, talla u opción mide al menos 44 x 44 px
  (size-11 / h-11 / min-h-11) con separación entre ellos. StoreButton `sm` también
  es h-11. Los enlaces dentro de un párrafo están exentos.
- Campos de formulario (input, select, textarea): texto de 16 px (text-base) para
  que iOS no haga zoom, alto h-11, y el teclado correcto (type="email", "tel",
  inputMode, autoComplete). Un campo suelto sin text-base rompe mobile.test.tsx.
- Contraste AA: los mensajes de error usan text-store-error / border-store-error
  (variable --store-error de cada tema), nunca un rojo fijo.
- Checkout: en celular el total y el botón "Apartar pedido" van en una barra fija
  inferior (data-checkout-bar, con safe-area); el botón de la columna del total
  solo se ve en pantallas grandes.
- Imágenes con width y height definidos; las fotos van dentro de cajas con
  aspect-ratio (no hay saltos de layout). Los banners dependen de la caja.
- Sombras: store-lift NO anima box-shadow. La sombra base (::before) y la de hover
  (::after) son capas fijas que cambian solo de opacidad, y la tarjeta se mueve con
  transform. Por eso el overflow-hidden de las tarjetas va en un contenedor
  interno, no en el elemento con store-lift. No animar filtros ni backdrop-blur (el
  encabezado de Copper's ya no lleva blur porque se mueve).
- La revisión usa una base SQLite temporal y capturas fuera del repositorio.
- Barra de compra en celular (fase 6.3b, BuyBar): por debajo del ancho de tableta
  (md:hidden), producto y carrito llevan una barra inferior con el monto y el
  botón principal, igual en estilo a la del checkout y con el área segura de iOS.
  Es sticky, no fixed: ocupa su lugar al final del contenido, así que no tapa el
  pie ni necesita espacio extra, y queda debajo del encabezado y del cajón (z-20).
  En celular el botón normal de la página se oculta (max-md:hidden); en pantalla
  grande no cambia nada.
  - Producto: precio y "Agregar al carrito". Sin talla elegida dice "Elige tu
    talla" y lleva al selector (con el foco en la primera talla elegible) en vez
    de agregar; agotado va deshabilitado; sobre pedido conserva el texto y lo
    avisa sobre el precio; los errores salen en la misma barra. Sin tallas
    registradas no hay barra.
  - Carrito: total estimado y "Continuar con la compra". Vacío, no se muestra.
  - Solo anima su entrada (opacity y transform, valores del tema); nada con
    prefers-reduced-motion ni en el carrito, que es ruta sin animaciones.

## Páginas de contenido, legales y errores (fase 6.4)
- Tabla store_pages (store_id, slug, title, body, internal_note, is_published),
  modelo StorePage con BelongsToStore y recurso de Filament en el tenant. El
  owner y el staff de la tienda editan (RichEditor: títulos, listas, negritas,
  enlaces); solo el owner publica o despublica (el servidor lo impone aunque se
  mande el campo por Livewire). internal_note es solo para el personal (por
  ejemplo "BORRADOR: revisar con el cliente antes de publicar") y nunca sale en
  la tienda.
- Slugs fijos (StorePage::PAGES, también el orden del pie): quienes-somos,
  contacto, preguntas-frecuentes, envios-y-entregas, cambios-y-devoluciones,
  aviso-de-privacidad, terminos-y-condiciones. Se ven en /pagina/{slug}; una no
  publicada o de otra tienda responde 404.
- HTML saneado con App\Support\SafeHtml (lista cerrada: p, h2-h4, listas,
  negritas, cursivas, citas, hr y enlaces http(s)/mailto/tel/relativos con rel
  forzado; sin scripts, iframes, imágenes, style, class ni on*) al guardar y otra
  vez al mostrar. El aspecto lo pone .store-prose con las variables del tema.
- StorePagesSeeder (lo llama DatabaseSeeder): borradores por tienda con el tono
  de la marca y datos reales (nombre, WhatsApp, dirección, horario, plazos de
  apartado, efectivo, entrega local). Lo pendiente del dueño va entre
  [CORCHETES]. Todo nace sin publicar, salvo Quiénes somos y Contacto cuando no
  les queda nada entre corchetes. Nunca pisa una página que ya exista.
- Pie de página (FooterDetails en ambos temas): enlaces solo a páginas
  publicadas, dirección y horario de recoger, WhatsApp. Se comparte como
  `footer` desde ResolveStore::bind. No hay campos de redes sociales en stores.
- Consentimiento (LegalConsent) en el registro y el checkout: "Al continuar
  aceptas el Aviso de privacidad y los Términos y condiciones"; cada nombre es
  enlace solo si esa página está publicada.
- Errores 404, 419, 500 y 503 con el diseño de la tienda del dominio
  (StoreErrorPage, desde bootstrap/app.php): página Inertia "error" con botón
  para volver al inicio. Una ruta inexistente pasa por Route::fallback dentro
  del grupo de la tienda. El 500 solo se reemplaza con APP_DEBUG apagado; el
  panel /admin, Livewire, JSON y dominios sin tienda conservan la respuesta de
  Laravel.
- SEO: cada página de contenido y de error lleva <title> con el nombre de la
  tienda y meta description propia (del texto de la página). Los títulos ya no
  llevan el sufijo " - Laravel".

## Recuperar y cambiar la contraseña del panel (fase 6.4c)
- Solo personal (guard web, tabla users, broker "users"). Los tokens van en
  password_reset_tokens (la de clientes es customer_password_reset_tokens: un token
  de una no sirve en la otra) y vencen a los 60 minutos, de un solo uso; pedir uno
  nuevo anula el anterior. El broker deja un enlace por minuto por usuario.
- Páginas propias en app/Filament/Auth (NO en app/Filament/Pages: esa carpeta se
  descubre sola como páginas del panel): Login, RequestPasswordReset, ResetPassword y
  EditProfile, activadas en AdminPanelProvider (login, passwordReset, profile).
- Pedir enlace: siempre el mismo mensaje ("Si el correo existe, te enviamos un
  enlace…") exista o no el correo, tenga o no acceso al panel, o ya se haya mandado
  uno hace poco. La pantalla de nueva contraseña también da el mismo error para un
  token malo que para un correo inexistente (la de Filament revelaría qué correos
  existen con un enlace armado a mano). Si el envío falla, se registra en el log.
- Correo: PanelResetPassword, en cola, con la marca de la tienda del dominio desde el
  que se pidió (ResolveStore::forHost; un dominio sin tienda usa el nombre de la app y
  los colores de Copper's). El enlace se arma con el esquema y dominio de esa petición
  al crear la notificación (el trabajo en cola ya no tiene petición): abre el mismo
  dominio, nunca otro ni APP_URL. Vistas mail/panel-reset-password(.text).
- Límites (RateLimiter, por IP detrás del proxy: ver trustProxies al desplegar):
  pedir enlace 5 por minuto por correo+IP y 30 por hora por IP; guardar contraseña
  nueva 10 por minuto por IP y 5 por minuto por correo; login del panel 5 fallos por
  minuto por correo+IP y 30 por hora por IP (solo cuentan los fallos), además del
  límite propio de Filament (5 por minuto por IP).
- Perfil (/admin/mi-perfil; /profile ya es "Datos de la tienda"): nombre, correo y
  contraseña de quien entró. Cambiar correo o contraseña pide la contraseña actual.
  Al cambiarla, la sesión actual sigue y las demás se cierran (AuthenticateSession del
  panel compara la huella de la contraseña; además se renueva remember_token). Al
  restablecer con el enlace pasa igual. La página no lleva tienda en la dirección y el
  menú de Filament truena sin una: EditProfile fija la tienda por defecto del usuario.
- Usuarios (solo owner): acción "Restablecer contraseña" en la lista y en la edición.
  Manda el enlace al empleado (no genera ni muestra contraseñas temporales: no hay
  columna que obligue a cambiarla). Solo a empleados: no a otro owner ni a sí mismo
  (para eso está "Mi perfil"). La regla se vuelve a comprobar al ejecutar
  (SendPasswordLinkAction::allowed). El staff ni siquiera ve el recurso Usuarios.

## Demo con túnel HTTPS (ngrok) y proxies de confianza (D2)
- TRUSTED_PROXIES (.env, ver .env.example): a qué proxies les cree Laravel
  X-Forwarded-For, -Port y -Proto (App\Support\TrustedProxies, llamado desde
  bootstrap/app.php). Vacío = ninguno (lo seguro: un X-Forwarded-Proto inventado no
  cambia nada). Local con ngrok: `*`. Producción: la IP real del proxy (varias con
  coma, o CIDR); nunca `*` con el puerto de la app expuesto. X-Forwarded-Host NO se
  confía: la tienda sale del Host de la petición y un encabezado no debe cambiarla.
  Sin esto, detrás de un túnel https los enlaces (Google, correos) salen con http, la
  cookie segura no vuelve (419) y la IP de los límites de intentos es la del túnel.
- STORE_DOMAIN_ALIASES (.env): "dominio=slug,otro=slug". ResolveStore::forHost busca
  primero stores.domain y, si no hay tienda, el alias (tienda activa). Sirve para una
  demo con el dominio fijo del túnel sin borrar coppers.localhost ni tocar la base.
  En producción el dominio real va en stores.domain, no aquí.
- Para la demo: SESSION_DOMAIN se queda en null (cookie del dominio exacto; con otro
  valor la cookie no viaja al túnel), SESSION_SECURE_COOKIE=true y APP_URL con el
  dominio https del túnel. No usar `composer dev` (deja public/hot y la página pide
  los assets a http://localhost:5173, que no existe para quien entra por el túnel):
  `npm run build` y `php artisan serve` + `php artisan queue:listen` aparte.
- config/services.php lleva `google.redirect`: Socialite truena con 500 si la llave
  no existe (las pruebas con driver simulado no lo ven; TunnelProxyTest usa el real).
