<?php
/**
 * CRM Abogados - Subida Segura de Archivos con Compresión de Imágenes
 */

if (!defined('CRM_ROOT')) {
    die('Acceso prohibido');
}

class FileUpload {
    
    // Tipos MIME permitidos
    private static $tiposPermitidos = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain'
    ];

    // Tipos de imagen que se pueden comprimir con GD
    private static $tiposImagen = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    // Tamaño máximo de entrada: 20 MB (GD lo comprimirá)
    private static $maxTamano = 20 * 1024 * 1024;

    // Límite máximo de dimensión para imágenes (px)
    private static $maxDimension = 1920;

    // Calidad de compresión para JPEG/WEBP (0-100)
    private static $calidadJpeg = 82;

    // Nivel de compresión PNG (0 = sin comprimir, 9 = máximo)
    private static $comprPng = 8;

    /**
     * Subir un archivo de forma segura, comprimiendo imágenes automáticamente.
     * Los archivos se guardan en /crm/storage/casos/{id}/ (bloqueado por .htaccess).
     * La descarga se sirve SIEMPRE a través del proxy casos/descargar.php.
     */
    public static function subir($archivo, $casoId) {
        // Validaciones básicas
        if (!isset($archivo) || $archivo['error'] !== UPLOAD_ERR_OK) {
            $errores = [
                UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamaño máximo del servidor',
                UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño máximo del formulario',
                UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente',
                UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal',
                UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir en disco',
            ];
            $msg = $errores[$archivo['error']] ?? 'Error desconocido al subir el archivo';
            return ['exito' => false, 'mensaje' => $msg, 'datos' => null];
        }

        // Verificar tamaño
        if ($archivo['size'] > self::$maxTamano) {
            return ['exito' => false, 'mensaje' => 'El archivo excede el tamaño máximo de 20 MB', 'datos' => null];
        }

        // Verificar tipo MIME real (lee los bytes del archivo, ignora la extensión)
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $tipoReal = $finfo->file($archivo['tmp_name']);

        if (!in_array($tipoReal, self::$tiposPermitidos)) {
            return ['exito' => false, 'mensaje' => 'Tipo de archivo no permitido: ' . $tipoReal, 'datos' => null];
        }

        // Generar nombre seguro con entropía criptográfica
        $esImagen  = in_array($tipoReal, self::$tiposImagen);
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

        // GIF/WEBP se convierten a JPEG para reducir superficie de ataque
        if ($esImagen && in_array($tipoReal, ['image/webp', 'image/gif'])) {
            $extension = 'jpg';
        }

        $nombreSeguro = sprintf('caso_%d_%s.%s', $casoId, bin2hex(random_bytes(16)), $extension);

        // ── Guardar en storage/ (bloqueado por .htaccess Deny from all) ──────
        $dirStorage = CRM_ROOT . '/storage/casos/' . $casoId;
        if (!is_dir($dirStorage)) {
            mkdir($dirStorage, 0750, true);
            // Asegurar que Apache no sirva este directorio directamente
            $htaccessStorage = CRM_ROOT . '/storage/.htaccess';
            if (!file_exists($htaccessStorage)) {
                file_put_contents($htaccessStorage, "Deny from all\n");
            }
        }
        $rutaCompleta = $dirStorage . '/' . $nombreSeguro;

        // ── Procesar imagen con GD ────────────────────────────────────────────
        if ($esImagen && extension_loaded('gd')) {
            $ok = self::comprimirImagen($archivo['tmp_name'], $rutaCompleta, $tipoReal);
            if (!$ok) {
                move_uploaded_file($archivo['tmp_name'], $rutaCompleta);
            }
        } else {
            if (!move_uploaded_file($archivo['tmp_name'], $rutaCompleta)) {
                return ['exito' => false, 'mensaje' => 'Error al guardar el archivo', 'datos' => null];
            }
        }

        $tamanoFinal = file_exists($rutaCompleta) ? filesize($rutaCompleta) : $archivo['size'];

        $datos = [
            'nombre_original' => basename($archivo['name']),
            'nombre_archivo'  => $nombreSeguro,
            // ruta_storage: ruta relativa desde CRM_ROOT, para el proxy
            'ruta_storage'    => 'storage/casos/' . $casoId . '/' . $nombreSeguro,
            // ruta: legacy field — apunta a storage también, para no romper queries antiguas
            'ruta'            => 'storage/casos/' . $casoId . '/' . $nombreSeguro,
            'tipo_mime'       => $tipoReal,
            'tamano_bytes'    => $tamanoFinal,
        ];

        return ['exito' => true, 'mensaje' => 'Archivo subido correctamente', 'datos' => $datos];
    }

    /**
     * Comprime y redimensiona una imagen con GD.
     * - JPEG/WEBP/GIF → guardados como JPEG con calidad 82%
     * - PNG → guardado como PNG preservando transparencia, compresión 8
     * - Nunca amplía la imagen, solo reduce si supera self::$maxDimension
     */
    private static function comprimirImagen(string $tmp, string $destino, string $mime): bool {
        $src = match($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png'  => @imagecreatefrompng($tmp),
            'image/gif'  => @imagecreatefromgif($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
            default      => false,
        };
        if (!$src) return false;

        $sw = imagesx($src);
        $sh = imagesy($src);
        $max = self::$maxDimension;

        // Calcular nuevas dimensiones (nunca ampliar)
        $ratio = min($max / $sw, $max / $sh, 1);
        $nw    = (int) round($sw * $ratio);
        $nh    = (int) round($sh * $ratio);

        $dst = imagecreatetruecolor($nw, $nh);

        $esPng = ($mime === 'image/png');

        if ($esPng) {
            // Preservar transparencia del PNG
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        } else {
            // Fondo blanco para JPEG (GIF/WEBP con transparencia → JPEG sin transparencia)
            $blanco = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $blanco);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);

        $ok = $esPng
            ? imagepng($dst, $destino, self::$comprPng)
            : imagejpeg($dst, $destino, self::$calidadJpeg);

        unset($src);
        unset($dst);
        return $ok;
    }

    /**
     * Eliminar un archivo del sistema (soporta rutas storage/ y uploads/ legacy)
     */
    public static function eliminar($ruta) {
        // La ruta es relativa a CRM_ROOT
        $rutaCompleta = CRM_ROOT . '/' . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta), DIRECTORY_SEPARATOR);
        if (file_exists($rutaCompleta) && is_file($rutaCompleta)) {
            return unlink($rutaCompleta);
        }
        return false;
    }

    /**
     * Formatear tamaño de archivo para mostrar
     */
    public static function formatearTamano($bytes) {
        $unidades = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($unidades) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $unidades[$i];
    }
}

