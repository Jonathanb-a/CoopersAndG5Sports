<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Store;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cifras de ventas de una tienda para el dashboard.
 *
 * Una venta es un pedido pagado, enviado o entregado (nunca apartado ni cancelado) y entra al
 * periodo por su fecha de pago (paid_at). Excepción: un pedido en efectivo es venta hasta que se
 * ENTREGA (se cobra al entregar) y entra por su fecha de entrega (delivered_at); mientras esté
 * confirmado o enviado cuenta como «por cobrar».
 */
class SalesReport
{
    /** Fecha en la que un pedido entra a las ventas: la entrega si es efectivo, el pago en los demás. */
    private const SALE_DATE = "CASE WHEN orders.payment_method = 'cash' THEN orders.delivered_at ELSE orders.paid_at END";

    /**
     * @return array{sales: float, orders: int, average: float, cost: float, profit: float}
     */
    public function summary(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $totals = $this->sales($store, $from, $to)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(orders.total), 0) as sales')
            ->first();

        // Costo de lo vendido: piezas × costo actual del producto (sin costo cuenta como 0).
        $cost = (float) $this->sales($store, $from, $to)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->sum(DB::raw('order_items.quantity * COALESCE(products.cost, 0)'));

        $sales = (float) $totals->sales;
        $orders = (int) $totals->orders;

        return [
            'sales' => $sales,
            'orders' => $orders,
            'average' => $orders > 0 ? round($sales / $orders, 2) : 0.0,
            'cost' => $cost,
            'profit' => $sales - $cost,
        ];
    }

    /** Apartados sin pagar hoy, sin importar el periodo. */
    public function pendingReservations(Store $store): int
    {
        return DB::table('orders')
            ->where('store_id', $store->getKey())
            ->where('status', OrderStatus::Reserved->value)
            ->count();
    }

    /**
     * Ventas por día, con los días sin ventas en 0.
     *
     * @return array<string, float> 'Y-m-d' => total
     */
    public function salesByDay(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];
        for ($day = Carbon::instance($from)->startOfDay(); $day->lte($to); $day->addDay()) {
            $days[$day->toDateString()] = 0.0;
        }

        // Sumado en la base de datos: un renglón por día, no un renglón por pedido.
        $this->sales($store, $from, $to)
            ->selectRaw('DATE('.self::SALE_DATE.') as day, COALESCE(SUM(orders.total), 0) as total')
            ->groupByRaw('DATE('.self::SALE_DATE.')')
            ->get()
            ->each(function ($row) use (&$days) {
                $days[Carbon::parse($row->day)->toDateString()] = (float) $row->total;
            });

        return $days;
    }

    /**
     * Pedidos creados en el periodo por estado (todos los estados, también los que están en 0).
     *
     * @return array<string, int> valor del estado => pedidos
     */
    public function ordersByStatus(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = DB::table('orders')
            ->where('store_id', $store->getKey())
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as total')
            ->pluck('total', 'status');

        $result = [];
        foreach (OrderStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * Ventas del periodo por canal.
     *
     * @return array<string, float> valor del canal => total
     */
    public function salesByChannel(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $totals = $this->sales($store, $from, $to)
            ->groupBy('orders.channel')
            ->selectRaw('orders.channel as channel, COALESCE(SUM(orders.total), 0) as total')
            ->pluck('total', 'channel');

        $result = [];
        foreach (OrderChannel::cases() as $channel) {
            $result[$channel->value] = (float) ($totals[$channel->value] ?? 0);
        }

        return $result;
    }

    /**
     * Ventas del periodo por método de entrega.
     *
     * @return array<string, float> valor del método => total
     */
    public function salesByDelivery(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $totals = $this->sales($store, $from, $to)
            ->groupBy('orders.delivery_method')
            ->selectRaw('orders.delivery_method as method, COALESCE(SUM(orders.total), 0) as total')
            ->pluck('total', 'method');

        $result = [];
        foreach (DeliveryMethod::cases() as $method) {
            $result[$method->value] = (float) ($totals[$method->value] ?? 0);
        }

        return $result;
    }

    /**
     * Por cobrar al día de hoy: apartados vigentes (sin pagar y con el plazo todavía abierto) y los
     * pedidos en efectivo ya confirmados o enviados, que se cobran al entregarlos.
     *
     * @return array{orders: int, amount: float}
     */
    public function receivable(Store $store): array
    {
        $row = DB::table('orders')
            ->where('store_id', $store->getKey())
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $reserved) => $reserved
                    ->where('status', OrderStatus::Reserved->value)
                    ->where(fn (Builder $open) => $open->whereNull('expires_at')->orWhere('expires_at', '>', now())))
                ->orWhere(fn (Builder $cash) => $cash
                    ->where('payment_method', PaymentMethod::Cash->value)
                    ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Shipped->value])))
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as amount')
            ->first();

        return ['orders' => (int) $row->orders, 'amount' => (float) $row->amount];
    }

    /**
     * Cambio en porcentaje contra el periodo anterior (25.0 = subió 25 %). Null si antes fue 0 y
     * no hay contra qué comparar.
     */
    public static function change(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    /**
     * Productos más vendidos del periodo por piezas.
     *
     * @return list<array{name: string, pieces: int, revenue: float}>
     */
    public function topProducts(Store $store, CarbonInterface $from, CarbonInterface $to, int $limit = 10): array
    {
        return $this->sales($store, $from, $to)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->groupBy('order_items.product_name')
            ->selectRaw('order_items.product_name as name')
            ->selectRaw('SUM(order_items.quantity) as pieces')
            ->selectRaw('SUM(order_items.quantity * order_items.unit_price) as revenue')
            ->orderByDesc('pieces')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'pieces' => (int) $row->pieces, 'revenue' => (float) $row->revenue])
            ->all();
    }

    /** Pedidos de la tienda que cuentan como venta dentro del periodo. */
    private function sales(Store $store, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return DB::table('orders')
            ->where('orders.store_id', $store->getKey())
            ->where(function (Builder $query) {
                // Efectivo: es venta hasta que se entrega, que es cuando se cobra. Nunca si se canceló.
                $query->where(fn (Builder $cash) => $cash
                    ->where('orders.payment_method', PaymentMethod::Cash->value)
                    ->where('orders.status', OrderStatus::Delivered->value))
                    // Transferencia (y mostrador): desde que está pagado, enviado o entregado.
                    ->orWhere(fn (Builder $other) => $other
                        ->where(fn (Builder $method) => $method->whereNull('orders.payment_method')->orWhere('orders.payment_method', '!=', PaymentMethod::Cash->value))
                        ->whereIn('orders.status', OrderStatus::saleValues()));
            })
            ->whereRaw('('.self::SALE_DATE.') BETWEEN ? AND ?', [$from->toDateTimeString(), $to->toDateTimeString()]);
    }
}
