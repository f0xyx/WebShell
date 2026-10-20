
<?php
declare(strict_types=1);

$message = '';
$status = '';

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR;
$maxSize = 10 * 1024 * 1024; // 10 MB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file']) || is_array($_FILES['file']['error'])) {
        $message = 'File tidak valid.';
        $status = 'error';
    } else {
        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Upload gagal. Kode: ' . $file['error'];
            $status = 'error';
        } elseif ($file['size'] > $maxSize) {
            $message = 'Ukuran maksimal 10 MB.';
            $status = 'error';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $message = 'File upload tidak valid.';
            $status = 'error';
        } else {
            $originalName = basename(
                str_replace('\\', '/', (string) $file['name'])
            );

            // Pertahankan ekstensi asli tanpa whitelist.
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $filename = bin2hex(random_bytes(12));

            if ($extension !== '') {
                $extension = preg_replace(
                    '/[^a-zA-Z0-9_-]/',
                    '',
                    $extension
                );
                if ($extension !== '') {
                    $filename .= '.' . $extension;
                }
            }

            $destination = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $message = 'Upload berhasil: ' . $filename;
                $status = 'success';
            } else {
                $message = 'Gagal menyimpan file. Periksa izin direktori.';
                $status = 'error';
            }
        }
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
            padding: 24px;
            background: #fff;
            border-radius: 10px;
        }
        input, button {
            box-sizing: border-box;
            width: 100%;
            margin-top: 14px;
            padding: 12px;
        }
        button {
            color: #fff;
            background: #2563eb;
            border: 0;
            border-radius: 5px;
            cursor: pointer;
        }
        .success { color: green; }
        .error { color: red; }
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

    <p>Semua ekstensi diterima. Maksimal 10 MB.</p>
</div>
</body>
</html>
