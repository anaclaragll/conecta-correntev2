<?php

const MAX_UPLOAD_IMAGE_COUNT = 10;
const MAX_UPLOAD_IMAGE_SIZE = 5 * 1024 * 1024;

function galleryImagesFromStorage($primary, $gallery)
{
    $images = [];

    if (is_string($primary) && trim($primary) !== '') {
        $images[] = trim($primary);
    }

    if (is_string($gallery) && trim($gallery) !== '') {
        $decoded = json_decode($gallery, true);
        $extras = is_array($decoded) ? $decoded : explode(',', $gallery);

        foreach ($extras as $image) {
            if (is_string($image) && trim($image) !== '') {
                $images[] = trim($image);
            }
        }
    }

    return array_values(array_unique($images));
}

function galleryColumnsFromImages(array $images)
{
    $images = array_values(array_unique(array_filter($images, function ($image) {
        return is_string($image) && trim($image) !== '';
    })));

    $extras = array_slice($images, 1);
    $encodedExtras = $extras ? json_encode($extras, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    if ($extras && $encodedExtras === false) {
        throw new RuntimeException('Não foi possível salvar a galeria de imagens.');
    }

    return [
        'imagem_url' => $images[0] ?? null,
        'galeria_fotos' => $encodedExtras
    ];
}

function managedUploadPath($relativePath, $entityType)
{
    if (!in_array($entityType, ['estabelecimentos', 'eventos'], true)) {
        return null;
    }

    $pattern = '~\Auploads/' . preg_quote($entityType, '~') . '/[a-f0-9]{32}\.(?:jpg|png|webp)\z~i';
    if (!is_string($relativePath) || !preg_match($pattern, $relativePath)) {
        return null;
    }

    $directory = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $entityType);
    $file = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($directory === false || $file === false) {
        return null;
    }

    $prefix = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncasecmp($file, $prefix, strlen($prefix)) !== 0 || !is_file($file)) {
        return null;
    }

    return $file;
}

function deleteManagedUpload($relativePath, $entityType)
{
    $path = managedUploadPath($relativePath, $entityType);
    return $path !== null && @unlink($path);
}

function storeUploadedImages($inputName, $entityType)
{
    if (!in_array($entityType, ['estabelecimentos', 'eventos'], true)) {
        throw new InvalidArgumentException('Tipo de imagem inválido.');
    }

    if (!isset($_FILES[$inputName])) {
        return [];
    }

    $files = $_FILES[$inputName];
    if (!isset($files['name'], $files['type'], $files['tmp_name'], $files['error'], $files['size'])
        || !is_array($files['name'])) {
        throw new RuntimeException('Não foi possível ler os arquivos enviados.');
    }

    $selected = [];
    foreach ($files['name'] as $index => $name) {
        $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_NO_FILE) {
            $selected[] = $index;
        }
    }

    if (count($selected) > MAX_UPLOAD_IMAGE_COUNT) {
        throw new RuntimeException('Envie no máximo 10 imagens por cadastro.');
    }

    if (!$selected) {
        return [];
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('A extensão Fileinfo do PHP precisa estar habilitada.');
    }

    $mimeExtensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $entityType;

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível criar a pasta de imagens.');
    }

    $storedPaths = [];

    try {
        foreach ($selected as $index) {
            $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                    throw new RuntimeException('Cada imagem pode ter no máximo 5 MB.');
                }
                throw new RuntimeException('Uma das imagens não foi enviada corretamente.');
            }

            $temporaryPath = $files['tmp_name'][$index] ?? '';
            $size = isset($files['size'][$index]) ? (int) $files['size'][$index] : 0;
            if ($size < 1 || $size > MAX_UPLOAD_IMAGE_SIZE || !is_uploaded_file($temporaryPath)) {
                throw new RuntimeException('Cada imagem deve ter até 5 MB e ser enviada pelo formulário.');
            }

            $actualSize = filesize($temporaryPath);
            if ($actualSize === false || $actualSize < 1 || $actualSize > MAX_UPLOAD_IMAGE_SIZE) {
                throw new RuntimeException('Cada imagem deve ter até 5 MB.');
            }

            $mime = $finfo->file($temporaryPath);
            if (!isset($mimeExtensions[$mime])) {
                throw new RuntimeException('Use somente imagens JPG, JPEG, PNG ou WebP.');
            }

            $imageInfo = @getimagesize($temporaryPath);
            if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== $mime) {
                throw new RuntimeException('Um dos arquivos enviados não é uma imagem válida.');
            }

            $extension = $mimeExtensions[$mime];
            $absolutePath = null;
            $destination = false;

            for ($attempt = 0; $attempt < 10; $attempt++) {
                $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                $candidate = $directory . DIRECTORY_SEPARATOR . $filename;
                $destination = @fopen($candidate, 'xb');
                if ($destination !== false) {
                    $absolutePath = $candidate;
                    break;
                }

                if (!file_exists($candidate)) {
                    throw new RuntimeException('Não foi possível gravar uma imagem enviada.');
                }
            }

            if ($destination === false || $absolutePath === null) {
                throw new RuntimeException('Não foi possível gerar um nome exclusivo para uma imagem.');
            }

            $storedPaths[] = 'uploads/' . $entityType . '/' . basename($absolutePath);
            $source = @fopen($temporaryPath, 'rb');
            if ($source === false) {
                fclose($destination);
                unlink($absolutePath);
                throw new RuntimeException('Não foi possível ler uma imagem enviada.');
            }

            try {
                $copiedBytes = stream_copy_to_stream($source, $destination);
            } finally {
                fclose($source);
                fclose($destination);
            }

            if ($copiedBytes !== $actualSize) {
                unlink($absolutePath);
                throw new RuntimeException('Não foi possível salvar uma imagem completa.');
            }

        }
    } catch (Throwable $exception) {
        foreach ($storedPaths as $storedPath) {
            $path = managedUploadPath($storedPath, $entityType);
            if ($path !== null && !@unlink($path)) {
                throw new RuntimeException('Não foi possível limpar uma imagem enviada parcialmente.');
            }
        }
        throw $exception;
    }

    return $storedPaths;
}
