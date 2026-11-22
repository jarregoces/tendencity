-- Esquema base de la tienda conectada a Mastershop (MySQL 8 / MariaDB 10.5+)
-- Todos los montos en COP, pesos enteros (sin decimales).
SET NAMES utf8mb4;

CREATE TABLE products (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ms_product_id   BIGINT UNSIGNED NOT NULL UNIQUE,
  name            VARCHAR(255) NOT NULL,
  slug            VARCHAR(255) NOT NULL UNIQUE,
  description     TEXT NULL,
  category        VARCHAR(120) NULL,
  price           INT UNSIGNED NOT NULL DEFAULT 0,   -- precio de venta
  cost            INT UNSIGNED NULL,                 -- precio proveedor (dropshipping)
  stock           INT NOT NULL DEFAULT 0,
  weight          DECIMAL(10,3) NULL,
  images          JSON NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 0,     -- lo activas tú desde el admin
  price_locked    TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = la sync no pisa tu precio
  raw             JSON NULL,                         -- respuesta completa de Mastershop
  synced_at       DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_variants (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      INT UNSIGNED NOT NULL,
  ms_variant_id   BIGINT UNSIGNED NOT NULL UNIQUE,
  sku             VARCHAR(120) NULL,
  name            VARCHAR(255) NULL,
  price           INT UNSIGNED NULL,                 -- NULL = usa el precio del producto
  stock           INT NOT NULL DEFAULT 0,
  weight          DECIMAL(10,3) NULL,
  raw             JSON NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE orders (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_id         VARCHAR(40) NULL UNIQUE,          -- lo que mandamos como id_order
  ms_order_id         BIGINT UNSIGNED NULL UNIQUE,      -- idOrder que asigna Mastershop
  sync_status         ENUM('pendiente_envio','enviado','error') NOT NULL DEFAULT 'pendiente_envio',
  sync_error          TEXT NULL,
  sync_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  payment_method      ENUM('cod','pia') NOT NULL,
  payment_status      ENUM('pendiente','pagado','contraentrega','fallido') NOT NULL,
  payment_gateway     VARCHAR(60) NULL,
  payment_reference   VARCHAR(120) NULL,
  subtotal            INT UNSIGNED NOT NULL,
  shipping_charge     INT UNSIGNED NOT NULL DEFAULT 0,
  total               INT UNSIGNED NOT NULL,
  currency            CHAR(3) NOT NULL DEFAULT 'COP',
  customer            JSON NOT NULL,
  shipping_address    JSON NOT NULL,
  notes               TEXT NULL,
  ms_status_id        TINYINT UNSIGNED NULL,
  ms_status_updated   DATETIME NULL,                    -- date_update_order del último evento aplicado
  carrier_name        VARCHAR(80) NULL,
  carrier_status      VARCHAR(120) NULL,
  tracking_code       VARCHAR(60) NULL,
  tracking_url        VARCHAR(500) NULL,
  label_url           VARCHAR(500) NULL,
  shipping_rate       INT UNSIGNED NULL,                -- costo real del envío según Mastershop
  novelty             JSON NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (sync_status), INDEX (ms_status_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  ms_product_id   BIGINT UNSIGNED NOT NULL,
  ms_variant_id   BIGINT UNSIGNED NOT NULL,
  sku             VARCHAR(120) NULL,
  name            VARCHAR(255) NOT NULL,
  quantity        INT UNSIGNED NOT NULL,
  unit_price      INT UNSIGNED NOT NULL,
  weight          DECIMAL(10,3) NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log de notificaciones. dedupe_key evita procesar dos veces el mismo evento
-- (Mastershop no garantiza entrega única ni orden).
CREATE TABLE webhook_events (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dedupe_key      CHAR(64) NOT NULL UNIQUE,
  event_name      VARCHAR(120) NULL,
  ms_order_id     BIGINT UNSIGNED NULL,
  payload         JSON NOT NULL,
  processed_at    DATETIME NULL,
  error           TEXT NULL,
  received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ms_order_id), INDEX (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- La API NO permite listar suscripciones: hay que guardar idProductWebhook
-- aquí o después no se podrán deshabilitar.
CREATE TABLE webhook_subscriptions (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_webhook            SMALLINT UNSIGNED NOT NULL,
  id_product_webhook    BIGINT UNSIGNED NULL,
  id_product            BIGINT UNSIGNED NULL,
  url                   VARCHAR(500) NOT NULL,
  is_enabled            TINYINT(1) NOT NULL DEFAULT 1,
  raw                   JSON NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
