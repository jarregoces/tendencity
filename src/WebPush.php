<?php
declare(strict_types=1);

/**
 * Notificaciones push (Web Push + VAPID) sin librerías externas.
 *
 * Se envían "pushes vacíos" (sin contenido cifrado): al recibirlo, el service worker del celular
 * pide los datos a /admin/ventas.php con la sesión del dueño y arma el aviso. Así ningún dato de
 * clientes viaja por los servidores de Google o Apple, y no hace falta cifrar el mensaje.
 *
 * Las claves VAPID se generan solas la primera vez y se guardan en settings.
 */
final class WebPush
{
    /** Clave pública VAPID en base64url (la necesita el navegador para suscribirse). */
    public static function publicKey(PDO $db): string
    {
        return self::b64u(self::rawPublic(self::privateKey($db)));
    }

    public static function hasSubscribers(PDO $db): bool
    {
        try {
            return (int) $db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function subscribe(PDO $db, string $email, string $endpoint, string $userAgent): void
    {
        if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 1000) {
            throw new InvalidArgumentException('Suscripción inválida');
        }
        $db->prepare('INSERT INTO push_subscriptions (endpoint_hash, endpoint, admin_email, user_agent) VALUES (?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE admin_email = VALUES(admin_email), user_agent = VALUES(user_agent), fails = 0')
           ->execute([hash('sha256', $endpoint), $endpoint, $email, mb_substr($userAgent, 0, 255)]);
    }

    public static function unsubscribe(PDO $db, string $endpoint): void
    {
        $db->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?')->execute([hash('sha256', $endpoint)]);
    }

    /**
     * Avisa a todos los celulares suscritos. Borra las suscripciones que el servicio da por muertas.
     * @return array{sent:int, failed:int, removed:int}
     */
    public static function notifyAll(PDO $db): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'removed' => 0];
        $subs = $db->query('SELECT id, endpoint FROM push_subscriptions')->fetchAll();
        if (!$subs) {
            return $out;
        }
        $key = self::privateKey($db);
        foreach ($subs as $s) {
            $code = self::send($s['endpoint'], $key);
            if ($code >= 200 && $code < 300) {
                $out['sent']++;
                $db->prepare('UPDATE push_subscriptions SET last_ok = NOW(), fails = 0 WHERE id = ?')->execute([$s['id']]);
            } elseif (in_array($code, [404, 410], true)) {
                $out['removed']++;   // el celular canceló o desinstaló la app
                $db->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$s['id']]);
            } else {
                $out['failed']++;
                $db->prepare('UPDATE push_subscriptions SET fails = fails + 1 WHERE id = ?')->execute([$s['id']]);
                $db->exec('DELETE FROM push_subscriptions WHERE fails >= 20');
                error_log("[webpush] HTTP {$code} al enviar a " . parse_url($s['endpoint'], PHP_URL_HOST));
            }
        }
        return $out;
    }

    /** Envía un push vacío. Devuelve el código HTTP (0 si no hubo conexión). */
    public static function send(string $endpoint, OpenSSLAsymmetricKey $key): int
    {
        $u = parse_url($endpoint);
        $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $jwt = self::jwt($aud, $key);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: vapid t=' . $jwt . ', k=' . self::b64u(self::rawPublic($key)),
                'TTL: 3600',
                'Urgency: high',
                'Content-Length: 0',
            ],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    /* ---------------- VAPID ---------------- */

    private static function jwt(string $aud, OpenSSLAsymmetricKey $key): string
    {
        $sub = 'mailto:' . (env('FALLBACK_EMAIL') ?: 'pedidos@tendencity.shop');
        $data = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
              . '.' . self::b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el aviso (OpenSSL).');
        }
        return $data . '.' . self::b64u(self::derToRaw($der));
    }

    private static function privateKey(PDO $db): OpenSSLAsymmetricKey
    {
        static $key = null;
        if ($key) {
            return $key;
        }
        $pem = (string) $db->query("SELECT v FROM settings WHERE k = 'vapid_private'")->fetchColumn();
        if ($pem === '') {
            $new = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if (!$new || !openssl_pkey_export($new, $pem)) {
                throw new RuntimeException('El servidor no pudo crear las claves de notificación (OpenSSL sin curvas EC).');
            }
            // INSERT IGNORE: si dos pedidos llegan a la vez, gana la primera clave y ambos usan esa
            $db->prepare("INSERT IGNORE INTO settings (k, v) VALUES ('vapid_private', ?)")->execute([$pem]);
            $pem = (string) $db->query("SELECT v FROM settings WHERE k = 'vapid_private'")->fetchColumn();
        }
        return $key = openssl_pkey_get_private($pem);
    }

    /** Punto público sin comprimir (65 bytes: 0x04 || X || Y). */
    private static function rawPublic(OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];
        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** Firma ECDSA en DER → R||S de 64 bytes, como pide JWT ES256. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;                                   // 0x30 + longitud de la secuencia
        if (ord($der[1]) & 0x80) { $pos += ord($der[1]) & 0x7f; }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);             // 0x02 + longitud del entero
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $parts[] = str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $parts[0] . $parts[1];
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
