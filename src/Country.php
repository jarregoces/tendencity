<?php
declare(strict_types=1);

/**
 * Perfil del país de esta instalación. Se elige con COUNTRY en el .env (CO o GT).
 * Cada país es una instalación separada (su propio .env, base de datos y panel);
 * el código es el mismo. Para agregar un país, copia un bloque de PROFILES.
 */
final class Country
{
    private const PROFILES = [
        'CO' => [
            'code'          => 'CO',
            'name'          => 'Colombia',
            'currency'      => 'COP',
            'currency_name' => 'pesos colombianos',
            'symbol'        => '$',
            'thousands'     => '.',
            'locale'        => 'es-CO',
            'og_locale'     => 'es_CO',
            'timezone'      => 'America/Bogota',
            'phone_code'    => '57',
            'phone_digits'  => 10,
            'phone_pattern' => '3[0-9]{9}',
            'phone_example' => '3001234567',
            'round_step'    => 100,     // descuentos redondeados a $100
            'price_step'    => 1000,    // la calculadora cierra en …900
            'price_back'    => 100,
            'pia_methods'   => 'Nequi, Daviplata o transferencia',
            'region_label'  => 'Departamento',
            'city_label'    => 'Ciudad o municipio',
            'district_label'=> 'Barrio',
            'address_example' => 'Calle 10 # 20-30',
            'regions' => ['Amazonas','Antioquia','Arauca','Atlántico','Bogotá D.C.','Bolívar','Boyacá','Caldas','Caquetá','Casanare','Cauca','Cesar','Chocó','Córdoba','Cundinamarca','Guainía','Guaviare','Huila','La Guajira','Magdalena','Meta','Nariño','Norte de Santander','Putumayo','Quindío','Risaralda','San Andrés y Providencia','Santander','Sucre','Tolima','Valle del Cauca','Vaupés','Vichada'],
        ],
        'GT' => [
            'code'          => 'GT',
            'name'          => 'Guatemala',
            'currency'      => 'GTQ',
            'currency_name' => 'quetzales',
            'symbol'        => 'Q',
            'thousands'     => ',',
            'locale'        => 'es-GT',
            'og_locale'     => 'es_GT',
            'timezone'      => 'America/Guatemala',
            'phone_code'    => '502',
            'phone_digits'  => 8,
            'phone_pattern' => '[2-7][0-9]{7}',
            'phone_example' => '55551234',
            'round_step'    => 1,       // descuentos redondeados al quetzal
            'price_step'    => 10,      // la calculadora cierra en …9 (Q199, Q249)
            'price_back'    => 1,
            'pia_methods'   => 'transferencia o depósito bancario',
            'region_label'  => 'Departamento',
            'city_label'    => 'Municipio',
            'district_label'=> 'Zona o colonia',
            'address_example' => '5a avenida 10-20, zona 1',
            'regions' => ['Alta Verapaz','Baja Verapaz','Chimaltenango','Chiquimula','El Progreso','Escuintla','Guatemala','Huehuetenango','Izabal','Jalapa','Jutiapa','Petén','Quetzaltenango','Quiché','Retalhuleu','Sacatepéquez','San Marcos','Santa Rosa','Sololá','Suchitepéquez','Totonicapán','Zacapa'],
        ],
    ];

    public static function code(): string
    {
        $c = strtoupper((string) env('COUNTRY', 'CO'));
        return isset(self::PROFILES[$c]) ? $c : 'CO';
    }

    public static function get(string $key): mixed
    {
        return self::PROFILES[self::code()][$key] ?? null;
    }

    public static function name(): string { return self::get('name'); }
    public static function currency(): string { return self::get('currency'); }
    public static function isCO(): bool { return self::code() === 'CO'; }

    /** "a toda Colombia" / "a toda Guatemala" */
    public static function toAll(): string
    {
        return 'a toda ' . self::name();
    }

    /** Teléfono nacional válido para el país (solo dígitos). */
    public static function validPhone(string $digits): bool
    {
        return (bool) preg_match('/^' . self::get('phone_pattern') . '$/', $digits);
    }

    /** Teléfono con indicativo, sin "+": 573001234567 / 50255551234 */
    public static function intlPhone(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);
        $cc = self::get('phone_code');
        return strlen($d) === self::get('phone_digits') ? $cc . $d : $d;
    }

    /** Festivos del año como 'Y-m-d' => true (días en que no se reparte). */
    public static function holidays(int $year): array
    {
        static $cache = [];
        $key = self::code() . $year;
        if (isset($cache[$key])) return $cache[$key];

        $d = fn(int $m, int $day) => new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $m, $day));
        $easter = $d(3, 21)->modify('+' . easter_days_safe($year) . ' days');

        if (self::code() === 'GT') {
            // Código de Trabajo de Guatemala, art. 127 (los medios días se cuentan completos: no hay reparto)
            $list = [$d(1, 1), $d(5, 1), $d(6, 30), $d(9, 15), $d(10, 20), $d(11, 1), $d(12, 24), $d(12, 25), $d(12, 31),
                     $easter->modify('-3 days'), $easter->modify('-2 days'), $easter->modify('-1 day')];
        } else {
            $nextMonday = fn(DateTimeImmutable $x) => (int) $x->format('N') === 1 ? $x : $x->modify('next monday');
            $list = [$d(1, 1), $d(5, 1), $d(7, 20), $d(8, 7), $d(12, 8), $d(12, 25)];                      // fijos
            foreach ([[1, 6], [3, 19], [6, 29], [8, 15], [10, 12], [11, 1], [11, 11]] as [$mm, $dy]) {    // Ley Emiliani
                $list[] = $nextMonday($d($mm, $dy));
            }
            $list[] = $easter->modify('-3 days');                      // Jueves Santo
            $list[] = $easter->modify('-2 days');                      // Viernes Santo
            $list[] = $nextMonday($easter->modify('+39 days'));        // Ascensión
            $list[] = $nextMonday($easter->modify('+60 days'));        // Corpus Christi
            $list[] = $nextMonday($easter->modify('+68 days'));        // Sagrado Corazón
        }
        $out = [];
        foreach ($list as $x) { $out[$x->format('Y-m-d')] = true; }
        return $cache[$key] = $out;
    }
}

/** Días desde el 21 de marzo hasta el domingo de Pascua (sin depender de la extensión calendar). */
function easter_days_safe(int $year): int
{
    $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $dd = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $dd - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return (int) (new DateTimeImmutable("$year-03-21"))->diff(new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->days;
}
