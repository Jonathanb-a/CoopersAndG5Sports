<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\Store;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cifras de ventas de una tienda para el dashboard.
 *
 * Una venta es un pedido pagado, enviado o entregado (nunca apartado ni cancelado) y
 * entra al periodo por su fecha de pago (paid_at).
 */
class SalesReport
{
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
            ->selectRaw('DATE(orders.paid_at) as day, COALESCE(SUM(orders.total), 0) as total')
            ->groupByRaw('DATE(orders.paid_at)')
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
     * Por cobrar: apartados vigentes (sin pagar y con el plazo todavía abierto) al día de hoy.
     *
     * @return array{orders: int, amount: float}
     */
    public function receivable(Store $store): array
    {
        $row = DB::table('orders')
            ->where('store_id', $store->getKey())
            ->where('status', OrderStatus::Reserved->value)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
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
            ->whereIn('orders.status', OrderStatus::saleValues())
            ->whereBetween('orders.paid_at', [$from, $to]);
    }
}
