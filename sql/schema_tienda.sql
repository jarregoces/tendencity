-- Tablas de la tienda Tendencity. Correr DESPUÉS de schema.sql.
SET NAMES utf8mb4;

-- SILO 1: categorías (pilar = parent_id NULL, subcategoría = parent_id con valor)
CREATE TABLE categories (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id         INT UNSIGNED NULL,
  slug              VARCHAR(120) NOT NULL,
  name              VARCHAR(120) NOT NULL,
  h1                VARCHAR(160) NULL,
  meta_title        VARCHAR(70) NULL,
  meta_description  VARCHAR(170) NULL,
  intro             VARCHAR(400) NULL,      -- 1-2 líneas arriba del listado
  seo_text          MEDIUMTEXT NULL,        -- texto largo abajo del listado (markdown simple)
  faq               JSON NULL,              -- [{"q":"...","a":"..."}]
  sort              SMALLINT NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cat (parent_id, slug),
  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contenido de la ficha de producto (AIDA), oferta y opciones del formulario
ALTER TABLE products
  ADD COLUMN category_id         INT UNSIGNED NULL AFTER ms_product_id,
  ADD COLUMN title               VARCHAR(160) NULL AFTER name,       -- nombre comercial (si vacío usa name)
  ADD COLUMN meta_title          VARCHAR(70) NULL,
  ADD COLUMN meta_description    VARCHAR(170) NULL,
  ADD COLUMN price_before        INT UNSIGNED NULL,                  -- precio real anterior (Ley 1480)
  ADD COLUMN offer_until         DATETIME NULL,
  ADD COLUMN aida_headline       VARCHAR(200) NULL,                  -- Atención: titular
  ADD COLUMN aida_hook           VARCHAR(400) NULL,                  -- Atención: gancho
  ADD COLUMN aida_benefits       TEXT NULL,                          -- Interés: un beneficio por línea
  ADD COLUMN aida_desire         MEDIUMTEXT NULL,                    -- Deseo: cómo se usa / qué resuelve (markdown simple)
  ADD COLUMN aida_for_whom       TEXT NULL,                          -- Deseo: para quién es, una línea por perfil
  ADD COLUMN aida_guarantee      VARCHAR(300) NULL,                  -- Acción: garantía
  ADD COLUMN aida_urgency        VARCHAR(300) NULL,                  -- Acción: motivo real para decidir hoy
  ADD COLUMN specs               TEXT NULL,                          -- "Clave: valor" por línea
  ADD COLUMN faq                 JSON NULL,
  ADD COLUMN allow_cod           TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN allow_pia           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN pia_discount        TINYINT UNSIGNED NOT NULL DEFAULT 0, -- % de descuento por pagar anticipado
  ADD COLUMN qty_offers          JSON NULL,                          -- [{"qty":2,"pct":10,"label":"Más vendido"}]
  ADD COLUMN sort                SMALLINT NOT NULL DEFAULT 0,
  ADD INDEX (category_id), ADD INDEX (is_active);

ALTER TABLE products
  ADD FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;

-- SILO 2: reseñas editoriales
CREATE TABLE reviews (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id       INT UNSIGNED NULL,      -- sub-pilar temático (pilar de categorías)
  product_id        INT UNSIGNED NULL,      -- producto que se reseña (destino del CTA)
  slug              VARCHAR(160) NOT NULL UNIQUE,
  title             VARCHAR(160) NOT NULL,
  meta_title        VARCHAR(70) NULL,
  meta_description  VARCHAR(170) NULL,
  rating            DECIMAL(2,1) NULL,      -- 1.0 a 5.0
  verdict           VARCHAR(400) NULL,
  pros              TEXT NULL,              -- uno por línea
  cons              TEXT NULL,              -- uno por línea (obligatorio tener contras reales)
  for_whom          TEXT NULL,
  body              MEDIUMTEXT NULL,
  is_published      TINYINT(1) NOT NULL DEFAULT 0,
  published_at      DATETIME NULL,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SILO 3: guías
CREATE TABLE guides (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id       INT UNSIGNED NULL,      -- puente temático hacia su categoría
  slug              VARCHAR(160) NOT NULL UNIQUE,
  title             VARCHAR(160) NOT NULL,
  meta_title        VARCHAR(70) NULL,
  meta_description  VARCHAR(170) NULL,
  excerpt           VARCHAR(300) NULL,
  body              MEDIUMTEXT NULL,
  product_ids       VARCHAR(255) NULL,      -- ids locales recomendados, separados por coma
  is_published      TINYINT(1) NOT NULL DEFAULT 0,
  published_at      DATETIME NULL,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Redirecciones 301 (productos que salen de Mastershop, slugs cambiados)
CREATE TABLE redirects (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_path   VARCHAR(255) NOT NULL UNIQUE,
  to_path     VARCHAR(255) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  k  VARCHAR(60) PRIMARY KEY,
  v  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admins (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email          VARCHAR(160) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Antispam del formulario de pedido
CREATE TABLE order_attempts (
  ip          VARBINARY(16) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, created_at)
) ENGINE=InnoDB;

-- Datos de partida (edítalos desde el admin)
INSERT INTO settings (k, v) VALUES
  ('site_name', 'Tendencity'),
  ('whatsapp', '573182165374'),
  ('shipping_flat', '0'),
  ('shipping_free_from', '0'),
  ('pia_instructions', 'Te escribimos por WhatsApp con los datos para pagar por Nequi, Daviplata o transferencia. Despachamos apenas confirmemos el pago.'),
  ('announcement', 'Envío incluido a toda Colombia · Pagas al recibir'),
  ('black_friday_active', '0'),
  ('black_friday_text', '');

INSERT INTO categories (id, parent_id, slug, name, h1, sort) VALUES
  (1, NULL, 'hogar-y-cocina', 'Hogar y cocina', 'Productos para el hogar y la cocina con pago contraentrega', 1),
  (2, NULL, 'tecnologia', 'Tecnología', 'Tecnología y accesorios con pago contraentrega', 2),
  (3, NULL, 'belleza-y-cuidado-personal', 'Belleza y cuidado personal', 'Belleza y cuidado personal con pago contraentrega', 3),
  (4, NULL, 'salud-y-bienestar', 'Salud y bienestar', 'Salud y bienestar con pago contraentrega', 4),
  (5, 1, 'organizadores', 'Organizadores', 'Organizadores para el hogar', 1),
  (6, 1, 'cocina', 'Cocina', 'Accesorios de cocina', 2),
  (7, 2, 'audio', 'Audio', 'Audífonos y parlantes', 1),
  (8, 2, 'power-banks-y-cargadores', 'Power banks y cargadores', 'Power banks y cargadores', 2);
