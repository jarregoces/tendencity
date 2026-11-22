-- v5: usuarios del admin y configuración de píxeles. Correr después de schema_v4_banners.sql.
SET NAMES utf8mb4;

ALTER TABLE admins
  ADD COLUMN name           VARCHAR(80) NULL AFTER email,
  ADD COLUMN last_login_at  DATETIME NULL;

INSERT IGNORE INTO settings (k, v) VALUES
  ('meta_pixel_id', ''),            -- Meta (Facebook / Instagram) Pixel
  ('meta_domain_verification', ''), -- verificación de dominio en Meta Business
  ('ga4_id', ''),                   -- Google Analytics 4: G-XXXXXXX
  ('gtm_id', ''),                   -- Google Tag Manager: GTM-XXXXXX
  ('google_site_verification', ''), -- Search Console (método etiqueta HTML)
  ('tiktok_pixel_id', '');
