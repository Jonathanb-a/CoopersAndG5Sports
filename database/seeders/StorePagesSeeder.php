<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\StorePage;
use Illuminate\Database\Seeder;

/**
 * Borradores de las páginas de contenido y legales de cada tienda, con el tono de su marca y los datos
 * reales del sistema cuando existen. Lo que falta del dueño va entre [CORCHETES] visibles.
 *
 * Solo crea las páginas que falten: nunca pisa una página que ya se editó en el panel. Todas nacen sin
 * publicar, salvo «Quiénes somos» y «Contacto» cuando no les queda nada entre corchetes.
 */
class StorePagesSeeder extends Seeder
{
    public const DRAFT_NOTE = 'BORRADOR: revisar con el cliente antes de publicar';

    /** Las páginas legales llevan siempre la nota de borrador. */
    private const LEGAL = ['envios-y-entregas', 'cambios-y-devoluciones', 'aviso-de-privacidad', 'terminos-y-condiciones'];

    /** Las que se publican solas si ya no tienen nada pendiente. */
    private const AUTO_PUBLISH = ['quienes-somos', 'contacto'];

    public function run(): void
    {
        foreach (Store::query()->get() as $store) {
            foreach ($this->pages($store) as $slug => $body) {
                $pending = str_contains(strip_tags($body), '[');

                StorePage::withoutGlobalScopes()->firstOrCreate(
                    ['store_id' => $store->id, 'slug' => $slug],
                    [
                        'title' => StorePage::PAGES[$slug],
                        'body' => $body,
                        'internal_note' => in_array($slug, self::LEGAL, true) || $pending ? self::DRAFT_NOTE : null,
                        'is_published' => in_array($slug, self::AUTO_PUBLISH, true) && ! $pending,
                    ],
                );
            }
        }
    }

    /** @return array<string, string> slug => HTML */
    public function pages(Store $store): array
    {
        $g5 = $store->theme === 'g5';
        $name = e($store->name);
        $whatsapp = filled($store->whatsapp_phone) ? e($store->whatsapp_phone) : '[NÚMERO DE WHATSAPP]';
        $address = filled($store->pickup_address) ? e($store->pickup_address) : '[DIRECCIÓN DE LA TIENDA]';
        $hours = filled($store->pickup_hours) ? e($store->pickup_hours) : '[HORARIO DE ATENCIÓN]';
        $reserve = (int) config('shop.reserve_hours', 48);
        $cashHours = (int) config('shop.cash_reserve_hours', 72);
        $cashDays = (int) config('shop.cash_reserve_max_days', 7);
        $maxOpen = (int) config('shop.max_open_reservations', 3);
        $localCost = (float) $store->local_shipping_cost > 0
            ? 'tiene un costo de $'.number_format((float) $store->local_shipping_cost, 2).' que se suma al total de tu pedido'
            : 'no tiene costo (se acuerda contigo por WhatsApp)';
        $about = trim((string) $store->about_text);

        $intro = $g5
            ? '<p><strong>Somos '.$name.':</strong> calzado deportivo y sneakers para la cancha y la calle.</p>'
            : '<p>En <strong>'.$name.'</strong> seleccionamos cada pieza con cuidado para que encuentres ropa original que te acompañe mucho tiempo.</p>';

        return [
            'quienes-somos' => $about !== ''
                ? collect(preg_split('/\n{2,}/', $about))->map(fn (string $paragraph) => '<p>'.nl2br(e(trim($paragraph)), false).'</p>')->implode('')
                : $intro.'<p>[HISTORIA DE LA TIENDA: CÓMO EMPEZÓ Y QUÉ LA HACE DIFERENTE]</p>',

            'contacto' => '<p>'.($g5 ? '¿Dudas con una talla o un modelo? Escríbenos y te contestamos rápido.' : 'Con gusto te ayudamos con tallas, disponibilidad o tu pedido.').'</p>'
                .'<h2>WhatsApp</h2><p>'.$whatsapp.'</p>'
                .'<h2>Tienda</h2><p>'.$address.'</p><p>'.$hours.'</p>',

            'preguntas-frecuentes' => '<h2>¿Necesito una cuenta para comprar?</h2>'
                .'<p>Puedes ver toda la tienda sin cuenta. Para apartar un pedido sí necesitas iniciar sesión.</p>'
                .'<h2>¿Cómo pago?</h2>'
                .'<p>Por transferencia o en efectivo. Con transferencia te mostramos los datos al apartar y nos mandas tu comprobante por WhatsApp con tu número de pedido. En efectivo pagas al recoger o al recibir tu pedido.</p>'
                .'<h2>¿Cuánto tiempo me apartan las piezas?</h2>'
                .'<p>Con transferencia, '.$reserve.' horas para pagar. En efectivo, '.$cashHours.' horas para confirmar tu pedido. Si no se paga o confirma a tiempo, el apartado se cancela y las piezas vuelven a la tienda.</p>'
                .'<h2>¿Cuántos pedidos puedo tener apartados?</h2>'
                .'<p>Hasta '.$maxOpen.' pedidos sin pagar al mismo tiempo.</p>'
                .'<h2>¿Qué significa «Sobre pedido»?</h2>'
                .'<p>Que la talla no está en tienda y la conseguimos para ti. Te decimos el tiempo de entrega al apartar o por WhatsApp.</p>'
                .'<h2>¿Puedo cambiar una talla?</h2>'
                .'<p>Revisa la página de Cambios y devoluciones. [CONFIRMAR POLÍTICA DE CAMBIOS CON EL DUEÑO]</p>',

            'envios-y-entregas' => '<h2>Recoger en tienda</h2>'
                .'<p>Pasa por tu pedido a '.$address.'. Horario: '.$hours.'. Te confirmamos por WhatsApp el día en que puedes recogerlo.</p>'
                .'<h2>Entrega local en Zihuatanejo</h2>'
                .'<p>Llevamos tu pedido al punto que nos indiques dentro de Zihuatanejo. La entrega local '.$localCost.'. El día y la hora los acordamos contigo por WhatsApp.</p>'
                .'<p>[ZONAS QUE CUBRE LA ENTREGA LOCAL]</p>'
                .'<h2>Pago en efectivo</h2>'
                .'<p>Si pagas en efectivo, ten el monto a la mano al recibir o recoger tu pedido. El apartado en efectivo no puede pasar de '.$cashDays.' días desde que lo hiciste.</p>'
                .'<h2>Envíos a otras ciudades</h2>'
                .'<p>Por ahora no hacemos envíos fuera de Zihuatanejo. [CONFIRMAR CON EL DUEÑO]</p>',

            'cambios-y-devoluciones' => '<p>Queremos que estés feliz con tu compra. Los cambios y devoluciones se atienden por WhatsApp al '.$whatsapp.' con tu número de pedido.</p>'
                .'<h2>Plazo</h2><p>Tienes [DÍAS PARA CAMBIOS Y DEVOLUCIONES] días después de recibir tu pedido.</p>'
                .'<h2>Condiciones</h2><ul><li>[CONDICIONES DEL PRODUCTO: SIN USO, CON ETIQUETAS, EMPAQUE ORIGINAL]</li><li>[PRODUCTOS QUE NO TIENEN CAMBIO]</li></ul>'
                .'<h2>Reembolsos</h2><p>Cuando procede un reembolso se hace por transferencia. [PLAZO PARA REEMBOLSAR]</p>'
                .'<h2>Gastos de envío</h2><p>[QUIÉN PAGA LA ENTREGA EN UN CAMBIO]</p>',

            'aviso-de-privacidad' => '<h2>Responsable</h2>'
                .'<p>[RAZÓN SOCIAL Y DOMICILIO], que opera la tienda en línea '.$name.', es responsable del tratamiento de tus datos personales.</p>'
                .'<h2>Datos que recabamos</h2>'
                .'<ul><li>Nombre, correo electrónico y teléfono.</li><li>Dirección o punto de entrega, cuando eliges entrega local.</li><li>Tus pedidos e historial de compras.</li><li>Si entras con Google, el identificador de tu cuenta de Google.</li></ul>'
                .'<p>No guardamos datos de tarjetas bancarias.</p>'
                .'<h2>Finalidades</h2>'
                .'<ul><li>Crear y administrar tu cuenta.</li><li>Apartar, preparar, entregar y dar seguimiento a tus pedidos.</li><li>Contactarte por WhatsApp sobre tu pedido.</li><li>[FINALIDADES SECUNDARIAS, POR EJEMPLO PROMOCIONES, Y CÓMO NEGARTE A ELLAS]</li></ul>'
                .'<h2>Con quién compartimos tus datos</h2>'
                .'<p>[TERCEROS CON QUIENES SE COMPARTEN LOS DATOS, POR EJEMPLO PROVEEDORES DE HOSPEDAJE O DE CORREO]</p>'
                .'<h2>Derechos ARCO</h2>'
                .'<p>Puedes acceder, rectificar, cancelar u oponerte al tratamiento de tus datos, así como revocar tu consentimiento. Envía tu solicitud a [CORREO PARA SOLICITUDES ARCO] con tu nombre, el derecho que quieres ejercer y una identificación. Te respondemos en [PLAZO DE RESPUESTA].</p>'
                .'<h2>Cambios a este aviso</h2>'
                .'<p>Cualquier cambio se publicará en esta misma página. Última actualización: [FECHA].</p>',

            'terminos-y-condiciones' => '<p>Estos términos aplican a las compras en la tienda en línea de '.$name.', operada por [RAZÓN SOCIAL Y DOMICILIO].</p>'
                .'<h2>Apartado y pago</h2>'
                .'<p>Al confirmar tu pedido apartamos las piezas a tu nombre. Puedes pagar por transferencia o en efectivo. Con transferencia nos mandas tu comprobante por WhatsApp con tu número de pedido; en efectivo pagas al recoger o al recibir.</p>'
                .'<h2>Vigencia del apartado</h2>'
                .'<p>Con transferencia tienes '.$reserve.' horas para pagar; en efectivo, '.$cashHours.' horas para confirmar. Si no se paga o confirma a tiempo, el apartado se cancela y las piezas vuelven a la tienda. Puedes tener hasta '.$maxOpen.' pedidos sin pagar al mismo tiempo.</p>'
                .'<h2>Cancelaciones</h2>'
                .'<p>Puedes cancelar un pedido apartado desde «Mis pedidos». Un pedido ya pagado solo se puede solicitar cancelar y lo revisamos contigo. [CONDICIONES PARA CANCELAR UN PEDIDO PAGADO Y PLAZO DE REEMBOLSO]</p>'
                .'<h2>Entrega y recogida</h2>'
                .'<p>Ofrecemos recoger en tienda y entrega local en Zihuatanejo. El día y la hora se acuerdan por WhatsApp. Consulta Envíos y entregas.</p>'
                .'<h2>Cambios y devoluciones</h2>'
                .'<p>Consulta la página de Cambios y devoluciones. [CONFIRMAR POLÍTICA CON EL DUEÑO]</p>'
                .'<h2>Precios</h2>'
                .'<p>Los precios están en pesos mexicanos e incluyen [IVA INCLUIDO O NO]. El precio que vale es el de tu pedido al apartarlo.</p>',
        ];
    }
}
