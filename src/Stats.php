<?php
declare(strict_types=1);

/**
 * Números del dashboard para un rango [from, to).
 * Estados Mastershop: 8 = entregada, 10 = devuelta, 9 = cancelada.
 * Tasa de entrega = entregados / (entregados + devueltos): solo cuenta pedidos ya cerrados.
 */
final class Stats
{
    public const CHANNELS = [
        'pagado' => 'Pagado (ads)', 'organico' => 'Orgánico (Google)', 'social' => 'Redes sociales',
        'directo' => 'Directo / WhatsApp', 'referido' => 'Otros sitios', 'email' => 'Email',
    ];

    public function __construct(private PDO $db, private string $from, private string $to) {}

    public static function range(string $key, ?string $customFrom = null, ?string $customTo = null): array
    {
        $today = date('Y-m-d');
        return match ($key) {
            'hoy'    => [$today, date('Y-m-d', strtotime('+1 day')), 'Hoy'],
            'ayer'   => [date('Y-m-d', strtotime('-1 day')), $today, 'Ayer'],
            '30'     => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d', strtotime('+1 day')), 'Últimos 30 días'],
            '90'     => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d', strtotime('+1 day')), 'Últimos 90 días'],
            'rango'  => [self::d($customFrom) ?? $today, date('Y-m-d', strtotime((self::d($customTo) ?? $today) . ' +1 day')), 'Rango'],
            default  => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d', strtotime('+1 day')), 'Últimos 7 días'],
        };
    }

    private static function d(?string $s): ?string
    {
        return $s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    private function q(string $sql, array $args = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute([$this->from, $this->to, ...$args]);
        return $st->fetchAll();
    }

    /** Agregados de pedidos reutilizables (por canal, campaña, producto...). */
    private function orderAgg(string $alias = 'o'): string
    {
        return "COUNT(*) pedidos,
            SUM(CASE WHEN {$alias}.ms_status_id = 9 THEN 0 ELSE {$alias}.total END) ventas,
            SUM({$alias}.ms_status_id = 8) entregados,
            SUM({$alias}.ms_status_id = 10) devueltos,
            SUM(CASE WHEN {$alias}.ms_status_id = 8 THEN {$alias}.total ELSE 0 END) ventas_entregadas";
    }

    public function kpis(): array
    {
        $v = $this->q('SELECT COUNT(*) visitas, COUNT(DISTINCT visitor_id) visitantes FROM visits WHERE started_at >= ? AND started_at < ?')[0];
        $o = $this->q('SELECT ' . $this->orderAgg() . ', SUM(o.ms_status_id = 9) cancelados FROM orders o WHERE o.created_at >= ? AND o.created_at < ?')[0];
        $o = array_map(fn($x) => (int) $x, $o);
        $closed = $o['entregados'] + $o['devueltos'];
        return [
            'visitas'     => (int) $v['visitas'],
            'visitantes'  => (int) $v['visitantes'],
            'pedidos'     => $o['pedidos'],
            'ventas'      => $o['ventas'],
            'entregados'  => $o['entregados'],
            'devueltos'   => $o['devueltos'],
            'cancelados'  => $o['cancelados'],
            'en_curso'    => $o['pedidos'] - $closed - $o['cancelados'],
            'conversion'  => $v['visitas'] ? $o['pedidos'] / $v['visitas'] * 100 : null,
            'tasa_entrega'=> $closed ? $o['entregados'] / $closed * 100 : null,
            'ticket'      => $o['pedidos'] ? $o['ventas'] / $o['pedidos'] : null,
        ] + $this->profit();
    }

    /**
     * Ganancia estimada de pedidos cerrados:
     *  entregado → total − costo de productos − flete real
     *  devuelto  → − flete × (1 + factor de retorno)
     * Si el pedido no trae flete real, se usa el promedio de los que sí lo traen.
     */
    public function profit(): array
    {
        $avgShip = (int) ($this->db->query('SELECT AVG(shipping_rate) FROM orders WHERE shipping_rate IS NOT NULL')->fetchColumn() ?: 0);
        $factor = (float) setting('return_freight_factor', '1');
        $rows = $this->q(
            'SELECT o.ms_status_id, o.total, o.shipping_rate,
                    (SELECT SUM(COALESCE(i.unit_cost, 0) * i.quantity) FROM order_items i WHERE i.order_id = o.id) costo,
                    (SELECT SUM(i.unit_cost IS NULL) FROM order_items i WHERE i.order_id = o.id) sin_costo
             FROM orders o WHERE o.created_at >= ? AND o.created_at < ? AND o.ms_status_id IN (8, 10)'
        );
        $gain = 0; $missing = 0;
        foreach ($rows as $r) {
            $ship = $r['shipping_rate'] !== null ? (int) $r['shipping_rate'] : $avgShip;
            if ((int) $r['ms_status_id'] === 8) {
                $gain += (int) $r['total'] - (int) $r['costo'] - $ship;
                $missing += (int) $r['sin_costo'];
            } else {
                $gain -= (int) round($ship * (1 + $factor));
            }
        }
        return ['ganancia' => $gain, 'ganancia_incompleta' => $missing > 0 || ($rows && !$avgShip)];
    }

    public function byChannel(): array
    {
        $visits = [];
        foreach ($this->q('SELECT channel, COUNT(*) n FROM visits WHERE started_at >= ? AND started_at < ? GROUP BY channel') as $r) {
            $visits[$r['channel']] = (int) $r['n'];
        }
        $orders = [];
        foreach ($this->q("SELECT COALESCE(o.channel, 'directo') channel, " . $this->orderAgg() . ' FROM orders o
                            WHERE o.created_at >= ? AND o.created_at < ? GROUP BY 1') as $r) {
            $orders[$r['channel']] = $r;
        }
        $out = [];
        foreach (self::CHANNELS as $key => $label) {
            $o = $orders[$key] ?? ['pedidos' => 0, 'ventas' => 0, 'entregados' => 0, 'devueltos' => 0, 'ventas_entregadas' => 0];
            $vis = $visits[$key] ?? 0;
            if (!$vis && !$o['pedidos']) continue;
            $out[] = $this->rowMetrics(['clave' => $key, 'nombre' => $label, 'visitas' => $vis] + $o);
        }
        return $out;
    }

    /** Campañas: agrupa por canal + fuente + campaña (y anuncio si $byAd). */
    public function campaigns(bool $byAd = false, ?string $channel = null): array
    {
        $cols = "channel, COALESCE(source, '—') source, COALESCE(campaign, '(sin campaña)') campaign" . ($byAd ? ", COALESCE(content, '(sin anuncio)') content" : '');
        $group = $byAd ? '1, 2, 3, 4' : '1, 2, 3';
        $chan = $channel ? ' AND channel = ?' : '';
        $args = $channel ? [$channel] : [];
        $visits = [];
        foreach ($this->q("SELECT {$cols}, COUNT(*) n FROM visits WHERE started_at >= ? AND started_at < ? AND channel IN ('pagado','social','email','referido'){$chan} GROUP BY {$group}", $args) as $r) {
            $visits[$this->key($r, $byAd)] = $r + ['visitas' => (int) $r['n']];
        }
        foreach ($this->q("SELECT {$cols}, " . $this->orderAgg() . " FROM orders o WHERE o.created_at >= ? AND o.created_at < ? AND channel IN ('pagado','social','email','referido'){$chan} GROUP BY {$group}", $args) as $r) {
            $k = $this->key($r, $byAd);
            $visits[$k] = ($visits[$k] ?? $r + ['visitas' => 0]) + $r;
        }
        $rows = array_map(fn($r) => $this->rowMetrics($r + ['pedidos' => 0, 'ventas' => 0, 'entregados' => 0, 'devueltos' => 0, 'ventas_entregadas' => 0]), array_values($visits));
        usort($rows, fn($a, $b) => [$b['ventas'], $b['visitas']] <=> [$a['ventas'], $a['visitas']]);
        return $rows;
    }

    private function key(array $r, bool $byAd): string
    {
        return $r['channel'] . '|' . $r['source'] . '|' . $r['campaign'] . ($byAd ? '|' . $r['content'] : '');
    }

    private function rowMetrics(array $r): array
    {
        foreach (['visitas', 'pedidos', 'ventas', 'entregados', 'devueltos', 'ventas_entregadas'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        $closed = $r['entregados'] + $r['devueltos'];
        $r['conversion'] = $r['visitas'] ? $r['pedidos'] / $r['visitas'] * 100 : null;
        $r['tasa_entrega'] = $closed ? $r['entregados'] / $closed * 100 : null;
        return $r;
    }

    /** ¿El rango es de un solo día? Entonces la gráfica va por horas. */
    public function isSingleDay(): bool
    {
        return strtotime($this->to) - strtotime($this->from) <= 86400;
    }

    /** Serie de visitas y pedidos: por hora si el rango es de un día, si no por día. */
    public function daily(): array
    {
        $hourly = $this->isSingleDay();
        $step = $hourly ? 3600 : 86400;
        $fmt = $hourly ? 'Y-m-d H:00' : 'Y-m-d';
        $sqlFmt = $hourly ? "DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00')" : 'DATE(%s)';
        $series = [];
        // Mientras sea hoy, la serie por horas llega solo hasta la hora actual
        $end = min(strtotime($this->to), $hourly ? strtotime(date('Y-m-d H:00')) + 3600 : PHP_INT_MAX);
        for ($t = strtotime($this->from); $t < $end; $t += $step) {
            $series[date($fmt, $t)] = ['visitas' => 0, 'pedidos' => 0, 'ventas' => 0];
        }
        $v = sprintf($sqlFmt, 'started_at');
        foreach ($this->q("SELECT {$v} d, COUNT(*) n FROM visits WHERE started_at >= ? AND started_at < ? GROUP BY d") as $r) {
            if (isset($series[$r['d']])) $series[$r['d']]['visitas'] = (int) $r['n'];
        }
        $o = sprintf($sqlFmt, 'created_at');
        foreach ($this->q("SELECT {$o} d, COUNT(*) n, SUM(total) v FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY d") as $r) {
            if (isset($series[$r['d']])) { $series[$r['d']]['pedidos'] = (int) $r['n']; $series[$r['d']]['ventas'] = (int) $r['v']; }
        }
        return $series;
    }

    /** Embudo por sesiones. */
    public function funnel(): array
    {
        $r = $this->q(
            "SELECT COUNT(*) sesiones,
                    SUM(EXISTS(SELECT 1 FROM pageviews pv WHERE pv.session_id = v.session_id AND pv.product_id IS NOT NULL)) vio_producto,
                    SUM(EXISTS(SELECT 1 FROM events e WHERE e.session_id = v.session_id AND e.type = 'form_open')) abrio_formulario,
                    SUM(EXISTS(SELECT 1 FROM events e WHERE e.session_id = v.session_id AND e.type = 'order')) pidio
             FROM visits v WHERE v.started_at >= ? AND v.started_at < ?"
        )[0];
        return array_map('intval', $r);
    }

    public function topProducts(int $limit = 10): array
    {
        return $this->q(
            "SELECT p.id, p.slug, COALESCE(p.title, p.name) nombre, p.cost, p.price,
                    (SELECT COUNT(*) FROM pageviews pv WHERE pv.product_id = p.id AND pv.at >= ? AND pv.at < ?) vistas,
                    (SELECT COUNT(*) FROM events e WHERE e.product_id = p.id AND e.type = 'form_open' AND e.at >= ? AND e.at < ?) formularios,
                    COUNT(DISTINCT o.id) pedidos, COALESCE(SUM(i.quantity), 0) unidades,
                    COUNT(DISTINCT CASE WHEN o.ms_status_id = 8 THEN o.id END) entregados,
                    COUNT(DISTINCT CASE WHEN o.ms_status_id = 10 THEN o.id END) devueltos
             FROM products p
             LEFT JOIN order_items i ON (i.product_id = p.id OR (i.product_id IS NULL AND i.ms_product_id = p.ms_product_id))
             LEFT JOIN orders o ON o.id = i.order_id AND o.created_at >= ? AND o.created_at < ?
             WHERE p.is_active = 1
             GROUP BY p.id, p.slug, p.title, p.name, p.cost, p.price HAVING vistas > 0 OR pedidos > 0
             ORDER BY pedidos DESC, vistas DESC LIMIT " . (int) $limit,
            [$this->from, $this->to, $this->from, $this->to]
        );
    }

    /** Páginas de entrada del tráfico orgánico (qué está posicionando). */
    public function organicLandings(int $limit = 10): array
    {
        return $this->q(
            "SELECT v.landing_path, COUNT(*) visitas,
                    (SELECT COUNT(*) FROM orders o WHERE o.landing_path = v.landing_path AND o.channel = 'organico' AND o.created_at >= ? AND o.created_at < ?) pedidos
             FROM visits v WHERE v.started_at >= ? AND v.started_at < ? AND v.channel = 'organico'
             GROUP BY v.landing_path ORDER BY visitas DESC LIMIT " . (int) $limit,
            [$this->from, $this->to]
        );
    }

    public function returnsByCity(int $limit = 10): array
    {
        return $this->q(
            // Subconsulta: MySQL no deja usar totales (SUM) dentro de un cálculo en el ORDER BY
            "SELECT t.ciudad, t.departamento, t.entregados, t.devueltos FROM (
                 SELECT JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.city')) AS ciudad,
                        JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.state')) AS departamento,
                        SUM(ms_status_id = 8) AS entregados, SUM(ms_status_id = 10) AS devueltos
                 FROM orders WHERE created_at >= ? AND created_at < ? AND ms_status_id IN (8, 10)
                 GROUP BY JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.city')), JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.state'))
             ) t
             WHERE t.devueltos > 0
             ORDER BY t.devueltos / (t.entregados + t.devueltos) DESC, t.devueltos DESC LIMIT " . (int) $limit
        );
    }

    public function statusFunnel(): array
    {
        return $this->q('SELECT COALESCE(ms_status_id, 0) s, COUNT(*) n FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY s ORDER BY s');
    }

    public function devices(): array
    {
        return $this->q('SELECT device, COUNT(*) n FROM visits WHERE started_at >= ? AND started_at < ? GROUP BY device ORDER BY n DESC');
    }

    /** Alertas: no dependen del rango. */
    public static function alerts(PDO $db): array
    {
        $one = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
        return array_filter([
            'error'      => [$one("SELECT COUNT(*) FROM orders WHERE sync_status = 'error' AND COALESCE(sync_error, '') NOT LIKE 'Despacho manual%' AND COALESCE(sync_error, '') NOT LIKE 'Pedido de prueba%'"), 'pedidos no llegaron a Mastershop', '?p=pedidos&f=error'],
            'manual'     => [$one("SELECT COUNT(*) FROM orders WHERE sync_status = 'error' AND sync_error LIKE 'Despacho manual%'"), 'pedidos de productos sin vincular: despáchalos tú', '?p=pedidos&f=manual'],
            'por_pagar'  => [$one("SELECT COUNT(*) FROM orders WHERE payment_method = 'pia' AND payment_status = 'pendiente'"), 'pagos anticipados por confirmar', '?p=pedidos&f=por_pagar'],
            'novedad'    => [$one('SELECT COUNT(*) FROM orders WHERE novelty IS NOT NULL AND ms_status_id = 6'), 'pedidos con novedad de la transportadora', '?p=pedidos&f=novedad'],
            'sin_stock'  => [$one('SELECT COUNT(*) FROM products WHERE is_active = 1 AND stock <= 0'), 'productos publicados sin stock', '?p=productos&f=sin_stock'],
            'sin_aida'   => [$one("SELECT COUNT(*) FROM products WHERE is_active = 1 AND (aida_hook IS NULL OR aida_benefits IS NULL)"), 'productos publicados con contenido incompleto', '?p=productos&f=incompletos'],
            'webhooks'   => [$one('SELECT COUNT(*) FROM webhook_events WHERE processed_at IS NULL AND error IS NOT NULL'), 'notificaciones de Mastershop con error', '?p=pedidos'],
        ], fn($a) => $a[0] > 0);
    }
}
