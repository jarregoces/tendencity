<?php
declare(strict_types=1);

/**
 * Flujo de pedidos:
 *  1. createLocalOrder(): valida carrito contra la BD (nunca confía en precios del navegador),
 *     guarda el pedido y genera el external_id (YB-000123).
 *  2. Contraentrega (cod): se envía a Mastershop de inmediato.
 *     Pago anticipado (pia): se envía solo cuando la pasarela confirma el pago (markPaidAndPush).
 *  3. pushToMastershop(): arma el payload, valida las 3 reglas que el esquema no expresa y crea el pedido.
 *     Si falla queda en 'error' y cli/retry_orders.php lo reintenta sin duplicar.
 */
final class OrderService
{
    public function __construct(private PDO $db, private MastershopClient $ms) {}

    /**
     * @param array $cart     [['variant_id' => int (id local de product_variants), 'qty' => int], ...]
     * @param array $customer first_name, last_name, phone, email, document_type?, document_number?
     * @param array $address  address1, address2?, city, state, district?, zip?
     * @param int   $discountPct descuento total ya calculado en el servidor (oferta por cantidad + pago anticipado)
     * @return int id local del pedido
     */
    public function createLocalOrder(
        array $cart,
        array $customer,
        array $address,
        string $paymentMethod,
        int $shippingCharge = 0,
        ?string $notes = null,
        int $discountPct = 0,
    ): int {
        if (!in_array($paymentMethod, ['cod', 'pia'], true)) {
            throw new InvalidArgumentException('Método de pago inválido');
        }
        if (!$cart) {
            throw new InvalidArgumentException('Carrito vacío');
        }
        $customer = $this->normalizeCustomer($customer);
        $address  = $this->normalizeAddress($address, $customer);

        $this->db->beginTransaction();
        try {
            $items = [];
            $subtotal = 0;
            $stmt = $this->db->prepare(
                'SELECT v.id, v.product_id, v.ms_variant_id, v.sku, v.name AS vname, v.price AS vprice, v.stock, v.weight AS vweight,
                        p.ms_product_id, p.name AS pname, p.price AS pprice, p.cost AS pcost, p.weight AS pweight, p.is_active
                 FROM product_variants v JOIN products p ON p.id = v.product_id
                 WHERE v.id = ? FOR UPDATE'
            );
            foreach ($cart as $line) {
                $qty = (int) ($line['qty'] ?? 0);
                if ($qty < 1) {
                    throw new InvalidArgumentException('Cantidad inválida');
                }
                $stmt->execute([(int) $line['variant_id']]);
                $row = $stmt->fetch();
                if (!$row || !$row['is_active']) {
                    throw new DomainException('Un producto del carrito ya no está disponible');
                }
                if ($row['stock'] < $qty) {
                    throw new DomainException("Sin stock suficiente para {$row['pname']}");
                }
                $price = Pricing::discounted((int) ($row['vprice'] ?? $row['pprice']), $discountPct);
                $name  = $row['vname'] && $row['vname'] !== $row['pname']
                    ? "{$row['pname']} - {$row['vname']}" : $row['pname'];
                $items[] = [
                    'product_id'    => (int) $row['product_id'],
                    'variant_id'    => (int) $row['id'],
                    'ms_product_id' => $row['ms_product_id'] !== null ? (int) $row['ms_product_id'] : null,
                    'ms_variant_id' => $row['ms_variant_id'] !== null ? (int) $row['ms_variant_id'] : null,
                    'sku'           => $row['sku'],
                    'name'          => $name,
                    'quantity'      => $qty,
                    'unit_price'    => $price,
                    'weight'        => $row['vweight'] ?? $row['pweight'],
                    'unit_cost'     => $row['pcost'] !== null ? (int) $row['pcost'] : null,
                ];
                $subtotal += $price * $qty; // enteros: sin errores de coma flotante
            }

            $total = $subtotal + $shippingCharge;
            $this->db->prepare(
                'INSERT INTO orders (payment_method, payment_status, subtotal, shipping_charge, total, currency,
                                     customer, shipping_address, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $paymentMethod,
                $paymentMethod === 'cod' ? 'contraentrega' : 'pendiente',
                $subtotal, $shippingCharge, $total, env('CURRENCY', 'COP'),
                json_encode($customer, JSON_UNESCAPED_UNICODE),
                json_encode($address, JSON_UNESCAPED_UNICODE),
                $notes,
            ]);
            $orderId = (int) $this->db->lastInsertId();

            // Solo letras, números y guiones: el GET por externalOrderId rechaza el guion bajo.
            $externalId = sprintf('%s-%06d', preg_replace('/[^A-Za-z0-9-]/', '', env('ORDER_PREFIX', 'TD')), $orderId);
            $this->db->prepare('UPDATE orders SET external_id = ? WHERE id = ?')->execute([$externalId, $orderId]);

            $ins = $this->db->prepare(
                'INSERT INTO order_items (order_id, product_id, variant_id, ms_product_id, ms_variant_id, sku, name, quantity, unit_price, weight, unit_cost)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $dec = $this->db->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ?');
            $decP = $this->db->prepare('UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ? AND ms_product_id IS NULL');
            foreach ($items as $it) {
                $ins->execute([$orderId, $it['product_id'], $it['variant_id'], $it['ms_product_id'], $it['ms_variant_id'], $it['sku'], $it['name'],
                               $it['quantity'], $it['unit_price'], $it['weight'], $it['unit_cost']]);
                $dec->execute([$it['quantity'], $it['variant_id']]); // reserva local; la sync corrige los vinculados
                $decP->execute([$it['quantity'], $it['product_id']]); // productos propios: su stock vive en la tienda
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($paymentMethod === 'cod') {
            $this->pushToMastershop($orderId); // si falla queda en 'error' para reintento
        }
        return $orderId;
    }

    /** Llamar desde el webhook/confirmación de tu pasarela (Wompi, Mercado Pago...). */
    public function markPaidAndPush(int $orderId, string $gateway, string $reference): bool
    {
        $this->db->prepare(
            "UPDATE orders SET payment_status = 'pagado', payment_gateway = ?, payment_reference = ?
             WHERE id = ? AND payment_method = 'pia'"
        )->execute([$gateway, $reference, $orderId]);
        return $this->pushToMastershop($orderId);
    }

    /** Crea el pedido en Mastershop. Idempotente: si ya existe allá, solo lo enlaza. */
    public function pushToMastershop(int $orderId): bool
    {
        $order = $this->db->query('SELECT * FROM orders WHERE id = ' . (int) $orderId)->fetch();
        if (!$order || $order['sync_status'] === 'enviado') {
            return (bool) $order;
        }
        if ($order['payment_method'] === 'pia' && $order['payment_status'] !== 'pagado') {
            return false; // no se despacha sin pago confirmado
        }
        // Si el producto se vinculó después de hacer el pedido, se toman los IDs de Mastershop actuales
        $this->db->exec('UPDATE order_items i
            JOIN product_variants v ON v.id = i.variant_id
            JOIN products p ON p.id = v.product_id
            SET i.ms_product_id = p.ms_product_id, i.ms_variant_id = v.ms_variant_id
            WHERE i.order_id = ' . (int) $orderId . ' AND (i.ms_product_id IS NULL OR i.ms_variant_id IS NULL)');
        $unlinked = $this->db->query('SELECT GROUP_CONCAT(name SEPARATOR ", ") FROM order_items WHERE order_id = ' . (int) $orderId
            . ' AND (ms_product_id IS NULL OR ms_variant_id IS NULL)')->fetchColumn();
        if ($unlinked) {
            $this->db->prepare("UPDATE orders SET sync_status = 'error', sync_error = ? WHERE id = ?")->execute([
                "Despacho manual: {$unlinked} no está vinculado a Mastershop. Despáchalo por tu cuenta, o vincula el producto (mismo SKU o ID de Mastershop) y pulsa Reintentar.",
                $orderId]);
            return false;
        }
        // Productos de prueba: el pedido queda guardado pero nunca se envía a Mastershop
        $demo = (int) $this->db->query('SELECT COUNT(*) FROM order_items WHERE order_id = ' . (int) $orderId
            . ' AND ms_product_id > ' . DemoSeeder::ID_FROM)->fetchColumn();
        if ($demo) {
            $this->db->prepare("UPDATE orders SET sync_status = 'error', sync_error = ? WHERE id = ?")
                ->execute(['Pedido de prueba: tiene productos de demostración y no se envía a Mastershop.', $orderId]);
            return false;
        }

        try {
            // Si un intento anterior se cayó a mitad de camino, puede que el pedido sí se haya creado.
            if ($order['sync_attempts'] > 0 && ($existing = $this->findRemoteByExternalId($order['external_id']))) {
                $this->markSent($orderId, $existing);
                return true;
            }

            $payload  = $this->buildPayload($order);
            $response = MastershopClient::unwrap($this->ms->createOrder($payload));
            $this->markSent($orderId, is_array($response) ? $response : []);
            return true;
        } catch (Throwable $e) {
            $this->db->prepare(
                "UPDATE orders SET sync_status = 'error', sync_error = ?, sync_attempts = sync_attempts + 1 WHERE id = ?"
            )->execute([mb_substr($e->getMessage(), 0, 2000), $orderId]);
            error_log("[mastershop] pedido {$orderId}: " . $e->getMessage());
            return false;
        }
    }

    public function buildPayload(array $order): array
    {
        $customer = json_decode($order['customer'], true);
        $address  = json_decode($order['shipping_address'], true);
        $items    = $this->db->query('SELECT * FROM order_items WHERE order_id = ' . (int) $order['id'])->fetchAll();

        $orderItems = [];
        $sum = 0;
        foreach ($items as $it) {
            $orderItems[] = [
                'id_product' => (int) $it['ms_product_id'],
                'id_variant' => (int) $it['ms_variant_id'],
                'quantity'   => (int) $it['quantity'],
                'sku'        => (string) ($it['sku'] ?? ''),
                'name'       => $it['name'],
                'weight'     => $it['weight'] !== null ? (float) $it['weight'] : 1,
                'price'      => (int) $it['unit_price'],
            ];
            $sum += (int) $it['unit_price'] * (int) $it['quantity'];
        }

        $charges = [];
        if ((int) $order['shipping_charge'] > 0) {
            // VERIFICAR con Mastershop el valor exacto de type_charge para el flete.
            $charges[] = ['type_charge' => 'shipping', 'value' => (int) $order['shipping_charge']];
        }
        $sum += array_sum(array_column($charges, 'value'));

        // Regla 1: total exacto = Σ price×quantity + Σ additional_charge.value
        if ($sum !== (int) $order['total']) {
            throw new DomainException("Total descuadrado: calculado {$sum}, guardado {$order['total']}");
        }

        $docType = $customer['documentType'] ?? null;
        $docNum  = $customer['documentNumber'] ?? null;
        // Regla 3: documento y tipo van los dos o ninguno.
        if (!$docType || !$docNum) {
            $docType = $docNum = null;
        }

        return [
            'id_order'          => $order['external_id'],
            'shipping_address'  => $address,
            'billing_address'   => $address,
            'order_transaction' => [
                'total'           => (int) $order['total'],
                'currency'        => $order['currency'], // Regla 2: COP para CO
                'payment_method'  => $order['payment_method'],
                'payment_gateway' => $order['payment_gateway'],
            ],
            'customer' => [
                'full_name'      => $customer['full_name'],
                'first_name'     => $customer['first_name'],
                'last_name'      => $customer['last_name'],
                'phone'          => $customer['phone'],
                'email'          => $customer['email'],
                'documentType'   => $docType,
                'documentNumber' => $docNum,
                'tags'           => [],
            ],
            'order_items'       => $orderItems,
            'additional_charge' => $charges,
            'notes'             => $order['notes'] ? [$order['notes']] : [],
            'tags'              => ['tienda-web'],
        ];
    }

    /* ---------------- helpers ---------------- */

    private function markSent(int $orderId, array $remote): void
    {
        $msId = $remote['idOrder'] ?? $remote['id_order_mastershop'] ?? $remote['id'] ?? null;
        if ($msId === null && isset($remote['id_order']) && is_numeric($remote['id_order'])) {
            $msId = $remote['id_order'];
        }
        $this->db->prepare(
            "UPDATE orders SET sync_status = 'enviado', sync_error = NULL, ms_order_id = ?,
                    ms_status_id = COALESCE(?, ms_status_id)
             WHERE id = ?"
        )->execute([$msId, $remote['idStatus'] ?? null, $orderId]);
    }

    private function findRemoteByExternalId(string $externalId): ?array
    {
        try {
            $r = MastershopClient::unwrap($this->ms->getOrder(['externalOrderId' => $externalId]));
            if (is_array($r) && array_is_list($r)) {
                $r = $r[0] ?? null;
            }
            return $r ?: null;
        } catch (MastershopException $e) {
            if (in_array($e->status(), [400, 404], true)) {
                return null;
            }
            throw $e;
        }
    }

    private function normalizeCustomer(array $c): array
    {
        $first = $this->cleanName($c['first_name'] ?? '');
        $last  = $this->cleanName($c['last_name'] ?? '');
        $phone = preg_replace('/\D/', '', (string) ($c['phone'] ?? ''));
        $email = filter_var(trim((string) ($c['email'] ?? '')), FILTER_VALIDATE_EMAIL);

        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('Nombre y apellido son obligatorios (solo letras)');
        }
        if (strlen($phone) < 10) {
            throw new InvalidArgumentException('Escribe un celular de 10 dígitos');
        }
        if (!$email) {
            if (trim((string) ($c['email'] ?? '')) !== '') {
                throw new InvalidArgumentException('Revisa el correo electrónico');
            }
            // El correo es opcional en el formulario; Mastershop lo pide, así que va uno de respaldo.
            $email = (string) env('FALLBACK_EMAIL', 'pedidos@tendencity.store');
        }
        $docType = trim((string) ($c['document_type'] ?? '')) ?: null;
        $docNum  = preg_replace('/[^0-9A-Za-z]/', '', (string) ($c['document_number'] ?? '')) ?: null;

        return [
            'first_name'     => $first,
            'last_name'      => $last,
            'full_name'      => "{$first} {$last}",
            'phone'          => $phone,
            'email'          => $email,
            'documentType'   => ($docType && $docNum) ? strtoupper($docType) : null,
            'documentNumber' => ($docType && $docNum) ? $docNum : null,
        ];
    }

    /** Las claves que pide el PUT de Mastershop; se usan también al crear. */
    private function normalizeAddress(array $a, array $customer): array
    {
        $state = trim((string) ($a['state'] ?? ''));
        $city  = trim((string) ($a['city'] ?? ''));
        $addr1 = trim((string) ($a['address1'] ?? ''));
        if (mb_strlen($state) < 3 || $city === '' || $addr1 === '') {
            throw new InvalidArgumentException('Dirección, ciudad y departamento son obligatorios');
        }
        return [
            'address1'   => $addr1,
            'address2'   => trim((string) ($a['address2'] ?? '')) ?: null,
            'city'       => $city,
            'state'      => $state,
            'district'   => trim((string) ($a['district'] ?? '')) ?: null,
            'country'    => strtoupper(env('COUNTRY', 'CO')),
            'zip'        => trim((string) ($a['zip'] ?? '')) ?: null,
            'first_name' => $customer['first_name'],
            'last_name'  => $customer['last_name'],
            'full_name'  => $customer['full_name'],
            'phone'      => $customer['phone'],
            'company'    => null,
        ];
    }

    private function cleanName(string $s): string
    {
        // Mastershop acepta solo letras y espacios en los nombres.
        $s = preg_replace('/[^\p{L}\s]/u', '', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
