# Tendencity — tienda conectada a Mastershop

PHP 8.1+ (extensiones curl, pdo_mysql, mbstring, zip, xml) y MySQL 8 / MariaDB 10.5+.
Sin frameworks ni Composer: se sube por FTP o Git a cualquier hosting con cPanel.

## Instalación

1. Copia `.env.example` a `.env` (fuera del web root) y llénalo. Genera los secretos con
   `php -r "echo bin2hex(random_bytes(32));"` para `WEBHOOK_TOKEN` y `APP_SECRET`.
2. Base de datos: corre solo `schema.sql` y `schema_tienda.sql`. El resto de versiones (v3 a v6) **se aplica sola**
   la primera vez que alguien entra a la tienda o al admin (`src/Migrator.php`). Si prefieres hacerlo a mano, el orden es:
   ```
   mysql -u usuario -p tienda < sql/schema.sql
   mysql -u usuario -p tienda < sql/schema_tienda.sql
   mysql -u usuario -p tienda < sql/schema_v3_analitica.sql
   mysql -u usuario -p tienda < sql/schema_v4_banners.sql
   mysql -u usuario -p tienda < sql/schema_v5_admin.sql
   mysql -u usuario -p tienda < sql/schema_v6_productos_propios.sql
   ```
   Dale permiso de escritura a `public/uploads/` (fotos propias de productos).
3. Apunta el dominio a `public/`. Si el hosting no lo permite, sube todo a la raíz:
   el `.htaccess` de la raíz bloquea `src/`, `cli/`, `sql/`, `templates/` y `.env`.
4. Crea tu usuario: `php cli/create_admin.php tu@correo.com`
5. Entra a `/admin/` → Productos → «Sincronizar con Mastershop».
6. Revisa las categorías en Admin → Categorías (vienen unas de ejemplo).
7. Descarga la plantilla en Admin → Carga masiva, llénala y súbela.
8. Ajustes: WhatsApp, costo de envío, instrucciones de pago anticipado.
9. Registra los webhooks: `php cli/register_webhooks.php`
10. Crontab:
   ```
   */30 * * * * php /ruta/cli/sync_products.php >> /ruta/logs/sync.log 2>&1
   */5  * * * * php /ruta/cli/retry_orders.php  >> /ruta/logs/retry.log 2>&1
   ```
11. En Google Search Console envía `https://tudominio.co/sitemap.xml`.

Para probar en tu PC: `php -S localhost:8080 -t public cli/dev_router.php`

## Portada

Basada en la plantilla de referencia: buscador, menú «Todas las categorías», banner principal con
contador y 4 productos, lista de categorías con conteo, banner de precio mayorista, 5 beneficios,
productos populares con pestañas por categoría, oferta exclusiva, oferta del día con contador,
banners intermedios y productos en oferta. Las tarjetas tienen «Pedir ahora» (abre el formulario
rápido de la ficha) y botón de WhatsApp. Sin carrito.

La búsqueda (`/buscar/`) no se indexa, para no competir con las categorías.

## Productos de prueba

Admin → Ajustes → «Cargar productos de prueba» (o `php cli/demo.php`) crea 5 productos con fotos,
contenido AIDA completo, variantes, ofertas por cantidad y una reseña publicada. Sirven para ver la
tienda antes de cargar los tuyos. Sus pedidos se guardan pero **nunca se envían a Mastershop**.
Bórralos antes de lanzar: Ajustes → «Borrar productos de prueba» (o `php cli/demo.php --borrar`).

## Panel admin (`/admin/`)

Menú lateral en tres grupos:

| Grupo | Secciones |
|---|---|
| Métricas y ventas | Dashboard, Fuentes de tráfico, Pedidos |
| Tienda | Productos, Carga masiva, Categorías, Promoción y ofertas, Banners, Reseñas, Guías, Páginas |
| Configuración | General, Píxeles y analítica, Mastershop, Usuarios, Productos de prueba |

- **Píxeles y analítica:** Meta Pixel, verificación de dominio de Meta, TikTok Pixel, Google Analytics 4,
  Google Tag Manager y verificación de Search Console. Se pega solo el ID (o la etiqueta completa de
  verificación). Eventos: PageView, ViewContent, InitiateCheckout y Purchase (con el número de pedido
  como eventID para no contar doble).
- **Mastershop:** estado de la API key, probar conexión, sincronizar, registrar webhooks y ver las
  últimas notificaciones recibidas. La API key se edita en el `.env`, no en el panel.
- **Usuarios:** crear, editar nombre y correo, cambiar contraseña (la tuya pide la actual) y eliminar.
  No puedes eliminarte a ti mismo ni al último administrador. Un usuario eliminado pierde la sesión al instante.

### Portada (secciones destacadas)

Tienda → Portada: un mapa de la página de inicio. Cada sección (banner principal, banner de envío
incluido, oferta exclusiva, oferta del día, productos populares, en oferta ahora) funciona en
**Automático** (regla indicada en pantalla) o **Manual** (los productos que agregues, en ese orden).
Atajos: casillas «Mostrar en la portada» en el editor de cada producto, y la estrella ☆ en la lista de
productos (agrega o quita de Destacados). Las secciones con foto grande solo muestran productos con foto.

### Editor de producto en 3 pasos

1. **Lo básico:** fotos, nombre, categoría, costo, precio (con calculadora), stock y publicar.
2. **Para vender:** gancho, 3 a 5 beneficios y una descripción corta (texto que lee Google).
3. **Imágenes de la descripción:** piezas diseñadas (características, ingredientes, uso, comparativa) que se
   muestran en el cuerpo de la página en orden, cada una con su texto «qué muestra» para Google.
Arriba, «Listo para publicar» / «Listo para pauta» muestra lo que falta. Todo lo demás (SEO, oferta,
landing, portada, bloques en texto, formulario de pedido, testimonios) está en «Opciones avanzadas».
Cada paso guarda solo sus campos.

**Valores por defecto** (Configuración → General): garantía, oferta por cantidad para productos nuevos,
motivo de urgencia y preguntas frecuentes generales (se suman a las de cada producto sin repetirse).

**Plantillas de carga masiva:** básica (12 columnas) y completa (todas las opciones).

### Bloques visuales y testimonios

En el editor de cada producto, sección «Bloques visuales» (o columnas `medidas`, `caracteristicas_foto`,
`formas_uso`, `comparacion_fotos` en la carga masiva). Se muestran en la landing y en la ficha normal:
- **Medidas:** Alto/Ancho/Fondo se dibujan con flechas sobre la foto; por piezas, barras a escala.
- **Características con foto** y **Formas de uso:** Título | texto | ruta de la foto, una por línea.
- **Fotos de la comparación:** foto de tu producto | foto de «otros».
- **Testimonios** (sección «Testimonios» del editor): nombre, ciudad, estrellas, texto y foto opcional.
  Con número de pedido real de ese producto sale «Compra verificada». Con testimonios publicados,
  Google puede mostrar las estrellas. Publica solo opiniones reales, con permiso del cliente.

### Fecha estimada de entrega y secciones de confianza

Debajo del botón de compra (ficha y landing): «Pide hoy y recíbelo entre el X y el Y», con línea de tiempo
Pides → Despachamos → Te llega. Cuenta días hábiles de Colombia: sin domingos ni festivos (incluye los que
se corren al lunes y Semana Santa), con hora de corte. Se configura en Configuración → General:
días de alistamiento, días de transporte, hora de corte, sábado hábil y la nota.
Al final de cada producto: «Así llega tu pedido» (5 pasos) y «¿Por qué comprar en Tendencity?» (5 razones).

### Meta: píxel y API de Conversiones

Configuración → Píxeles y analítica. El píxel envía PageView, ViewContent, InitiateCheckout y Purchase
(en la página de gracias). Con el token de la API de Conversiones, cada compra también se envía desde el
servidor con el mismo `event_id` (número de pedido), así Meta no la cuenta doble. Datos del cliente en
SHA-256. Versión de la API: v25.0 (cámbiala con `META_GRAPH_VERSION` en el `.env`).

### Calculadora de precios

Tienda → Calculadora de precios: **precio = costo + flete + publicidad (si el producto lleva) + utilidad**,
cerrado en …900. La utilidad es el mayor entre un % sobre el costo y una utilidad mínima en pesos.
En la carga masiva, si `precio` va vacío y hay `costo`, el precio se calcula solo (`con_publicidad` = si/no).
Si cambian el flete o la pauta, se recalculan todos los precios desde esa pantalla.

### Marca

Campo `marca` en la carga masiva y en el editor. Se muestra sobre el nombre del producto y va en el
schema Product para Google. Usa solo la marca real del producto: nunca "tipo" otra marca.

### Productos propios (sin Mastershop)

Los productos se pueden crear directamente en la tienda (Productos → «+ Nuevo producto» o la carga
masiva sin `ms_product_id`). Solo necesitan título, precio y categoría; además pueden llevar SKU,
costo, stock, peso, fotos y variantes.

- **Vinculación automática:** al sincronizar, si un producto de Mastershop tiene el mismo SKU (o el de
  una de sus variantes), se vincula al producto de la tienda en vez de duplicarlo. También se puede
  vincular a mano escribiendo el ID de Mastershop en el editor.
- **Pedidos:** los de productos sin vincular se guardan con su origen y quedan en Pedidos →
  «Despacho manual». Si luego se vincula el producto, «Reintentar» lo envía a Mastershop.
- **Stock:** en productos propios lo manejas tú y baja con cada pedido; al vincularse pasa a venir de Mastershop.

## Medición de visitas y fuentes

Analítica propia medida en el servidor (no la bloquean los bloqueadores de anuncios). No reemplaza
a Search Console: para las palabras clave de Google usa Search Console.

- Canales: Pagado (ads), Orgánico (Google), Redes sociales, Directo / WhatsApp, Otros sitios, Email.
- El pedido se atribuye al último canal no directo de los últimos 30 días: si alguien llega por un
  anuncio y vuelve días después escribiendo la dirección, el pedido cuenta para el anuncio.
- Meta Ads: en cada anuncio → Parámetros de URL:
  `utm_source=meta&utm_medium=paid&utm_campaign={{campaign.name}}&utm_content={{ad.name}}`
- Orgánico de redes y WhatsApp: usa el generador de enlaces de cada producto.
- Los bots no se cuentan. La página de confirmación no se cuenta como visita.

## Estructura SEO (silos)

| Silo | URL | Se indexa |
|---|---|---|
| 1. Categorías | `/hogar-y-cocina/` y `/hogar-y-cocina/organizadores/` | Sí, si tiene productos |
| 2. Reseñas | `/resenas/`, `/resenas/{pilar}/`, `/resenas/{pilar}/{reseña}/` | Sí, al publicarse |
| 3. Guías | `/guias/`, `/guias/{guía}/` | Sí, al publicarse |
| Productos (destino común) | `/p/{producto}/` | Sí |
| Ofertas | `/ofertas/` | No (`noindex, follow`) |
| Black Friday | `/black-friday/` (URL fija, se reutiliza cada año) | Sí |

Enlazado: el producto sube solo a su categoría (migas + schema), enlaza a 4 hermanos de su
subcategoría y a su reseña si existe. Reseñas y guías tienen un solo puente a su categoría.
Cambiar una URL crea la redirección 301 sola.

## Contenido de producto (AIDA)

| Bloque | Columna del Excel | Dónde sale |
|---|---|---|
| Atención | `atencion_gancho` | Bajo el nombre del producto |
| Atención | `atencion_titular` | Frase grande al empezar la descripción |
| Interés | `interes_beneficios` | Lista de beneficios |
| Deseo | `deseo_texto`, `deseo_para_quien`, `caracteristicas` | Uso, perfiles y tabla |
| Acción | `accion_urgencia`, `accion_garantia` | Junto al segundo botón de pedido |

Un bloque vacío no se muestra. La hoja «Instrucciones» de la plantilla explica cada columna.

## Formulario de pedido

- Por producto: contraentrega, anticipado o ambos; % de descuento por pagar anticipado;
  ofertas por cantidad (`2:10:Más vendido | 3:15`).
- Todos los precios se recalculan en el servidor (`src/Pricing.php`); el navegador solo muestra.
- Contraentrega: el pedido llega a Mastershop al instante.
- Anticipado: queda retenido hasta que lo marques como pagado en Admin → Pedidos
  (filtro «Pago anticipado pendiente»). Ahí mismo se envía a Mastershop.
  Para conectar una pasarela (Wompi, Mercado Pago), en su confirmación llama
  `OrderService::markPaidAndPush()`.
- Meta Pixel: si tienes el pixel instalado, el formulario dispara `InitiateCheckout` y `Purchase`.

## Reglas de Mastershop que el código ya respeta

Total exacto (pesos enteros), documento y tipo en pareja, IDs externos con letras/números/guiones,
`limit` máximo 50, `startDate` en el listado de pedidos, y webhooks con token secreto,
deduplicación, control de orden y verificación contra `GET /api/orders`.

## ⚠️ Verificar en la primera prueba real con Mastershop

1. **ID de la variante por defecto** en productos sin variantes: `php cli/sync_products.php --dump`
   y revisa qué trae `variation` con `includeAllVariations=true`.
2. **`type_charge` del flete** en `additional_charge` (se envía `"shipping"`). Si no cobras
   envío (ajuste en 0), no se envía ningún cargo.
3. **`payment_method`**: se envía `cod` / `pia`.
4. **Precio del ítem**: se envía tu precio de venta (lo que cobra la transportadora en contraentrega).
5. **Ciudad**: el formulario la recibe como texto libre. Si Mastershop exige un nombre exacto
   de municipio, hay que cambiarla por una lista.
6. **Respuestas de `POST /api/orders` y `POST /api/webhooks`**: dónde vienen `idOrder` e `idProductWebhook`.

## Antes de publicar

- Reemplaza el texto de `templates/pages/terminos.md` y `politica-de-privacidad.md`
  (razón social, NIT, Ley 1581 de 2012).
- `precio_antes` debe ser un precio al que realmente vendiste (Ley 1480). El contador de oferta
  solo aparece con una fecha de fin real.
- Las reseñas publicadas exigen contras reales; el sistema no deja publicar sin ellos.
