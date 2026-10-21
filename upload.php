<?php
declare(strict_types=1);

// Debugging: aktifkan selama proses perbaikan.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$message = '';
$status = '';
$maxSize = 10 * 1024 * 1024; // 10 MB
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new RuntimeException('File tidak ditemukan.');
        }

        $file = $_FILES['file'];

        if (!isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
            throw new RuntimeException('Data upload tidak lengkap.');
        }

        if (is_array($file['error'])) {
            throw new RuntimeException('Upload file tidak valid.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'File melebihi batas upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE  => 'File melebihi batas ukuran formulir.',
                UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
                UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang dipilih.',
                UPLOAD_ERR_NO_TMP_DIR => 'Direktori sementara PHP tidak tersedia.',
                UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file sementara.',
                UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh ekstensi PHP.',
            ];

            throw new RuntimeException(
                $errors[$file['error']] ?? 'Upload gagal dengan kode ' . $file['error']
            );
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Sumber file upload tidak valid.');
        }

        if ((int) $file['size'] > $maxSize) {
            throw new RuntimeException('Ukuran maksimal file adalah 10 MB.');
        }

        if (!is_writable($uploadDir)) {
            throw new RuntimeException(
                'Direktori tujuan tidak dapat ditulis oleh PHP.'
            );
        }

        // Nama acak agar tidak menimpa file yang sudah ada.
        $originalName = basename(
            str_replace('\\', '/', (string) $file['name'])
        );

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16));

        if ($extension !== '') {
            $extension = preg_replace('/[^a-zA-Z0-9_-]/', '', $extension);

            if ($extension !== '') {
                $filename .= '.' . $extension;
            }
        }

        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException(
                'Gagal menyimpan file. Periksa izin direktori dan log server.'
            );
        }

        $message = 'Upload berhasil: ' . $filename;
        $status = 'success';

    } catch (Throwable $e) {
        error_log('Upload error: ' . $e->getMessage());

        $message = $e->getMessage();
        $status = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload File</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 480px;
            margin: 60px auto;
            padding: 20px;
            background: #f4f4f4;
        }

        .container {
            background: #fff;
            padding: 24px;
            border-radius: 10px;
            box-shadow: 0 2px 12px #0001;
        }

        input, button {
            box-sizing: border-box;
            width: 100%;
            margin-top: 14px;
            padding: 12px;
        }

        button {
            background: #2563eb;
            color: #fff;
            border: 0;
            border-radius: 5px;
            cursor: pointer;
        }

        .success { color: #15803d; }
        .error { color: #dc2626; }

        .filename {
            overflow-wrap: anywhere;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Upload File</h2>

    <?php if ($message !== ''): ?>
        <p class="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="file" required>
        <button type="submit">Upload</button>
    </form>

    <p>Semua ekstensi diterima. Maksimal ukuran file 10 MB.</p>
</div>
</body>
</html>
