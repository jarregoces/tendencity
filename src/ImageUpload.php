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
