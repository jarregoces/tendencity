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
 *  Sin Mastershop (FULFILLMENT=dropi|manual): el pedido queda en 'pendiente_envio' (cola de despacho).
 *     Claude lo lee por /api/pedidos, lo crea en Dropi y lo marca con markDispatched().
 */
final class OrderService
{
    public function __construct(private PDO $db, private ?MastershopClient $ms = null) {}

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
        ?int $fixedTotal = null,   // precio fijo del paquete (oferta «2 por Q289»); solo con una línea en el carrito
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
                // Paquete a precio fijo: 289 en 2 unidades → 1 × 145 + 1 × 144 (enteros que suman exacto)
                $splits = [[$price, $qty]];
                if ($fixedTotal !== null && count($cart) === 1) {
                    $base = intdiv($fixedTotal, $qty);
                    $rem = $fixedTotal % $qty;
                    $splits = array_values(array_filter([[$base + 1, $rem], [$base, $qty - $rem]], fn($s) => $s[1] > 0));
                }
                foreach ($splits as [$price, $splitQty]) {
                $items[] = [
                    'product_id'    => (int) $row['product_id'],
                    'variant_id'    => (int) $row['id'],
                    'ms_product_id' => $row['ms_product_id'] !== null ? (int) $row['ms_product_id'] : null,
                    'ms_variant_id' => $row['ms_variant_id'] !== null ? (int) $row['ms_variant_id'] : null,
                    'sku'           => $row['sku'],
                    'name'          => $name,
                    'quantity'      => $splitQty,
                    'unit_price'    => $price,
                    'weight'        => $row['vweight'] ?? $row['pweight'],
                    'unit_cost'     => $row['pcost'] !== null ? (int) $row['pcost'] : null,
                ];
                $subtotal += $price * $splitQty; // enteros: sin errores de coma flotante
                }
            }

            $total = $subtotal + $shippingCharge;
            $this->db->prepare(
                'INSERT INTO orders (payment_method, payment_status, subtotal, shipping_charge, total, currency,
                                     customer, shipping_address, notes, confirm_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $paymentMethod,
                $paymentMethod === 'cod' ? 'contraentrega' : 'pendiente',
                $subtotal, $shippingCharge, $total, Country::currency(),
                json_encode($customer, JSON_UNESCAPED_UNICODE),
                json_encode($address, JSON_UNESCAPED_UNICODE),
                $notes,
                // Sin Mastershop, cada pedido se confirma por WhatsApp antes de ir a Dropi
                $this->ms ? 'confirmado' : 'por_confirmar',
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

        if ($paymentMethod === 'cod' && $this->ms) {
            $this->pushToMastershop($orderId); // si falla queda en 'error' para reintento
        }
        if (!$this->ms) {
            // Productos de prueba: el pedido se guarda pero nunca entra a la cola de Dropi
            $demo = (int) $this->db->query('SELECT COUNT(*) FROM order_items WHERE order_id = ' . (int) $orderId
                . ' AND ms_product_id > ' . DemoSeeder::ID_FROM)->fetchColumn();
            if ($demo) {
                $this->db->prepare("UPDATE orders SET sync_status = 'error', sync_error = ? WHERE id = ?")
                    ->execute(['Pedido de prueba: tiene productos de demostración y no se despacha.', $orderId]);
            }
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
        if (!$this->ms) {
            return true; // queda en la cola de despacho (Dropi / manual)
        }
        return $this->pushToMastershop($orderId);
    }

    /**
     * Pedidos listos para despachar fuera de Mastershop: contraentrega, o anticipado ya pagado,
     * que todavía no se han creado en el proveedor (Dropi).
     */
    public function dispatchQueue(int $limit = 50): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM orders
             WHERE sync_status = 'pendiente_envio' AND confirm_status = 'confirmado'
               AND (payment_method = 'cod' OR payment_status = 'pagado')
             ORDER BY id LIMIT " . max(1, min(200, $limit))
        );
        $st->execute();
        return $st->fetchAll();
    }

    /* ---------------- Confirmación por WhatsApp ---------------- */

    /** El cliente confirmó: el pedido pasa a «Por despachar». */
    public function confirm(int $orderId, string $by): bool
    {
        $st = $this->db->prepare("UPDATE orders SET confirm_status = 'confirmado', confirmed_at = NOW(), confirmed_by = ?
                                  WHERE id = ? AND confirm_status = 'por_confirmar'");
        $st->execute([mb_substr($by, 0, 120), $orderId]);
        return $st->rowCount() > 0;
    }

    /** No confirmó o canceló: nunca llega a Dropi y las unidades vuelven al inventario. */
    public function cancel(int $orderId, string $by): bool
    {
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT id FROM orders WHERE id = ? AND confirm_status <> 'cancelado' AND sync_status <> 'enviado' FOR UPDATE");
            $st->execute([$orderId]);
            if (!$st->fetchColumn()) {
                $this->db->rollBack();
                return false;   // ya cancelado, o ya está en Dropi (se cancela allá)
            }
            $items = $this->db->prepare('SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ?');
            $items->execute([$orderId]);
            $incV = $this->db->prepare('UPDATE product_variants SET stock = stock + ? WHERE id = ?');
            $incP = $this->db->prepare('UPDATE products SET stock = stock + ? WHERE id = ? AND ms_product_id IS NULL');
            foreach ($items as $it) {
                if ($it['variant_id']) { $incV->execute([$it['quantity'], $it['variant_id']]); }
                if ($it['product_id']) { $incP->execute([$it['quantity'], $it['product_id']]); }
            }
            $this->db->prepare("UPDATE orders SET confirm_status = 'cancelado', confirmed_at = NOW(), confirmed_by = ? WHERE id = ?")
                ->execute([mb_substr($by, 0, 120), $orderId]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Corrige la dirección que dio el cliente al confirmar. */
    public function updateAddress(int $orderId, array $in): void
    {
        $st = $this->db->prepare('SELECT shipping_address FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $a = json_decode((string) $st->fetchColumn(), true) ?: [];
        foreach (['address1' => 255, 'address2' => 255, 'district' => 120, 'city' => 120, 'state' => 120] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = trim((string) $in[$k]);
                $a[$k] = $v === '' ? null : mb_substr($v, 0, $max);
            }
        }
        if (empty($a['address1']) || empty($a['city']) || empty($a['state'])) {
            throw new InvalidArgumentException('Dirección, municipio y departamento son obligatorios.');
        }
        $this->db->prepare('UPDATE orders SET shipping_address = ? WHERE id = ?')
            ->execute([json_encode($a, JSON_UNESCAPED_UNICODE), $orderId]);
    }

    /** Mensaje de confirmación para enviar por WhatsApp (mismo estilo que los que se envían a mano). */
    public function confirmText(int $orderId, string $sender): string
    {
        $o = $this->db->query('SELECT * FROM orders WHERE id = ' . (int) $orderId)->fetch();
        $c = json_decode((string) $o['customer'], true) ?: [];
        $a = json_decode((string) $o['shipping_address'], true) ?: [];
        $items = $this->db->prepare('SELECT i.name, i.quantity, i.unit_price, p.box_contents FROM order_items i
                                     LEFT JOIN products p ON p.id = i.product_id WHERE i.order_id = ? ORDER BY i.id');
        $items->execute([$orderId]);
        $first = explode(' ', trim((string) ($c['first_name'] ?? '')))[0] ?: 'hola';
        $lines = [];
        $lines[] = 'Hola ' . $first . '! 👋';
        $lines[] = '';
        $lines[] = 'Te saluda ' . $sender . ' de *Tendencity*, tu tienda de productos en tendencia ✨';
        $lines[] = '';
        $lines[] = 'Recibimos tu pedido *' . $o['external_id'] . '* y queremos confirmarlo antes de enviarlo:';
        foreach ($items as $it) {
            $short = trim(preg_split('/\s*(?::|\s[–—|-]\s)\s*/u', (string) $it['name'], 2)[0]);
            $lines[] = '';
            $lines[] = '🛍️ *' . (int) $it['quantity'] . ' ' . $short . '* – ' . money((int) $it['unit_price'] * (int) $it['quantity']);
            $box = lines((string) $it['box_contents']);
            if ($box) {
                $lines[] = 'Incluye:';
                foreach ($box as $b) { $lines[] = '• ' . $b; }
            }
        }
        $lines[] = '';
        $lines[] = $o['payment_method'] === 'cod'
            ? '💵 Pagas *' . money((int) $o['total']) . ' en efectivo cuando lo recibas*.' . ((int) $o['shipping_charge'] === 0 ? ' El envío es gratis.' : '')
            : '💳 Total: *' . money((int) $o['total']) . '* (pago anticipado).';
        $lines[] = '';
        $lines[] = '📍 *Datos de entrega:*';
        $lines[] = '• Dirección: ' . trim(implode(', ', array_filter([$a['address1'] ?? '', $a['district'] ?? '', $a['address2'] ?? ''])));
        $lines[] = '• ' . Country::get('city_label') . ': ' . ($a['city'] ?? '');
        $lines[] = '• ' . Country::get('region_label') . ': ' . ($a['state'] ?? '');
        $lines[] = '• Teléfono: ' . ($c['phone'] ?? '');
        $lines[] = '';
        $lines[] = '¿Nos ayudas con una *referencia* para que el mensajero encuentre tu casa fácilmente? (color de la casa, un negocio o una iglesia cercana)';
        $lines[] = '';
        $lines[] = '✅ Si todo está correcto, responde *CONFIRMAR* y despachamos tu pedido.';
        $lines[] = '';
        $lines[] = '¡Gracias por tu compra! 💜';
        return implode("\n", $lines);
    }

    /** Marca un pedido como creado en el proveedor (Dropi) con su número allá. */
    public function markDispatched(int $orderId, string $providerRef, ?string $carrier = null, ?string $tracking = null): bool
    {
        $st = $this->db->prepare(
            "UPDATE orders SET sync_status = 'enviado', sync_error = NULL, fulfillment_ref = ?,
                    carrier_name = COALESCE(?, carrier_name), tracking_code = COALESCE(?, tracking_code)
             WHERE id = ? AND sync_status <> 'enviado'"
        );
        $st->execute([mb_substr($providerRef, 0, 60), $carrier, $tracking, $orderId]);
        return $st->rowCount() > 0;
    }

    /** Actualiza el estado de transporte que reporta el proveedor (Dropi). */
    public function updateShipment(int $orderId, ?string $carrierStatus, ?string $carrier = null, ?string $tracking = null, ?string $trackingUrl = null): void
    {
        $this->db->prepare(
            'UPDATE orders SET carrier_status = COALESCE(?, carrier_status), carrier_name = COALESCE(?, carrier_name),
                    tracking_code = COALESCE(?, tracking_code), tracking_url = COALESCE(?, tracking_url)
             WHERE id = ?'
        )->execute([$carrierStatus ? mb_substr($carrierStatus, 0, 120) : null, $carrier, $tracking, $trackingUrl, $orderId]);
    }

    /** Deja el pedido con un error visible en el panel (p. ej. Dropi no tiene cobertura). */
    public function markDispatchError(int $orderId, string $error): void
    {
        $this->db->prepare("UPDATE orders SET sync_status = 'error', sync_error = ?, sync_attempts = sync_attempts + 1 WHERE id = ?")
            ->execute([mb_substr($error, 0, 2000), $orderId]);
    }

    /** Crea el pedido en Mastershop. Idempotente: si ya existe allá, solo lo enlaza. */
    public function pushToMastershop(int $orderId): bool
    {
        if (!$this->ms) {
            return false; // esta tienda no despacha con Mastershop
        }
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
        if (strlen($phone) > Country::get('phone_digits') && str_starts_with($phone, Country::get('phone_code'))) {
            $phone = substr($phone, strlen(Country::get('phone_code'))); // quitaron o pusieron el indicativo
        }
        if (!Country::validPhone($phone)) {
            throw new InvalidArgumentException('Escribe un celular de ' . Country::get('phone_digits') . ' dígitos');
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
            'country'    => Country::code(),
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
