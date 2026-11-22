-- v3: visitas, fuentes de tráfico, atribución de pedidos, imágenes propias,
-- promoción y páginas editables. Correr DESPUÉS de schema.sql y schema_tienda.sql.
SET NAMES utf8mb4;

-- Una fila por sesión (30 min de inactividad = sesión nueva)
CREATE TABLE visits (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id      CHAR(16) NOT NULL UNIQUE,
  visitor_id      CHAR(16) NOT NULL,
  started_at      DATETIME NOT NULL,
  last_at         DATETIME NOT NULL,
  pageviews       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  landing_path    VARCHAR(255) NOT NULL,
  channel         ENUM('pagado','organico','social','directo','referido','email') NOT NULL,
  source          VARCHAR(80) NULL,      -- google, meta, instagram, tiktok...
  medium          VARCHAR(80) NULL,
  campaign        VARCHAR(150) NULL,
  content         VARCHAR(150) NULL,     -- anuncio / creativo
  term            VARCHAR(150) NULL,
  referrer_host   VARCHAR(120) NULL,
  device          ENUM('movil','tablet','escritorio') NOT NULL DEFAULT 'movil',
  INDEX (started_at), INDEX (visitor_id, started_at), INDEX (channel, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pageviews (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id  CHAR(16) NOT NULL,
  path        VARCHAR(255) NOT NULL,
  product_id  INT UNSIGNED NULL,
  at          DATETIME NOT NULL,
  INDEX (at), INDEX (product_id, at), INDEX (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Eventos del embudo: abrir el formulario de pedido
CREATE TABLE events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id  CHAR(16) NOT NULL,
  type        VARCHAR(30) NOT NULL,
  product_id  INT UNSIGNED NULL,
  at          DATETIME NOT NULL,
  UNIQUE KEY uq_once (session_id, type, product_id),
  INDEX (type, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Atribución del pedido (último toque no directo en 30 días)
ALTER TABLE orders
  ADD COLUMN session_id    CHAR(16) NULL,
  ADD COLUMN channel       VARCHAR(20) NULL,
  ADD COLUMN source        VARCHAR(80) NULL,
  ADD COLUMN medium        VARCHAR(80) NULL,
  ADD COLUMN campaign      VARCHAR(150) NULL,
  ADD COLUMN content       VARCHAR(150) NULL,
  ADD COLUMN landing_path  VARCHAR(255) NULL,
  ADD INDEX (channel), ADD INDEX (created_at);

-- Costo al momento del pedido (para calcular ganancia real aunque el costo cambie)
ALTER TABLE order_items ADD COLUMN unit_cost INT UNSIGNED NULL;

-- Montar y promocionar
ALTER TABLE products
  ADD COLUMN custom_images  JSON NULL,           -- tus fotos; si existen, mandan sobre las de Mastershop
  ADD COLUMN badge          VARCHAR(30) NULL,    -- etiqueta en la tarjeta: Nuevo, Más vendido...
  ADD COLUMN is_featured    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN offer_from     DATETIME NULL;       -- oferta programada: desde

-- Páginas legales e informativas editables desde el admin
CREATE TABLE pages (
  slug        VARCHAR(60) PRIMARY KEY,
  body        MEDIUMTEXT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (k, v) VALUES
  ('hero_product_id', ''), ('hero_title', ''), ('hero_text', ''),
  ('return_freight_factor', '1');   -- devoluciones: fletes extra que te cuesta cada una
