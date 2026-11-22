-- v4: banners administrables de la portada. Correr después de schema_v3_analitica.sql.
SET NAMES utf8mb4;

CREATE TABLE banners (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slot          ENUM('principal','mayorista','intermedio') NOT NULL,
  eyebrow       VARCHAR(80) NULL,        -- texto pequeño arriba: "¡Está aquí! Todo en oferta"
  title         VARCHAR(120) NOT NULL,
  highlight     VARCHAR(120) NULL,       -- parte del título en degradado
  text          VARCHAR(300) NULL,
  button_text   VARCHAR(40) NULL,
  button_url    VARCHAR(255) NULL,
  button2_text  VARCHAR(40) NULL,        -- segundo botón (ej. WhatsApp)
  button2_url   VARCHAR(255) NULL,
  image         VARCHAR(255) NULL,       -- foto propia; vacío = usa productos
  product_ids   VARCHAR(255) NULL,       -- principal: productos que se muestran al lado (ids, coma)
  until         DATETIME NULL,           -- contador real hasta esta fecha
  sort          SMALLINT NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  INDEX (slot, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO banners (slot, eyebrow, title, highlight, text, button_text, button_url, until, sort) VALUES
 ('principal', 'Temporada navideña', 'Navidad', 'en tendencia',
  'Regalos que sí van a usar, con el envío incluido y pago al recibir en toda Colombia.',
  'Ver regalos y ofertas', '/ofertas/', CONCAT(YEAR(CURDATE()), '-12-24 23:59:59'), 1);

INSERT INTO banners (slot, eyebrow, title, highlight, text, button_text, button_url, button2_text, button2_url, sort) VALUES
 ('mayorista', 'Sin costos sorpresa', 'El precio que ves', 'ya incluye el envío',
  'Llega a tu casa en cualquier ciudad de Colombia y pagas en efectivo cuando lo recibes.',
  'Ver productos', '/ofertas/', NULL, NULL, 1);

INSERT INTO banners (slot, eyebrow, title, highlight, text, button_text, button_url, sort) VALUES
 ('intermedio', 'Mega descuentos', 'Hasta', '30% menos', 'En productos seleccionados', 'Ver ofertas', '/ofertas/', 1),
 ('intermedio', 'Para tu casa', 'Hogar y cocina', NULL, 'Organización y cocina a precio de bodega', 'Explorar', '/hogar-y-cocina/', 2),
 ('intermedio', 'Lo más buscado', 'Tecnología', NULL, 'Power banks, audio y accesorios', 'Explorar', '/tecnologia/', 3);
