<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Cek apakah tombol submit sudah ditekan
if (isset($_POST['submit'])) {
    // Ambil informasi file dari variabel global $_FILES
    $namaFile = $_FILES['berkas']['name'];
    $ukuranFile = $_FILES['berkas']['size'];
    $error = $_FILES['berkas']['error'];
    $tmpName = $_FILES['berkas']['tmp_name'];

    // 1. Cek apakah tidak ada file yang di-upload
    if ($error === 4) {
        echo "<script>alert('Pilih file terlebih dahulu!'); window.location='index.php';</script>";
        return;
    }

    // 2. Batasi ukuran file (Contoh: maksimal 2 MB / 2000000 byte)
    if ($ukuranFile > 2000000) {
        echo "<script>alert('Ukuran file terlalu besar! Maksimal 2MB.'); window.location='index.php';</script>";
        return;
    }

    // Ambil ekstensi asli file untuk digabungkan dengan nama unik
    $ekstensiFile = explode('.', $namaFile);
    $ekstensiFile = strtolower(end($ekstensiFile));

    // 3. Buat nama file baru yang unik
    $namaFileBaru = uniqid();
    $namaFileBaru .= '.';
    $namaFileBaru .= $ekstensiFile;

    // 4. Pastikan folder 'uploads/' ada, jika belum buat secara otomatis
    $tujuanFolder = 'uploads/';
    if (!is_dir($tujuanFolder)) {
        mkdir($tujuanFolder, 0777, true);
    }

    // 5. Pindahkan file dari direktori sementara ke folder tujuan
    $targetFile = $tujuanFolder . $namaFileBaru;
    if (move_uploaded_file($tmpName, $targetFile)) {
        
        // --- MEMBUAT URL PATH OTOMATIS ---
        // Mendapatkan protokol (http atau https)
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        
        // Mendapatkan host/domain (contoh: localhost atau namadomain.com)
        $host = $_SERVER['HTTP_HOST'];
        
        // Mendapatkan path direktori proyek saat ini
        $pathDirektori = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        
        // Menggabungkan semuanya menjadi URL lengkap menuju file
        $urlFile = $protocol . "://" . $host . $pathDirektori . "/" . $targetFile;

        // Tampilkan hasil ke pengguna
        echo "<h3>Upload Berhasil!</h3>";
        echo "Nama file baru: <b>$namaFileBaru</b><br><br>";
        echo "Path URL File:<br>";
        echo "<input type='text' value='$urlFile' style='width: 80%; padding: 5px;' readonly><br><br>";
        echo "<a href='$urlFile' target='_blank'>Buka File di Tab Baru</a> | ";
        echo "<a href='index.php'>Upload file lain</a>";

    } else {
        echo "<script>alert('Gagal mengunggah file!'); window.location='index.php';</script>";
    }
} else {
    // Jika diakses langsung tanpa lewat form, kembalikan ke index.php
    header("Location: index.php");
    exit;
}
?>
