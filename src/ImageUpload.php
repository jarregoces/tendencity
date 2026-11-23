<?php
declare(strict_types=1);

/**
 * Fotos propias de producto: valida, redimensiona a máx. 1200 px y guarda en WebP.
 * Quedan en public/uploads/products/{id}/ (esa carpeta necesita permiso de escritura).
 */
final class ImageUpload
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_SIDE = 1200;

    public static function dir(int $productId, string $folder = 'products'): string
    {
        return dirname(__DIR__) . "/public/uploads/{$folder}/{$productId}";
    }

    /** @return string URL pública de la imagen guardada */
    public static function store(array $file, int $productId, string $folder = 'products', bool $byWidth = false): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No llegó el archivo. Revisa que pese menos de 8 MB.');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('La imagen pesa más de 8 MB.');
        }
        return self::storeFile($file['tmp_name'], $productId, $folder, $byWidth);
    }

    /**
     * Descarga una foto de otra web (p. ej. el CDN de Shopify) y la guarda como las subidas a mano.
     * Así la tienda no depende de que la otra web siga existiendo.
     * @return string URL pública de la imagen guardada
     */
    public static function fromUrl(string $url, int $productId, string $folder = 'products', bool $byWidth = false): string
    {
        if (!preg_match('#^https://#', $url) || !function_exists('curl_init')) {
            throw new RuntimeException('Solo se pueden descargar imágenes https.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        $fh = fopen($tmp, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'TendencityImporter/1.0',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => fn($c, $dlTotal, $dl) => $dl > self::MAX_BYTES ? 1 : 0, // corta archivos gigantes
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);
        try {
            if (!$ok || $code !== 200) {
                throw new RuntimeException("No se pudo descargar la imagen (HTTP {$code}).");
            }
            return self::storeFile($tmp, $productId, $folder, $byWidth);
        } finally {
            @unlink($tmp);
        }
    }

    private static function storeFile(string $path, int $productId, string $folder, bool $byWidth): string
    {
        $file = ['tmp_name' => $path];
        $info = @getimagesize($file['tmp_name']);
        $src = match ($info['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/webp' => @imagecreatefromwebp($file['tmp_name']),
            default      => throw new RuntimeException('Formato no permitido. Usa JPG, PNG o WebP.'),
        };
        if (!$src) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = $byWidth ? min(1, 1200 / $w) : min(1, self::MAX_SIDE / max($w, $h));
        $nw = (int) round($w * $scale); $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $dir = self::dir($productId, $folder);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('No se pudo crear la carpeta de imágenes. Dale permiso de escritura a public/uploads.');
        }
        $name = bin2hex(random_bytes(6)) . '.webp';
        if (!imagewebp($dst, "{$dir}/{$name}", 82)) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }
        imagedestroy($src);
        imagedestroy($dst);
        return "/uploads/{$folder}/{$productId}/{$name}";
    }

    public static function delete(string $url, int $productId): void
    {
        $prefix = "/uploads/products/{$productId}/";
        if (str_starts_with($url, $prefix) && preg_match('/^[a-f0-9]{12}\.webp$/', substr($url, strlen($prefix)))) {
            @unlink(dirname(__DIR__) . '/public' . $url);
        }
    }
}
