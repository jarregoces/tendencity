-- v6: productos propios (sin vincular a Mastershop). Correr después de schema_v5_admin.sql.
SET NAMES utf8mb4;

-- El ID de Mastershop pasa a ser opcional; el SKU identifica al producto y sirve para vincularlo después
ALTER TABLE products
  MODIFY ms_product_id BIGINT UNSIGNED NULL,
  ADD COLUMN sku VARCHAR(120) NULL AFTER ms_product_id,
  ADD UNIQUE KEY uq_products_sku (sku);

ALTER TABLE product_variants MODIFY ms_variant_id BIGINT UNSIGNED NULL;

-- El pedido guarda también los IDs locales: si el producto se vincula después, se puede reenviar
ALTER TABLE order_items
  MODIFY ms_product_id BIGINT UNSIGNED NULL,
  MODIFY ms_variant_id BIGINT UNSIGNED NULL,
  ADD COLUMN product_id INT UNSIGNED NULL AFTER order_id,
  ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id,
  ADD INDEX (product_id);
