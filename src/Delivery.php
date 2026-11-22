<?php
declare(strict_types=1);

/**
 * Fecha estimada de entrega en días hábiles de Colombia.
 * - No cuenta domingos ni festivos (Ley Emiliani: algunos se corren al lunes; Semana Santa según la Pascua).
 * - Sábado cuenta como hábil si así se configura (las transportadoras suelen repartir los sábados).
 * - Pedidos después de la hora de corte empiezan a contar el siguiente día hábil.
 * Ajustes: ship_prep_days (alistamiento, ej. "0-1"), ship_transit_days (transporte, ej. "2-3"),
 *          ship_cutoff_hour (ej. 14) y ship_saturday (1 = el sábado es hábil).
 */
final class Delivery
{
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /** Festivos de Colombia del año, como 'Y-m-d' => true. */
    public static function holidays(int $year): array
    {
        static $cache = [];
        if (isset($cache[$year])) return $cache[$year];
        $d = fn(int $m, int $day) => new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $m, $day));
        $nextMonday = fn(DateTimeImmutable $x) => (int) $x->format('N') === 1 ? $x : $x->modify('next monday');
        // Domingo de Pascua (algoritmo de Gauss / anónimo gregoriano)
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $dd = intdiv($b, 4); $e = $b % 4;
        $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $dd - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = $d($month, $day);

        $list = [$d(1, 1), $d(5, 1), $d(7, 20), $d(8, 7), $d(12, 8), $d(12, 25)];                      // fijos
        foreach ([[1, 6], [3, 19], [6, 29], [8, 15], [10, 12], [11, 1], [11, 11]] as [$mm, $dy]) {    // se corren al lunes
            $list[] = $nextMonday($d($mm, $dy));
        }
        $list[] = $easter->modify('-3 days');                      // Jueves Santo
        $list[] = $easter->modify('-2 days');                      // Viernes Santo
        $list[] = $nextMonday($easter->modify('+39 days'));        // Ascensión
        $list[] = $nextMonday($easter->modify('+60 days'));        // Corpus Christi
        $list[] = $nextMonday($easter->modify('+68 days'));        // Sagrado Corazón
        $out = [];
        foreach ($list as $x) { $out[$x->format('Y-m-d')] = true; }
        return $cache[$year] = $out;
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
