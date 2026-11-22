<?php
declare(strict_types=1);

/**
 * Fecha estimada de entrega en días hábiles del país de la tienda (Country::holidays).
 * - No cuenta domingos ni festivos (Colombia: Ley Emiliani; Guatemala: Código de Trabajo art. 127).
 * - Sábado cuenta como hábil si así se configura (las transportadoras suelen repartir los sábados).
 * - Pedidos después de la hora de corte empiezan a contar el siguiente día hábil.
 * Ajustes: ship_prep_days (alistamiento, ej. "0-1"), ship_transit_days (transporte, ej. "2-3"),
 *          ship_cutoff_hour (ej. 14) y ship_saturday (1 = el sábado es hábil).
 */
final class Delivery
{
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /** Festivos del país de la tienda, como 'Y-m-d' => true. */
    public static function holidays(int $year): array
    {
        return Country::holidays($year);
    }

    public static function isBusinessDay(DateTimeImmutable $d): bool
    {
        $dow = (int) $d->format('N');
        if ($dow === 7 || ($dow === 6 && setting('ship_saturday', '1') !== '1')) return false;
        return !isset(self::holidays((int) $d->format('Y'))[$d->format('Y-m-d')]);
    }

    private static function addBusinessDays(DateTimeImmutable $d, int $n): DateTimeImmutable
    {
        while ($n > 0) {
            $d = $d->modify('+1 day');
            if (self::isBusinessDay($d)) $n--;
        }
        return $d;
    }

    private static function range(string $key, string $default): array
    {
        $v = preg_replace('/\s/', '', (string) setting($key, $default));
        [$a, $b] = array_pad(array_map('intval', explode('-', $v)), 2, null);
        $b ??= $a;
        return [max(0, min($a, $b)), max(0, max($a, $b))];
    }

    /** @return array{ordered: DateTimeImmutable, ready: array{0: DateTimeImmutable, 1: DateTimeImmutable}, delivered: array{0: DateTimeImmutable, 1: DateTimeImmutable}} */
    public static function estimate(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $today = $now->setTime(0, 0);
        // Punto de partida: hoy si es hábil y antes de la hora de corte; si no, el siguiente día hábil
        $start = (self::isBusinessDay($today) && (int) $now->format('G') < (int) setting('ship_cutoff_hour', '14'))
            ? $today : self::addBusinessDays($today, 1);
        [$p1, $p2] = self::range('ship_prep_days', '0-1');
        [$t1, $t2] = self::range('ship_transit_days', '2-3');
        $r1 = self::addBusinessDays($start, $p1); $r2 = self::addBusinessDays($start, $p2);
        return ['ordered' => $today, 'ready' => [$r1, $r2], 'delivered' => [self::addBusinessDays($r1, $t1), self::addBusinessDays($r2, $t2)]];
    }

    public static function fmt(DateTimeImmutable $d): string
    {
        return (int) $d->format('j') . ' ' . self::MESES[(int) $d->format('n') - 1];
    }

    /** "12 nov", "12-13 nov" o "30 nov - 2 dic" */
    public static function fmtRange(array $r): string
    {
        if ($r[0] == $r[1]) return self::fmt($r[0]);
        // mismo mes: "10-11 nov"; meses distintos: "30 nov - 2 dic"
        return $r[0]->format('Y-m') === $r[1]->format('Y-m')
            ? $r[0]->format('j') . '-' . self::fmt($r[1])
            : self::fmt($r[0]) . ' - ' . self::fmt($r[1]);
    }
}
