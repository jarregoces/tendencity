<?php
declare(strict_types=1);

/**
 * Analítica propia, medida en el servidor (no la bloquean los bloqueadores de anuncios).
 *
 * Clasificación del canal, en este orden:
 *  1. UTM con medium de pago (cpc, paid, ads…) o gclid/fbclid+utm → pagado
 *  2. utm_medium = email → email
 *  3. Otros UTM → según utm_source (social u otro)
 *  4. Referrer de buscador → orgánico (SEO)
 *  5. Referrer de red social o fbclid sin UTM → social (orgánico de redes)
 *  6. Otro referrer → referido
 *  7. Nada → directo (incluye WhatsApp, que no manda referrer)
 *
 * Para que Meta Ads se mida como pagado, en el anuncio pon estos parámetros de URL:
 *   utm_source=meta&utm_medium=paid&utm_campaign={{campaign.name}}&utm_content={{ad.name}}
 */
final class Tracker
{
    private const SESSION_TTL = 1800;           // 30 min
    private const VISITOR_TTL = 31536000;       // 1 año
    private const ATTRIBUTION_DAYS = 30;

    private const SEARCH = ['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'ecosia.', 'search.brave.', 'yandex.'];
    private const SOCIAL = ['facebook.', 'fb.', 'instagram.', 'tiktok.', 't.co', 'twitter.', 'x.com', 'youtube.',
                            'pinterest.', 'linkedin.', 'threads.', 'whatsapp.'];
    private const PAID_MEDIUMS = ['cpc', 'ppc', 'paid', 'paid_social', 'paidsocial', 'ads', 'cpm', 'cpv', 'display', 'pagado'];
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|preview|lighthouse|pingdom|curl|wget|python|headless|monitor/i';

    public function __construct(private PDO $db) {}

    public static function isBot(): bool
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return $ua === '' || preg_match(self::BOTS, $ua) === 1;
    }

    /** Registra la vista. Debe llamarse antes de enviar salida (index.php usa ob_start). */
    public function hit(string $path, ?int $productId = null): void
    {
        if (self::isBot() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        try {
            $now = date('Y-m-d H:i:s');
            $visitor = $this->cookie('td_v') ?? bin2hex(random_bytes(8));
            $session = $this->cookie('td_s');
            $touch = $this->classify();

            // Una nueva fuente con UTM o referrer externo abre sesión nueva aunque la anterior siga viva
            $newSource = $touch['channel'] !== 'directo' && $session && !$this->sameSource($session, $touch);
            if (!$session || $newSource) {
                $session = bin2hex(random_bytes(8));
                $this->db->prepare(
                    'INSERT INTO visits (session_id, visitor_id, started_at, last_at, landing_path, channel, source, medium,
                                         campaign, content, term, referrer_host, device)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$session, $visitor, $now, $now, mb_substr($path, 0, 255), $touch['channel'], $touch['source'],
                    $touch['medium'], $touch['campaign'], $touch['content'], $touch['term'], $touch['referrer_host'], $this->device()]);
            } else {
                $this->db->prepare('UPDATE visits SET last_at = ?, pageviews = pageviews + 1 WHERE session_id = ?')
                    ->execute([$now, $session]);
            }
            $this->db->prepare('INSERT INTO pageviews (session_id, path, product_id, at) VALUES (?, ?, ?, ?)')
                ->execute([$session, mb_substr($path, 0, 255), $productId, $now]);

            $this->setCookie('td_v', $visitor, self::VISITOR_TTL);
            $this->setCookie('td_s', $session, self::SESSION_TTL);
        } catch (Throwable $e) {
            error_log('[tracker] ' . $e->getMessage()); // la medición nunca rompe la página
        }
    }

    public function event(string $type, ?int $productId): void
    {
        $session = $this->cookie('td_s');
        if (!$session || self::isBot()) {
            return;
        }
        $this->db->prepare('INSERT IGNORE INTO events (session_id, type, product_id, at) VALUES (?, ?, ?, NOW())')
            ->execute([$session, mb_substr($type, 0, 30), $productId]);
    }

    /**
     * Atribución del pedido: último toque NO directo del visitante en 30 días.
     * Si solo hubo visitas directas, queda directo.
     */
    public function attribution(): array
    {
        $empty = ['session_id' => null, 'channel' => 'directo', 'source' => null, 'medium' => null,
                  'campaign' => null, 'content' => null, 'landing_path' => null];
        $visitor = $this->cookie('td_v');
        $session = $this->cookie('td_s');
        if (!$visitor) {
            return $empty;
        }
        $st = $this->db->prepare(
            "SELECT session_id, channel, source, medium, campaign, content, landing_path FROM visits
             WHERE visitor_id = ? AND channel <> 'directo' AND started_at > NOW() - INTERVAL " . self::ATTRIBUTION_DAYS . ' DAY
             ORDER BY started_at DESC LIMIT 1'
        );
        $st->execute([$visitor]);
        $row = $st->fetch();
        if ($row) {
            return ['session_id' => $session ?? $row['session_id']] + $row;
        }
        $st = $this->db->prepare('SELECT session_id, channel, source, medium, campaign, content, landing_path FROM visits WHERE session_id = ?');
        $st->execute([$session]);
        return $st->fetch() ?: $empty;
    }

    /** Clasifica la visita actual según UTM, click ids y referrer. */
    public function classify(): array
    {
        $g = fn(string $k) => ($v = trim((string) ($_GET[$k] ?? ''))) !== '' ? mb_substr(strtolower($v), 0, 150) : null;
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $refHost = $ref ? strtolower((string) parse_url($ref, PHP_URL_HOST)) : null;
        $ownHost = strtolower((string) parse_url((string) env('SITE_URL', ''), PHP_URL_HOST));
        if ($refHost && ($refHost === $ownHost || $refHost === ($_SERVER['HTTP_HOST'] ?? ''))) {
            $refHost = null; // navegación interna
        }
        $out = ['channel' => 'directo', 'source' => null, 'medium' => $g('utm_medium'), 'campaign' => $g('utm_campaign'),
                'content' => $g('utm_content'), 'term' => $g('utm_term'), 'referrer_host' => $refHost];
        $source = $g('utm_source');

        if ($source || $out['medium']) {
            $out['source'] = $this->normalizeSource($source ?? $refHost ?? 'otro');
            if (in_array($out['medium'], self::PAID_MEDIUMS, true) || isset($_GET['gclid'])) {
                $out['channel'] = 'pagado';
            } elseif ($out['medium'] === 'email') {
                $out['channel'] = 'email';
            } else {
                $out['channel'] = $this->matches($out['source'], self::SOCIAL) || in_array($out['source'], ['meta', 'facebook', 'instagram', 'tiktok', 'whatsapp'], true)
                    ? 'social' : 'referido';
            }
            return $out;
        }
        if (isset($_GET['gclid']) || isset($_GET['wbraid']) || isset($_GET['gbraid'])) {
            return ['channel' => 'pagado', 'source' => 'google', 'medium' => 'cpc'] + $out;
        }
        if (isset($_GET['ttclid'])) {
            return ['channel' => 'pagado', 'source' => 'tiktok', 'medium' => 'paid'] + $out;
        }
        if ($refHost && $this->matches($refHost, self::SEARCH)) {
            return ['channel' => 'organico', 'source' => $this->normalizeSource($refHost), 'medium' => 'organic'] + $out;
        }
        if (($refHost && $this->matches($refHost, self::SOCIAL)) || isset($_GET['fbclid'])) {
            return ['channel' => 'social', 'source' => $this->normalizeSource($refHost ?? 'facebook'), 'medium' => 'social'] + $out;
        }
        if ($refHost) {
            return ['channel' => 'referido', 'source' => preg_replace('/^www\./', '', $refHost), 'medium' => 'referral'] + $out;
        }
        return $out;
    }

    private function normalizeSource(string $s): string
    {
        $s = strtolower($s);
        $map = ['google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo', 'duckduckgo' => 'duckduckgo',
                'instagram' => 'instagram', 'ig' => 'instagram', 'facebook' => 'facebook', 'fb' => 'facebook',
                'meta' => 'meta', 'tiktok' => 'tiktok', 'youtube' => 'youtube', 't.co' => 'x', 'twitter' => 'x',
                'whatsapp' => 'whatsapp', 'wa' => 'whatsapp', 'pinterest' => 'pinterest'];
        foreach ($map as $needle => $name) {
            if ($s === $needle || str_contains($s, $needle . '.') || str_starts_with($s, $needle)) {
                return $name;
            }
        }
        return mb_substr(preg_replace('/^(www|l|m|lm)\./', '', $s), 0, 80);
    }

    private function sameSource(string $session, array $touch): bool
    {
        $st = $this->db->prepare('SELECT channel, source, campaign FROM visits WHERE session_id = ?');
        $st->execute([$session]);
        $v = $st->fetch();
        return $v && $v['channel'] === $touch['channel'] && $v['source'] === $touch['source'] && $v['campaign'] === $touch['campaign'];
    }

    private function matches(?string $host, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($host && str_contains($host, $n)) {
                return true;
            }
        }
        return false;
    }

    private function device(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/ipad|tablet/i', $ua)) return 'tablet';
        if (preg_match('/mobi|android|iphone/i', $ua)) return 'movil';
        return 'escritorio';
    }

    private function cookie(string $name): ?string
    {
        $v = $_COOKIE[$name] ?? null;
        return is_string($v) && preg_match('/^[a-f0-9]{16}$/', $v) ? $v : null;
    }

    private function setCookie(string $name, string $value, int $ttl): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, ['expires' => time() + $ttl, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[$name] = $value;
    }
}
