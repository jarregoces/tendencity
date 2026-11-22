<?php
declare(strict_types=1);

/**
 * Cliente de la API pública de Mastershop.
 * Doc: https://documenter.getpostman.com/view/33212980/2sB3WnxNAk
 *
 * - Autenticación: header ms-api-key.
 * - 429: reintenta esperando RateLimit-Reset.
 * - Los límites >50 se recortan en silencio, así que se fuerza máximo 50.
 */
final class MastershopClient
{
    private const MAX_LIMIT = 50;

    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://prod.api.mastershop.com',
        private int $timeout = 30,
        private int $maxRetries = 3,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /* ---------------- Productos ---------------- */

    /** Catálogo del negocio. page empieza en 1. */
    public function listProducts(int $page = 1, int $limit = 50, ?string $category = null, ?string $search = null): array
    {
        return $this->request('GET', '/api/products', array_filter([
            'page'     => max(1, $page),
            'limit'    => min(self::MAX_LIMIT, $limit),
            'category' => $category,
            'search'   => $search,
        ], fn($v) => $v !== null));
    }

    public function getProduct(int $id, bool $allVariations = true): array
    {
        return $this->request('GET', "/api/products/{$id}", [
            'includeAllVariations' => $allVariations ? 'true' : 'false',
        ]);
    }

    /**
     * Marketplace. OJO: aquí page es un DESPLAZAMIENTO EN FILAS (0, 50, 100...),
     * no un número de página. Este método recibe $offset para que no haya confusión.
     */
    public function searchMarket(array $filters = [], int $offset = 0, int $limit = 50): array
    {
        return $this->request('POST', '/api/products/market', [
            'page'  => max(0, $offset),
            'limit' => min(self::MAX_LIMIT, $limit),
        ], $filters ?: null);
    }

    public function getMarketProduct(int $id): array
    {
        return $this->request('GET', "/api/products/market/{$id}");
    }

    /* ---------------- Pedidos ---------------- */

    public function createOrder(array $payload): array
    {
        return $this->request('POST', '/api/orders', [], $payload);
    }

    /** Uno de: idOrder, externalOrderId (sin guion bajo), carrierTrackingCode. */
    public function getOrder(array $by): array
    {
        $by = array_intersect_key($by, array_flip(['idOrder', 'externalOrderId', 'carrierTrackingCode']));
        if (!$by) {
            throw new InvalidArgumentException('getOrder requiere idOrder, externalOrderId o carrierTrackingCode');
        }
        return $this->request('GET', '/api/orders', $by);
    }

    /**
     * Sin filtros Mastershop solo devuelve los últimos 7 días SIN avisar.
     * Por eso se exige startDate.
     */
    public function listOrders(string $startDate, array $filters = [], int $page = 1, int $limit = 50): array
    {
        $filters['startDate'] = $startDate;
        return $this->request('POST', '/api/orders/list', [
            'page'  => max(1, $page),
            'limit' => min(self::MAX_LIMIT, $limit),
        ], $filters);
    }

    /** Solo en estado 1 o 2. Edición parcial; una dirección viaja completa o no viaja. */
    public function updateOrder(int $idOrder, array $changes): array
    {
        return $this->request('PUT', "/api/orders/{$idOrder}", [], $changes);
    }

    /** Cancelar es exactamente {"idStatus": 9}. */
    public function cancelOrder(int $idOrder): array
    {
        return $this->updateOrder($idOrder, ['idStatus' => 9]);
    }

    /** Solo para pedidos en estado 10 (Devuelta). 404 en cualquier otro caso. */
    public function getReturn(int $idOrder): array
    {
        return $this->request('GET', "/api/orders/return/{$idOrder}");
    }

    /* ---------------- Webhooks ---------------- */

    /** Guarda el idProductWebhook de la respuesta: no hay forma de listarlo después. */
    public function createWebhook(int $idWebhook, string $url, string $name, ?int $idProduct = null): array
    {
        return $this->request('POST', '/api/webhooks', [], [
            'idWebhook'   => $idWebhook,
            'url'         => $url,
            'name'        => $name,
            'webhookName' => $name,
            'idProduct'   => $idProduct,
        ]);
    }

    public function setWebhookEnabled(int $idProductWebhook, bool $enabled, ?int $idProduct = null): array
    {
        return $this->request('PUT', '/api/webhooks', [], [
            'idProductWebhook' => $idProductWebhook,
            'idProduct'        => $idProduct,
            'isEnable'         => $enabled ? 1 : 0,
        ]);
    }

    /* ---------------- Billetera ---------------- */

    public function listWalletMovements(array $filters = [], int $page = 1, int $limit = 50): array
    {
        return $this->request('POST', '/api/wallets/movements/list', [
            'page'  => max(1, $page),
            'limit' => min(self::MAX_LIMIT, $limit),
        ], $filters ?: null);
    }

    /* ---------------- HTTP ---------------- */

    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = [
            'ms-api-key: ' . $this->apiKey,
            'Accept: application/json',
            // Sin User-Agent algunos firewalls responden 403 "Forbidden" aunque la llave sea válida
            'User-Agent: Mozilla/5.0 (compatible; TendencityStore/1.0; +' . (env('SITE_URL') ?: 'https://tendencity.store') . ')',
            'Accept-Language: es-CO,es;q=0.9',
        ];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }

        for ($attempt = 0; ; $attempt++) {
            $respHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                if ($attempt < $this->maxRetries) {
                    sleep(2 ** $attempt);
                    continue;
                }
                throw new MastershopException("Error de red: {$err}", 0, null);
            }

            $data = json_decode($raw, true);

            if ($status === 429 && $attempt < $this->maxRetries) {
                $wait = (int) ($respHeaders['ratelimit-reset'] ?? 0);
                sleep(max(1, min(60, $wait)));
                continue;
            }
            if ($status >= 500 && $attempt < $this->maxRetries && $method === 'GET') {
                sleep(2 ** $attempt);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                $msg = is_array($data) ? ($data['error'] ?? $data['message'] ?? $raw) : $raw;
                throw new MastershopException(
                    "Mastershop {$method} {$path} respondió {$status}: " . (is_string($msg) ? $msg : json_encode($msg)),
                    $status,
                    is_array($data) ? $data : null
                );
            }

            return is_array($data) ? $data : [];
        }
    }

    /**
     * Las respuestas pueden venir envueltas ({data: ...}) o directas.
     * Usa esto para obtener el contenido útil sin depender del sobre.
     */
    public static function unwrap(array $response): mixed
    {
        return array_key_exists('data', $response) ? $response['data'] : $response;
    }
}

final class MastershopException extends RuntimeException
{
    public function __construct(string $message, int $status, public readonly ?array $body)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
