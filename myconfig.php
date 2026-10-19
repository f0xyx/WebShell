<?php
/**
 * ============================================================================
 *  FoxManager  v1.0
 *  Single-file web file manager (elFinder core + CDN frontend).
 *
 *  - Standalone: taruh file ini di server PHP mana pun, buka di browser.
 *  - Login pakai password sendiri (hash bcrypt di bawah).
 *  - Opsional: otomatis detect & load wp-load.php (WordPress) untuk ambil
 *    ABSPATH/site URL, dan izinkan admin WP yang sudah login bypass password.
 *
 *  Cara ganti password:
 *    php -r "echo password_hash('PASSWORD_BARU', PASSWORD_BCRYPT), PHP_EOL;"
 *  lalu tempel hasilnya ke $FOX['password'] di bawah.
 * ============================================================================
 */

@error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_WARNING & ~E_NOTICE);
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');

/* ============================== KONFIGURASI ============================== */
$FOX = array(
    // Hash bcrypt untuk password login. Default di bawah = "admin" (GANTI!).
    'password'       => '$2y$12$0nTAWA5Qx3Wor.AOAy2KpedxfN8O5QHCQ1Gg6pLhPkqpeJLNsLIk2',

    // Root folder yang ditampilkan. Kosongkan = otomatis:
    //   - ABSPATH bila WordPress terdeteksi
    //   - DOCUMENT_ROOT bila tidak ada WordPress
    'root'           => '',

    // Path eksplisit ke wp-load.php (opsional). Kosongkan = auto-detect ke atas.
    'wp_load'        => '',

    // Otomatis load WordPress (untuk ambil path/URL + izinkan admin WP bypass).
    'bootstrap_wp'   => true,

    // Izinkan admin WordPress yang sudah login untuk langsung masuk tanpa password.
    'allow_wp_admin' => true,

    // Mode read-only (matikan upload/edit/hapus/rename/dll).
    'read_only'      => false,

    // Bahasa UI elFinder (id = Indonesia, en = English).
    'locale'         => 'id',

    // Daftar regex untuk menyembunyikan file/folder, contoh '#/\.git#i'.
    'hidden'         => array(),
);

/* Versi elFinder (frontend CDN). Harus cocok dengan backend (2.1 rev 66). */
$FOX['elfinder_cdn']  = 'https://cdn.jsdelivr.net/gh/Studio-42/elFinder@2.1.66/';
$FOX['jquery_cdn']    = 'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js';
$FOX['jquery_ui_js']  = 'https://cdn.jsdelivr.net/npm/jquery-ui@1.13.2/dist/jquery-ui.min.js';
$FOX['codemirror_cdn'] = 'https://cdn.jsdelivr.net/npm/codemirror@5.65.16/';

/* ============================== SESI & AUTH ============================== */
@ini_set('session.use_only_cookies', '1');
@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function fox_self_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $uri   = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $uri   = preg_replace('/\?.*$/', '', $uri);
    return ($https ? 'https://' : 'http://') . $host . $uri;
}

function fox_is_connector()
{
    return isset($_REQUEST['cmd']) || isset($_REQUEST['fox_connector']);
}

function fox_find_wp_load($start, $override = '')
{
    if ($override !== '' && is_file($override)) {
        return $override;
    }
    $dir = $start;
    for ($i = 0; $i < 14; $i++) {
        $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'wp-load.php';
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return false;
}

function fox_authed()
{
    if (!empty($_SESSION['fox_authed'])) {
        return true;
    }
    $FOX = $GLOBALS['FOX'];
    if ($FOX['bootstrap_wp'] && $FOX['allow_wp_admin']
        && function_exists('is_user_logged_in') && is_user_logged_in()
        && function_exists('current_user_can') && current_user_can('manage_options')
    ) {
        return true;
    }
    return false;
}

/* --- Handle login (JSON) --- */
if (isset($_POST['fox_action']) && $_POST['fox_action'] === 'login') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $pass = isset($_POST['fox_password']) ? (string) $_POST['fox_password'] : '';
    $ok   = $pass !== '' && password_verify($pass, $GLOBALS['FOX']['password']);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['fox_authed'] = true;
        echo json_encode(array('ok' => true));
    } else {
        sleep(1); // sedikit menahan brute-force
        echo json_encode(array('ok' => false, 'error' => 'Password salah.'));
    }
    exit;
}

/* --- Handle logout --- */
if (isset($_GET['fox_action']) && $_GET['fox_action'] === 'logout') {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . fox_self_url());
    exit;
}

/* ============================ BOOTSTRAP WORDPRESS ============================ */
$FOX_WP_LOADED = false;
if ($FOX['bootstrap_wp'] && !defined('ABSPATH')) {
    $fox_wp = fox_find_wp_load(__DIR__, $FOX['wp_load']);
    if ($fox_wp) {
        $GLOBALS['fox_wp_load'] = $fox_wp;
        @include $fox_wp; // include (bukan require) supaya gagal tidak fatal
        if (defined('ABSPATH')) {
            $FOX_WP_LOADED = true;
        }
    }
}

/* ============================ ROOT & URL ============================ */
if ($FOX_WP_LOADED) {
    $FOX_ROOT = $FOX['root'] !== '' ? $FOX['root'] : rtrim(str_replace('\\', '/', ABSPATH), '/');
    $FOX_URL  = function_exists('site_url') ? rtrim(site_url(''), '/') . '/' : '';
} else {
    $docroot  = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $FOX_ROOT = $FOX['root'] !== '' ? $FOX['root'] : ($docroot ? rtrim(str_replace('\\', '/', $docroot), '/') : '/');
    $FOX_URL  = '';
}

if ($FOX_URL === '') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $FOX_URL = ($https ? 'https://' : 'http://')
        . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . '/';
}

$FOX_ROOT = rtrim($FOX_ROOT, '/\\') ?: '/';


/* ===================== [embedded] elFinderSessionInterface ===================== */
if (!interface_exists('elFinderSessionInterface', false)) {
/**
 * elFinder - file manager for web.
 * Session Wrapper Interface.
 *
 * @package elfinder
 * @author  Naoki Sawada
 **/

interface elFinderSessionInterface
{
    /**
     * Session start
     *
     * @return  self
     **/
    public function start();

    /**
     * Session write & close
     *
     * @return  self
     **/
    public function close();

    /**
     * Get session data
     * This method must be equipped with an automatic start / close.
     *
     * @param   string $key   Target key
     * @param   mixed  $empty Return value of if session target key does not exist
     *
     * @return  mixed
     **/
    public function get($key, $empty = '');

    /**
     * Set session data
     * This method must be equipped with an automatic start / close.
     *
     * @param   string $key  Target key
     * @param   mixed  $data Value
     *
     * @return  self
     **/
    public function set($key, $data);

    /**
     * Get session data
     *
     * @param   string $key Target key
     *
     * @return  self
     **/
    public function remove($key);
}

}

/* ===================== [embedded] elFinderSession ===================== */
if (!class_exists('elFinderSession', false)) {
/**
 * elFinder - file manager for web.
 * Session Wrapper Class.
 *
 * @package elfinder
 * @author  Naoki Sawada
 **/

class elFinderSession implements elFinderSessionInterface
{
    /**
     * A flag of session started
     *
     * @var        boolean
     */
    protected $started = false;

    /**
     * To fix PHP bug that duplicate Set-Cookie header to be sent
     *
     * @var        boolean
     * @see        https://bugs.php.net/bug.php?id=75554
     */
    protected $fixCookieRegist = false;

    /**
     * Array of session keys of this instance
     *
     * @var        array
     */
    protected $keys = array();

    /**
     * Is enabled base64encode
     *
     * @var        boolean
     */
    protected $base64encode = false;

    /**
     * Default options array
     *
     * @var        array
     */
    protected $opts = array(
        'base64encode' => false,
        'keys' => array(
            'default' => 'elFinderCaches',
            'netvolume' => 'elFinderNetVolumes'
        ),
        'cookieParams' => array()
    );

    /**
     * Constractor
     *
     * @param      array $opts The options
     *
     * @return     self    Instanse of this class
     */
    public function __construct($opts)
    {
        $this->opts = array_merge($this->opts, $opts);
        $this->base64encode = !empty($this->opts['base64encode']);
        $this->keys = $this->opts['keys'];
        if (function_exists('apache_get_version') || $this->opts['cookieParams']) {
            $this->fixCookieRegist = true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, $empty = null)
    {
        $closed = false;
        if (!$this->started) {
            $closed = true;
            $this->start();
        }

        $data = null;

        if ($this->started) {
            $session =& $this->getSessionRef($key);
            $data = $session;
            if ($data && $this->base64encode) {
                $data = $this->decodeData($data);
            }
        }

        $checkFn = null;
        if (!is_null($empty)) {
            if (is_string($empty)) {
                $checkFn = 'is_string';
            } elseif (is_array($empty)) {
                $checkFn = 'is_array';
            } elseif (is_object($empty)) {
                $checkFn = 'is_object';
            } elseif (is_float($empty)) {
                $checkFn = 'is_float';
            } elseif (is_int($empty)) {
                $checkFn = 'is_int';
            }
        }

        if (is_null($data) || ($checkFn && !$checkFn($data))) {
            $session = $data = $empty;
        }

        if ($closed) {
            $this->close();
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function start()
    {
        set_error_handler(array($this, 'session_start_error'), E_NOTICE | E_WARNING);

        // apache2 SAPI has a bug of session cookie register
        // see https://bugs.php.net/bug.php?id=75554
        // see https://github.com/php/php-src/pull/3231
        if ($this->fixCookieRegist === true) {
            if ((int)ini_get('session.use_cookies') === 1) {
                if (ini_set('session.use_cookies', 0) === false) {
                    $this->fixCookieRegist = false;
                }
            }
        }

        if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
        } else {
            session_start();
        }
        $this->started = session_id() ? true : false;

        restore_error_handler();

        return $this;
    }

    /**
     * Get variable reference of $_SESSION
     *
     * @param string $key key of $_SESSION array
     *
     * @return mixed|null
     */
    protected function & getSessionRef($key)
    {
        $session = null;
        if ($this->started) {
            list($cat, $name) = array_pad(explode('.', $key, 2), 2, null);
            if (is_null($name)) {
                if (!isset($this->keys[$cat])) {
                    $name = $cat;
                    $cat = 'default';
                }
            }
            if (isset($this->keys[$cat])) {
                $cat = $this->keys[$cat];
            } else {
                $name = $cat . '.' . $name;
                $cat = $this->keys['default'];
            }
            if (is_null($name)) {
                if (!isset($_SESSION[$cat])) {
                    $_SESSION[$cat] = null;
                }
                $session =& $_SESSION[$cat];
            } else {
                if (!isset($_SESSION[$cat]) || !is_array($_SESSION[$cat])) {
                    $_SESSION[$cat] = array();
                }
                if (!isset($_SESSION[$cat][$name])) {
                    $_SESSION[$cat][$name] = null;
                }
                $session =& $_SESSION[$cat][$name];
            }
        }
        return $session;
    }

    /**
     * base64 decode of session val
     *
     * @param $data
     *
     * @return bool|mixed|string|null
     */
    protected function decodeData($data)
    {
        if ($this->base64encode) {
            if (is_string($data)) {
                if (($data = base64_decode($data)) !== false) {
                    $data = unserialize($data);
                } else {
                    $data = null;
                }
            } else {
                $data = null;
            }
        }
        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->started) {
            if ($this->fixCookieRegist === true) {
                // regist cookie only once for apache2 SAPI
                $cParm = session_get_cookie_params();
                if ($this->opts['cookieParams'] && is_array($this->opts['cookieParams'])) {
                    $cParm = array_merge($cParm, $this->opts['cookieParams']);
                }
                if (version_compare(PHP_VERSION, '7.3', '<')) {
                    setcookie(session_name(), session_id(), 0, $cParm['path'] . (!empty($cParm['SameSite'])? '; SameSite=' . $cParm['SameSite'] : ''), $cParm['domain'], $cParm['secure'], $cParm['httponly']);
                } else {
                    $allows = array('expires' => true, 'path' => true, 'domain' => true, 'secure' => true, 'httponly' => true, 'samesite' => true);
                    foreach(array_keys($cParm) as $_k) {
                        if (!isset($allows[$_k])) {
                            unset($cParm[$_k]);
                        }
                    }
                    setcookie(session_name(), session_id(), $cParm);
                }
                $this->fixCookieRegist = false;
            }
            session_write_close();
        }
        $this->started = false;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $data)
    {
        $closed = false;
        if (!$this->started) {
            $closed = true;
            $this->start();
        }
        $session =& $this->getSessionRef($key);
        if ($this->base64encode) {
            $data = $this->encodeData($data);
        }
        $session = $data;

        if ($closed) {
            $this->close();
        }

        return $this;
    }

    /**
     * base64 encode for session val
     *
     * @param $data
     *
     * @return string
     */
    protected function encodeData($data)
    {
        if ($this->base64encode) {
            $data = base64_encode(serialize($data));
        }
        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function remove($key)
    {
        $closed = false;
        if (!$this->started) {
            $closed = true;
            $this->start();
        }

        list($cat, $name) = array_pad(explode('.', $key, 2), 2, null);
        if (is_null($name)) {
            if (!isset($this->keys[$cat])) {
                $name = $cat;
                $cat = 'default';
            }
        }
        if (isset($this->keys[$cat])) {
            $cat = $this->keys[$cat];
        } else {
            $name = $cat . '.' . $name;
            $cat = $this->keys['default'];
        }
        if (is_null($name)) {
            unset($_SESSION[$cat]);
        } else {
            if (isset($_SESSION[$cat]) && is_array($_SESSION[$cat])) {
                unset($_SESSION[$cat][$name]);
            }
        }

        if ($closed) {
            $this->close();
        }

        return $this;
    }

    /**
     * sessioin error handler (Only for suppression of error at session start)
     *
     * @param $errno
     * @param $errstr
     */
    protected function session_start_error($errno, $errstr)
    {
    }
}

}

/* ===================== [embedded] elFinderVolumeDriver ===================== */
if (!class_exists('elFinderVolumeDriver', false)) {
/**
 * Base class for elFinder volume.
 * Provide 2 layers:
 *  1. Public API (commands)
 *  2. abstract fs API
 * All abstract methods begin with "_"
 *
 * @author Dmitry (dio) Levashov
 * @author Troex Nevelin
 * @author Alexey Sukhotin
 * @method netmountPrepare(array $options)
 * @method postNetmount(array $options)
 */
abstract class elFinderVolumeDriver
{

    /**
     * Net mount key
     *
     * @var string
     **/
    public $netMountKey = '';

    /**
     * Request args
     * $_POST or $_GET values
     *
     * @var array
     */
    protected $ARGS = array();

    /**
     * Driver id
     * Must be started from letter and contains [a-z0-9]
     * Used as part of volume id
     *
     * @var string
     **/
    protected $driverId = 'a';

    /**
     * Volume id - used as prefix for files hashes
     *
     * @var string
     **/
    protected $id = '';

    /**
     * Flag - volume "mounted" and available
     *
     * @var bool
     **/
    protected $mounted = false;

    /**
     * Root directory path
     *
     * @var string
     **/
    protected $root = '';

    /**
     * Root basename | alias
     *
     * @var string
     **/
    protected $rootName = '';

    /**
     * Default directory to open
     *
     * @var string
     **/
    protected $startPath = '';

    /**
     * Base URL
     *
     * @var string
     **/
    protected $URL = '';

    /**
     * Path to temporary directory
     *
     * @var string
     */
    protected $tmp;

    /**
     * A file save destination path when a temporary content URL is required
     * on a network volume or the like
     * If not specified, it tries to use "Connector Path/../files/.tmb".
     *
     * @var string
     */
    protected $tmpLinkPath = '';

    /**
     * A file save destination URL when a temporary content URL is required
     * on a network volume or the like
     * If not specified, it tries to use "Connector URL/../files/.tmb".
     *
     * @var string
     */
    protected $tmpLinkUrl = '';

    /**
     * Thumbnails dir path
     *
     * @var string
     **/
    protected $tmbPath = '';

    /**
     * Is thumbnails dir writable
     *
     * @var bool
     **/
    protected $tmbPathWritable = false;

    /**
     * Thumbnails base URL
     *
     * @var string
     **/
    protected $tmbURL = '';

    /**
     * Thumbnails size in px
     *
     * @var int
     **/
    protected $tmbSize = 48;

    /**
     * Image manipulation lib name
     * auto|imagick|gd|convert
     *
     * @var string
     **/
    protected $imgLib = 'auto';

    /**
     * Video to Image converter
     *
     * @var array
     */
    protected $imgConverter = array();

    /**
     * Library to crypt files name
     *
     * @var string
     **/
    protected $cryptLib = '';

    /**
     * Archivers config
     *
     * @var array
     **/
    protected $archivers = array(
        'create' => array(),
        'extract' => array()
    );

    /**
     * Static var of $this->options['maxArcFilesSize']
     * 
     * @var int|string
     */
    protected static $maxArcFilesSize;

    /**
     * Server character encoding
     *
     * @var string or null
     **/
    protected $encoding = null;

    /**
     * How many subdirs levels return for tree
     *
     * @var int
     **/
    protected $treeDeep = 1;

    /**
     * Errors from last failed action
     *
     * @var array
     **/
    protected $error = array();

    /**
     * Today 24:00 timestamp
     *
     * @var int
     **/
    protected $today = 0;

    /**
     * Yesterday 24:00 timestamp
     *
     * @var int
     **/
    protected $yesterday = 0;

    /**
     * Force make dirctory on extract
     *
     * @var int
     **/
    protected $extractToNewdir = 'auto';

    /**
     * Object configuration
     *
     * @var array
     **/
    protected $options = array(
        // Driver ID (Prefix of volume ID), Normally, the value specified for each volume driver is used.
        'driverId' => '',
        // Id (Suffix of volume ID), Normally, the number incremented according to the specified number of volumes is used.
        'id' => '',
        // revision id of root directory that uses for caching control of root stat
        'rootRev' => '',
        // driver type it uses volume root's CSS class name. e.g. 'group' -> Adds 'elfinder-group' to CSS class name.
        'type' => '',
        // root directory path
        'path' => '',
        // Folder hash value on elFinder to be the parent of this volume
        'phash' => '',
        // Folder hash value on elFinder to trash bin of this volume, it require 'copyJoin' to true
        'trashHash' => '',
        // open this path on initial request instead of root path
        'startPath' => '',
        // how many subdirs levels return per request
        'treeDeep' => 1,
        // root url, not set to URL via the connector. If you want to hide the file URL, do not set this value. (replacement for old "fileURL" option)
        'URL' => '',
        // enable onetime URL to a file - (true, false, 'auto' (true if a temporary directory is available) or callable (A function that return onetime URL))
        'onetimeUrl' => 'auto',
        // directory link url to own manager url with folder hash (`true`, `false`, `'hide'`(No show) or default `'auto'`: URL is empty then `true` else `false`)
        'dirUrlOwn' => 'auto',
        // directory separator. required by client to show paths correctly
        'separator' => DIRECTORY_SEPARATOR,
        // Use '/' as directory separator when the path hash encode/decode on the Windows server too
        'winHashFix' => false,
        // Server character encoding (default is '': UTF-8)
        'encoding' => '',
        // for convert character encoding (default is '': Not change locale)
        'locale' => '',
        // URL of volume icon image
        'icon' => '',
        // CSS Class of volume root in tree
        'rootCssClass' => '',
        // Items to disable session caching
        'noSessionCache' => array(),
        // enable i18n folder name that convert name to elFinderInstance.messages['folder_'+name]
        'i18nFolderName' => false,
        // Search timeout (sec)
        'searchTimeout' => 30,
        // Search exclusion directory regex pattern (require demiliter e.g. '#/path/to/exclude_directory#i')
        'searchExDirReg' => '',
        // library to crypt/uncrypt files names (not implemented)
        'cryptLib' => '',
        // how to detect files mimetypes. (auto/internal/finfo/mime_content_type)
        'mimeDetect' => 'auto',
        // mime.types file path (for mimeDetect==internal)
        'mimefile' => '',
        // Static extension/MIME of general server side scripts to security issues
        'staticMineMap' => array(
            'php:*' => 'text/x-php',
            'pht:*' => 'text/x-php',
            'php3:*' => 'text/x-php',
            'php4:*' => 'text/x-php',
            'php5:*' => 'text/x-php',
            'php7:*' => 'text/x-php',
            'php8:*' => 'text/x-php',
            'php9:*' => 'text/x-php',
            'phtml:*' => 'text/x-php',
            'phar:*' => 'text/x-php',
            'cgi:*' => 'text/x-httpd-cgi',
            'pl:*' => 'text/x-perl',
            'asp:*' => 'text/x-asap',
            'aspx:*' => 'text/x-asap',
            'py:*' => 'text/x-python',
            'rb:*' => 'text/x-ruby',
            'jsp:*' => 'text/x-jsp'
        ),
        // mime type normalize map : Array '[ext]:[detected mime type]' => '[normalized mime]'
        'mimeMap' => array(
            'md:application/x-genesis-rom' => 'text/x-markdown',
            'md:text/plain' => 'text/x-markdown',
            'markdown:text/plain' => 'text/x-markdown',
            'css:text/x-asm' => 'text/css',
            'css:text/plain' => 'text/css',
            'csv:text/plain' => 'text/csv',
            'java:text/x-c' => 'text/x-java-source',
            'json:text/plain' => 'application/json',
            'sql:text/plain' => 'text/x-sql',
            'rtf:text/rtf' => 'application/rtf',
            'rtfd:text/rtfd' => 'application/rtfd',
            'ico:image/vnd.microsoft.icon' => 'image/x-icon',
            'svg:text/plain' => 'image/svg+xml',
            'pxd:application/octet-stream' => 'image/x-pixlr-data',
            'dng:image/tiff' => 'image/x-adobe-dng',
            'sketch:application/zip' => 'image/x-sketch',
            'sketch:application/octet-stream' => 'image/x-sketch',
            'xcf:application/octet-stream' => 'image/x-xcf',
            'amr:application/octet-stream' => 'audio/amr',
            'm4a:video/mp4' => 'audio/mp4',
            'oga:application/ogg' => 'audio/ogg',
            'ogv:application/ogg' => 'video/ogg',
            'zip:application/x-zip' => 'application/zip',
            'm3u8:text/plain' => 'application/x-mpegURL',
            'mpd:text/plain' => 'application/dash+xml',
            'mpd:application/xml' => 'application/dash+xml',
            '*:application/x-dosexec' => 'application/x-executable',
            'doc:application/vnd.ms-office' => 'application/msword',
            'xls:application/vnd.ms-office' => 'application/vnd.ms-excel',
            'ppt:application/vnd.ms-office' => 'application/vnd.ms-powerpoint',
            'yml:text/plain' => 'text/x-yaml',
            'ai:application/pdf' => 'application/postscript',
            'cgm:text/plain' => 'image/cgm',
            'dxf:text/plain' => 'image/vnd.dxf',
            'dds:application/octet-stream' => 'image/vnd-ms.dds',
            'hpgl:text/plain' => 'application/vnd.hp-hpgl',
            'igs:text/plain' => 'model/iges',
            'iges:text/plain' => 'model/iges',
            'plt:application/octet-stream' => 'application/plt',
            'plt:text/plain' => 'application/plt',
            'sat:text/plain' => 'application/sat',
            'step:text/plain' => 'application/step',
            'stp:text/plain' => 'application/step'
        ),
        // An option to add MimeMap to the `mimeMap` option
        // Array '[ext]:[detected mime type]' => '[normalized mime]'
        'additionalMimeMap' => array(),
        // MIME-Type of filetype detected as unknown
        'mimeTypeUnknown' => 'application/octet-stream',
        // MIME regex of send HTTP header "Content-Disposition: inline" or allow preview in quicklook
        // '.' is allow inline of all of MIME types
        // '$^' is not allow inline of all of MIME types
        'dispInlineRegex' => '^(?:(?:video|audio)|image/(?!.+\+xml)|application/(?:ogg|x-mpegURL|dash\+xml)|(?:text/plain|application/pdf)$)',
        // temporary content URL's base path
        'tmpLinkPath' => '',
        // temporary content URL's base URL
        'tmpLinkUrl' => '',
        // directory for thumbnails
        'tmbPath' => '.tmb',
        // mode to create thumbnails dir
        'tmbPathMode' => 0777,
        // thumbnails dir URL. Set it if store thumbnails outside root directory
        'tmbURL' => '',
        // thumbnails size (px)
        'tmbSize' => 48,
        // thumbnails crop (true - crop, false - scale image to fit thumbnail size)
        'tmbCrop' => true,
        // thumbnail URL require custom data as the GET query
        'tmbReqCustomData' => false,
        // thumbnails background color (hex #rrggbb or 'transparent')
        'tmbBgColor' => 'transparent',
        // image rotate fallback background color (hex #rrggbb)
        'bgColorFb' => '#ffffff',
        // image manipulations library (imagick|gd|convert|auto|none, none - Does not check the image library at all.)
        'imgLib' => 'auto',
        // Fallback self image to thumbnail (nothing imgLib)
        'tmbFbSelf' => true,
        // Video to Image converters ['TYPE or MIME' => ['func' => function($file){ /* Converts $file to Image */ return true; }, 'maxlen' => (int)TransferLength]]
        'imgConverter' => array(),
        // Max length of transfer to image converter
        'tmbVideoConvLen' => 10000000,
        // Captre point seccond
        'tmbVideoConvSec' => 6,
        // Life time (hour) for thumbnail garbage collection ("0" means no GC)
        'tmbGcMaxlifeHour' => 0,
        // Percentage of garbage collection executed for thumbnail creation command ("1" means "1%")
        'tmbGcPercentage' => 1,
        // Resource path of fallback icon images defailt: php/resouces
        'resourcePath' => '',
        // Jpeg image saveing quality
        'jpgQuality' => 100,
        // Save as progressive JPEG on image editing
        'jpgProgressive' => true,
        // enable to get substitute image with command `dim`
        'substituteImg' => true,
        // on paste file -  if true - old file will be replaced with new one, if false new file get name - original_name-number.ext
        'copyOverwrite' => true,
        // if true - join new and old directories content on paste
        'copyJoin' => true,
        // on upload -  if true - old file will be replaced with new one, if false new file get name - original_name-number.ext
        'uploadOverwrite' => true,
        // mimetypes allowed to upload
        'uploadAllow' => array(),
        // mimetypes not allowed to upload
        'uploadDeny' => array(),
        // order to process uploadAllow and uploadDeny options
        'uploadOrder' => array('deny', 'allow'),
        // maximum upload file size. NOTE - this is size for every uploaded files
        'uploadMaxSize' => 0,
        // Maximum number of folders that can be created at one time. (0: unlimited)
        'uploadMaxMkdirs' => 0,
        // maximum number of chunked upload connection. `-1` to disable chunked upload
        'uploadMaxConn' => 3,
        // maximum get file size. NOTE - Maximum value is 50% of PHP memory_limit
        'getMaxSize' => 0,
        // files dates format
        'dateFormat' => 'j M Y H:i',
        // files time format
        'timeFormat' => 'H:i',
        // if true - every folder will be check for children folders, -1 - every folder will be check asynchronously, false -  all folders will be marked as having subfolders
        'checkSubfolders' => true, // true, false or -1
        // allow to copy from this volume to other ones?
        'copyFrom' => true,
        // allow to copy from other volumes to this one?
        'copyTo' => true,
        // cmd duplicate suffix format e.g. '_%s_' to without spaces
        'duplicateSuffix' => ' %s ',
        // unique name numbar format e.g. '(%d)' to (1), (2)...
        'uniqueNumFormat' => '%d',
        // list of commands disabled on this root
        'disabled' => array(),
        // enable file owner, group & mode info, `false` to inactivate "chmod" command.
        'statOwner' => false,
        // allow exec chmod of read-only files
        'allowChmodReadOnly' => false,
        // regexp or function name to validate new file name
        'acceptedName' => '/^[^\.].*/', // Notice: overwritten it in some volume drivers contractor
        // regexp or function name to validate new directory name
        'acceptedDirname' => '', // used `acceptedName` if empty value
        // function/class method to control files permissions
        'accessControl' => null,
        // some data required by access control
        'accessControlData' => null,
        // root stat that return without asking the system when mounted and not the current volume. Query to the system with false. array|false
        'rapidRootStat' => array(
            'read' => true,
            'write' => true,
            'locked' => false,
            'hidden' => false,
            'size' => 0,  // Unknown
            'ts' => 0,    // Unknown
            'dirs' => -1, // Check on demand for subdirectories
            'mime' => 'directory'
        ),
        // default permissions.
        'defaults' => array(
            'read' => true,
            'write' => true,
            'locked' => false,
            'hidden' => false
        ),
        // files attributes
        'attributes' => array(),
        // max allowed archive files size (0 - no limit)
        'maxArcFilesSize' => '2G',
        // Allowed archive's mimetypes to create. Leave empty for all available types.
        'archiveMimes' => array(),
        // Manual config for archivers. See example below. Leave empty for auto detect
        'archivers' => array(),
        // Use Archive function for remote volume
        'useRemoteArchive' => false,
        // plugin settings
        'plugin' => array(),
        // Is support parent directory time stamp update on add|remove|rename item
        // Default `null` is auto detection that is LocalFileSystem, FTP or Dropbox are `true`
        'syncChkAsTs' => null,
        // Long pooling sync checker function for syncChkAsTs is true
        // Calls with args (TARGET DIRCTORY PATH, STAND-BY(sec), OLD TIMESTAMP, VOLUME DRIVER INSTANCE, ELFINDER INSTANCE)
        // This function must return the following values. Changed: New Timestamp or Same: Old Timestamp or Error: false
        // Default `null` is try use elFinderVolumeLocalFileSystem::localFileSystemInotify() on LocalFileSystem driver
        // another driver use elFinder stat() checker
        'syncCheckFunc' => null,
        // Long polling sync stand-by time (sec)
        'plStandby' => 30,
        // Sleep time (sec) for elFinder stat() checker (syncChkAsTs is true)
        'tsPlSleep' => 10,
        // Sleep time (sec) for elFinder ls() checker (syncChkAsTs is false)
        'lsPlSleep' => 30,
        // Client side sync interval minimum (ms)
        // Default `null` is auto set to ('tsPlSleep' or 'lsPlSleep') * 1000
        // `0` to disable auto sync
        'syncMinMs' => null,
        // required to fix bug on macos
        // However, we recommend to use the Normalizer plugin instead this option
        'utf8fix' => false,
        //                           й                 ё              Й               Ё              Ø         Å
        'utf8patterns' => array("\u0438\u0306", "\u0435\u0308", "\u0418\u0306", "\u0415\u0308", "\u00d8A", "\u030a"),
        'utf8replace' => array("\u0439", "\u0451", "\u0419", "\u0401", "\u00d8", "\u00c5"),
        // cache control HTTP headers for commands `file` and  `get`
        'cacheHeaders' => array(
            'Cache-Control: max-age=3600',
            'Expires:',
            'Pragma:'
        ),
        // Header to use to accelerate sending local files to clients (e.g. 'X-Sendfile', 'X-Accel-Redirect')
        'xsendfile' => '',
        // Root path to xsendfile target. Probably, this is required for 'X-Accel-Redirect' on Nginx.
        'xsendfilePath' => ''
    );

    /**
     * Defaults permissions
     *
     * @var array
     **/
    protected $defaults = array(
        'read' => true,
        'write' => true,
        'locked' => false,
        'hidden' => false
    );

    /**
     * Access control function/class
     *
     * @var mixed
     **/
    protected $attributes = array();

    /**
     * Access control function/class
     *
     * @var mixed
     **/
    protected $access = null;

    /**
     * Mime types allowed to upload
     *
     * @var array
     **/
    protected $uploadAllow = array();

    /**
     * Mime types denied to upload
     *
     * @var array
     **/
    protected $uploadDeny = array();

    /**
     * Order to validate uploadAllow and uploadDeny
     *
     * @var array
     **/
    protected $uploadOrder = array();

    /**
     * Maximum allowed upload file size.
     * Set as number or string with unit - "10M", "500K", "1G"
     *
     * @var int|string
     **/
    protected $uploadMaxSize = 0;

    /**
     * Run time setting of overwrite items on upload
     *
     * @var string
     */
    protected $uploadOverwrite = true;

    /**
     * Maximum allowed get file size.
     * Set as number or string with unit - "10M", "500K", "1G"
     *
     * @var int|string
     **/
    protected $getMaxSize = -1;

    /**
     * Mimetype detect method
     *
     * @var string
     **/
    protected $mimeDetect = 'auto';

    /**
     * Flag - mimetypes from externail file was loaded
     *
     * @var bool
     **/
    private static $mimetypesLoaded = false;

    /**
     * Finfo resource for mimeDetect == 'finfo'
     *
     * @var resource
     **/
    protected $finfo = null;

    /**
     * List of disabled client's commands
     *
     * @var array
     **/
    protected $disabled = array();

    /**
     * overwrite extensions/mimetypes to mime.types
     *
     * @var array
     **/
    protected static $mimetypes = array(
        // applications
        'exe' => 'application/x-executable',
        'jar' => 'application/x-jar',
        // archives
        'gz' => 'application/x-gzip',
        'tgz' => 'application/x-gzip',
        'tbz' => 'application/x-bzip2',
        'rar' => 'application/x-rar',
        // texts
        'php' => 'text/x-php',
        'js' => 'text/javascript',
        'rtfd' => 'application/rtfd',
        'py' => 'text/x-python',
        'rb' => 'text/x-ruby',
        'sh' => 'text/x-shellscript',
        'pl' => 'text/x-perl',
        'xml' => 'text/xml',
        'c' => 'text/x-csrc',
        'h' => 'text/x-chdr',
        'cpp' => 'text/x-c++src',
        'hh' => 'text/x-c++hdr',
        'md' => 'text/x-markdown',
        'markdown' => 'text/x-markdown',
        'yml' => 'text/x-yaml',
        // images
        'bmp' => 'image/x-ms-bmp',
        'tga' => 'image/x-targa',
        'xbm' => 'image/xbm',
        'pxm' => 'image/pxm',
        //audio
        'wav' => 'audio/wav',
        // video
        'dv' => 'video/x-dv',
        'wm' => 'video/x-ms-wmv',
        'ogm' => 'video/ogg',
        'm2ts' => 'video/MP2T',
        'mts' => 'video/MP2T',
        'ts' => 'video/MP2T',
        'm3u8' => 'application/x-mpegURL',
        'mpd' => 'application/dash+xml'
    );

    /**
     * Directory separator - required by client
     *
     * @var string
     **/
    protected $separator = DIRECTORY_SEPARATOR;

    /**
     * Directory separator for decode/encode hash
     *
     * @var string
     **/
    protected $separatorForHash = '';

    /**
     * System Root path (Unix like: '/', Windows: '\', 'C:\' or 'D:\'...)
     *
     * @var string
     **/
    protected $systemRoot = DIRECTORY_SEPARATOR;

    /**
     * Mimetypes allowed to display
     *
     * @var array
     **/
    protected $onlyMimes = array();

    /**
     * Store files moved or overwrited files info
     *
     * @var array
     **/
    protected $removed = array();

    /**
     * Store files added files info
     *
     * @var array
     **/
    protected $added = array();

    /**
     * Cache storage
     *
     * @var array
     **/
    protected $cache = array();

    /**
     * Cache by folders
     *
     * @var array
     **/
    protected $dirsCache = array();

    /**
     * You should use `$this->sessionCache['subdirs']` instead
     *
     * @var array
     * @deprecated
     */
    protected $subdirsCache = array();

    /**
     * This volume session cache
     *
     * @var array
     */
    protected $sessionCache;

    /**
     * Session caching item list
     *
     * @var array
     */
    protected $sessionCaching = array('rootstat' => true, 'subdirs' => true);

    /**
     * elFinder session wrapper object
     *
     * @var elFinderSessionInterface
     */
    protected $session;

    /**
     * Search start time
     *
     * @var int
     */
    protected $searchStart;

    /**
     * Current query word on doSearch
     *
     * @var array
     **/
    protected $doSearchCurrentQuery = array();

    /**
     * Is root modified (for clear root stat cache)
     *
     * @var bool
     */
    protected $rootModified = false;

    /**
     * Is disable of command `url`
     *
     * @var string
     */
    protected $disabledGetUrl = false;

    /**
     * Accepted filename validator
     *
     * @var string | callable
     */
    protected $nameValidator;

    /**
     * Accepted dirname validator
     *
     * @var string | callable
     */
    protected $dirnameValidator;

    /**
     * This request require online state
     *
     * @var boolean
     */
    protected $needOnline;

    /*********************************************************************/
    /*                            INITIALIZATION                         */
    /*********************************************************************/

    /**
     * Sets the need online.
     *
     * @param  boolean  $state  The state
     */
    public function setNeedOnline($state = null)
    {
        if ($state !== null) {
            $this->needOnline = (bool)$state;
            return;
        }

        $need = false;
        $arg = $this->ARGS;
        $id = $this->id;

        $target = !empty($arg['target'])? $arg['target'] : (!empty($arg['dst'])? $arg['dst'] : '');
        $targets = !empty($arg['targets'])? $arg['targets'] : array();
        if (!is_array($targets)) {
            $targets = array($targets);
        }

        if ($target && strpos($target, $id) === 0) {
            $need = true;
        } else if ($targets) {
            foreach($targets as $t) {
                if ($t && strpos($t, $id) === 0) {
                    $need = true;
                    break;
                }
            }
        }

        $this->needOnline = $need;
    }

    /**
     * Prepare driver before mount volume.
     * Return true if volume is ready.
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function init()
    {
        return true;
    }

    /**
     * Configure after successfull mount.
     * By default set thumbnails path and image manipulation library.
     *
     * @return void
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function configure()
    {
        // set thumbnails path
        $path = $this->options['tmbPath'];
        if ($path) {
            if (!file_exists($path)) {
                if (mkdir($path)) {
                    chmod($path, $this->options['tmbPathMode']);
                } else {
                    $path = '';
                }
            }

            if (is_dir($path) && is_readable($path)) {
                $this->tmbPath = $path;
                $this->tmbPathWritable = is_writable($path);
            }
        }
        // set resouce path
        if (!is_dir($this->options['resourcePath'])) {
            $this->options['resourcePath'] = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'resources';
        }

        // set image manipulation library
        $type = preg_match('/^(imagick|gd|convert|auto|none)$/i', $this->options['imgLib'])
            ? strtolower($this->options['imgLib'])
            : 'auto';

        if ($type === 'none') {
            $this->imgLib = '';
        } else {
            if (($type === 'imagick' || $type === 'auto') && extension_loaded('imagick')) {
                $this->imgLib = 'imagick';
            } else if (($type === 'gd' || $type === 'auto') && function_exists('gd_info')) {
                $this->imgLib = 'gd';
            } else {
                $convertCache = 'imgLibConvert';
                if (($convertCmd = $this->session->get($convertCache, false)) !== false) {
                    $this->imgLib = $convertCmd;
                } else {
                    $this->imgLib = ($this->procExec(ELFINDER_CONVERT_PATH . ' -version') === 0) ? 'convert' : '';
                    $this->session->set($convertCache, $this->imgLib);
                }
            }
            if ($type !== 'auto' && $this->imgLib === '') {
                // fallback
                $this->imgLib = extension_loaded('imagick') ? 'imagick' : (function_exists('gd_info') ? 'gd' : '');
            }
        }

        // check video to img converter
        if (!empty($this->options['imgConverter']) && is_array($this->options['imgConverter'])) {
            foreach ($this->options['imgConverter'] as $_type => $_converter) {
                if (isset($_converter['func'])) {
                    $this->imgConverter[strtolower($_type)] = $_converter;
                }
            }
        }
        if (!isset($this->imgConverter['video'])) {
            $videoLibCache = 'videoLib';
            if (($videoLibCmd = $this->session->get($videoLibCache, false)) === false) {
                $videoLibCmd = ($this->procExec(ELFINDER_FFMPEG_PATH . ' -version') === 0) ? 'ffmpeg' : '';
                $this->session->set($videoLibCache, $videoLibCmd);
            }
            if ($videoLibCmd) {
                $this->imgConverter['video'] = array(
                    'func' => array($this, $videoLibCmd . 'ToImg'),
                    'maxlen' => $this->options['tmbVideoConvLen']
                );
            }
        }

        // check onetimeUrl
        if (strtolower($this->options['onetimeUrl']) === 'auto') {
            $this->options['onetimeUrl'] = elFinder::getStaticVar('commonTempPath')? true : false;
        }

        // check archivers
        if (empty($this->archivers['create'])) {
            $this->disabled[] = 'archive';
        }
        if (empty($this->archivers['extract'])) {
            $this->disabled[] = 'extract';
        }
        $_arc = $this->getArchivers();
        if (empty($_arc['create'])) {
            $this->disabled[] = 'zipdl';
        }

        if ($this->options['maxArcFilesSize']) {
            $this->options['maxArcFilesSize'] = elFinder::getIniBytes('', $this->options['maxArcFilesSize']);
        }
        self::$maxArcFilesSize = $this->options['maxArcFilesSize'];

        // check 'statOwner' for command `chmod`
        if (empty($this->options['statOwner'])) {
            $this->disabled[] = 'chmod';
        }

        // check 'mimeMap'
        if (!is_array($this->options['mimeMap'])) {
            $this->options['mimeMap'] = array();
        }
        if (is_array($this->options['staticMineMap']) && $this->options['staticMineMap']) {
            $this->options['mimeMap'] = array_merge($this->options['mimeMap'], $this->options['staticMineMap']);
        }
        if (is_array($this->options['additionalMimeMap']) && $this->options['additionalMimeMap']) {
            $this->options['mimeMap'] = array_merge($this->options['mimeMap'], $this->options['additionalMimeMap']);
        }

        // check 'url' in disabled commands
        if (in_array('url', $this->disabled)) {
            $this->disabledGetUrl = true;
        }

        // set run time setting uploadOverwrite
        $this->uploadOverwrite = $this->options['uploadOverwrite'];
    }

    /**
     * @deprecated
     */
    protected function sessionRestart()
    {
        $this->sessionCache = $this->session->start()->get($this->id, array());
        return true;
    }

    /*********************************************************************/
    /*                              PUBLIC API                           */
    /*********************************************************************/

    /**
     * Return driver id. Used as a part of volume id.
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    public function driverId()
    {
        return $this->driverId;
    }

    /**
     * Return volume id
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    public function id()
    {
        return $this->id;
    }

    /**
     * Assign elFinder session wrapper object
     *
     * @param  $session  elFinderSessionInterface
     */
    public function setSession($session)
    {
        $this->session = $session;
    }

    /**
     * Get elFinder sesson wrapper object
     *
     * @return object  The session object
     */
    public function getSession()
    {
        return $this->session;
    }

    /**
     * Save session cache data
     * Calls this function before umount this volume on elFinder::exec()
     *
     * @return void
     */
    public function saveSessionCache()
    {
        $this->session->set($this->id, $this->sessionCache);
    }

    /**
     * Return debug info for client
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    public function debug()
    {
        return array(
            'id' => $this->id(),
            'name' => strtolower(substr(get_class($this), strlen('elfinderdriver'))),
            'mimeDetect' => $this->mimeDetect,
            'imgLib' => $this->imgLib
        );
    }

    /**
     * chmod a file or folder
     *
     * @param  string $hash file or folder hash to chmod
     * @param  string $mode octal string representing new permissions
     *
     * @return array|false
     * @author David Bartle
     **/
    public function chmod($hash, $mode)
    {
        if ($this->commandDisabled('chmod')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if (!($file = $this->file($hash))) {
            return $this->setError(elFinder::ERROR_FILE_NOT_FOUND);
        }

        if (!$this->options['allowChmodReadOnly']) {
            if (!$this->attr($this->decode($hash), 'write', null, ($file['mime'] === 'directory'))) {
                return $this->setError(elFinder::ERROR_PERM_DENIED, $file['name']);
            }
        }

        $path = $this->decode($hash);
        $write = $file['write'];

        if ($this->convEncOut(!$this->_chmod($this->convEncIn($path), $mode))) {
            return $this->setError(elFinder::ERROR_PERM_DENIED, $file['name']);
        }

        $this->clearstatcache();
        if ($path == $this->root) {
            $this->rootModified = true;
        }

        if ($file = $this->stat($path)) {
            $files = array($file);
            if ($file['mime'] === 'directory' && $write !== $file['write']) {
                foreach ($this->getScandir($path) as $stat) {
                    if ($this->mimeAccepted($stat['mime'])) {
                        $files[] = $stat;
                    }
                }
            }
            return $files;
        } else {
            return $this->setError(elFinder::ERROR_FILE_NOT_FOUND);
        }
    }

    /**
     * stat a file or folder for elFinder cmd exec
     *
     * @param  string $hash file or folder hash to chmod
     *
     * @return array
     * @author Naoki Sawada
     **/
    public function fstat($hash)
    {
        $path = $this->decode($hash);
        return $this->stat($path);
    }

    /**
     * Clear PHP stat cache & all of inner stat caches
     */
    public function clearstatcache()
    {
        clearstatcache();
        $this->clearcache();
    }

    /**
     * Clear inner stat caches for target hash
     *
     * @param string $hash
     */
    public function clearcaches($hash = null)
    {
        if ($hash === null) {
            $this->clearcache();
        } else {
            $path = $this->decode($hash);
            unset($this->cache[$path], $this->dirsCache[$path]);
        }
    }

    /**
     * "Mount" volume.
     * Return true if volume available for read or write,
     * false - otherwise
     *
     * @param array $opts
     *
     * @return bool
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     */
    public function mount(array $opts)
    {
        $this->options = array_merge($this->options, $opts);

        if (!isset($this->options['path']) || $this->options['path'] === '') {
            return $this->setError('Path undefined.');
        }

        if (!$this->session) {
            return $this->setError('Session wrapper dose not set. Need to `$volume->setSession(elFinderSessionInterface);` before mount.');
        }
        if (!($this->session instanceof elFinderSessionInterface)) {
            return $this->setError('Session wrapper instance must be "elFinderSessionInterface".');
        }

        // set driverId
        if (!empty($this->options['driverId'])) {
            $this->driverId = $this->options['driverId'];
        }

        $this->id = $this->driverId . (!empty($this->options['id']) ? $this->options['id'] : elFinder::$volumesCnt++) . '_';
        $this->root = $this->normpathCE($this->options['path']);
        $this->separator = isset($this->options['separator']) ? $this->options['separator'] : DIRECTORY_SEPARATOR;
        if (!empty($this->options['winHashFix'])) {
            $this->separatorForHash = ($this->separator !== '/') ? '/' : '';
        }
        $this->systemRoot = isset($this->options['systemRoot']) ? $this->options['systemRoot'] : $this->separator;

        // set ARGS
        $this->ARGS = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

        $argInit = !empty($this->ARGS['init']);

        // set $this->needOnline
        if (!is_bool($this->needOnline)) {
            $this->setNeedOnline();
        }

        // session cache
        if ($argInit) {
            $this->session->set($this->id, array());
        }
        $this->sessionCache = $this->session->get($this->id, array());

        // default file attribute
        $this->defaults = array(
            'read' => isset($this->options['defaults']['read']) ? !!$this->options['defaults']['read'] : true,
            'write' => isset($this->options['defaults']['write']) ? !!$this->options['defaults']['write'] : true,
            'locked' => isset($this->options['defaults']['locked']) ? !!$this->options['defaults']['locked'] : false,
            'hidden' => isset($this->options['defaults']['hidden']) ? !!$this->options['defaults']['hidden'] : false
        );

        // root attributes
        $this->attributes[] = array(
            'pattern' => '~^' . preg_quote($this->separator) . '$~',
            'locked' => true,
            'hidden' => false
        );
        // set files attributes
        if (!empty($this->options['attributes']) && is_array($this->options['attributes'])) {

            foreach ($this->options['attributes'] as $a) {
                // attributes must contain pattern and at least one rule
                if (!empty($a['pattern']) || (is_array($a) && count($a) > 1)) {
                    $this->attributes[] = $a;
                }
            }
        }

        if (!empty($this->options['accessControl']) && is_callable($this->options['accessControl'])) {
            $this->access = $this->options['accessControl'];
        }

        $this->today = mktime(0, 0, 0, date('m'), date('d'), date('Y'));
        $this->yesterday = $this->today - 86400;

        if (!$this->init()) {
            return false;
        }

        // set server encoding
        if (!empty($this->options['encoding']) && strtoupper($this->options['encoding']) !== 'UTF-8') {
            $this->encoding = $this->options['encoding'];
        } else {
            $this->encoding = null;
        }

        // check some options is arrays
        $this->uploadAllow = isset($this->options['uploadAllow']) && is_array($this->options['uploadAllow'])
            ? $this->options['uploadAllow']
            : array();

        $this->uploadDeny = isset($this->options['uploadDeny']) && is_array($this->options['uploadDeny'])
            ? $this->options['uploadDeny']
            : array();

        $this->options['uiCmdMap'] = (isset($this->options['uiCmdMap']) && is_array($this->options['uiCmdMap']))
            ? $this->options['uiCmdMap']
            : array();

        if (is_string($this->options['uploadOrder'])) { // telephat_mode on, compatibility with 1.x
            $parts = explode(',', isset($this->options['uploadOrder']) ? $this->options['uploadOrder'] : 'deny,allow');
            $this->uploadOrder = array(trim($parts[0]), trim($parts[1]));
        } else { // telephat_mode off
            $this->uploadOrder = !empty($this->options['uploadOrder']) ? $this->options['uploadOrder'] : array('deny', 'allow');
        }

        if (!empty($this->options['uploadMaxSize'])) {
            $this->uploadMaxSize = elFinder::getIniBytes('', $this->options['uploadMaxSize']);
        }
        // Set maximum to PHP_INT_MAX
        if (!defined('PHP_INT_MAX')) {
            define('PHP_INT_MAX', 2147483647);
        }
        if ($this->uploadMaxSize < 1 || $this->uploadMaxSize > PHP_INT_MAX) {
            $this->uploadMaxSize = PHP_INT_MAX;
        }

        // Set to get maximum size to 50% of memory_limit
        $memLimit = elFinder::getIniBytes('memory_limit') / 2;
        if ($memLimit > 0) {
            $this->getMaxSize = empty($this->options['getMaxSize']) ? $memLimit : min($memLimit, elFinder::getIniBytes('', $this->options['getMaxSize']));
        } else {
            $this->getMaxSize = -1;
        }

        $this->disabled = isset($this->options['disabled']) && is_array($this->options['disabled'])
            ? array_values(array_diff($this->options['disabled'], array('open'))) // 'open' is required
            : array();

        $this->cryptLib = $this->options['cryptLib'];
        $this->mimeDetect = $this->options['mimeDetect'];

        // find available mimetype detect method
        $regexp = '/text\/x\-(php|c\+\+)/';
        $auto_types = array();

        if (class_exists('finfo', false)) {
            $tmpFileInfo = explode(';', finfo_file(finfo_open(FILEINFO_MIME), __FILE__));
             if ($tmpFileInfo && preg_match($regexp, array_shift($tmpFileInfo))) {
                $auto_types[] = 'finfo';
            }
        }
        
        if (function_exists('mime_content_type')) {
            $_mimetypes = explode(';', mime_content_type(__FILE__));
            if (preg_match($regexp, array_shift($_mimetypes))) {
                $auto_types[] = 'mime_content_type';
            }
        }
            
        $auto_types[] = 'internal';

        $type = strtolower($this->options['mimeDetect']);
        if (!in_array($type, $auto_types)) {
            $type = 'auto';
        }

        if ($type == 'auto') {
            $type = array_shift($auto_types);
        }

        $this->mimeDetect = $type;

        if ($this->mimeDetect == 'finfo') {
            $this->finfo = finfo_open(FILEINFO_MIME);
        } else if ($this->mimeDetect == 'internal' && !elFinderVolumeDriver::$mimetypesLoaded) {
            // load mimes from external file for mimeDetect == 'internal'
            // based on Alexey Sukhotin idea and patch: http://elrte.org/redmine/issues/163
            // file must be in file directory or in parent one
            elFinderVolumeDriver::loadMimeTypes(!empty($this->options['mimefile']) ? $this->options['mimefile'] : '');
        }
        $this->rootName = empty($this->options['alias']) ? $this->basenameCE($this->root) : $this->options['alias'];

        // This get's triggered if $this->root == '/' and alias is empty.
        // Maybe modify _basename instead?
        if ($this->rootName === '') $this->rootName = $this->separator;

        $this->_checkArchivers();

        $root = $this->stat($this->root);

        if (!$root) {
            return $this->setError('Root folder does not exist.');
        }
        if (!$root['read'] && !$root['write']) {
            return $this->setError('Root folder has not read and write permissions.');
        }

        if ($root['read']) {
            if ($argInit) {
                // check startPath - path to open by default instead of root
                $startPath = $this->options['startPath'] ? $this->normpathCE($this->options['startPath']) : '';
                if ($startPath) {
                    $start = $this->stat($startPath);
                    if (!empty($start)
                        && $start['mime'] == 'directory'
                        && $start['read']
                        && empty($start['hidden'])
                        && $this->inpathCE($startPath, $this->root)) {
                        $this->startPath = $startPath;
                        if (substr($this->startPath, -1, 1) == $this->options['separator']) {
                            $this->startPath = substr($this->startPath, 0, -1);
                        }
                    }
                }
            }
        } else {
            $this->options['URL'] = '';
            $this->options['tmbURL'] = '';
            $this->options['tmbPath'] = '';
            // read only volume
            array_unshift($this->attributes, array(
                'pattern' => '/.*/',
                'read' => false
            ));
        }
        $this->treeDeep = $this->options['treeDeep'] > 0 ? (int)$this->options['treeDeep'] : 1;
        $this->tmbSize = $this->options['tmbSize'] > 0 ? (int)$this->options['tmbSize'] : 48;
        $this->URL = $this->options['URL'];
        if ($this->URL && preg_match("|[^/?&=]$|", $this->URL)) {
            $this->URL .= '/';
        }

        $dirUrlOwn = strtolower($this->options['dirUrlOwn']);
        if ($dirUrlOwn === 'auto') {
            $this->options['dirUrlOwn'] = $this->URL ? false : true;
        } else if ($dirUrlOwn === 'hide') {
            $this->options['dirUrlOwn'] = 'hide';
        } else {
            $this->options['dirUrlOwn'] = (bool)$this->options['dirUrlOwn'];
        }

        $this->tmbURL = !empty($this->options['tmbURL']) ? $this->options['tmbURL'] : '';
        if ($this->tmbURL && $this->tmbURL !== 'self' && preg_match("|[^/?&=]$|", $this->tmbURL)) {
            $this->tmbURL .= '/';
        }

        $this->nameValidator = !empty($this->options['acceptedName']) && (is_string($this->options['acceptedName']) || is_callable($this->options['acceptedName']))
            ? $this->options['acceptedName']
            : '';

        $this->dirnameValidator = !empty($this->options['acceptedDirname']) && (is_callable($this->options['acceptedDirname']) || (is_string($this->options['acceptedDirname']) && preg_match($this->options['acceptedDirname'], '') !== false))
            ? $this->options['acceptedDirname']
            : $this->nameValidator;

        // enabling archivers['create'] with options['useRemoteArchive']
        if ($this->options['useRemoteArchive'] && empty($this->archivers['create']) && $this->getTempPath()) {
            $_archivers = $this->getArchivers();
            $this->archivers['create'] = $_archivers['create'];
        }

        // manual control archive types to create
        if (!empty($this->options['archiveMimes']) && is_array($this->options['archiveMimes'])) {
            foreach ($this->archivers['create'] as $mime => $v) {
                if (!in_array($mime, $this->options['archiveMimes'])) {
                    unset($this->archivers['create'][$mime]);
                }
            }
        }

        // manualy add archivers
        if (!empty($this->options['archivers']['create']) && is_array($this->options['archivers']['create'])) {
            foreach ($this->options['archivers']['create'] as $mime => $conf) {
                if (strpos($mime, 'application/') === 0
                    && !empty($conf['cmd'])
                    && isset($conf['argc'])
                    && !empty($conf['ext'])
                    && !isset($this->archivers['create'][$mime])) {
                    $this->archivers['create'][$mime] = $conf;
                }
            }
        }

        if (!empty($this->options['archivers']['extract']) && is_array($this->options['archivers']['extract'])) {
            foreach ($this->options['archivers']['extract'] as $mime => $conf) {
                if (strpos($mime, 'application/') === 0
                    && !empty($conf['cmd'])
                    && isset($conf['argc'])
                    && !empty($conf['ext'])
                    && !isset($this->archivers['extract'][$mime])) {
                    $this->archivers['extract'][$mime] = $conf;
                }
            }
        }

        if (!empty($this->options['noSessionCache']) && is_array($this->options['noSessionCache'])) {
            foreach ($this->options['noSessionCache'] as $_key) {
                $this->sessionCaching[$_key] = false;
                unset($this->sessionCache[$_key]);
            }
        }
        if ($this->sessionCaching['subdirs']) {
            if (!isset($this->sessionCache['subdirs'])) {
                $this->sessionCache['subdirs'] = array();
            }
        }


        $this->configure();

        // Normalize disabled (array_merge`for type array of JSON)
        $this->disabled = array_values(array_unique($this->disabled));

        // fix sync interval
        if ($this->options['syncMinMs'] !== 0) {
            $this->options['syncMinMs'] = max($this->options[$this->options['syncChkAsTs'] ? 'tsPlSleep' : 'lsPlSleep'] * 1000, intval($this->options['syncMinMs']));
        }

        // ` copyJoin` is required for the trash function
        if ($this->options['trashHash'] && empty($this->options['copyJoin'])) {
            $this->options['trashHash'] = '';
        }

        // set tmpLinkPath
        if (elFinder::$tmpLinkPath && !$this->options['tmpLinkPath']) {
            if (is_writeable(elFinder::$tmpLinkPath)) {
                $this->options['tmpLinkPath'] = elFinder::$tmpLinkPath;
            } else {
                elFinder::$tmpLinkPath = '';
            }
        }
        if ($this->options['tmpLinkPath'] && is_writable($this->options['tmpLinkPath'])) {
            $this->tmpLinkPath = realpath($this->options['tmpLinkPath']);
        } else if (!$this->tmpLinkPath && $this->tmbURL && $this->tmbPath) {
            $this->tmpLinkPath = $this->tmbPath;
            $this->options['tmpLinkUrl'] = $this->tmbURL;
        } else if (!$this->options['URL'] && is_writable('../files/.tmb')) {
            $this->tmpLinkPath = realpath('../files/.tmb');
            $this->options['tmpLinkUrl'] = '';
            if (!elFinder::$tmpLinkPath) {
                elFinder::$tmpLinkPath = $this->tmpLinkPath;
                elFinder::$tmpLinkUrl = '';
            }
        }

        // set tmpLinkUrl
        if (elFinder::$tmpLinkUrl && !$this->options['tmpLinkUrl']) {
            $this->options['tmpLinkUrl'] = elFinder::$tmpLinkUrl;
        }
        if ($this->options['tmpLinkUrl']) {
            $this->tmpLinkUrl = $this->options['tmpLinkUrl'];
        }
        if ($this->tmpLinkPath && !$this->tmpLinkUrl) {
            $cur = realpath('./');
            $i = 0;
            while ($cur !== $this->systemRoot && strpos($this->tmpLinkPath, $cur) !== 0) {
                $i++;
                $cur = dirname($cur);
            }
            list($req) = explode('?', $_SERVER['REQUEST_URI']);
            $reqs = explode('/', dirname($req));
            $uri = join('/', array_slice($reqs, 0, count($reqs) - 1)) . substr($this->tmpLinkPath, strlen($cur));
            $https = (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
            $this->tmpLinkUrl = ($https ? 'https://' : 'http://')
                . $_SERVER['SERVER_NAME'] // host
                . (((!$https && $_SERVER['SERVER_PORT'] == 80) || ($https && $_SERVER['SERVER_PORT'] == 443)) ? '' : (':' . $_SERVER['SERVER_PORT']))  // port
                . $uri;
            if (!elFinder::$tmpLinkUrl) {
                elFinder::$tmpLinkUrl = $this->tmpLinkUrl;
            }
        }

        // remove last '/'
        if ($this->tmpLinkPath) {
            $this->tmpLinkPath = rtrim($this->tmpLinkPath, '/');
        }
        if ($this->tmpLinkUrl) {
            $this->tmpLinkUrl = rtrim($this->tmpLinkUrl, '/');
        }

        // to update options cache
        if (isset($this->sessionCache['rootstat'])) {
            unset($this->sessionCache['rootstat'][$this->getRootstatCachekey()]);
        }
        $this->updateCache($this->root, $root);

        return $this->mounted = true;
    }

    /**
     * Some "unmount" stuffs - may be required by virtual fs
     *
     * @return void
     * @author Dmitry (dio) Levashov
     **/
    public function umount()
    {
    }

    /**
     * Remove session cache of this volume
     */
    public function clearSessionCache()
    {
        $this->sessionCache = array();
    }

    /**
     * Return error message from last failed action
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    public function error()
    {
        return $this->error;
    }

    /**
     * Return is uploadable that given file name
     *
     * @param  string $name file name
     * @param  bool   $allowUnknown
     *
     * @return bool
     * @author Naoki Sawada
     **/
    public function isUploadableByName($name, $allowUnknown = false)
    {
        $mimeByName = $this->mimetype($name, true);
        return (($allowUnknown && $mimeByName === 'unknown') || $this->allowPutMime($mimeByName));
    }

    /**
     * Return Extention/MIME Table (elFinderVolumeDriver::$mimetypes)
     *
     * @return array
     * @author Naoki Sawada
     */
    public function getMimeTable()
    {
        // load mime.types
        if (!elFinderVolumeDriver::$mimetypesLoaded) {
            elFinderVolumeDriver::loadMimeTypes();
        }
        return elFinderVolumeDriver::$mimetypes;
    }

    /**
     * Return file extention detected by MIME type
     *
     * @param  string $mime   MIME type
     * @param  string $suffix Additional suffix
     *
     * @return string
     * @author Naoki Sawada
     */
    public function getExtentionByMime($mime, $suffix = '')
    {
        static $extTable = null;

        if (is_null($extTable)) {
            $extTable = array_flip(array_unique($this->getMimeTable()));
            foreach ($this->options['mimeMap'] as $pair => $_mime) {
                list($ext) = explode(':', $pair);
                if ($ext !== '*' && !isset($extTable[$_mime])) {
                    $extTable[$_mime] = $ext;
                }
            }
        }

        if ($mime && isset($extTable[$mime])) {
            return $suffix ? ($extTable[$mime] . $suffix) : $extTable[$mime];
        }
        return '';
    }

    /**
     * Set mimetypes allowed to display to client
     *
     * @param  array $mimes
     *
     * @return void
     * @author Dmitry (dio) Levashov
     **/
    public function setMimesFilter($mimes)
    {
        if (is_array($mimes)) {
            $this->onlyMimes = $mimes;
        }
    }

    /**
     * Return root folder hash
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    public function root()
    {
        return $this->encode($this->root);
    }

    /**
     * Return root path
     *
     * @return string
     * @author Naoki Sawada
     **/
    public function getRootPath()
    {
        return $this->root;
    }

    /**
     * Return target path hash
     *
     * @param  string $path
     * @param  string $name
     *
     * @author Naoki Sawada
     * @return string
     */
    public function getHash($path, $name = '')
    {
        if ($name !== '') {
            $path = $this->joinPathCE($path, $name);
        }
        return $this->encode($path);
    }

    /**
     * Return decoded path of target hash
     * This method do not check the stat of target
     * Use method `realpath()` to do check of the stat of target
     *
     * @param  string $hash
     *
     * @author Naoki Sawada
     * @return string
     */
    public function getPath($hash)
    {
        return $this->decode($hash);
    }

    /**
     * Return root or startPath hash
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    public function defaultPath()
    {
        return $this->encode($this->startPath ? $this->startPath : $this->root);
    }

    /**
     * Return volume options required by client:
     *
     * @param $hash
     *
     * @return array
     * @author Dmitry (dio) Levashov
     */
    public function options($hash)
    {
        $create = $createext = array();
        if (isset($this->archivers['create']) && is_array($this->archivers['create'])) {
            foreach ($this->archivers['create'] as $m => $v) {
                $create[] = $m;
                $createext[$m] = $v['ext'];
            }
        }
        $opts = array(
            'path' => $hash ? $this->path($hash) : '',
            'url' => $this->URL,
            'tmbUrl' => (!$this->imgLib && $this->options['tmbFbSelf']) ? 'self' : $this->tmbURL,
            'disabled' => $this->disabled,
            'separator' => $this->separator,
            'copyOverwrite' => intval($this->options['copyOverwrite']),
            'uploadOverwrite' => intval($this->options['uploadOverwrite']),
            'uploadMaxSize' => intval($this->uploadMaxSize),
            'uploadMaxConn' => intval($this->options['uploadMaxConn']),
            'uploadMime' => array(
                'firstOrder' => isset($this->uploadOrder[0]) ? $this->uploadOrder[0] : 'deny',
                'allow' => $this->uploadAllow,
                'deny' => $this->uploadDeny
            ),
            'dispInlineRegex' => $this->options['dispInlineRegex'],
            'jpgQuality' => intval($this->options['jpgQuality']),
            'archivers' => array(
                'create' => $create,
                'extract' => isset($this->archivers['extract']) && is_array($this->archivers['extract']) ? array_keys($this->archivers['extract']) : array(),
                'createext' => $createext
            ),
            'uiCmdMap' => (isset($this->options['uiCmdMap']) && is_array($this->options['uiCmdMap'])) ? $this->options['uiCmdMap'] : array(),
            'syncChkAsTs' => intval($this->options['syncChkAsTs']),
            'syncMinMs' => intval($this->options['syncMinMs']),
            'i18nFolderName' => intval($this->options['i18nFolderName']),
            'tmbCrop' => intval($this->options['tmbCrop']),
            'tmbReqCustomData' => (bool)$this->options['tmbReqCustomData'],
            'substituteImg' => (bool)$this->options['substituteImg'],
            'onetimeUrl' => (bool)$this->options['onetimeUrl'],
        );
        if (!empty($this->options['trashHash'])) {
            $opts['trashHash'] = $this->options['trashHash'];
        }
        if ($hash === null) {
            // call from getRootStatExtra()
            if (!empty($this->options['icon'])) {
                $opts['icon'] = $this->options['icon'];
            }
            if (!empty($this->options['rootCssClass'])) {
                $opts['csscls'] = $this->options['rootCssClass'];
            }
            if (isset($this->options['netkey'])) {
                $opts['netkey'] = $this->options['netkey'];
            }
        }
        return $opts;
    }

    /**
     * Get option value of this volume
     *
     * @param string $name target option name
     *
     * @return NULL|mixed   target option value
     * @author Naoki Sawada
     */
    public function getOption($name)
    {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    /**
     * Get plugin values of this options
     *
     * @param string $name Plugin name
     *
     * @return NULL|array   Plugin values
     * @author Naoki Sawada
     */
    public function getOptionsPlugin($name = '')
    {
        if ($name) {
            return isset($this->options['plugin'][$name]) ? $this->options['plugin'][$name] : array();
        } else {
            return $this->options['plugin'];
        }
    }

    /**
     * Return true if command disabled in options
     *
     * @param  string $cmd command name
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    public function commandDisabled($cmd)
    {
        return in_array($cmd, $this->disabled);
    }

    /**
     * Return true if mime is required mimes list
     *
     * @param  string    $mime  mime type to check
     * @param  array     $mimes allowed mime types list or not set to use client mimes list
     * @param  bool|null $empty what to return on empty list
     *
     * @return bool|null
     * @author Dmitry (dio) Levashov
     * @author Troex Nevelin
     **/
    public function mimeAccepted($mime, $mimes = null, $empty = true)
    {
        $mimes = is_array($mimes) ? $mimes : $this->onlyMimes;
        if (empty($mimes)) {
            return $empty;
        }
        return $mime == 'directory'
            || in_array('all', $mimes)
            || in_array('All', $mimes)
            || in_array($mime, $mimes)
            || in_array(substr($mime, 0, strpos($mime, '/')), $mimes);
    }

    /**
     * Return true if voume is readable.
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    public function isReadable()
    {
        $stat = $this->stat($this->root);
        return $stat['read'];
    }

    /**
     * Return true if copy from this volume allowed
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    public function copyFromAllowed()
    {
        return !!$this->options['copyFrom'];
    }

    /**
     * Return file path related to root with convert encoging
     *
     * @param  string $hash file hash
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    public function path($hash)
    {
        return $this->convEncOut($this->_path($this->convEncIn($this->decode($hash))));
    }

    /**
     * Return file real path if file exists
     *
     * @param  string $hash file hash
     *
     * @return string | false
     * @author Dmitry (dio) Levashov
     **/
    public function realpath($hash)
    {
        $path = $this->decode($hash);
        return $this->stat($path) ? $path : false;
    }

    /**
     * Return list of moved/overwrited files
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    public function removed()
    {
        if ($this->removed) {
            $unsetSubdir = isset($this->sessionCache['subdirs']) ? true : false;
            foreach ($this->removed as $item) {
                if ($item['mime'] === 'directory') {
                    $path = $this->decode($item['hash']);
                    if ($unsetSubdir) {
                        unset($this->sessionCache['subdirs'][$path]);
                    }
                    if ($item['phash'] !== '') {
                        $parent = $this->decode($item['phash']);
                        unset($this->cache[$parent]);
                        if ($this->root === $parent) {
                            $this->sessionCache['rootstat'] = array();
                        }
                        if ($unsetSubdir) {
                            unset($this->sessionCache['subdirs'][$parent]);
                        }
                    }
                }
            }
            $this->removed = array_values($this->removed);
        }
        return $this->removed;
    }

    /**
     * Return list of added files
     *
     * @deprecated
     * @return array
     * @author Naoki Sawada
     **/
    public function added()
    {
        return $this->added;
    }

    /**
     * Clean removed files list
     *
     * @return void
     * @author Dmitry (dio) Levashov
     **/
    public function resetRemoved()
    {
        $this->resetResultStat();
    }

    /**
     * Clean added/removed files list
     *
     * @return void
     **/
    public function resetResultStat()
    {
        $this->removed = array();
        $this->added = array();
    }

    /**
     * Return file/dir hash or first founded child hash with required attr == $val
     *
     * @param  string $hash file hash
     * @param  string $attr attribute name
     * @param  bool   $val  attribute value
     *
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    public function closest($hash, $attr, $val)
    {
        return ($path = $this->closestByAttr($this->decode($hash), $attr, $val)) ? $this->encode($path) : false;
    }

    /**
     * Return file info or false on error
     *
     * @param  string $hash file hash
     *
     * @return array|false
     * @internal param bool $realpath add realpath field to file info
     * @author   Dmitry (dio) Levashov
     */
    public function file($hash)
    {
        $file = $this->stat($this->decode($hash));

        return ($file) ? $file : $this->setError(elFinder::ERROR_FILE_NOT_FOUND);
    }

    /**
     * Return folder info
     *
     * @param  string $hash folder hash
     * @param bool    $resolveLink
     *
     * @return array|false
     * @internal param bool $hidden return hidden file info
     * @author   Dmitry (dio) Levashov
     */
    public function dir($hash, $resolveLink = false)
    {
        if (($dir = $this->file($hash)) == false) {
            return $this->setError(elFinder::ERROR_DIR_NOT_FOUND);
        }

        if ($resolveLink && !empty($dir['thash'])) {
            $dir = $this->file($dir['thash']);
        }

        return $dir && $dir['mime'] == 'directory' && empty($dir['hidden'])
            ? $dir
            : $this->setError(elFinder::ERROR_NOT_DIR);
    }

    /**
     * Return directory content or false on error
     *
     * @param  string $hash file hash
     *
     * @return array|false
     * @author Dmitry (dio) Levashov
     **/
    public function scandir($hash)
    {
        if (($dir = $this->dir($hash)) == false) {
            return false;
        }

        $path = $this->decode($hash);
        if ($res = $dir['read']
            ? $this->getScandir($path)
            : $this->setError(elFinder::ERROR_PERM_DENIED)) {

            $dirs = null;
            if ($this->sessionCaching['subdirs'] && isset($this->sessionCache['subdirs'][$path])) {
                $dirs = $this->sessionCache['subdirs'][$path];
            }
            if ($dirs !== null || (isset($dir['dirs']) && $dir['dirs'] != 1)) {
                $_dir = $dir;
                if ($dirs || $this->subdirs($hash)) {
                    $dir['dirs'] = 1;
                } else {
                    unset($dir['dirs']);
                }
                if ($dir !== $_dir) {
                    $this->updateCache($path, $dir);
                }
            }
        }

        return $res;
    }

    /**
     * Return dir files names list
     *
     * @param  string $hash file hash
     * @param null    $intersect
     *
     * @return array|false
     * @author Dmitry (dio) Levashov
     */
    public function ls($hash, $intersect = null)
    {
        if (($dir = $this->dir($hash)) == false || !$dir['read']) {
            return false;
        }

        $list = array();
        $path = $this->decode($hash);

        $check = array();
        if ($intersect) {
            $check = array_flip($intersect);
        }

        foreach ($this->getScandir($path) as $stat) {
            if (empty($stat['hidden']) && (!$check || isset($check[$stat['name']])) && $this->mimeAccepted($stat['mime'])) {
                $list[$stat['hash']] = $stat['name'];
            }
        }

        return $list;
    }

    /**
     * Return subfolders for required folder or false on error
     *
     * @param  string $hash    folder hash or empty string to get tree from root folder
     * @param  int    $deep    subdir deep
     * @param  string $exclude dir hash which subfolders must be exluded from result, required to not get stat twice on cwd subfolders
     *
     * @return array|false
     * @author Dmitry (dio) Levashov
     **/
    public function tree($hash = '', $deep = 0, $exclude = '')
    {
        $path = $hash ? $this->decode($hash) : $this->root;

        if (($dir = $this->stat($path)) == false || $dir['mime'] != 'directory') {
            return false;
        }

        $dirs = $this->gettree($path, $deep > 0 ? $deep - 1 : $this->treeDeep - 1, $exclude ? $this->decode($exclude) : null);
        array_unshift($dirs, $dir);
        return $dirs;
    }

    /**
     * Return part of dirs tree from required dir up to root dir
     *
     * @param  string    $hash   directory hash
     * @param  bool|null $lineal only lineal parents
     *
     * @return array|false
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    public function parents($hash, $lineal = false)
    {
        if (($current = $this->dir($hash)) == false) {
            return false;
        }

        $args = func_get_args();
        // checks 3rd param `$until` (elFinder >= 2.1.24)
        $until = '';
        if (isset($args[2])) {
            $until = $args[2];
        }

        $path = $this->decode($hash);
        $tree = array();

        while ($path && $path != $this->root) {
            elFinder::checkAborted();
            $path = $this->dirnameCE($path);
            if (!($stat = $this->stat($path)) || !empty($stat['hidden']) || !$stat['read']) {
                return false;
            }

            array_unshift($tree, $stat);
            if (!$lineal) {
                foreach ($this->gettree($path, 0) as $dir) {
                    elFinder::checkAborted();
                    if (!isset($tree[$dir['hash']])) {
                        $tree[$dir['hash']] = $dir;
                    }
                }
            }

            if ($until && $until === $this->encode($path)) {
                break;
            }
        }

        return $tree ? array_values($tree) : array($current);
    }

    /**
     * Create thumbnail for required file and return its name or false on failed
     *
     * @param $hash
     *
     * @return false|string
     * @throws ImagickException
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    public function tmb($hash)
    {
        $path = $this->decode($hash);
        $stat = $this->stat($path);

        if (isset($stat['tmb'])) {
            $res = $stat['tmb'] == "1" ? $this->createTmb($path, $stat) : $stat['tmb'];
            if (!$res) {
                list($type) = explode('/', $stat['mime']);
                $fallback = $this->options['resourcePath'] . DIRECTORY_SEPARATOR . strtolower($type) . '.png';
                if (is_file($fallback)) {
                    $res = $this->tmbname($stat);
                    if (!copy($fallback, $this->tmbPath . DIRECTORY_SEPARATOR . $res)) {
                        $res = false;
                    }
                }
            }
            // tmb garbage collection
            if ($res && $this->options['tmbGcMaxlifeHour'] && $this->options['tmbGcPercentage'] > 0) {
                $rand = mt_rand(1, 10000);
                if ($rand <= $this->options['tmbGcPercentage'] * 100) {
                    register_shutdown_function(array('elFinder', 'GlobGC'), $this->tmbPath . DIRECTORY_SEPARATOR . '*.png', $this->options['tmbGcMaxlifeHour'] * 3600);
                }
            }
            return $res;
        }
        return false;
    }

    /**
     * Return file size / total directory size
     *
     * @param  string   file hash
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    public function size($hash)
    {
        return $this->countSize($this->decode($hash));
    }

    /**
     * Open file for reading and return file pointer
     *
     * @param  string   file hash
     *
     * @return Resource|false
     * @author Dmitry (dio) Levashov
     **/
    public function open($hash)
    {
        if (($file = $this->file($hash)) == false
            || $file['mime'] == 'directory') {
            return false;
        }
        // check extra option for network stream pointer
        if (func_num_args() > 1) {
            $opts = func_get_arg(1);
        } else {
            $opts = array();
        }
        return $this->fopenCE($this->decode($hash), 'rb', $opts);
    }

    /**
     * Close file pointer
     *
     * @param  Resource $fp   file pointer
     * @param  string   $hash file hash
     *
     * @return void
     * @author Dmitry (dio) Levashov
     **/
    public function close($fp, $hash)
    {
        $this->fcloseCE($fp, $this->decode($hash));
    }

    /**
     * Create directory and return dir info
     *
     * @param  string $dsthash destination directory hash
     * @param  string $name    directory name
     *
     * @return array|false
     * @author Dmitry (dio) Levashov
     **/
    public function mkdir($dsthash, $name)
    {
        if ($this->commandDisabled('mkdir')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if (!$this->nameAccepted($name, true)) {
            return $this->setError(elFinder::ERROR_INVALID_DIRNAME);
        }

        if (($dir = $this->dir($dsthash)) == false) {
            return $this->setError(elFinder::ERROR_TRGDIR_NOT_FOUND, '#' . $dsthash);
        }

        $path = $this->decode($dsthash);

        if (!$dir['write'] || !$this->allowCreate($path, $name, true)) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if (substr($name, 0, 1) === '/' || substr($name, 0, 1) === '\\') {
            return $this->setError(elFinder::ERROR_INVALID_DIRNAME);
        }

        $dst = $this->joinPathCE($path, $name);
        $stat = $this->isNameExists($dst);
        if (!empty($stat)) {
            return $this->setError(elFinder::ERROR_EXISTS, $name);
        }
        $this->clearcache();

        $mkpath = $this->convEncOut($this->_mkdir($this->convEncIn($path), $this->convEncIn($name)));
        if ($mkpath) {
            $this->clearstatcache();
            $this->updateSubdirsCache($path, true);
            $this->updateSubdirsCache($mkpath, false);
        }

        return $mkpath ? $this->stat($mkpath) : false;
    }

    /**
     * Create empty file and return its info
     *
     * @param  string $dst  destination directory
     * @param  string $name file name
     *
     * @return array|false
     * @author Dmitry (dio) Levashov
     **/
    public function mkfile($dst, $name)
    {
        if ($this->commandDisabled('mkfile')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if (!$this->nameAccepted($name, false)) {
            return $this->setError(elFinder::ERROR_INVALID_NAME);
        }

        if (substr($name, 0, 1) === '/' || substr($name, 0, 1) === '\\') {
            return $this->setError(elFinder::ERROR_INVALID_DIRNAME);
        }

        $mimeByName = $this->mimetype($name, true);
        if ($mimeByName && !$this->allowPutMime($mimeByName)) {
            return $this->setError(elFinder::ERROR_UPLOAD_FILE_MIME, $name);
        }

        if (($dir = $this->dir($dst)) == false) {
            return $this->setError(elFinder::ERROR_TRGDIR_NOT_FOUND, '#' . $dst);
        }

        $path = $this->decode($dst);

        if (!$dir['write'] || !$this->allowCreate($path, $name, false)) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if ($this->isNameExists($this->joinPathCE($path, $name))) {
            return $this->setError(elFinder::ERROR_EXISTS, $name);
        }

        $this->clearcache();
        $res = false;
        if ($path = $this->convEncOut($this->_mkfile($this->convEncIn($path), $this->convEncIn($name)))) {
            $this->clearstatcache();
            $res = $this->stat($path);
        }
        return $res;
    }

    /**
     * Rename file and return file info
     *
     * @param  string $hash file hash
     * @param  string $name new file name
     *
     * @return array|false
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    public function rename($hash, $name)
    {
        if ($this->commandDisabled('rename')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if (!($file = $this->file($hash))) {
            return $this->setError(elFinder::ERROR_FILE_NOT_FOUND);
        }

        if ($name === $file['name']) {
            return $file;
        }

        if (!empty($this->options['netkey']) && !empty($file['isroot'])) {
            // change alias of netmount root
            $rootKey = $this->getRootstatCachekey();
            // delete old cache data
            if ($this->sessionCaching['rootstat']) {
                unset($this->sessionCaching['rootstat'][$rootKey]);
            }
            if (elFinder::$instance->updateNetVolumeOption($this->options['netkey'], 'alias', $name)) {
                $this->clearcache();
                $this->rootName = $this->options['alias'] = $name;
                return $this->stat($this->root);
            } else {
                return $this->setError(elFinder::ERROR_TRGDIR_NOT_FOUND, $name);
            }
        }

        if (!empty($file['locked'])) {
            return $this->setError(elFinder::ERROR_LOCKED, $file['name']);
        }

        $isDir = ($file['mime'] === 'directory');

        if (!$this->nameAccepted($name, $isDir)) {
            return $this->setError($isDir ? elFinder::ERROR_INVALID_DIRNAME : elFinder::ERROR_INVALID_NAME);
        }

        if (!$isDir) {
            $mimeByName = $this->mimetype($name, true);
            if ($mimeByName && !$this->allowPutMime($mimeByName)) {
                return $this->setError(elFinder::ERROR_UPLOAD_FILE_MIME, $name);
            }
        }

        $path = $this->decode($hash);
        $dir = $this->dirnameCE($path);
        $stat = $this->isNameExists($this->joinPathCE($dir, $name));
        if ($stat) {
            return $this->setError(elFinder::ERROR_EXISTS, $name);
        }

        if (!$this->allowCreate($dir, $name, ($file['mime'] === 'directory'))) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        $this->rmTmb($file); // remove old name tmbs, we cannot do this after dir move


        if ($path = $this->convEncOut($this->_move($this->convEncIn($path), $this->convEncIn($dir), $this->convEncIn($name)))) {
            $this->clearcache();
            return $this->stat($path);
        }
        return false;
    }

    /**
     * Create file copy with suffix "copy number" and return its info
     *
     * @param  string $hash   file hash
     * @param  string $suffix suffix to add to file name
     *
     * @return array|false
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    public function duplicate($hash, $suffix = 'copy')
    {
        if ($this->commandDisabled('duplicate')) {
            return $this->setError(elFinder::ERROR_COPY, '#' . $hash, elFinder::ERROR_PERM_DENIED);
        }

        if (($file = $this->file($hash)) == false) {
            return $this->setError(elFinder::ERROR_COPY, elFinder::ERROR_FILE_NOT_FOUND);
        }

        $path = $this->decode($hash);
        $dir = $this->dirnameCE($path);
        $name = $this->uniqueName($dir, $file['name'], sprintf($this->options['duplicateSuffix'], $suffix));

        if (!$this->allowCreate($dir, $name, ($file['mime'] === 'directory'))) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        return ($path = $this->copy($path, $dir, $name)) == false
            ? false
            : $this->stat($path);
    }

    /**
     * Save uploaded file.
     * On success return array with new file stat and with removed file hash (if existed file was replaced)
     *
     * @param  Resource $fp      file pointer
     * @param  string   $dst     destination folder hash
     * @param           $name
     * @param  string   $tmpname file tmp name - required to detect mime type
     * @param  array    $hashes  exists files hash array with filename as key
     *
     * @return array|false
     * @throws elFinderAbortException
     * @internal param string $src file name
     * @author   Dmitry (dio) Levashov
     */
    public function upload($fp, $dst, $name, $tmpname, $hashes = array())
    {
        if ($this->commandDisabled('upload')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if (($dir = $this->dir($dst)) == false) {
            return $this->setError(elFinder::ERROR_TRGDIR_NOT_FOUND, '#' . $dst);
        }

        if (empty($dir['write'])) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if (!$this->nameAccepted($name, false)) {
            return $this->setError(elFinder::ERROR_INVALID_NAME);
        }

        $mimeByName = '';
        if ($this->mimeDetect === 'internal') {
            $mime = $this->mimetype($tmpname, $name);
        } else {
            $mime = $this->mimetype($tmpname, $name);
            $mimeByName = $this->mimetype($name, true);
            if ($mime === 'unknown') {
                $mime = $mimeByName;
            }
        }

        if (!$this->allowPutMime($mime) || ($mimeByName && !$this->allowPutMime($mimeByName))) {
            return $this->setError(elFinder::ERROR_UPLOAD_FILE_MIME, '(' . $mime . ')');
        }

        $tmpsize = (int)sprintf('%u', filesize($tmpname));
        if ($this->uploadMaxSize > 0 && $tmpsize > $this->uploadMaxSize) {
            return $this->setError(elFinder::ERROR_UPLOAD_FILE_SIZE);
        }

        $dstpath = $this->decode($dst);
        if (isset($hashes[$name])) {
            $test = $this->decode($hashes[$name]);
            $file = $this->stat($test);
        } else {
            $test = $this->joinPathCE($dstpath, $name);
            $file = $this->isNameExists($test);
        }

        $this->clearcache();

        if ($file && $file['name'] === $name) { // file exists and check filename for item ID based filesystem
            if ($this->uploadOverwrite) {
                if (!$file['write']) {
                    return $this->setError(elFinder::ERROR_PERM_DENIED);
                } elseif ($file['mime'] == 'directory') {
                    return $this->setError(elFinder::ERROR_NOT_REPLACE, $name);
                }
                $this->remove($test);
            } else {
                $name = $this->uniqueName($dstpath, $name, '-', false);
            }
        }

        $stat = array(
            'mime' => $mime,
            'width' => 0,
            'height' => 0,
            'size' => $tmpsize);

        // $w = $h = 0;
        if (strpos($mime, 'image') === 0 && ($s = getimagesize($tmpname))) {
            $stat['width'] = $s[0];
            $stat['height'] = $s[1];
        }
        // $this->clearcache();
        if (($path = $this->saveCE($fp, $dstpath, $name, $stat)) == false) {
            return false;
        }

        $stat = $this->stat($path);
        // Try get URL
        if (empty($stat['url']) && ($url = $this->getContentUrl($stat['hash']))) {
            $stat['url'] = $url;
        }

        return $stat;
    }

    /**
     * Paste files
     *
     * @param  Object $volume source volume
     * @param         $src
     * @param  string $dst    destination dir hash
     * @param  bool   $rmSrc  remove source after copy?
     * @param array   $hashes
     *
     * @return array|false
     * @throws elFinderAbortException
     * @internal param string $source file hash
     * @author   Dmitry (dio) Levashov
     */
    public function paste($volume, $src, $dst, $rmSrc = false, $hashes = array())
    {
        $err = $rmSrc ? elFinder::ERROR_MOVE : elFinder::ERROR_COPY;

        if ($this->commandDisabled('paste')) {
            return $this->setError($err, '#' . $src, elFinder::ERROR_PERM_DENIED);
        }

        if (($file = $volume->file($src, $rmSrc)) == false) {
            return $this->setError($err, '#' . $src, elFinder::ERROR_FILE_NOT_FOUND);
        }

        $name = $file['name'];
        $errpath = $volume->path($file['hash']);

        if (($dir = $this->dir($dst)) == false) {
            return $this->setError($err, $errpath, elFinder::ERROR_TRGDIR_NOT_FOUND, '#' . $dst);
        }

        if (!$dir['write'] || !$file['read']) {
            return $this->setError($err, $errpath, elFinder::ERROR_PERM_DENIED);
        }

        $destination = $this->decode($dst);

        if (($test = $volume->closest($src, $rmSrc ? 'locked' : 'read', $rmSrc))) {
            return $rmSrc
                ? $this->setError($err, $errpath, elFinder::ERROR_LOCKED, $volume->path($test))
                : $this->setError($err, $errpath, empty($file['thash']) ? elFinder::ERROR_PERM_DENIED : elFinder::ERROR_MKOUTLINK);
        }

        if (isset($hashes[$name])) {
            $test = $this->decode($hashes[$name]);
            $stat = $this->stat($test);
        } else {
            $test = $this->joinPathCE($destination, $name);
            $stat = $this->isNameExists($test);
        }
        $this->clearcache();
        $dstDirExists = false;
        if ($stat && $stat['name'] === $name) { // file exists and check filename for item ID based filesystem
            if ($this->options['copyOverwrite']) {
                // do not replace file with dir or dir with file
                if (!$this->isSameType($file['mime'], $stat['mime'])) {
                    return $this->setError(elFinder::ERROR_NOT_REPLACE, $this->path($stat['hash']));
                }
                // existed file is not writable
                if (empty($stat['write'])) {
                    return $this->setError($err, $errpath, elFinder::ERROR_PERM_DENIED);
                }
                if ($this->options['copyJoin']) {
                    if (!empty($stat['locked'])) {
                        return $this->setError(elFinder::ERROR_LOCKED, $this->path($stat['hash']));
                    }
                } else {
                    // existed file locked or has locked child
                    if (($locked = $this->closestByAttr($test, 'locked', true))) {
                        $stat = $this->stat($locked);
                        return $this->setError(elFinder::ERROR_LOCKED, $this->path($stat['hash']));
                    }
                }
                // target is entity file of alias
                if ($volume === $this && ((isset($file['target']) && $test == $file['target']) || $test == $this->decode($src))) {
                    return $this->setError(elFinder::ERROR_REPLACE, $errpath);
                }
                // remove existed file
                if (!$this->options['copyJoin'] || $stat['mime'] !== 'directory') {
                    if (!$this->remove($test)) {
                        return $this->setError(elFinder::ERROR_REPLACE, $this->path($stat['hash']));
                    }
                } else if ($stat['mime'] === 'directory') {
                    $dstDirExists = true;
                }
            } else {
                $name = $this->uniqueName($destination, $name, ' ', false);
            }
        }

        // copy/move inside current volume
        if ($volume === $this) { //  changing == operand to === fixes issue #1285 - Paul Canning 24/03/2016
            $source = $this->decode($src);
            // do not copy into itself
            if ($this->inpathCE($destination, $source)) {
                return $this->setError(elFinder::ERROR_COPY_ITSELF, $errpath);
            }
            $rmDir = false;
            if ($rmSrc) {
                if ($dstDirExists) {
                    $rmDir = true;
                    $method = 'copy';
                } else {
                    $method = 'move';
                }
            } else {
                $method = 'copy';
            }
            $this->clearcache();
            if ($res = ($path = $this->$method($source, $destination, $name)) ? $this->stat($path) : false) {
                if ($rmDir) {
                    $this->remove($source);
                }
            } else {
                return false;
            }
        } else {
            // copy/move from another volume
            if (!$this->options['copyTo'] || !$volume->copyFromAllowed()) {
                return $this->setError(elFinder::ERROR_COPY, $errpath, elFinder::ERROR_PERM_DENIED);
            }

            $this->error = array();
            if (($path = $this->copyFrom($volume, $src, $destination, $name)) == false) {
                return false;
            }

            if ($rmSrc && !$this->error()) {
                if (!$volume->rm($src)) {
                    if ($volume->file($src)) {
                        $this->addError(elFinder::ERROR_RM_SRC);
                    } else {
                        $this->removed[] = $file;
                    }
                }
            }
            $res = $this->stat($path);
        }
        return $res;
    }

    /**
     * Return path info array to archive of target items
     *
     * @param  array $hashes
     *
     * @return array|false
     * @throws Exception
     * @author Naoki Sawada
     */
    public function zipdl($hashes)
    {
        if ($this->commandDisabled('zipdl')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        $archivers = $this->getArchivers();
        $cmd = null;
        if (!$archivers || empty($archivers['create'])) {
            return false;
        }
        $archivers = $archivers['create'];
        if (!$archivers) {
            return false;
        }
        $file = $mime = '';
        foreach (array('zip', 'tgz') as $ext) {
            $mime = $this->mimetype('file.' . $ext, true);
            if (isset($archivers[$mime])) {
                $cmd = $archivers[$mime];
                break;
            }
        }
        if (!$cmd) {
            $cmd = array_shift($archivers);
            if (!empty($ext)) {
                $mime = $this->mimetype('file.' . $ext, true);
            }
        }
        $ext = $cmd['ext'];
        $res = false;
        $mixed = false;
        $hashes = array_values($hashes);
        $dirname = dirname(str_replace($this->separator, DIRECTORY_SEPARATOR, $this->path($hashes[0])));
        $cnt = count($hashes);
        if ($cnt > 1) {
            for ($i = 1; $i < $cnt; $i++) {
                if ($dirname !== dirname(str_replace($this->separator, DIRECTORY_SEPARATOR, $this->path($hashes[$i])))) {
                    $mixed = true;
                    break;
                }
            }
        }
        if ($mixed || $this->root == $this->dirnameCE($this->decode($hashes[0]))) {
            $prefix = $this->rootName;
        } else {
            $prefix = basename($dirname);
        }
        if ($dir = $this->getItemsInHand($hashes)) {
            $tmppre = (substr(PHP_OS, 0, 3) === 'WIN') ? 'zd-' : 'elfzdl-';
            $pdir = dirname($dir);
            // garbage collection (expire 2h)
            register_shutdown_function(array('elFinder', 'GlobGC'), $pdir . DIRECTORY_SEPARATOR . $tmppre . '*', 7200);
            $files = self::localScandir($dir);
            if ($files && ($arc = tempnam($dir, $tmppre))) {
                unlink($arc);
                $arc = $arc . '.' . $ext;
                $name = basename($arc);
                if ($arc = $this->makeArchive($dir, $files, $name, $cmd)) {
                    $file = tempnam($pdir, $tmppre);
                    unlink($file);
                    $res = rename($arc, $file);
                    $this->rmdirRecursive($dir);
                }
            }
        }
        return $res ? array('path' => $file, 'ext' => $ext, 'mime' => $mime, 'prefix' => $prefix) : false;
    }

    /**
     * Return file contents
     *
     * @param  string $hash file hash
     *
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    public function getContents($hash)
    {
        $file = $this->file($hash);

        if (!$file) {
            return $this->setError(elFinder::ERROR_FILE_NOT_FOUND);
        }

        if ($file['mime'] == 'directory') {
            return $this->setError(elFinder::ERROR_NOT_FILE);
        }

        if (!$file['read']) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if ($this->getMaxSize > 0 && $file['size'] > $this->getMaxSize) {
            return $this->setError(elFinder::ERROR_UPLOAD_FILE_SIZE);
        }

        return $file['size'] ? $this->_getContents($this->convEncIn($this->decode($hash), true)) : '';
    }

    /**
     * Put content in text file and return file info.
     *
     * @param  string $hash    file hash
     * @param  string $content new file content
     *
     * @return array|false
     * @author Dmitry (dio) Levashov
     **/
    public function putContents($hash, $content)
    {
        if ($this->commandDisabled('edit')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        $path = $this->decode($hash);

        if (!($file = $this->file($hash))) {
            return $this->setError(elFinder::ERROR_FILE_NOT_FOUND);
        }

        if (!$file['write']) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        // check data cheme
        if (preg_match('~^\0data:(.+?/.+?);base64,~', $content, $m)) {
            $dMime = $m[1];
            if ($file['size'] > 0 && $dMime !== $file['mime']) {
                return $this->setError(elFinder::ERROR_PERM_DENIED);
            }
            $content = base64_decode(substr($content, strlen($m[0])));
        }

        // check MIME
        $name = $this->basenameCE($path);
        $mime = '';
        $mimeByName = $this->mimetype($name, true);
        if ($this->mimeDetect !== 'internal') {
            if ($tp = $this->tmpfile()) {
                fwrite($tp, $content);
                $info = stream_get_meta_data($tp);
                $filepath = $info['uri'];
                $mime = $this->mimetype($filepath, $name);
                fclose($tp);
            }
        }
        if (!$this->allowPutMime($mimeByName) || ($mime && !$this->allowPutMime($mime))) {
            return $this->setError(elFinder::ERROR_UPLOAD_FILE_MIME);
        }

        $this->clearcache();
        $res = false;
        if ($this->convEncOut($this->_filePutContents($this->convEncIn($path), $content))) {
            $this->rmTmb($file);
            $this->clearstatcache();
            $res = $this->stat($path);
        }
        return $res;
    }

    /**
     * Extract files from archive
     *
     * @param  string $hash archive hash
     * @param null    $makedir
     *
     * @return array|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     */
    public function extract($hash, $makedir = null)
    {
        if ($this->commandDisabled('extract')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if (($file = $this->file($hash)) == false) {
            return $this->setError(elFinder::ERROR_FILE_NOT_FOUND);
        }

        $archiver = isset($this->archivers['extract'][$file['mime']])
            ? $this->archivers['extract'][$file['mime']]
            : array();

        if (!$archiver) {
            return $this->setError(elFinder::ERROR_NOT_ARCHIVE);
        }

        $path = $this->decode($hash);
        $parent = $this->stat($this->dirnameCE($path));

        if (!$file['read'] || !$parent['write']) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }
        $this->clearcache();
        $this->extractToNewdir = is_null($makedir) ? 'auto' : (bool)$makedir;

        if ($path = $this->convEncOut($this->_extract($this->convEncIn($path), $archiver))) {
            if (is_array($path)) {
                foreach ($path as $_k => $_p) {
                    $path[$_k] = $this->stat($_p);
                }
            } else {
                $path = $this->stat($path);
            }
            return $path;
        } else {
            return false;
        }
    }

    /**
     * Add files to archive
     *
     * @param        $hashes
     * @param        $mime
     * @param string $name
     *
     * @return array|bool
     * @throws Exception
     */
    public function archive($hashes, $mime, $name = '')
    {
        if ($this->commandDisabled('archive')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if ($name !== '' && !$this->nameAccepted($name, false)) {
            return $this->setError(elFinder::ERROR_INVALID_NAME);
        }

        $archiver = isset($this->archivers['create'][$mime])
            ? $this->archivers['create'][$mime]
            : array();

        if (!$archiver) {
            return $this->setError(elFinder::ERROR_ARCHIVE_TYPE);
        }

        $files = array();
        $useRemoteArchive = !empty($this->options['useRemoteArchive']);

        $dir = '';
        foreach ($hashes as $hash) {
            if (($file = $this->file($hash)) == false) {
                return $this->setError(elFinder::ERROR_FILE_NOT_FOUND, '#' . $hash);
            }
            if (!$file['read']) {
                return $this->setError(elFinder::ERROR_PERM_DENIED);
            }
            $path = $this->decode($hash);
            if ($dir === '') {
                $dir = $this->dirnameCE($path);
                $stat = $this->stat($dir);
                if (!$stat['write']) {
                    return $this->setError(elFinder::ERROR_PERM_DENIED);
                }
            }

            $files[] = $useRemoteArchive ? $hash : $this->basenameCE($path);
        }

        if ($name === '') {
            $name = count($files) == 1 ? $files[0] : 'Archive';
        } else {
            $name = str_replace(array('/', '\\'), '_', preg_replace('/\.' . preg_quote($archiver['ext'], '/') . '$/i', '', $name));
        }
        $name .= '.' . $archiver['ext'];
        $name = $this->uniqueName($dir, $name, '');
        $this->clearcache();
        if ($useRemoteArchive) {
            return ($path = $this->remoteArchive($files, $name, $archiver)) ? $this->stat($path) : false;
        } else {
            return ($path = $this->convEncOut($this->_archive($this->convEncIn($dir), $this->convEncIn($files), $this->convEncIn($name), $archiver))) ? $this->stat($path) : false;
        }
    }

    /**
     * Create an archive from remote items
     *
     * @param      array  $hashes files hashes list
     * @param      string $name   archive name
     * @param      array  $arc    archiver options
     *
     * @return     string|boolean  path of created archive
     * @throws     Exception
     */
    protected function remoteArchive($hashes, $name, $arc)
    {
        $resPath = false;
        $file0 = $this->file($hashes[0]);
        if ($file0 && ($dir = $this->getItemsInHand($hashes))) {
            $files = self::localScandir($dir);
            if ($files) {
                if ($arc = $this->makeArchive($dir, $files, $name, $arc)) {
                    if ($fp = fopen($arc, 'rb')) {
                        $fstat = stat($arc);
                        $stat = array(
                            'size' => $fstat['size'],
                            'ts' => $fstat['mtime'],
                            'mime' => $this->mimetype($arc, $name)
                        );
                        $path = $this->decode($file0['phash']);
                        $resPath = $this->saveCE($fp, $path, $name, $stat);
                        fclose($fp);
                    }
                }
            }
            $this->rmdirRecursive($dir);
        }
        return $resPath;
    }

    /**
     * Resize image
     *
     * @param  string $hash       image file
     * @param  int    $width      new width
     * @param  int    $height     new height
     * @param  int    $x          X start poistion for crop
     * @param  int    $y          Y start poistion for crop
     * @param  string $mode       action how to mainpulate image
     * @param  string $bg         background color
     * @param  int    $degree     rotete degree
     * @param  int    $jpgQuality JEPG quality (1-100)
     *
     * @return array|false
     * @throws ImagickException
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     * @author nao-pon
     * @author Troex Nevelin
     */
    public function resize($hash, $width, $height, $x, $y, $mode = 'resize', $bg = '', $degree = 0, $jpgQuality = null)
    {
        if ($this->commandDisabled('resize')) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if ($mode === 'rotate' && $degree == 0) {
            return array('losslessRotate' => ($this->procExec(ELFINDER_EXIFTRAN_PATH . ' -h') === 0 || $this->procExec(ELFINDER_JPEGTRAN_PATH . ' -version') === 0));
        }

        if (($file = $this->file($hash)) == false) {
            return $this->setError(elFinder::ERROR_FILE_NOT_FOUND);
        }

        if (!$file['write'] || !$file['read']) {
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        $path = $this->decode($hash);

        $work_path = $this->getWorkFile($this->encoding ? $this->convEncIn($path, true) : $path);

        if (!$work_path || !is_writable($work_path)) {
            if ($work_path && $path !== $work_path && is_file($work_path)) {
                unlink($work_path);
            }
            return $this->setError(elFinder::ERROR_PERM_DENIED);
        }

        if ($this->imgLib !== 'imagick' && $this->imgLib !== 'convert') {
            if (elFinder::isAnimationGif($work_path)) {
                return $this->setError(elFinder::ERROR_UNSUPPORT_TYPE);
            }
        }

        if (elFinder::isAnimationPng($work_path)) {
            return $this->setError(elFinder::ERROR_UNSUPPORT_TYPE);
        }

        switch ($mode) {

            case 'propresize':
                $result = $this->imgResize($work_path, $width, $height, true, true, null, $jpgQuality);
                break;

            case 'crop':
                $result = $this->imgCrop($work_path, $width, $height, $x, $y, null, $jpgQuality);
                break;

            case 'fitsquare':
                $result = $this->imgSquareFit($work_path, $width, $height, 'center', 'middle', ($bg ? $bg : $this->options['tmbBgColor']), null, $jpgQuality);
                break;

            case 'rotate':
                $result = $this->imgRotate($work_path, $degree, ($bg ? $bg : $this->options['bgColorFb']), null, $jpgQuality);
                break;

            default:
                $result = $this->imgResize($work_path, $width, $height, false, true, null, $jpgQuality);
                break;
        }

        $ret = false;
        if ($result) {
            $this->rmTmb($file);
            $this->clearstatcache();
            $fstat = stat($work_path);
            $imgsize = getimagesize($work_path);
            if ($path !== $work_path) {
                $file['size'] = $fstat['size'];
                $file['ts'] = $fstat['mtime'];
                if ($imgsize) {
                    $file['width'] = $imgsize[0];
                    $file['height'] = $imgsize[1];
                }
                if ($fp = fopen($work_path, 'rb')) {
                    $ret = $this->saveCE($fp, $this->dirnameCE($path), $this->basenameCE($path), $file);
                    fclose($fp);
                }
            } else {
                $ret = true;
            }
            if ($ret) {
                $this->clearcache();
                $ret = $this->stat($path);
                if ($imgsize) {
                    $ret['width'] = $imgsize[0];
                    $ret['height'] = $imgsize[1];
                }
            }
        }
        if ($path !== $work_path) {
            is_file($work_path) && unlink($work_path);
        }

        return $ret;
    }

    /**
     * Remove file/dir
     *
     * @param  string $hash file hash
     *
     * @return bool
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    public function rm($hash)
    {
        return $this->commandDisabled('rm')
            ? $this->setError(elFinder::ERROR_PERM_DENIED)
            : $this->remove($this->decode($hash));
    }

    /**
     * Search files
     *
     * @param  string $q search string
     * @param  array  $mimes
     * @param null    $hash
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    public function search($q, $mimes, $hash = null)
    {
        $res = array();
        $matchMethod = null;
        $args = func_get_args();
        if (!empty($args[3])) {
            $matchMethod = 'searchMatch' . $args[3];
            if (!is_callable(array($this, $matchMethod))) {
                return array();
            }
        }

        $dir = null;
        if ($hash) {
            $dir = $this->decode($hash);
            $stat = $this->stat($dir);
            if (!$stat || $stat['mime'] !== 'directory' || !$stat['read']) {
                $q = '';
            }
        }
        if ($mimes && $this->onlyMimes) {
            $mimes = array_intersect($mimes, $this->onlyMimes);
            if (!$mimes) {
                $q = '';
            }
        }
        $this->searchStart = time();

        $qs = preg_split('/"([^"]+)"| +/', $q, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        $query = $excludes = array();
        foreach ($qs as $_q) {
            $_q = trim($_q);
            if ($_q !== '') {
                if ($_q[0] === '-') {
                    if (isset($_q[1])) {
                        $excludes[] = substr($_q, 1);
                    }
                } else {
                    $query[] = $_q;
                }
            }
        }
        if (!$query) {
            $q = '';
        } else {
            $q = join(' ', $query);
            $this->doSearchCurrentQuery = array(
                'q' => $q,
                'excludes' => $excludes,
                'matchMethod' => $matchMethod
            );
        }

        if ($q === '' || $this->commandDisabled('search')) {
            return $res;
        }

        // valided regex $this->options['searchExDirReg']
        if ($this->options['searchExDirReg']) {
            if (false === preg_match($this->options['searchExDirReg'], '')) {
                $this->options['searchExDirReg'] = '';
            }
        }

        // check the leaf root too
        if (!$mimes && (is_null($dir) || $dir == $this->root)) {
            $rootStat = $this->stat($this->root);
            if (!empty($rootStat['phash'])) {
                if ($this->stripos($rootStat['name'], $q) !== false) {
                    $res = array($rootStat);
                }
            }
        }

        return array_merge($res, $this->doSearch(is_null($dir) ? $this->root : $dir, $q, $mimes));
    }

    /**
     * Return image dimensions
     *
     * @param  string $hash file hash
     *
     * @return array|string
     * @author Dmitry (dio) Levashov
     **/
    public function dimensions($hash)
    {
        if (($file = $this->file($hash)) == false) {
            return false;
        }
        // Throw additional parameters for some drivers
        if (func_num_args() > 1) {
            $args = func_get_arg(1);
        } else {
            $args = array();
        }
        return $this->convEncOut($this->_dimensions($this->convEncIn($this->decode($hash)), $file['mime'], $args));
    }

    /**
     * Return has subdirs
     *
     * @param  string $hash file hash
     *
     * @return bool
     * @author Naoki Sawada
     **/
    public function subdirs($hash)
    {
        return (bool)$this->subdirsCE($this->decode($hash));
    }

    /**
     * Return content URL (for netmout volume driver)
     * If file.url == 1 requests from JavaScript client with XHR
     *
     * @param string $hash    file hash
     * @param array  $options options array
     *
     * @return boolean|string
     * @author Naoki Sawada
     */
    public function getContentUrl($hash, $options = array())
    {
        if (($file = $this->file($hash)) === false) {
            return false;
        }
        if (!empty($options['onetime']) && $this->options['onetimeUrl']) {
            if (is_callable($this->options['onetimeUrl'])) {
                return call_user_func_array($this->options['onetimeUrl'], array($file, $options, $this));
            } else {
                $ret = false;
                if ($tmpdir = elFinder::getStaticVar('commonTempPath')) {
                    if ($source = $this->open($hash)) {
                        if ($_dat = tempnam($tmpdir, 'ELF')) {
                            $token = md5($_dat . session_id());
                            $dat = $tmpdir . DIRECTORY_SEPARATOR . 'ELF' . $token;
                            if (rename($_dat, $dat)) {
                                $info = stream_get_meta_data($source);
                                if (!empty($info['uri'])) {
                                    $tmp = $info['uri'];
                                } else {
                                    $tmp = tempnam($tmpdir, 'ELF');
                                    if ($dest = fopen($tmp, 'wb')) {
                                        if (!stream_copy_to_stream($source, $dest)) {
                                            $tmp = false;
                                        }
                                        fclose($dest);
                                    }
                                }
                                $this->close($source, $hash);
                                if ($tmp) {
                                    $info = array(
                                        'file' => base64_encode($tmp),
                                        'name' => $file['name'],
                                        'mime' => $file['mime'],
                                        'ts' => $file['ts']
                                    );
                                    if (file_put_contents($dat, json_encode($info))) {
                                        $conUrl = elFinder::getConnectorUrl();
                                        $ret = $conUrl . (strpos($conUrl, '?') !== false? '&' : '?') . 'cmd=file&onetime=1&target=' . $token;

                                    }
                                }
                                if (!$ret) {
                                    unlink($dat);
                                }
                            } else {
                                unlink($_dat);
                            }
                        }
                    }
                }
                return $ret;
            }
        }
        if (empty($file['url']) && $this->URL) {
            $path = str_replace($this->separator, '/', substr($this->decode($hash), strlen(rtrim($this->root, '/' . $this->separator)) + 1));
            if ($this->encoding) {
                $path = $this->convEncIn($path, true);
            }
            $path = str_replace('%2F', '/', rawurlencode($path));
            return $this->URL . $path;
        } else {
            $ret = false;
            if (!empty($file['url']) && $file['url'] != 1) {
                return $file['url'];
            } else if (!empty($options['temporary']) && ($tempInfo = $this->getTempLinkInfo('temp_' . md5($hash . session_id())))) {
                if (is_readable($tempInfo['path'])) {
                    touch($tempInfo['path']);
                    $ret = $tempInfo['url'] . '?' . rawurlencode($file['name']);
                } else if ($source = $this->open($hash)) {
                    if ($dest = fopen($tempInfo['path'], 'wb')) {
                        if (stream_copy_to_stream($source, $dest)) {
                            $ret = $tempInfo['url'] . '?' . rawurlencode($file['name']);
                        }
                        fclose($dest);
                    }
                    $this->close($source, $hash);
                }
            }
            return $ret;
        }
    }

    /**
     * Get temporary contents link infomation
     *
     * @param string $name
     *
     * @return boolean|array
     * @author Naoki Sawada
     */
    public function getTempLinkInfo($name = null)
    {
        if ($this->tmpLinkPath) {
            if (!$name) {
                $name = 'temp_' . md5($_SERVER['REMOTE_ADDR'] . (string)microtime(true));
            } else if (substr($name, 0, 5) !== 'temp_') {
                $name = 'temp_' . $name;
            }
            register_shutdown_function(array('elFinder', 'GlobGC'), $this->tmpLinkPath . DIRECTORY_SEPARATOR . 'temp_*', elFinder::$tmpLinkLifeTime);
            return array(
                'path' => $path = $this->tmpLinkPath . DIRECTORY_SEPARATOR . $name,
                'url' => $this->tmpLinkUrl . '/' . rawurlencode($name)
            );
        }
        return false;
    }

    /**
     * Get URL of substitute image by request args `substitute` or 4th argument $maxSize
     *
     * @param string   $target  Target hash
     * @param array    $srcSize Size info array [width, height]
     * @param resource $srcfp   Source file file pointer
     * @param integer  $maxSize Maximum pixel of substitute image
     *
     * @return boolean
     * @throws ImagickException
     * @throws elFinderAbortException
     */
    public function getSubstituteImgLink($target, $srcSize, $srcfp = null, $maxSize = null)
    {
        $url = false;
        $file = $this->file($target);
        $force = !in_array($file['mime'], array('image/jpeg', 'image/png', 'image/gif'));
        if (!$maxSize) {
            $args = elFinder::$currentArgs;
            if (!empty($args['substitute'])) {
                $maxSize = $args['substitute'];
            }
        }
        if ($maxSize && $srcSize[0] && $srcSize[1]) {
            if ($this->getOption('substituteImg')) {
                $maxSize = intval($maxSize);
                $zoom = min(($maxSize / $srcSize[0]), ($maxSize / $srcSize[1]));
                if ($force || $zoom < 1) {
                    $width = round($srcSize[0] * $zoom);
                    $height = round($srcSize[1] * $zoom);
                    $jpgQuality = 50;
                    $preserveExif = false;
                    $unenlarge = true;
                    $checkAnimated = true;
                    $destformat = $file['mime'] === 'image/jpeg'? null : 'png';
                    if (!$srcfp) {
                        elFinder::checkAborted();
                        $srcfp = $this->open($target);
                    }
                    if ($srcfp && ($tempLink = $this->getTempLinkInfo())) {
                        elFinder::checkAborted();
                        $dest = fopen($tempLink['path'], 'wb');
                        if ($dest && stream_copy_to_stream($srcfp, $dest)) {
                            fclose($dest);
                            if ($this->imageUtil('resize', $tempLink['path'], compact('width', 'height', 'jpgQuality', 'preserveExif', 'unenlarge', 'checkAnimated', 'destformat'))) {
                                $url = $tempLink['url'];
                                // set expire to 1 min left
                                touch($tempLink['path'], time() - elFinder::$tmpLinkLifeTime + 60);
                            } else {
                                unlink($tempLink['path']);
                            }
                        }
                        $this->close($srcfp, $target);
                    }
                }
            }
        }

        return $url;
    }

    /**
     * Return temp path
     *
     * @return string
     * @author Naoki Sawada
     */
    public function getTempPath()
    {
        $tempPath = null;
        if (isset($this->tmpPath) && $this->tmpPath && is_writable($this->tmpPath)) {
            $tempPath = $this->tmpPath;
        } else if (isset($this->tmp) && $this->tmp && is_writable($this->tmp)) {
            $tempPath = $this->tmp;
        } else if (elFinder::getStaticVar('commonTempPath') && is_writable(elFinder::getStaticVar('commonTempPath'))) {
            $tempPath = elFinder::getStaticVar('commonTempPath');
        } else if (function_exists('sys_get_temp_dir')) {
            $tempPath = sys_get_temp_dir();
        } else if ($this->tmbPathWritable) {
            $tempPath = $this->tmbPath;
        }
        if ($tempPath && DIRECTORY_SEPARATOR !== '/') {
            $tempPath = str_replace('/', DIRECTORY_SEPARATOR, $tempPath);
        }
		if(opendir($tempPath)){
			return $tempPath;
		} else if (defined( 'WP_TEMP_DIR' )) {
			return get_temp_dir();
		} else {
			$custom_temp_path = WP_CONTENT_DIR.'/temp';
			if (!is_dir($custom_temp_path)) {
				mkdir($custom_temp_path, 0777, true);
			}
			return $custom_temp_path;
		}
    }

    /**
     * (Make &) Get upload taget dirctory hash
     *
     * @param string $baseTargetHash
     * @param string $path
     * @param array  $result
     *
     * @return boolean|string
     * @author Naoki Sawada
     */
    public function getUploadTaget($baseTargetHash, $path, & $result)
    {
        $base = $this->decode($baseTargetHash);
        $targetHash = $baseTargetHash;
        $path = ltrim($path, $this->separator);
        $dirs = explode($this->separator, $path);
        array_pop($dirs);
        foreach ($dirs as $dir) {
            $targetPath = $this->joinPathCE($base, $dir);
            if (!$_realpath = $this->realpath($this->encode($targetPath))) {
                if ($stat = $this->mkdir($targetHash, $dir)) {
                    $result['added'][] = $stat;
                    $targetHash = $stat['hash'];
                    $base = $this->decode($targetHash);
                } else {
                    return false;
                }
            } else {
                $targetHash = $this->encode($_realpath);
                if ($this->dir($targetHash)) {
                    $base = $this->decode($targetHash);
                } else {
                    return false;
                }
            }
        }
        return $targetHash;
    }

    /**
     * Return this uploadMaxSize value
     *
     * @return integer
     * @author Naoki Sawada
     */
    public function getUploadMaxSize()
    {
        return $this->uploadMaxSize;
    }

    public function setUploadOverwrite($var)
    {
        $this->uploadOverwrite = (bool)$var;
    }

    /**
     * Image file utility
     *
     * @param string $mode    'resize', 'rotate', 'propresize', 'crop', 'fitsquare'
     * @param string $src     Image file local path
     * @param array  $options excute options
     *
     * @return bool
     * @throws ImagickException
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    public function imageUtil($mode, $src, $options = array())
    {
        if (!isset($options['jpgQuality'])) {
            $options['jpgQuality'] = intval($this->options['jpgQuality']);
        }
        if (!isset($options['bgcolor'])) {
            $options['bgcolor'] = '#ffffff';
        }
        if (!isset($options['bgColorFb'])) {
            $options['bgColorFb'] = $this->options['bgColorFb'];
        }
        $destformat = !empty($options['destformat'])? $options['destformat'] : null;

        // check 'width' ,'height'
        if (in_array($mode, array('resize', 'propresize', 'crop', 'fitsquare'))) {
            if (empty($options['width']) || empty($options['height'])) {
                return false;
            }
        }

        if (!empty($options['checkAnimated'])) {
            if ($this->imgLib !== 'imagick' && $this->imgLib !== 'convert') {
                if (elFinder::isAnimationGif($src)) {
                    return false;
                }
            }
            if (elFinder::isAnimationPng($src)) {
                return false;
            }
        }

        switch ($mode) {
            case 'rotate':
                if (empty($options['degree'])) {
                    return true;
                }
                return (bool)$this->imgRotate($src, $options['degree'], $options['bgColorFb'], $destformat, $options['jpgQuality']);

            case 'resize':
                return (bool)$this->imgResize($src, $options['width'], $options['height'], false, true, $destformat, $options['jpgQuality'], $options);

            case 'propresize':
                return (bool)$this->imgResize($src, $options['width'], $options['height'], true, true, $destformat, $options['jpgQuality'], $options);

            case 'crop':
                if (isset($options['x']) && isset($options['y'])) {
                    return (bool)$this->imgCrop($src, $options['width'], $options['height'], $options['x'], $options['y'], $destformat, $options['jpgQuality']);
                }
                break;

            case 'fitsquare':
                return (bool)$this->imgSquareFit($src, $options['width'], $options['height'], 'center', 'middle', $options['bgcolor'], $destformat, $options['jpgQuality']);

        }
        return false;
    }

    /**
     * Convert Video To Image by ffmpeg
     *
     * @param  string $file video source file path
     * @param  array  $stat file stat array
     * @param  object $self volume driver object
     * @param  int    $ss   start seconds
     *
     * @return bool
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    public function ffmpegToImg($file, $stat, $self, $ss = null)
    {
        $name = basename($file);
        $path = dirname($file);
        $tmp = $path . DIRECTORY_SEPARATOR . md5($name);
        // register auto delete on shutdown
        $GLOBALS['elFinderTempFiles'][$tmp] = true;
        if (rename($file, $tmp)) {
            if ($ss === null) {
                // specific start time by file name (xxx^[sec].[extention] - video^3.mp4)
                if (preg_match('/\^(\d+(?:\.\d+)?)\.[^.]+$/', $stat['name'], $_m)) {
                    $ss = $_m[1];
                } else {
                    $ss = $this->options['tmbVideoConvSec'];
                }
            }
            $cmd = sprintf(ELFINDER_FFMPEG_PATH . ' -i %s -ss 00:00:%.3f -vframes 1 -f image2 -- %s', escapeshellarg($tmp), $ss, escapeshellarg($file));
            $r = ($this->procExec($cmd) === 0);
            clearstatcache();
            if ($r && $ss > 0 && !file_exists($file)) {
                // Retry by half of $ss
                $ss = max(intval($ss / 2), 0);
                rename($tmp, $file);
                $r = $this->ffmpegToImg($file, $stat, $self, $ss);
            } else {
                unlink($tmp);
            }
            return $r;
        }
        return false;
    }

    /**
     * Creates a temporary file and return file pointer
     *
     * @return resource|boolean
     */
    public function tmpfile()
    {
        if ($tmp = $this->getTempFile()) {
            return fopen($tmp, 'wb');
        }
        return false;
    }

    /**
     * Save error message
     *
     * @param  array  error
     *
     * @return boolean false
     * @author Naoki Sawada
     **/
    protected function setError()
    {
        $this->error = array();
        $this->addError(func_get_args());
        return false;
    }

    /**
     * Add error message
     *
     * @param  array  error
     *
     * @return false
     * @author Dmitry(dio) Levashov
     **/
    protected function addError()
    {
        foreach (func_get_args() as $err) {
            if (is_array($err)) {
                foreach($err as $er) {
                    $this->addError($er);
                }
            } else {
                $this->error[] = (string)$err;
            }
        }
        return false;
    }

    /*********************************************************************/
    /*                               FS API                              */
    /*********************************************************************/

    /***************** server encoding support *******************/

    /**
     * Return parent directory path (with convert encoding)
     *
     * @param  string $path file path
     *
     * @return string
     * @author Naoki Sawada
     **/
    protected function dirnameCE($path)
    {
        $dirname = (!$this->encoding) ? $this->_dirname($path) : $this->convEncOut($this->_dirname($this->convEncIn($path)));
        // check to infinite loop prevention
        return ($dirname != $path) ? $dirname : '';
    }

    /**
     * Return file name (with convert encoding)
     *
     * @param  string $path file path
     *
     * @return string
     * @author Naoki Sawada
     **/
    protected function basenameCE($path)
    {
        return (!$this->encoding) ? $this->_basename($path) : $this->convEncOut($this->_basename($this->convEncIn($path)));
    }

    /**
     * Join dir name and file name and return full path. (with convert encoding)
     * Some drivers (db) use int as path - so we give to concat path to driver itself
     *
     * @param  string $dir  dir path
     * @param  string $name file name
     *
     * @return string
     * @author Naoki Sawada
     **/
    protected function joinPathCE($dir, $name)
    {
        return (!$this->encoding) ? $this->_joinPath($dir, $name) : $this->convEncOut($this->_joinPath($this->convEncIn($dir), $this->convEncIn($name)));
    }

    /**
     * Return normalized path (with convert encoding)
     *
     * @param  string $path file path
     *
     * @return string
     * @author Naoki Sawada
     **/
    protected function normpathCE($path)
    {
        return (!$this->encoding) ? $this->_normpath($path) : $this->convEncOut($this->_normpath($this->convEncIn($path)));
    }

    /**
     * Return file path related to root dir (with convert encoding)
     *
     * @param  string $path file path
     *
     * @return string
     * @author Naoki Sawada
     **/
    protected function relpathCE($path)
    {
        return (!$this->encoding) ? $this->_relpath($path) : $this->convEncOut($this->_relpath($this->convEncIn($path)));
    }

    /**
     * Convert path related to root dir into real path (with convert encoding)
     *
     * @param  string $path rel file path
     *
     * @return string
     * @author Naoki Sawada
     **/
    protected function abspathCE($path)
    {
        return (!$this->encoding) ? $this->_abspath($path) : $this->convEncOut($this->_abspath($this->convEncIn($path)));
    }

    /**
     * Return true if $path is children of $parent (with convert encoding)
     *
     * @param  string $path   path to check
     * @param  string $parent parent path
     *
     * @return bool
     * @author Naoki Sawada
     **/
    protected function inpathCE($path, $parent)
    {
        return (!$this->encoding) ? $this->_inpath($path, $parent) : $this->convEncOut($this->_inpath($this->convEncIn($path), $this->convEncIn($parent)));
    }

    /**
     * Open file and return file pointer (with convert encoding)
     *
     * @param  string $path file path
     * @param string  $mode
     *
     * @return false|resource
     * @internal param bool $write open file for writing
     * @author   Naoki Sawada
     */
    protected function fopenCE($path, $mode = 'rb')
    {
        // check extra option for network stream pointer
        if (func_num_args() > 2) {
            $opts = func_get_arg(2);
        } else {
            $opts = array();
        }
        return (!$this->encoding) ? $this->_fopen($path, $mode, $opts) : $this->convEncOut($this->_fopen($this->convEncIn($path), $mode, $opts));
    }

    /**
     * Close opened file (with convert encoding)
     *
     * @param  resource $fp   file pointer
     * @param  string   $path file path
     *
     * @return bool
     * @author Naoki Sawada
     **/
    protected function fcloseCE($fp, $path = '')
    {
        return (!$this->encoding) ? $this->_fclose($fp, $path) : $this->convEncOut($this->_fclose($fp, $this->convEncIn($path)));
    }

    /**
     * Create new file and write into it from file pointer. (with convert encoding)
     * Return new file path or false on error.
     *
     * @param  resource $fp   file pointer
     * @param  string   $dir  target dir path
     * @param  string   $name file name
     * @param  array    $stat file stat (required by some virtual fs)
     *
     * @return bool|string
     * @author Naoki Sawada
     **/
    protected function saveCE($fp, $dir, $name, $stat)
    {
        $res = (!$this->encoding) ? $this->_save($fp, $dir, $name, $stat) : $this->convEncOut($this->_save($fp, $this->convEncIn($dir), $this->convEncIn($name), $this->convEncIn($stat)));
        if ($res !== false) {
            $this->clearstatcache();
        }
        return $res;
    }

    /**
     * Return true if path is dir and has at least one childs directory (with convert encoding)
     *
     * @param  string $path dir path
     *
     * @return bool
     * @author Naoki Sawada
     **/
    protected function subdirsCE($path)
    {
        if ($this->sessionCaching['subdirs']) {
            if (isset($this->sessionCache['subdirs'][$path]) && !$this->isMyReload()) {
                return $this->sessionCache['subdirs'][$path];
            }
        }
        $hasdir = (bool)((!$this->encoding) ? $this->_subdirs($path) : $this->convEncOut($this->_subdirs($this->convEncIn($path))));
        $this->updateSubdirsCache($path, $hasdir);
        return $hasdir;
    }

    /**
     * Return files list in directory (with convert encoding)
     *
     * @param  string $path dir path
     *
     * @return array
     * @author Naoki Sawada
     **/
    protected function scandirCE($path)
    {
        return (!$this->encoding) ? $this->_scandir($path) : $this->convEncOut($this->_scandir($this->convEncIn($path)));
    }

    /**
     * Create symlink (with convert encoding)
     *
     * @param  string $source    file to link to
     * @param  string $targetDir folder to create link in
     * @param  string $name      symlink name
     *
     * @return bool
     * @author Naoki Sawada
     **/
    protected function symlinkCE($source, $targetDir, $name)
    {
        return (!$this->encoding) ? $this->_symlink($source, $targetDir, $name) : $this->convEncOut($this->_symlink($this->convEncIn($source), $this->convEncIn($targetDir), $this->convEncIn($name)));
    }

    /***************** paths *******************/

    /**
     * Encode path into hash
     *
     * @param  string  file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     * @author Troex Nevelin
     **/
    protected function encode($path)
    {
        if ($path !== '') {

            // cut ROOT from $path for security reason, even if hacker decodes the path he will not know the root
            $p = $this->relpathCE($path);
            // if reqesting root dir $path will be empty, then assign '/' as we cannot leave it blank for crypt
            if ($p === '') {
                $p = $this->separator;
            }
            // change separator
            if ($this->separatorForHash) {
                $p = str_replace($this->separator, $this->separatorForHash, $p);
            }
            // TODO crypt path and return hash
            $hash = $this->crypt($p);
            // hash is used as id in HTML that means it must contain vaild chars
            // make base64 html safe and append prefix in begining
            $hash = strtr(base64_encode($hash), '+/=', '-_.');
            // remove dots '.' at the end, before it was '=' in base64
            $hash = rtrim($hash, '.');
            // append volume id to make hash unique
            return $this->id . $hash;
        }
        //TODO: Add return statement here
    }

    /**
     * Decode path from hash
     *
     * @param  string  file hash
     *
     * @return string
     * @author Dmitry (dio) Levashov
     * @author Troex Nevelin
     **/
    protected function decode($hash)
    {
        if (strpos($hash, $this->id) === 0) {
            // cut volume id after it was prepended in encode
            $h = substr($hash, strlen($this->id));
            // replace HTML safe base64 to normal
            $h = base64_decode(strtr($h, '-_.', '+/='));
            // TODO uncrypt hash and return path
            $path = $this->uncrypt($h);
            // change separator
            if ($this->separatorForHash) {
                $path = str_replace($this->separatorForHash, $this->separator, $path);
            }
            // append ROOT to path after it was cut in encode
            return $this->abspathCE($path);//$this->root.($path === $this->separator ? '' : $this->separator.$path);
        }
        return '';
    }

    /**
     * Return crypted path
     * Not implemented
     *
     * @param  string  path
     *
     * @return mixed
     * @author Dmitry (dio) Levashov
     **/
    protected function crypt($path)
    {
        return $path;
    }

    /**
     * Return uncrypted path
     * Not implemented
     *
     * @param  mixed  hash
     *
     * @return mixed
     * @author Dmitry (dio) Levashov
     **/
    protected function uncrypt($hash)
    {
        return $hash;
    }

    /**
     * Validate file name based on $this->options['acceptedName'] regexp or function
     *
     * @param  string $name file name
     * @param  bool   $isDir
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     */
    protected function nameAccepted($name, $isDir = false)
    {
        if (json_encode($name) === false) {
            return false;
        }
        $nameValidator = $isDir ? $this->dirnameValidator : $this->nameValidator;
        if ($nameValidator) {
            if (is_callable($nameValidator)) {
                $res = call_user_func($nameValidator, $name);
                return $res;
            }
            if (preg_match($nameValidator, '') !== false) {
                return preg_match($nameValidator, $name);
            }
        }
        return true;
    }

    /**
     * Return session rootstat cache key
     *
     * @return string
     */
    protected function getRootstatCachekey()
    {
        return md5($this->root . (isset($this->options['alias']) ? $this->options['alias'] : ''));
    }

    /**
     * Return new unique name based on file name and suffix
     *
     * @param         $dir
     * @param         $name
     * @param  string $suffix suffix append to name
     * @param bool    $checkNum
     * @param int     $start
     *
     * @return string
     * @internal param string $path file path
     * @author   Dmitry (dio) Levashov
     */
    public function uniqueName($dir, $name, $suffix = ' copy', $checkNum = true, $start = 1)
    {
        static $lasts = null;

        if ($lasts === null) {
            $lasts = array();
        }

        $ext = '';

        $splits = elFinder::splitFileExtention($name);
        if ($splits[1]) {
            $ext = '.' . $splits[1];
            $name = $splits[0];
        }

        if ($checkNum && preg_match('/(' . preg_quote($suffix, '/') . ')(\d*)$/i', $name, $m)) {
            $i = (int)$m[2];
            $name = substr($name, 0, strlen($name) - strlen($m[2]));
        } else {
            $i = $start;
            $name .= $suffix;
        }
        $max = $i + 100000;

        if (isset($lasts[$name])) {
            $i = max($i, $lasts[$name]);
        }

        while ($i <= $max) {
            $n = $name . ($i > 0 ? sprintf($this->options['uniqueNumFormat'], $i) : '') . $ext;

            if (!$this->isNameExists($this->joinPathCE($dir, $n))) {
                $this->clearcache();
                $lasts[$name] = ++$i;
                return $n;
            }
            $i++;
        }
        return $name . md5($dir) . $ext;
    }

    /**
     * Converts character encoding from UTF-8 to server's one
     *
     * @param  mixed  $var           target string or array var
     * @param  bool   $restoreLocale do retore global locale, default is false
     * @param  string $unknown       replaces character for unknown
     *
     * @return mixed
     * @author Naoki Sawada
     */
    public function convEncIn($var = null, $restoreLocale = false, $unknown = '_')
    {
        return (!$this->encoding) ? $var : $this->convEnc($var, 'UTF-8', $this->encoding, $this->options['locale'], $restoreLocale, $unknown);
    }

    /**
     * Converts character encoding from server's one to UTF-8
     *
     * @param  mixed  $var           target string or array var
     * @param  bool   $restoreLocale do retore global locale, default is true
     * @param  string $unknown       replaces character for unknown
     *
     * @return mixed
     * @author Naoki Sawada
     */
    public function convEncOut($var = null, $restoreLocale = true, $unknown = '_')
    {
        return (!$this->encoding) ? $var : $this->convEnc($var, $this->encoding, 'UTF-8', $this->options['locale'], $restoreLocale, $unknown);
    }

    /**
     * Converts character encoding (base function)
     *
     * @param  mixed  $var     target string or array var
     * @param  string $from    from character encoding
     * @param  string $to      to character encoding
     * @param  string $locale  local locale
     * @param         $restoreLocale
     * @param  string $unknown replaces character for unknown
     *
     * @return mixed
     */
    protected function convEnc($var, $from, $to, $locale, $restoreLocale, $unknown = '_')
    {
        if (strtoupper($from) !== strtoupper($to)) {
            if ($locale) {
                setlocale(LC_ALL, $locale);
            }
            if (is_array($var)) {
                $_ret = array();
                foreach ($var as $_k => $_v) {
                    $_ret[$_k] = $this->convEnc($_v, $from, $to, '', false, $unknown = '_');
                }
                $var = $_ret;
            } else {
                $_var = false;
                if (is_string($var)) {
                    $_var = $var;
                    $errlev = error_reporting();
                    error_reporting($errlev ^ E_NOTICE);
                    if (false !== ($_var = iconv($from, $to . '//TRANSLIT', $_var))) {
                        $_var = str_replace('?', $unknown, $_var);
                    }
                    error_reporting($errlev);
                }
                if ($_var !== false) {
                    $var = $_var;
                }
            }
            if ($restoreLocale) {
                setlocale(LC_ALL, elFinder::$locale);
            }
        }
        return $var;
    }

    /**
     * Normalize MIME-Type by options['mimeMap']
     *
     * @param      string $type MIME-Type
     * @param      string $name Filename
     * @param      string $ext  File extention without first dot (optional)
     *
     * @return     string  Normalized MIME-Type
     */
    public function mimeTypeNormalize($type, $name, $ext = '')
    {
        if ($ext === '') {
            $ext = (false === $pos = strrpos($name, '.')) ? '' : substr($name, $pos + 1);
        }
        $_checkKey = strtolower($ext . ':' . $type);
        if ($type === '') {
            $_keylen = strlen($_checkKey);
            foreach ($this->options['mimeMap'] as $_key => $_type) {
                if (substr($_key, 0, $_keylen) === $_checkKey) {
                    $type = $_type;
                    break;
                }
            }
        } else if (isset($this->options['mimeMap'][$_checkKey])) {
            $type = $this->options['mimeMap'][$_checkKey];
        } else {
            $_checkKey = strtolower($ext . ':*');
            if (isset($this->options['mimeMap'][$_checkKey])) {
                $type = $this->options['mimeMap'][$_checkKey];
            } else {
                $_checkKey = strtolower('*:' . $type);
                if (isset($this->options['mimeMap'][$_checkKey])) {
                    $type = $this->options['mimeMap'][$_checkKey];
                }
            }
        }
        return $type;
    }

    /*********************** util mainly for inheritance class *********************/

    /**
     * Get temporary filename. Tempfile will be removed when after script execution finishes or exit() is called.
     * When needing the unique file to a path, give $path to parameter.
     *
     * @param  string $path for get unique file to a path
     *
     * @return string|false
     * @author Naoki Sawada
     */
    protected function getTempFile($path = '')
    {
        static $cache = array();

        $key = '';
        if ($path !== '') {
            $key = $this->id . '#' . $path;
            if (isset($cache[$key])) {
                return $cache[$key];
            }
        }

        if ($tmpdir = $this->getTempPath()) {
            $name = tempnam($tmpdir, 'ELF');
            if ($key) {
                $cache[$key] = $name;
            }
            // register auto delete on shutdown
            $GLOBALS['elFinderTempFiles'][$name] = true;
            return $name;
        }

        return false;
    }

    /**
     * File path of local server side work file path
     *
     * @param  string $path path need convert encoding to server encoding
     *
     * @return string
     * @author Naoki Sawada
     */
    protected function getWorkFile($path)
    {
        if ($wfp = $this->tmpfile()) {
            if ($fp = $this->_fopen($path)) {
                while (!feof($fp)) {
                    fwrite($wfp, fread($fp, 8192));
                }
                $info = stream_get_meta_data($wfp);
                fclose($wfp);
                if ($info && !empty($info['uri'])) {
                    return $info['uri'];
                }
            }
        }
        return false;
    }

    /**
     * Get image size array with `dimensions`
     *
     * @param string $path path need convert encoding to server encoding
     * @param string $mime file mime type
     *
     * @return array|false
     * @throws ImagickException
     * @throws elFinderAbortException
     */
    public function getImageSize($path, $mime = '')
    {
        $size = false;
        if ($mime === '' || strtolower(substr($mime, 0, 5)) === 'image') {
            if ($work = $this->getWorkFile($path)) {
                if ($size = getimagesize($work)) {
                    $size['dimensions'] = $size[0] . 'x' . $size[1];
                    $srcfp = fopen($work, 'rb');
                    $cArgs = elFinder::$currentArgs;
                    if (!empty($cArgs['target']) && $subImgLink = $this->getSubstituteImgLink($cArgs['target'], $size, $srcfp)) {
                        $size['url'] = $subImgLink;
                    }
                }
            }
            is_file($work) && unlink($work);
        }
        return $size;
    }

    /**
     * Delete dirctory trees
     *
     * @param string $localpath path need convert encoding to server encoding
     *
     * @return boolean
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    protected function delTree($localpath)
    {
        foreach ($this->_scandir($localpath) as $p) {
            elFinder::checkAborted();
            $stat = $this->stat($this->convEncOut($p));
            $this->convEncIn();
            ($stat['mime'] === 'directory') ? $this->delTree($p) : $this->_unlink($p);
        }
        $res = $this->_rmdir($localpath);
        $res && $this->clearstatcache();
        return $res;
    }

    /**
     * Copy items to a new temporary directory on the local server
     *
     * @param  array  $hashes  target hashes
     * @param  string $dir     destination directory (for recurcive)
     * @param  string $canLink it can use link() (for recurcive)
     *
     * @return string|false    saved path name
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    protected function getItemsInHand($hashes, $dir = null, $canLink = null)
    {
        static $banChrs = null;
        static $totalSize = 0;

        if  (is_null($banChrs)) {
            $banChrs = DIRECTORY_SEPARATOR !== '/'? array('\\', '/', ':', '*', '?', '"', '<', '>', '|') : array('\\', '/');
        }

        if (is_null($dir)) {
            $totalSize = 0;
            if (!$tmpDir = $this->getTempPath()) {
                return false;
            }
            $dir = tempnam($tmpDir, 'elf');
            if (!unlink($dir) || !mkdir($dir, 0700, true)) {
                return false;
            }
            register_shutdown_function(array($this, 'rmdirRecursive'), $dir);
        }
        if (is_null($canLink)) {
            $canLink = ($this instanceof elFinderVolumeLocalFileSystem);
        }
        elFinder::checkAborted();
        $res = true;
        $files = array();
        foreach ($hashes as $hash) {
            if (($file = $this->file($hash)) == false) {
                continue;
            }
            if (!$file['read']) {
                continue;
            }

            $name = $file['name'];
            // remove ctrl characters
            $name = preg_replace('/[[:cntrl:]]+/', '', $name);
            // replace ban characters
            $name = str_replace($banChrs, '_', $name);

            // for call from search results
            if (isset($files[$name])) {
                $name = preg_replace('/^(.*?)(\..*)?$/', '$1_' . $files[$name]++ . '$2', $name);
            } else {
                $files[$name] = 1;
            }
            $target = $dir . DIRECTORY_SEPARATOR . $name;

            if ($file['mime'] === 'directory') {
                $chashes = array();
                $_files = $this->scandir($hash);
                foreach ($_files as $_file) {
                    if ($file['read']) {
                        $chashes[] = $_file['hash'];
                    }
                }
                if (($res = mkdir($target, 0700, true)) && $chashes) {
                    $res = $this->getItemsInHand($chashes, $target, $canLink);
                }
                if (!$res) {
                    break;
                }
                !empty($file['ts']) && touch($target, $file['ts']);
            } else {
                $path = $this->decode($hash);
                if (!$canLink || !($canLink = $this->localFileSystemSymlink($path, $target))) {
                    if (file_exists($target)) {
                        unlink($target);
                    }
                    if ($fp = $this->fopenCE($path)) {
                        if ($tfp = fopen($target, 'wb')) {
                            $totalSize += stream_copy_to_stream($fp, $tfp);
                            fclose($tfp);
                        }
                        !empty($file['ts']) && touch($target, $file['ts']);
                        $this->fcloseCE($fp, $path);
                    }
                } else {
                    $totalSize += filesize($path);
                }
                if ($this->options['maxArcFilesSize'] > 0 && $this->options['maxArcFilesSize'] < $totalSize) {
                    $res = $this->setError(elFinder::ERROR_ARC_MAXSIZE);
                }
            }
        }
        return $res ? $dir : false;
    }

    /*********************** file stat *********************/

    /**
     * Check file attribute
     *
     * @param  string $path  file path
     * @param  string $name  attribute name (read|write|locked|hidden)
     * @param  bool   $val   attribute value returned by file system
     * @param  bool   $isDir path is directory (true: directory, false: file)
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function attr($path, $name, $val = null, $isDir = null)
    {
        if (!isset($this->defaults[$name])) {
            return false;
        }

        $relpath = $this->relpathCE($path);
        if ($this->separator !== '/') {
            $relpath = str_replace($this->separator, '/', $relpath);
        }
        $relpath = '/' . $relpath;

        $perm = null;

        if ($this->access) {
            $perm = call_user_func($this->access, $name, $path, $this->options['accessControlData'], $this, $isDir, $relpath);
            if ($perm !== null) {
                return !!$perm;
            }
        }

        foreach ($this->attributes as $attrs) {
            if (isset($attrs[$name]) && isset($attrs['pattern']) && preg_match($attrs['pattern'], $relpath)) {
                $perm = $attrs[$name];
                break;
            }
        }

        return $perm === null ? (is_null($val) ? $this->defaults[$name] : $val) : !!$perm;
    }

    /**
     * Return true if file with given name can be created in given folder.
     *
     * @param string $dir  parent dir path
     * @param string $name new file name
     * @param null   $isDir
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     */
    protected function allowCreate($dir, $name, $isDir = null)
    {
        return $this->attr($this->joinPathCE($dir, $name), 'write', true, $isDir);
    }

    /**
     * Return true if file MIME type can save with check uploadOrder config.
     *
     * @param string $mime
     *
     * @return boolean
     */
    protected function allowPutMime($mime)
    {
        // logic based on http://httpd.apache.org/docs/2.2/mod/mod_authz_host.html#order
        $allow = $this->mimeAccepted($mime, $this->uploadAllow, null);
        $deny = $this->mimeAccepted($mime, $this->uploadDeny, null);
        if (strtolower($this->uploadOrder[0]) == 'allow') { // array('allow', 'deny'), default is to 'deny'
            $res = false; // default is deny
            if (!$deny && ($allow === true)) { // match only allow
                $res = true;
            }// else (both match | no match | match only deny) { deny }
        } else { // array('deny', 'allow'), default is to 'allow' - this is the default rule
            $res = true; // default is allow
            if (($deny === true) && !$allow) { // match only deny
                $res = false;
            } // else (both match | no match | match only allow) { allow }
        }
        return $res;
    }

    /**
     * Return fileinfo
     *
     * @param  string $path file cache
     *
     * @return array|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function stat($path)
    {
        if ($path === false || is_null($path)) {
            return false;
        }
        $is_root = ($path == $this->root);
        if ($is_root) {
            $rootKey = $this->getRootstatCachekey();
            if ($this->sessionCaching['rootstat'] && !isset($this->sessionCache['rootstat'])) {
                $this->sessionCache['rootstat'] = array();
            }
            if (!isset($this->cache[$path]) && !$this->isMyReload()) {
                // need $path as key for netmount/netunmount
                if ($this->sessionCaching['rootstat'] && isset($this->sessionCache['rootstat'][$rootKey])) {
                    if ($ret = $this->sessionCache['rootstat'][$rootKey]) {
                        if ($this->options['rootRev'] === $ret['rootRev']) {
                            if (isset($this->options['phash'])) {
                                $ret['isroot'] = 1;
                                $ret['phash'] = $this->options['phash'];
                            }
                            return $ret;
                        }
                    }
                }
            }
        }
        $rootSessCache = false;
        if (isset($this->cache[$path])) {
            $ret = $this->cache[$path];
        } else {
            if ($is_root && !empty($this->options['rapidRootStat']) && is_array($this->options['rapidRootStat']) && !$this->needOnline) {
                $ret = $this->updateCache($path, $this->options['rapidRootStat'], true);
            } else {
                $ret = $this->updateCache($path, $this->convEncOut($this->_stat($this->convEncIn($path))), true);
                if ($is_root && !empty($rootKey) && $this->sessionCaching['rootstat']) {
                    $rootSessCache = true;
                }
            }
        } 
        if ($is_root) {
            if ($ret) {
                $this->rootModified = false;
                if ($rootSessCache) {
                    $this->sessionCache['rootstat'][$rootKey] = $ret;
                }
                if (isset($this->options['phash'])) {
                    $ret['isroot'] = 1;
                    $ret['phash'] = $this->options['phash'];
                }
            } else if (!empty($rootKey) && $this->sessionCaching['rootstat']) {
                unset($this->sessionCache['rootstat'][$rootKey]);
            }
        }
        return $ret;
    }

    /**
     * Get root stat extra key values
     *
     * @return array stat extras
     * @author Naoki Sawada
     */
    protected function getRootStatExtra()
    {
        $stat = array();
        if ($this->rootName) {
            $stat['name'] = $this->rootName;
        }
        $stat['rootRev'] = $this->options['rootRev'];
        $stat['options'] = $this->options(null);
        return $stat;
    }

    /**
     * Return fileinfo based on filename
     * For item ID based path file system
     * Please override if needed on each drivers
     *
     * @param  string $path file cache
     *
     * @return array
     */
    protected function isNameExists($path)
    {
        return $this->stat($path);
    }

    /**
     * Put file stat in cache and return it
     *
     * @param  string $path file path
     * @param  array  $stat file stat
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function updateCache($path, $stat)
    {
        if (empty($stat) || !is_array($stat)) {
            return $this->cache[$path] = array();
        }

        if (func_num_args() > 2) {
            $fromStat = func_get_arg(2);
        } else {
            $fromStat = false;
        }

        $stat['hash'] = $this->encode($path);

        $root = $path == $this->root;
        $parent = '';

        if ($root) {
            $stat = array_merge($stat, $this->getRootStatExtra());
        } else {
            if (!isset($stat['name']) || $stat['name'] === '') {
                $stat['name'] = $this->basenameCE($path);
            }
            if (empty($stat['phash'])) {
                $parent = $this->dirnameCE($path);
                $stat['phash'] = $this->encode($parent);
            } else {
                $parent = $this->decode($stat['phash']);
            }
        }

        // name check
        if (isset($stat['name']) && !$jeName = json_encode($stat['name'])) {
            return $this->cache[$path] = array();
        }
        // fix name if required
        if ($this->options['utf8fix'] && $this->options['utf8patterns'] && $this->options['utf8replace']) {
            $stat['name'] = json_decode(str_replace($this->options['utf8patterns'], $this->options['utf8replace'], $jeName));
        }

        if (!isset($stat['size'])) {
            $stat['size'] = 'unknown';
        }

        $mime = isset($stat['mime']) ? $stat['mime'] : '';
        if ($isDir = ($mime === 'directory')) {
            $stat['volumeid'] = $this->id;
        } else {
            if (empty($stat['mime']) || $stat['size'] == 0) {
                $stat['mime'] = $this->mimetype($stat['name'], true, $stat['size'], $mime);
            } else {
                $stat['mime'] = $this->mimeTypeNormalize($stat['mime'], $stat['name']);
            }
        }

        $stat['read'] = intval($this->attr($path, 'read', isset($stat['read']) ? !!$stat['read'] : null, $isDir));
        $stat['write'] = intval($this->attr($path, 'write', isset($stat['write']) ? !!$stat['write'] : null, $isDir));
        if ($root) {
            $stat['locked'] = 1;
            if ($this->options['type'] !== '') {
                $stat['type'] = $this->options['type'];
            }
        } else {
            // lock when parent directory is not writable
            if (!isset($stat['locked'])) {
                $pstat = $this->stat($parent);
                if (isset($pstat['write']) && !$pstat['write']) {
                    $stat['locked'] = true;
                }
            }
            if ($this->attr($path, 'locked', isset($stat['locked']) ? !!$stat['locked'] : null, $isDir)) {
                $stat['locked'] = 1;
            } else {
                unset($stat['locked']);
            }
        }

        if ($root) {
            unset($stat['hidden']);
        } elseif ($this->attr($path, 'hidden', isset($stat['hidden']) ? !!$stat['hidden'] : null, $isDir)
            || !$this->mimeAccepted($stat['mime'])) {
            $stat['hidden'] = 1;
        } else {
            unset($stat['hidden']);
        }

        if ($stat['read'] && empty($stat['hidden'])) {

            if ($isDir) {
                // caching parent's subdirs
                if ($parent) {
                    $this->updateSubdirsCache($parent, true);
                }
                // for dir - check for subdirs
                if ($this->options['checkSubfolders']) {
                    if (!isset($stat['dirs']) && intval($this->options['checkSubfolders']) === -1) {
                        $stat['dirs'] = -1;
                    }
                    if (isset($stat['dirs'])) {
                        if ($stat['dirs']) {
                            if ($stat['dirs'] == -1) {
                                $stat['dirs'] = ($this->sessionCaching['subdirs'] && isset($this->sessionCache['subdirs'][$path])) ? (int)$this->sessionCache['subdirs'][$path] : -1;
                            } else {
                                $stat['dirs'] = 1;
                            }
                        } else {
                            unset($stat['dirs']);
                        }
                    } elseif (!empty($stat['alias']) && !empty($stat['target'])) {
                        $stat['dirs'] = isset($this->cache[$stat['target']])
                            ? intval(isset($this->cache[$stat['target']]['dirs']))
                            : $this->subdirsCE($stat['target']);

                    } elseif ($this->subdirsCE($path)) {
                        $stat['dirs'] = 1;
                    }
                } else {
                    $stat['dirs'] = 1;
                }
                if ($this->options['dirUrlOwn'] === true) {
                    // Set `null` to use the client option `commandsOptions.info.nullUrlDirLinkSelf = true`
                    $stat['url'] = null;
                } else if ($this->options['dirUrlOwn'] === 'hide') {
                    // to hide link in info dialog of the elFinder client
                    $stat['url'] = '';
                }
            } else {
                // for files - check for thumbnails
                $p = isset($stat['target']) ? $stat['target'] : $path;
                if ($this->tmbURL && !isset($stat['tmb']) && $this->canCreateTmb($p, $stat)) {
                    $tmb = $this->gettmb($p, $stat);
                    $stat['tmb'] = $tmb ? $tmb : 1;
                }

            }
            if (!isset($stat['url']) && $this->URL && $this->encoding) {
                $_path = str_replace($this->separator, '/', substr($path, strlen($this->root) + 1));
                $stat['url'] = rtrim($this->URL, '/') . '/' . str_replace('%2F', '/', rawurlencode((substr(PHP_OS, 0, 3) === 'WIN') ? $_path : $this->convEncIn($_path, true)));
            }
        } else {
            if ($isDir) {
                unset($stat['dirs']);
            }
        }

        if (!empty($stat['alias']) && !empty($stat['target'])) {
            $stat['thash'] = $this->encode($stat['target']);
            //$this->cache[$stat['target']] = $stat;
            unset($stat['target']);
        }

        $this->cache[$path] = $stat;

        if (!$fromStat && $root && $this->sessionCaching['rootstat']) {
            // to update session cache
            $this->stat($path);
        }

        return $stat;
    }

    /**
     * Get stat for folder content and put in cache
     *
     * @param  string $path
     *
     * @return void
     * @author Dmitry (dio) Levashov
     **/
    protected function cacheDir($path)
    {
        $this->dirsCache[$path] = array();
        $hasDir = false;

        foreach ($this->scandirCE($path) as $p) {
            if (($stat = $this->stat($p)) && empty($stat['hidden'])) {
                if (!$hasDir && $stat['mime'] === 'directory') {
                    $hasDir = true;
                }
                $this->dirsCache[$path][] = $p;
            }
        }

        $this->updateSubdirsCache($path, $hasDir);
    }

    /**
     * Clean cache
     *
     * @return void
     * @author Dmitry (dio) Levashov
     **/
    protected function clearcache()
    {
        $this->cache = $this->dirsCache = array();
    }

    /**
     * Return file mimetype
     *
     * @param  string      $path file path
     * @param  string|bool $name
     * @param  integer     $size
     * @param  string      $mime was notified from the volume driver
     *
     * @return string
     * @author Dmitry (dio) Levashov
     */
    protected function mimetype($path, $name = '', $size = null, $mime = null)
    {
        $type = '';
        $nameCheck = false;

        if ($name === '') {
            $name = $path;
        } else if ($name === true) {
            $name = $path;
            $nameCheck = true;
        }
        if (!$this instanceof elFinderVolumeLocalFileSystem) {
            $nameCheck = true;
        }
        $ext = (false === $pos = strrpos($name, '.')) ? '' : strtolower(substr($name, $pos + 1));
        if (!$nameCheck && $size === null) {
            $size = file_exists($path) ? filesize($path) : -1;
        }
        if (!$nameCheck && is_readable($path) && $size > 0) {
            // detecting by contents
            if ($this->mimeDetect === 'finfo') {
                $type = finfo_file($this->finfo, $path);
            } else if ($this->mimeDetect === 'mime_content_type') {
                $type = mime_content_type($path);
            }
            if ($type) {
                $type = explode(';', $type);
                $type = trim($type[0]);
                if ($ext && preg_match('~^application/(?:octet-stream|(?:x-)?zip|xml)$~', $type)) {
                    // load default MIME table file "mime.types"
                    if (!elFinderVolumeDriver::$mimetypesLoaded) {
                        elFinderVolumeDriver::loadMimeTypes();
                    }
                    if (isset(elFinderVolumeDriver::$mimetypes[$ext])) {
                        $type = elFinderVolumeDriver::$mimetypes[$ext];
                    }
                } else if ($ext === 'js' && preg_match('~^text/~', $type)) {
                    $type = 'text/javascript';
                }
            }
        }
        if (!$type) {
            // detecting by filename
            $type = elFinderVolumeDriver::mimetypeInternalDetect($name);
            if ($type === 'unknown') {
                if ($mime) {
                    $type = $mime;
                } else {
                    $type = ($size == 0) ? 'text/plain' : $this->options['mimeTypeUnknown'];
                }
            }
        }

        // mime type normalization
        $type = $this->mimeTypeNormalize($type, $name, $ext);

        return $type;
    }

    /**
     * Load file of mime.types
     *
     * @param string $mimeTypesFile The mime types file
     */
    static protected function loadMimeTypes($mimeTypesFile = '')
    {
        if (!elFinderVolumeDriver::$mimetypesLoaded) {
            elFinderVolumeDriver::$mimetypesLoaded = true;
            $file = false;
            if (!empty($mimeTypesFile) && file_exists($mimeTypesFile)) {
                $file = $mimeTypesFile;
            } elseif (elFinder::$defaultMimefile && file_exists(elFinder::$defaultMimefile)) {
                $file = elFinder::$defaultMimefile;
            } elseif (file_exists(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'mime.types')) {
                $file = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'mime.types';
            } elseif (file_exists(dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'mime.types')) {
                $file = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'mime.types';
            }

            if ($file && file_exists($file)) {
                $mimecf = file($file);

                foreach ($mimecf as $line_num => $line) {
                    if (!preg_match('/^\s*#/', $line)) {
                        $mime = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
                        for ($i = 1, $size = count($mime); $i < $size; $i++) {
                            if (!isset(self::$mimetypes[$mime[$i]])) {
                                self::$mimetypes[$mime[$i]] = $mime[0];
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Detect file mimetype using "internal" method or Loading mime.types with $path = ''
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    static protected function mimetypeInternalDetect($path = '')
    {
        // load default MIME table file "mime.types"
        if (!elFinderVolumeDriver::$mimetypesLoaded) {
            elFinderVolumeDriver::loadMimeTypes();
        }
        $ext = '';
        if ($path) {
            $pinfo = pathinfo($path);
            $ext = isset($pinfo['extension']) ? strtolower($pinfo['extension']) : '';
        }
        $res = ($ext && isset(elFinderVolumeDriver::$mimetypes[$ext])) ? elFinderVolumeDriver::$mimetypes[$ext] : 'unknown';
        // Recursive check if MIME type is unknown with multiple extensions
        if ($res === 'unknown' && strpos($pinfo['filename'], '.')) {
            return elFinderVolumeDriver::mimetypeInternalDetect($pinfo['filename']);
        } else {
            return $res;
        }
    }

    /**
     * Return file/total directory size infomation
     *
     * @param  string $path file path
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function countSize($path)
    {

        elFinder::checkAborted();

        $result = array('size' => 0, 'files' => 0, 'dirs' => 0);
        $stat = $this->stat($path);

        if (empty($stat) || !$stat['read'] || !empty($stat['hidden'])) {
            $result['size'] = 'unknown';
            return $result;
        }

        if ($stat['mime'] !== 'directory') {
            $result['size'] = intval($stat['size']);
            $result['files'] = 1;
            return $result;
        }

        $result['dirs'] = 1;
        $subdirs = $this->options['checkSubfolders'];
        $this->options['checkSubfolders'] = true;
        foreach ($this->getScandir($path) as $stat) {
            if ($isDir = ($stat['mime'] === 'directory' && $stat['read'])) {
                ++$result['dirs'];
            } else {
                ++$result['files'];
            }
            $res = $isDir
                ? $this->countSize($this->decode($stat['hash']))
                : (isset($stat['size']) ? array('size' => intval($stat['size'])) : array());
            if (!empty($res['size']) && is_numeric($res['size'])) {
                $result['size'] += $res['size'];
            }
            if (!empty($res['files']) && is_numeric($res['files'])) {
                $result['files'] += $res['files'];
            }
            if (!empty($res['dirs']) && is_numeric($res['dirs'])) {
                $result['dirs'] += $res['dirs'];
                --$result['dirs'];
            }
        }
        $this->options['checkSubfolders'] = $subdirs;
        return $result;
    }

    /**
     * Return true if all mimes is directory or files
     *
     * @param  string $mime1 mimetype
     * @param  string $mime2 mimetype
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function isSameType($mime1, $mime2)
    {
        return ($mime1 == 'directory' && $mime1 == $mime2) || ($mime1 != 'directory' && $mime2 != 'directory');
    }

    /**
     * If file has required attr == $val - return file path,
     * If dir has child with has required attr == $val - return child path
     *
     * @param  string $path file path
     * @param  string $attr attribute name
     * @param  bool   $val  attribute value
     *
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    protected function closestByAttr($path, $attr, $val)
    {
        $stat = $this->stat($path);

        if (empty($stat)) {
            return false;
        }

        $v = isset($stat[$attr]) ? $stat[$attr] : false;

        if ($v == $val) {
            return $path;
        }

        return $stat['mime'] == 'directory'
            ? $this->childsByAttr($path, $attr, $val)
            : false;
    }

    /**
     * Return first found children with required attr == $val
     *
     * @param  string $path file path
     * @param  string $attr attribute name
     * @param  bool   $val  attribute value
     *
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    protected function childsByAttr($path, $attr, $val)
    {
        foreach ($this->scandirCE($path) as $p) {
            if (($_p = $this->closestByAttr($p, $attr, $val)) != false) {
                return $_p;
            }
        }
        return false;
    }

    protected function isMyReload($target = '', $ARGtarget = '')
    {
        if ($this->rootModified || (!empty($this->ARGS['cmd']) && $this->ARGS['cmd'] === 'parents')) {
            return true;
        }
        if (!empty($this->ARGS['reload'])) {
            if ($ARGtarget === '') {
                $ARGtarget = isset($this->ARGS['target']) ? $this->ARGS['target']
                    : ((isset($this->ARGS['targets']) && is_array($this->ARGS['targets']) && count($this->ARGS['targets']) === 1) ?
                        $this->ARGS['targets'][0] : '');
            }
            if ($ARGtarget !== '') {
                $ARGtarget = strval($ARGtarget);
                if ($target === '') {
                    return (strpos($ARGtarget, $this->id) === 0);
                } else {
                    $target = strval($target);
                    return ($target === $ARGtarget);
                }
            }
        }
        return false;
    }

    /**
     * Update subdirs cache data
     *
     * @param string $path
     * @param bool   $subdirs
     *
     * @return void
     */
    protected function updateSubdirsCache($path, $subdirs)
    {
        if (isset($this->cache[$path])) {
            if ($subdirs) {
                $this->cache[$path]['dirs'] = 1;
            } else {
                unset($this->cache[$path]['dirs']);
            }
        }
        if ($this->sessionCaching['subdirs']) {
            $this->sessionCache['subdirs'][$path] = $subdirs;
        }
        if ($this->sessionCaching['rootstat'] && $path == $this->root) {
            unset($this->sessionCache['rootstat'][$this->getRootstatCachekey()]);
        }
    }

    /*****************  get content *******************/

    /**
     * Return required dir's files info.
     * If onlyMimes is set - return only dirs and files of required mimes
     *
     * @param  string $path dir path
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function getScandir($path)
    {
        $files = array();

        !isset($this->dirsCache[$path]) && $this->cacheDir($path);

        foreach ($this->dirsCache[$path] as $p) {
            if (($stat = $this->stat($p)) && empty($stat['hidden'])) {
                $files[] = $stat;
            }
        }

        return $files;
    }


    /**
     * Return subdirs tree
     *
     * @param  string $path parent dir path
     * @param  int    $deep tree deep
     * @param string  $exclude
     *
     * @return array
     * @author Dmitry (dio) Levashov
     */
    protected function gettree($path, $deep, $exclude = '')
    {
        $dirs = array();

        !isset($this->dirsCache[$path]) && $this->cacheDir($path);

        foreach ($this->dirsCache[$path] as $p) {
            $stat = $this->stat($p);

            if ($stat && empty($stat['hidden']) && $p != $exclude && $stat['mime'] == 'directory') {
                $dirs[] = $stat;
                if ($deep > 0 && !empty($stat['dirs'])) {
                    $dirs = array_merge($dirs, $this->gettree($p, $deep - 1));
                }
            }
        }

        return $dirs;
    }

    /**
     * Recursive files search
     *
     * @param  string $path dir path
     * @param  string $q    search string
     * @param  array  $mimes
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function doSearch($path, $q, $mimes)
    {
        $result = array();
        $matchMethod = empty($this->doSearchCurrentQuery['matchMethod']) ? 'searchMatchName' : $this->doSearchCurrentQuery['matchMethod'];
        $timeout = $this->options['searchTimeout'] ? $this->searchStart + $this->options['searchTimeout'] : 0;
        if ($timeout && $timeout < time()) {
            $this->setError(elFinder::ERROR_SEARCH_TIMEOUT, $this->path($this->encode($path)));
            return $result;
        }

        foreach ($this->scandirCE($path) as $p) {
            elFinder::extendTimeLimit($this->options['searchTimeout'] + 30);

            if ($timeout && ($this->error || $timeout < time())) {
                !$this->error && $this->setError(elFinder::ERROR_SEARCH_TIMEOUT, $this->path($this->encode($path)));
                break;
            }


            $stat = $this->stat($p);

            if (!$stat) { // invalid links
                continue;
            }

            if (!empty($stat['hidden']) || !$this->mimeAccepted($stat['mime'], $mimes)) {
                continue;
            }

            $name = $stat['name'];

            if ($this->doSearchCurrentQuery['excludes']) {
                foreach ($this->doSearchCurrentQuery['excludes'] as $exclude) {
                    if ($this->stripos($name, $exclude) !== false) {
                        continue 2;
                    }
                }
            }

            if ((!$mimes || $stat['mime'] !== 'directory') && $this->$matchMethod($name, $q, $p) !== false) {
                $stat['path'] = $this->path($stat['hash']);
                if ($this->URL && !isset($stat['url'])) {
                    $path = str_replace($this->separator, '/', substr($p, strlen($this->root) + 1));
                    if ($this->encoding) {
                        $path = str_replace('%2F', '/', rawurlencode($this->convEncIn($path, true)));
                    } else {
                        $path = str_replace('%2F', '/', rawurlencode($path));
                    }
                    $stat['url'] = $this->URL . $path;
                }

                $result[] = $stat;
            }
            if ($stat['mime'] == 'directory' && $stat['read'] && !isset($stat['alias'])) {
                if (!$this->options['searchExDirReg'] || !preg_match($this->options['searchExDirReg'], $p)) {
                    $result = array_merge($result, $this->doSearch($p, $q, $mimes));
                }
            }
        }

        return $result;
    }

    /**********************  manuipulations  ******************/

    /**
     * Copy file/recursive copy dir only in current volume.
     * Return new file path or false.
     *
     * @param  string $src  source path
     * @param  string $dst  destination dir path
     * @param  string $name new file name (optionaly)
     *
     * @return string|false
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function copy($src, $dst, $name)
    {

        elFinder::checkAborted();

        $srcStat = $this->stat($src);

        if (!empty($srcStat['thash'])) {
            $target = $this->decode($srcStat['thash']);
            if (!$this->inpathCE($target, $this->root)) {
                return $this->setError(elFinder::ERROR_COPY, $this->path($srcStat['hash']), elFinder::ERROR_MKOUTLINK);
            }
            $stat = $this->stat($target);
            $this->clearcache();
            return $stat && $this->symlinkCE($target, $dst, $name)
                ? $this->joinPathCE($dst, $name)
                : $this->setError(elFinder::ERROR_COPY, $this->path($srcStat['hash']));
        }

        if ($srcStat['mime'] === 'directory') {
            $testStat = $this->isNameExists($this->joinPathCE($dst, $name));
            $this->clearcache();

            if (($testStat && $testStat['mime'] !== 'directory') || (!$testStat && !$testStat = $this->mkdir($this->encode($dst), $name))) {
                return $this->setError(elFinder::ERROR_COPY, $this->path($srcStat['hash']));
            }

            $dst = $this->decode($testStat['hash']);

            // start time
            $stime = microtime(true);
            foreach ($this->getScandir($src) as $stat) {
                if (empty($stat['hidden'])) {
                    // current time
                    $ctime = microtime(true);
                    if (($ctime - $stime) > 2) {
                        $stime = $ctime;
                        elFinder::checkAborted();
                    }
                    $name = $stat['name'];
                    $_src = $this->decode($stat['hash']);
                    if (!$this->copy($_src, $dst, $name)) {
                        $this->remove($dst, true); // fall back
                        return $this->setError($this->error, elFinder::ERROR_COPY, $this->_path($src));
                    }
                }
            }

            $this->added[] = $testStat;

            return $dst;
        }

        if ($this->options['copyJoin']) {
            $test = $this->joinPathCE($dst, $name);
            if ($this->isNameExists($test)) {
                $this->remove($test);
            }
        }
        if ($res = $this->convEncOut($this->_copy($this->convEncIn($src), $this->convEncIn($dst), $this->convEncIn($name)))) {
            $path = is_string($res) ? $res : $this->joinPathCE($dst, $name);
            $this->clearstatcache();
            $this->added[] = $this->stat($path);
            return $path;
        }

        return $this->setError(elFinder::ERROR_COPY, $this->path($srcStat['hash']));
    }

    /**
     * Move file
     * Return new file path or false.
     *
     * @param  string $src  source path
     * @param  string $dst  destination dir path
     * @param  string $name new file name
     *
     * @return string|false
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function move($src, $dst, $name)
    {
        $stat = $this->stat($src);
        $stat['realpath'] = $src;
        $this->rmTmb($stat); // can not do rmTmb() after _move()
        $this->clearcache();

        $res = $this->convEncOut($this->_move($this->convEncIn($src), $this->convEncIn($dst), $this->convEncIn($name)));
        // if moving it didn't work try to copy / delete
        if (!$res) {
            if ($this->copy($src, $dst, $name)) {
                $res = $this->remove($src);
            }
        }

        if ($res) {
            $this->clearstatcache();
            if ($stat['mime'] === 'directory') {
                $this->updateSubdirsCache($dst, true);
            }
            $path = is_string($res) ? $res : $this->joinPathCE($dst, $name);
            $this->added[] = $this->stat($path);
            $this->removed[] = $stat;
            return $path;
        }

        return $this->setError(elFinder::ERROR_MOVE, $this->path($stat['hash']));
    }

    /**
     * Copy file from another volume.
     * Return new file path or false.
     *
     * @param  Object $volume      source volume
     * @param  string $src         source file hash
     * @param  string $destination destination dir path
     * @param  string $name        file name
     *
     * @return string|false
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function copyFrom($volume, $src, $destination, $name)
    {

        elFinder::checkAborted();

        if (($source = $volume->file($src)) == false) {
            return $this->addError(elFinder::ERROR_COPY, '#' . $src, $volume->error());
        }

        $srcIsDir = ($source['mime'] === 'directory');

        $errpath = $volume->path($source['hash']);

        $errors = array();
        try {
            $thash = $this->encode($destination);
            elFinder::$instance->trigger('paste.copyfrom', array(&$thash, &$name, '', elFinder::$instance, $this), $errors);
        } catch (elFinderTriggerException $e) {
            return $this->addError(elFinder::ERROR_COPY, $name, $errors);
        }

        if (!$this->nameAccepted($name, $srcIsDir)) {
            return $this->addError(elFinder::ERROR_COPY, $name, $srcIsDir ? elFinder::ERROR_INVALID_DIRNAME : elFinder::ERROR_INVALID_NAME);
        }

        if (!$this->allowCreate($destination, $name, $srcIsDir)) {
            return $this->addError(elFinder::ERROR_COPY, $name, elFinder::ERROR_PERM_DENIED);
        }

        if (!$source['read']) {
            return $this->addError(elFinder::ERROR_COPY, $errpath, elFinder::ERROR_PERM_DENIED);
        }

        if ($srcIsDir) {
            $test = $this->isNameExists($this->joinPathCE($destination, $name));
            $this->clearcache();

            if (($test && $test['mime'] != 'directory') || (!$test && !$test = $this->mkdir($this->encode($destination), $name))) {
                return $this->addError(elFinder::ERROR_COPY, $errpath);
            }

            //$path = $this->joinPathCE($destination, $name);
            $path = $this->decode($test['hash']);

            foreach ($volume->scandir($src) as $entr) {
                $this->copyFrom($volume, $entr['hash'], $path, $entr['name']);
            }

            $this->added[] = $test;
        } else {
            // size check
            if (!isset($source['size']) || $source['size'] > $this->uploadMaxSize) {
                return $this->setError(elFinder::ERROR_UPLOAD_FILE_SIZE);
            }

            // MIME check
            $mimeByName = $this->mimetype($source['name'], true);
            if ($source['mime'] === $mimeByName) {
                $mimeByName = '';
            }
            if (!$this->allowPutMime($source['mime']) || ($mimeByName && !$this->allowPutMime($mimeByName))) {
                return $this->addError(elFinder::ERROR_UPLOAD_FILE_MIME, $errpath);
            }

            if (strpos($source['mime'], 'image') === 0 && ($dim = $volume->dimensions($src))) {
                if (is_array($dim)) {
                    $dim = isset($dim['dim']) ? $dim['dim'] : null;
                }
                if ($dim) {
                    $s = explode('x', $dim);
                    $source['width'] = $s[0];
                    $source['height'] = $s[1];
                }
            }

            if (($fp = $volume->open($src)) == false
                || ($path = $this->saveCE($fp, $destination, $name, $source)) == false) {
                $fp && $volume->close($fp, $src);
                return $this->addError(elFinder::ERROR_COPY, $errpath);
            }
            $volume->close($fp, $src);

            $this->added[] = $this->stat($path);;
        }

        return $path;
    }

    /**
     * Remove file/ recursive remove dir
     *
     * @param  string $path  file path
     * @param  bool   $force try to remove even if file locked
     *
     * @return bool
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function remove($path, $force = false)
    {
        $stat = $this->stat($path);

        if (empty($stat)) {
            return $this->setError(elFinder::ERROR_RM, $this->relpathCE($path), elFinder::ERROR_FILE_NOT_FOUND);
        }

        $stat['realpath'] = $path;
        $this->rmTmb($stat);
        $this->clearcache();

        if (!$force && !empty($stat['locked'])) {
            return $this->setError(elFinder::ERROR_LOCKED, $this->path($stat['hash']));
        }

        if ($stat['mime'] == 'directory' && empty($stat['thash'])) {
            $ret = $this->delTree($this->convEncIn($path));
            $this->convEncOut();
            if (!$ret) {
                return $this->setError(elFinder::ERROR_RM, $this->path($stat['hash']));
            }
        } else {
            if ($this->convEncOut(!$this->_unlink($this->convEncIn($path)))) {
                return $this->setError(elFinder::ERROR_RM, $this->path($stat['hash']));
            }
            $this->clearstatcache();
        }

        $this->removed[] = $stat;
        return true;
    }


    /************************* thumbnails **************************/

    /**
     * Return thumbnail file name for required file
     *
     * @param  array $stat file stat
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function tmbname($stat)
    {
        $name = $stat['hash'] . (isset($stat['ts']) ? $stat['ts'] : '') . '.png';
        if (strlen($name) > 255) {
            $name = $this->id . md5($stat['hash']) . $stat['ts'] . '.png';
        }
        return $name;
    }

    /**
     * Return thumnbnail name if exists
     *
     * @param  string $path file path
     * @param  array  $stat file stat
     *
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    protected function gettmb($path, $stat)
    {
        if ($this->tmbURL && $this->tmbPath) {
            // file itself thumnbnail
            if (strpos($path, $this->tmbPath) === 0) {
                return basename($path);
            }

            $name = $this->tmbname($stat);
            $tmb = $this->tmbPath . DIRECTORY_SEPARATOR . $name;
            if (file_exists($tmb)) {
                if ($this->options['tmbGcMaxlifeHour'] && $this->options['tmbGcPercentage'] > 0) {
                    touch($tmb);
                }
                return $name;
            }
        }
        return false;
    }

    /**
     * Return true if thumnbnail for required file can be created
     *
     * @param  string $path thumnbnail path
     * @param  array  $stat file stat
     * @param  bool   $checkTmbPath
     *
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function canCreateTmb($path, $stat, $checkTmbPath = true)
    {
        static $gdMimes = null;
        static $imgmgPS = null;
        if ($gdMimes === null) {
            $_mimes = array('image/jpeg', 'image/png', 'image/gif', 'image/x-ms-bmp');
            if (function_exists('imagecreatefromwebp')) {
                $_mimes[] = 'image/webp';
            }
            $gdMimes = array_flip($_mimes);
            $imgmgPS = array_flip(array('application/postscript', 'application/pdf'));
        }
        if ((!$checkTmbPath || $this->tmbPathWritable)
            && (!$this->tmbPath || strpos($path, $this->tmbPath) === false) // do not create thumnbnail for thumnbnail
        ) {
            $mime = strtolower($stat['mime']);
            list($type) = explode('/', $mime);
            if (!empty($this->imgConverter)) {
                if (isset($this->imgConverter[$mime])) {
                    return true;
                }
                if (isset($this->imgConverter[$type])) {
                    return true;
                }
            }
            return $this->imgLib
                && (
                    ($type === 'image' && ($this->imgLib === 'gd' ? isset($gdMimes[$stat['mime']]) : true))
                    ||
                    (ELFINDER_IMAGEMAGICK_PS && isset($imgmgPS[$stat['mime']]) && $this->imgLib !== 'gd')
                );
        }
        return false;
    }

    /**
     * Return true if required file can be resized.
     * By default - the same as canCreateTmb
     *
     * @param  string $path thumnbnail path
     * @param  array  $stat file stat
     *
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function canResize($path, $stat)
    {
        return $this->canCreateTmb($path, $stat, false);
    }

    /**
     * Create thumnbnail and return it's URL on success
     *
     * @param  string $path file path
     * @param         $stat
     *
     * @return false|string
     * @internal param string $mime file mime type
     * @throws elFinderAbortException
     * @throws ImagickException
     * @author   Dmitry (dio) Levashov
     */
    protected function createTmb($path, $stat)
    {
        if (!$stat || !$this->canCreateTmb($path, $stat)) {
            return false;
        }

        $name = $this->tmbname($stat);
        $tmb = $this->tmbPath . DIRECTORY_SEPARATOR . $name;

        $maxlength = -1;
        $imgConverter = null;

        // check imgConverter
        $mime = strtolower($stat['mime']);
        list($type) = explode('/', $mime);
        if (isset($this->imgConverter[$mime])) {
            $imgConverter = $this->imgConverter[$mime]['func'];
            if (!empty($this->imgConverter[$mime]['maxlen'])) {
                $maxlength = intval($this->imgConverter[$mime]['maxlen']);
            }
        } else if (isset($this->imgConverter[$type])) {
            $imgConverter = $this->imgConverter[$type]['func'];
            if (!empty($this->imgConverter[$type]['maxlen'])) {
                $maxlength = intval($this->imgConverter[$type]['maxlen']);
            }
        }
        if ($imgConverter && !is_callable($imgConverter)) {
            return false;
        }

        // copy image into tmbPath so some drivers does not store files on local fs
        if (($src = $this->fopenCE($path, 'rb')) == false) {
            return false;
        }

        if (($trg = fopen($tmb, 'wb')) == false) {
            $this->fcloseCE($src, $path);
            return false;
        }

        stream_copy_to_stream($src, $trg, $maxlength);

        $this->fcloseCE($src, $path);
        fclose($trg);

        // call imgConverter
        if ($imgConverter) {
            if (!call_user_func_array($imgConverter, array($tmb, $stat, $this))) {
                file_exists($tmb) && unlink($tmb);
                return false;
            }
        }

        $result = false;

        $tmbSize = $this->tmbSize;

        if ($this->imgLib === 'imagick') {
            try {
                $imagickTest = new imagick($tmb . '[0]');
                $imagickTest->clear();
                $imagickTest = true;
            } catch (Exception $e) {
                $imagickTest = false;
            }
        }

        if (($this->imgLib === 'imagick' && !$imagickTest) || ($s = getimagesize($tmb)) === false) {
            if ($this->imgLib === 'imagick') {
                $bgcolor = $this->options['tmbBgColor'];
                if ($bgcolor === 'transparent') {
                    $bgcolor = 'rgba(255, 255, 255, 0.0)';
                }
                try {
                    $imagick = new imagick();
                    $imagick->setBackgroundColor(new ImagickPixel($bgcolor));
                    $imagick->readImage($this->getExtentionByMime($stat['mime'], ':') . $tmb . '[0]');
                    try {
                        $imagick->trimImage(0);
                    } catch (Exception $e) {
                    }
                    $imagick->setImageFormat('png');
                    $imagick->writeImage($tmb);
                    $imagick->clear();
                    if (($s = getimagesize($tmb)) !== false) {
                        $result = true;
                    }
                } catch (Exception $e) {
                }
            } else if ($this->imgLib === 'convert') {
                $convParams = $this->imageMagickConvertPrepare($tmb, 'png', 100, array(), $stat['mime']);
                $cmd = sprintf('%s -colorspace sRGB -trim -- %s %s', ELFINDER_CONVERT_PATH, $convParams['quotedPath'], $convParams['quotedDstPath']);
                $result = false;
                if ($this->procExec($cmd) === 0) {
                    if (($s = getimagesize($tmb)) !== false) {
                        $result = true;
                    }
                }
            }
            if (!$result) {
                // fallback imgLib to GD
                if (function_exists('gd_info') && ($s = getimagesize($tmb))) {
                    $this->imgLib = 'gd';
                } else {
                    file_exists($tmb) && unlink($tmb);
                    return false;
                }
            }
        }

        /* If image smaller or equal thumbnail size - just fitting to thumbnail square */
        if ($s[0] <= $tmbSize && $s[1] <= $tmbSize) {
            $result = $this->imgSquareFit($tmb, $tmbSize, $tmbSize, 'center', 'middle', $this->options['tmbBgColor'], 'png');
        } else {

            if ($this->options['tmbCrop']) {

                $result = $tmb;
                /* Resize and crop if image bigger than thumbnail */
                if (!(($s[0] > $tmbSize && $s[1] <= $tmbSize) || ($s[0] <= $tmbSize && $s[1] > $tmbSize)) || ($s[0] > $tmbSize && $s[1] > $tmbSize)) {
                    $result = $this->imgResize($tmb, $tmbSize, $tmbSize, true, false, 'png');
                }

                if ($result && ($s = getimagesize($tmb)) != false) {
                    $x = $s[0] > $tmbSize ? intval(($s[0] - $tmbSize) / 2) : 0;
                    $y = $s[1] > $tmbSize ? intval(($s[1] - $tmbSize) / 2) : 0;
                    $result = $this->imgCrop($result, $tmbSize, $tmbSize, $x, $y, 'png');
                } else {
                    $result = false;
                }

            } else {
                $result = $this->imgResize($tmb, $tmbSize, $tmbSize, true, true, 'png');
            }

            if ($result) {
                if ($s = getimagesize($tmb)) {
                    if ($s[0] !== $tmbSize || $s[1] !== $tmbSize) {
                        $result = $this->imgSquareFit($result, $tmbSize, $tmbSize, 'center', 'middle', $this->options['tmbBgColor'], 'png');
                    }
                }
            }
        }

        if (!$result) {
            unlink($tmb);
            return false;
        }

        return $name;
    }

    /**
     * Resize image
     *
     * @param  string $path               image file
     * @param  int    $width              new width
     * @param  int    $height             new height
     * @param  bool   $keepProportions    crop image
     * @param  bool   $resizeByBiggerSide resize image based on bigger side if true
     * @param  string $destformat         image destination format
     * @param  int    $jpgQuality         JEPG quality (1-100)
     * @param  array  $options            Other extra options
     *
     * @return string|false
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     */
    protected function imgResize($path, $width, $height, $keepProportions = false, $resizeByBiggerSide = true, $destformat = null, $jpgQuality = null, $options = array())
    {
        if (($s = getimagesize($path)) == false) {
            return false;
        }

        if (!$jpgQuality) {
            $jpgQuality = $this->options['jpgQuality'];
        }

        list($orig_w, $orig_h) = array($s[0], $s[1]);
        list($size_w, $size_h) = array($width, $height);

        if (empty($options['unenlarge']) || $orig_w > $size_w || $orig_h > $size_h) {
            if ($keepProportions == true) {
                /* Resizing by biggest side */
                if ($resizeByBiggerSide) {
                    if ($orig_w > $orig_h) {
                        $size_h = round($orig_h * $width / $orig_w);
                        $size_w = $width;
                    } else {
                        $size_w = round($orig_w * $height / $orig_h);
                        $size_h = $height;
                    }
                } else {
                    if ($orig_w > $orig_h) {
                        $size_w = round($orig_w * $height / $orig_h);
                        $size_h = $height;
                    } else {
                        $size_h = round($orig_h * $width / $orig_w);
                        $size_w = $width;
                    }
                }
            }
        } else {
            $size_w = $orig_w;
            $size_h = $orig_h;
        }

        elFinder::extendTimeLimit(300);
        switch ($this->imgLib) {
            case 'imagick':

                try {
                    $img = new imagick($path);
                } catch (Exception $e) {
                    return false;
                }

                // Imagick::FILTER_BOX faster than FILTER_LANCZOS so use for createTmb
                // resize bench: http://app-mgng.rhcloud.com/9
                // resize sample: http://www.dylanbeattie.net/magick/filters/result.html
                $filter = ($destformat === 'png' /* createTmb */) ? Imagick::FILTER_BOX : Imagick::FILTER_LANCZOS;

                $ani = ($img->getNumberImages() > 1);
                if ($ani && is_null($destformat)) {
                    $img = $img->coalesceImages();
                    do {
                        $img->resizeImage($size_w, $size_h, $filter, 1);
                    } while ($img->nextImage());
                    $img->optimizeImageLayers();
                    $result = $img->writeImages($path, true);
                } else {
                    if ($ani) {
                        $img->setFirstIterator();
                    }
                    if (strtoupper($img->getImageFormat()) === 'JPEG') {
                        $img->setImageCompression(imagick::COMPRESSION_JPEG);
                        $img->setImageCompressionQuality($jpgQuality);
                        if (isset($options['preserveExif']) && !$options['preserveExif']) {
                            try {
                                $orientation = $img->getImageOrientation();
                            } catch (ImagickException $e) {
                                $orientation = 0;
                            }
                            $img->stripImage();
                            if ($orientation) {
                                $img->setImageOrientation($orientation);
                            }
                        }
                        if ($this->options['jpgProgressive']) {
                            $img->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                        }
                    }
                    $img->resizeImage($size_w, $size_h, $filter, true);
                    if ($destformat) {
                        $result = $this->imagickImage($img, $path, $destformat, $jpgQuality);
                    } else {
                        $result = $img->writeImage($path);
                    }
                }

                $img->clear();

                return $result ? $path : false;

                break;

            case 'convert':
                extract($this->imageMagickConvertPrepare($path, $destformat, $jpgQuality, $s));
                /**
                 * @var string $ani
                 * @var string $index
                 * @var string $coalesce
                 * @var string $deconstruct
                 * @var string $jpgQuality
                 * @var string $quotedPath
                 * @var string $quotedDstPath
                 * @var string $interlace
                 */
                $filter = ($destformat === 'png' /* createTmb */) ? '-filter Box' : '-filter Lanczos';
                $strip = (isset($options['preserveExif']) && !$options['preserveExif']) ? ' -strip' : '';
                $cmd = sprintf('%s %s%s%s%s%s %s -geometry %dx%d! %s %s', ELFINDER_CONVERT_PATH, $quotedPath, $coalesce, $jpgQuality, $strip, $interlace, $filter, $size_w, $size_h, $deconstruct, $quotedDstPath);

                $result = false;
                if ($this->procExec($cmd) === 0) {
                    $result = true;
                }
                return $result ? $path : false;

                break;

            case 'gd':
                elFinder::expandMemoryForGD(array($s, array($size_w, $size_h)));
                $img = $this->gdImageCreate($path, $s['mime']);

                if ($img && false != ($tmp = imagecreatetruecolor($size_w, $size_h))) {

                    $bgNum = false;
                    if ($s[2] === IMAGETYPE_GIF && (!$destformat || $destformat === 'gif')) {
                        $bgIdx = imagecolortransparent($img);
                        if ($bgIdx !== -1) {
                            $c = imagecolorsforindex($img, $bgIdx);
                            $bgNum = imagecolorallocate($tmp, $c['red'], $c['green'], $c['blue']);
                            imagefill($tmp, 0, 0, $bgNum);
                            imagecolortransparent($tmp, $bgNum);
                        }
                    }
                    if ($bgNum === false) {
                        $this->gdImageBackground($tmp, 'transparent');
                    }

                    if (!imagecopyresampled($tmp, $img, 0, 0, 0, 0, $size_w, $size_h, $s[0], $s[1])) {
                        return false;
                    }

                    $result = $this->gdImage($tmp, $path, $destformat, $s['mime'], $jpgQuality);

                    imagedestroy($img);
                    imagedestroy($tmp);

                    return $result ? $path : false;

                }
                break;
        }

        return false;
    }

    /**
     * Crop image
     *
     * @param  string $path       image file
     * @param  int    $width      crop width
     * @param  int    $height     crop height
     * @param  bool   $x          crop left offset
     * @param  bool   $y          crop top offset
     * @param  string $destformat image destination format
     * @param  int    $jpgQuality JEPG quality (1-100)
     *
     * @return string|false
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     */
    protected function imgCrop($path, $width, $height, $x, $y, $destformat = null, $jpgQuality = null)
    {
        if (($s = getimagesize($path)) == false) {
            return false;
        }

        if (!$jpgQuality) {
            $jpgQuality = $this->options['jpgQuality'];
        }

        elFinder::extendTimeLimit(300);
        switch ($this->imgLib) {
            case 'imagick':

                try {
                    $img = new imagick($path);
                } catch (Exception $e) {
                    return false;
                }

                $ani = ($img->getNumberImages() > 1);
                if ($ani && is_null($destformat)) {
                    $img = $img->coalesceImages();
                    do {
                        $img->setImagePage($s[0], $s[1], 0, 0);
                        $img->cropImage($width, $height, $x, $y);
                        $img->setImagePage($width, $height, 0, 0);
                    } while ($img->nextImage());
                    $img->optimizeImageLayers();
                    $result = $img->writeImages($path, true);
                } else {
                    if ($ani) {
                        $img->setFirstIterator();
                    }
                    $img->setImagePage($s[0], $s[1], 0, 0);
                    $img->cropImage($width, $height, $x, $y);
                    $img->setImagePage($width, $height, 0, 0);
                    $result = $this->imagickImage($img, $path, $destformat, $jpgQuality);
                }

                $img->clear();

                return $result ? $path : false;

                break;

            case 'convert':
                extract($this->imageMagickConvertPrepare($path, $destformat, $jpgQuality, $s));
                /**
                 * @var string $ani
                 * @var string $index
                 * @var string $coalesce
                 * @var string $deconstruct
                 * @var string $jpgQuality
                 * @var string $quotedPath
                 * @var string $quotedDstPath
                 * @var string $interlace
                 */
                $cmd = sprintf('%s %s%s%s%s -crop %dx%d+%d+%d%s %s', ELFINDER_CONVERT_PATH, $quotedPath, $coalesce, $jpgQuality, $interlace, $width, $height, $x, $y, $deconstruct, $quotedDstPath);

                $result = false;
                if ($this->procExec($cmd) === 0) {
                    $result = true;
                }
                return $result ? $path : false;

                break;

            case 'gd':
                elFinder::expandMemoryForGD(array($s, array($width, $height)));
                $img = $this->gdImageCreate($path, $s['mime']);

                if ($img && false != ($tmp = imagecreatetruecolor($width, $height))) {

                    $bgNum = false;
                    if ($s[2] === IMAGETYPE_GIF && (!$destformat || $destformat === 'gif')) {
                        $bgIdx = imagecolortransparent($img);
                        if ($bgIdx !== -1) {
                            $c = imagecolorsforindex($img, $bgIdx);
                            $bgNum = imagecolorallocate($tmp, $c['red'], $c['green'], $c['blue']);
                            imagefill($tmp, 0, 0, $bgNum);
                            imagecolortransparent($tmp, $bgNum);
                        }
                    }
                    if ($bgNum === false) {
                        $this->gdImageBackground($tmp, 'transparent');
                    }

                    $size_w = $width;
                    $size_h = $height;

                    if ($s[0] < $width || $s[1] < $height) {
                        $size_w = $s[0];
                        $size_h = $s[1];
                    }

                    if (!imagecopy($tmp, $img, 0, 0, $x, $y, $size_w, $size_h)) {
                        return false;
                    }

                    $result = $this->gdImage($tmp, $path, $destformat, $s['mime'], $jpgQuality);

                    imagedestroy($img);
                    imagedestroy($tmp);

                    return $result ? $path : false;

                }
                break;
        }

        return false;
    }

    /**
     * Put image to square
     *
     * @param  string    $path       image file
     * @param  int       $width      square width
     * @param  int       $height     square height
     * @param int|string $align      reserved
     * @param int|string $valign     reserved
     * @param  string    $bgcolor    square background color in #rrggbb format
     * @param  string    $destformat image destination format
     * @param  int       $jpgQuality JEPG quality (1-100)
     *
     * @return false|string
     * @throws ImagickException
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     */
    protected function imgSquareFit($path, $width, $height, $align = 'center', $valign = 'middle', $bgcolor = '#0000ff', $destformat = null, $jpgQuality = null)
    {
        if (($s = getimagesize($path)) == false) {
            return false;
        }

        $result = false;

        /* Coordinates for image over square aligning */
        $y = ceil(abs($height - $s[1]) / 2);
        $x = ceil(abs($width - $s[0]) / 2);

        if (!$jpgQuality) {
            $jpgQuality = $this->options['jpgQuality'];
        }

        elFinder::extendTimeLimit(300);
        switch ($this->imgLib) {
            case 'imagick':
                try {
                    $img = new imagick($path);
                } catch (Exception $e) {
                    return false;
                }

                if ($bgcolor === 'transparent') {
                    $bgcolor = 'rgba(255, 255, 255, 0.0)';
                }
                $ani = ($img->getNumberImages() > 1);
                if ($ani && is_null($destformat)) {
                    $img1 = new Imagick();
                    $img1->setFormat('gif');
                    $img = $img->coalesceImages();
                    do {
                        $gif = new Imagick();
                        $gif->newImage($width, $height, new ImagickPixel($bgcolor));
                        $gif->setImageColorspace($img->getImageColorspace());
                        $gif->setImageFormat('gif');
                        $gif->compositeImage($img, imagick::COMPOSITE_OVER, $x, $y);
                        $gif->setImageDelay($img->getImageDelay());
                        $gif->setImageIterations($img->getImageIterations());
                        $img1->addImage($gif);
                        $gif->clear();
                    } while ($img->nextImage());
                    $img1->optimizeImageLayers();
                    $result = $img1->writeImages($path, true);
                } else {
                    if ($ani) {
                        $img->setFirstIterator();
                    }
                    $img1 = new Imagick();
                    $img1->newImage($width, $height, new ImagickPixel($bgcolor));
                    $img1->setImageColorspace($img->getImageColorspace());
                    $img1->compositeImage($img, imagick::COMPOSITE_OVER, $x, $y);
                    $result = $this->imagickImage($img1, $path, $destformat, $jpgQuality);
                }

                $img1->clear();
                $img->clear();
                return $result ? $path : false;

                break;

            case 'convert':
                extract($this->imageMagickConvertPrepare($path, $destformat, $jpgQuality, $s));
                /**
                 * @var string $ani
                 * @var string $index
                 * @var string $coalesce
                 * @var string $deconstruct
                 * @var string $jpgQuality
                 * @var string $quotedPath
                 * @var string $quotedDstPath
                 * @var string $interlace
                 */
                if ($bgcolor === 'transparent') {
                    $bgcolor = 'rgba(255, 255, 255, 0.0)';
                }
                $cmd = sprintf('%s -size %dx%d %s png:- | convert%s%s%s png:-  %s -geometry +%d+%d -compose over -composite%s %s', ELFINDER_CONVERT_PATH, $width, $height, escapeshellarg('xc:' . $bgcolor), $coalesce, $jpgQuality, $interlace, $quotedPath, $x, $y, $deconstruct, $quotedDstPath);

                $result = false;
                if ($this->procExec($cmd) === 0) {
                    $result = true;
                }
                return $result ? $path : false;

                break;

            case 'gd':
                elFinder::expandMemoryForGD(array($s, array($width, $height)));
                $img = $this->gdImageCreate($path, $s['mime']);

                if ($img && false != ($tmp = imagecreatetruecolor($width, $height))) {

                    $this->gdImageBackground($tmp, $bgcolor);
                    if ($bgcolor === 'transparent' && ($destformat === 'png' || $s[2] === IMAGETYPE_PNG)) {
                        $bgNum = imagecolorallocatealpha($tmp, 255, 255, 255, 127);
                        imagefill($tmp, 0, 0, $bgNum);
                    }

                    if (!imagecopy($tmp, $img, $x, $y, 0, 0, $s[0], $s[1])) {
                        return false;
                    }

                    $result = $this->gdImage($tmp, $path, $destformat, $s['mime'], $jpgQuality);

                    imagedestroy($img);
                    imagedestroy($tmp);

                    return $result ? $path : false;
                }
                break;
        }

        return false;
    }

    /**
     * Rotate image
     *
     * @param  string $path       image file
     * @param  int    $degree     rotete degrees
     * @param  string $bgcolor    square background color in #rrggbb format
     * @param  string $destformat image destination format
     * @param  int    $jpgQuality JEPG quality (1-100)
     *
     * @return string|false
     * @throws elFinderAbortException
     * @author nao-pon
     * @author Troex Nevelin
     */
    protected function imgRotate($path, $degree, $bgcolor = '#ffffff', $destformat = null, $jpgQuality = null)
    {
        if (($s = getimagesize($path)) == false || $degree % 360 === 0) {
            return false;
        }

        $result = false;

        // try lossless rotate
        if ($degree % 90 === 0 && in_array($s[2], array(IMAGETYPE_JPEG, IMAGETYPE_JPEG2000))) {
            $count = ($degree / 90) % 4;
            $exiftran = array(
                1 => '-9',
                2 => '-1',
                3 => '-2'
            );
            $jpegtran = array(
                1 => '90',
                2 => '180',
                3 => '270'
            );
            $quotedPath = escapeshellarg($path);
            $cmds = array();
            if ($this->procExec(ELFINDER_EXIFTRAN_PATH . ' -h') === 0) {
                $cmds[] = ELFINDER_EXIFTRAN_PATH . ' -i ' . $exiftran[$count] . ' -- ' . $quotedPath;
            }
            if ($this->procExec(ELFINDER_JPEGTRAN_PATH . ' -version') === 0) {
                $cmds[] = ELFINDER_JPEGTRAN_PATH . ' -rotate ' . $jpegtran[$count] . ' -copy all -outfile ' . $quotedPath . ' -- ' . $quotedPath;
            }
            foreach ($cmds as $cmd) {
                if ($this->procExec($cmd) === 0) {
                    $result = true;
                    break;
                }
            }
            if ($result) {
                return $path;
            }
        }

        if (!$jpgQuality) {
            $jpgQuality = $this->options['jpgQuality'];
        }

        elFinder::extendTimeLimit(300);
        switch ($this->imgLib) {
            case 'imagick':
                try {
                    $img = new imagick($path);
                } catch (Exception $e) {
                    return false;
                }

                if ($s[2] === IMAGETYPE_GIF || $s[2] === IMAGETYPE_PNG) {
                    $bgcolor = 'rgba(255, 255, 255, 0.0)';
                }
                if ($img->getNumberImages() > 1) {
                    $img = $img->coalesceImages();
                    do {
                        $img->rotateImage(new ImagickPixel($bgcolor), $degree);
                    } while ($img->nextImage());
                    $img->optimizeImageLayers();
                    $result = $img->writeImages($path, true);
                } else {
                    $img->rotateImage(new ImagickPixel($bgcolor), $degree);
                    $result = $this->imagickImage($img, $path, $destformat, $jpgQuality);
                }
                $img->clear();
                return $result ? $path : false;

                break;

            case 'convert':
                extract($this->imageMagickConvertPrepare($path, $destformat, $jpgQuality, $s));
                /**
                 * @var string $ani
                 * @var string $index
                 * @var string $coalesce
                 * @var string $deconstruct
                 * @var string $jpgQuality
                 * @var string $quotedPath
                 * @var string $quotedDstPath
                 * @var string $interlace
                 */
                if ($s[2] === IMAGETYPE_GIF || $s[2] === IMAGETYPE_PNG) {
                    $bgcolor = 'rgba(255, 255, 255, 0.0)';
                }
                $cmd = sprintf('%s%s%s%s -background %s -rotate %d%s -- %s %s', ELFINDER_CONVERT_PATH, $coalesce, $jpgQuality, $interlace, escapeshellarg($bgcolor), $degree, $deconstruct, $quotedPath, $quotedDstPath);

                $result = false;
                if ($this->procExec($cmd) === 0) {
                    $result = true;
                }
                return $result ? $path : false;

                break;

            case 'gd':
                elFinder::expandMemoryForGD(array($s, $s));
                $img = $this->gdImageCreate($path, $s['mime']);

                $degree = 360 - $degree;

                $bgNum = -1;
                $bgIdx = false;
                if ($s[2] === IMAGETYPE_GIF) {
                    $bgIdx = imagecolortransparent($img);
                    if ($bgIdx !== -1) {
                        $c = imagecolorsforindex($img, $bgIdx);
                        $w = imagesx($img);
                        $h = imagesy($img);
                        $newImg = imagecreatetruecolor($w, $h);
                        imagepalettecopy($newImg, $img);
                        $bgNum = imagecolorallocate($newImg, $c['red'], $c['green'], $c['blue']);
                        imagefill($newImg, 0, 0, $bgNum);
                        imagecolortransparent($newImg, $bgNum);
                        imagecopy($newImg, $img, 0, 0, 0, 0, $w, $h);
                        imagedestroy($img);
                        $img = $newImg;
                        $newImg = null;
                    }
                } else if ($s[2] === IMAGETYPE_PNG) {
                    $bgNum = imagecolorallocatealpha($img, 255, 255, 255, 127);
                }
                if ($bgNum === -1) {
                    list($r, $g, $b) = sscanf($bgcolor, "#%02x%02x%02x");
                    $bgNum = imagecolorallocate($img, $r, $g, $b);
                }

                $tmp = imageRotate($img, $degree, $bgNum);
                if ($bgIdx !== -1) {
                    imagecolortransparent($tmp, $bgNum);
                }

                $result = $this->gdImage($tmp, $path, $destformat, $s['mime'], $jpgQuality);

                imageDestroy($img);
                imageDestroy($tmp);

                return $result ? $path : false;

                break;
        }

        return false;
    }

    /**
     * Execute shell command
     *
     * @param  string $command      command line
     * @param  string $output       stdout strings
     * @param  int    $return_var   process exit code
     * @param  string $error_output stderr strings
     *
     * @return int exit code
     * @throws elFinderAbortException
     * @author Alexey Sukhotin
     */
    protected function procExec($command, &$output = '', &$return_var = -1, &$error_output = '', $cwd = null)
    {
        return elFinder::procExec($command, $output, $return_var, $error_output);
    }

    /**
     * Remove thumbnail, also remove recursively if stat is directory
     *
     * @param  array $stat file stat
     *
     * @return void
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Naoki Sawada
     * @author Troex Nevelin
     */
    protected function rmTmb($stat)
    {
        if ($this->tmbPathWritable) {
            if ($stat['mime'] === 'directory') {
                foreach ($this->scandirCE($this->decode($stat['hash'])) as $p) {
                    elFinder::extendTimeLimit(30);
                    $name = $this->basenameCE($p);
                    $name != '.' && $name != '..' && $this->rmTmb($this->stat($p));
                }
            } else if (!empty($stat['tmb']) && $stat['tmb'] != "1") {
                $tmb = $this->tmbPath . DIRECTORY_SEPARATOR . rawurldecode($stat['tmb']);
                file_exists($tmb) && unlink($tmb);
                clearstatcache();
            }
        }
    }

    /**
     * Create an gd image according to the specified mime type
     *
     * @param string $path image file
     * @param string $mime
     *
     * @return resource|false GD image resource identifier
     */
    protected function gdImageCreate($path, $mime)
    {
        switch ($mime) {
            case 'image/jpeg':
                return imagecreatefromjpeg($path);

            case 'image/png':
                return imagecreatefrompng($path);

            case 'image/gif':
                return imagecreatefromgif($path);

            case 'image/x-ms-bmp':
                if (!function_exists('imagecreatefrombmp')) {
                    include_once dirname(__FILE__) . '/libs/GdBmp.php';
                }
                return imagecreatefrombmp($path);

            case 'image/xbm':
                return imagecreatefromxbm($path);

            case 'image/xpm':
                return imagecreatefromxpm($path);

            case 'image/webp':
                return imagecreatefromwebp($path);
        }
        return false;
    }

    /**
     * Output gd image to file
     *
     * @param resource $image      gd image resource
     * @param string   $filename   The path to save the file to.
     * @param string   $destformat The Image type to use for $filename
     * @param string   $mime       The original image mime type
     * @param int      $jpgQuality JEPG quality (1-100)
     *
     * @return bool
     */
    protected function gdImage($image, $filename, $destformat, $mime, $jpgQuality = null)
    {

        if (!$jpgQuality) {
            $jpgQuality = $this->options['jpgQuality'];
        }
        if ($destformat) {
            switch ($destformat) {
                case 'jpg':
                    $mime = 'image/jpeg';
                    break;
                case 'gif':
                    $mime = 'image/gif';
                    break;
                case 'png':
                default:
                    $mime = 'image/png';
                    break;
            }
        }
        switch ($mime) {
            case 'image/gif':
                return imagegif($image, $filename);
            case 'image/jpeg':
                if ($this->options['jpgProgressive']) {
                    imageinterlace($image, true);
                }
                return imagejpeg($image, $filename, $jpgQuality);
            case 'image/wbmp':
                return imagewbmp($image, $filename);
            case 'image/png':
            default:
                return imagepng($image, $filename);
        }
    }

    /**
     * Output imagick image to file
     *
     * @param imagick $img        imagick image resource
     * @param string  $filename   The path to save the file to.
     * @param string  $destformat The Image type to use for $filename
     * @param int     $jpgQuality JEPG quality (1-100)
     *
     * @return bool
     */
    protected function imagickImage($img, $filename, $destformat, $jpgQuality = null)
    {

        if (!$jpgQuality) {
            $jpgQuality = $this->options['jpgQuality'];
        }

        try {
            if ($destformat) {
                if ($destformat === 'gif') {
                    $img->setImageFormat('gif');
                } else if ($destformat === 'png') {
                    $img->setImageFormat('png');
                } else if ($destformat === 'jpg') {
                    $img->setImageFormat('jpeg');
                }
            }
            if (strtoupper($img->getImageFormat()) === 'JPEG') {
                $img->setImageCompression(imagick::COMPRESSION_JPEG);
                $img->setImageCompressionQuality($jpgQuality);
                if ($this->options['jpgProgressive']) {
                    $img->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                }
                try {
                    $orientation = $img->getImageOrientation();
                } catch (ImagickException $e) {
                    $orientation = 0;
                }
                $img->stripImage();
                if ($orientation) {
                    $img->setImageOrientation($orientation);
                }
            }
            $result = $img->writeImage($filename);
        } catch (Exception $e) {
            $result = false;
        }

        return $result;
    }

    /**
     * Assign the proper background to a gd image
     *
     * @param resource $image   gd image resource
     * @param string   $bgcolor background color in #rrggbb format
     */
    protected function gdImageBackground($image, $bgcolor)
    {

        if ($bgcolor === 'transparent') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        } else {
            list($r, $g, $b) = sscanf($bgcolor, "#%02x%02x%02x");
            $bgcolor1 = imagecolorallocate($image, $r, $g, $b);
            imagefill($image, 0, 0, $bgcolor1);
        }
    }

    /**
     * Prepare variables for exec convert of ImageMagick
     *
     * @param  string $path
     * @param  string $destformat
     * @param  int    $jpgQuality
     * @param  array  $imageSize
     * @param null    $mime
     *
     * @return array
     * @throws elFinderAbortException
     */
    protected function imageMagickConvertPrepare($path, $destformat, $jpgQuality, $imageSize = null, $mime = null)
    {
        if (is_null($imageSize)) {
            $imageSize = getimagesize($path);
        }
        if (is_null($mime)) {
            $mime = $this->mimetype($path);
        }
        $srcType = $this->getExtentionByMime($mime, ':');
        $ani = false;
        if (preg_match('/^(?:gif|png|ico)/', $srcType)) {
            $cmd = ELFINDER_IDENTIFY_PATH . ' -- ' . escapeshellarg($srcType . $path);
            if ($this->procExec($cmd, $o) === 0) {
                $ani = preg_split('/(?:\r\n|\n|\r)/', trim($o));
                if (count($ani) < 2) {
                    $ani = false;
                }
            }
        }
        $coalesce = $index = $interlace = '';
        $deconstruct = ' +repage';
        if ($ani && $destformat !== 'png'/* not createTmb */) {
            if (is_null($destformat)) {
                $coalesce = ' -coalesce -repage 0x0';
                $deconstruct = ' +repage -deconstruct -layers optimize';
            } else if ($imageSize) {
                if ($srcType === 'ico:') {
                    $index = '[0]';
                    foreach ($ani as $_i => $_info) {
                        if (preg_match('/ (\d+)x(\d+) /', $_info, $m)) {
                            if ($m[1] == $imageSize[0] && $m[2] == $imageSize[1]) {
                                $index = '[' . $_i . ']';
                                break;
                            }
                        }
                    }
                }
            }
        } else {
            $index = '[0]';
        }
        if ($imageSize && ($imageSize[2] === IMAGETYPE_JPEG || $imageSize[2] === IMAGETYPE_JPEG2000)) {
            $jpgQuality = ' -quality ' . $jpgQuality;
            if ($this->options['jpgProgressive']) {
                $interlace = ' -interlace Plane';
            }
        } else {
            $jpgQuality = '';
        }
        $quotedPath = escapeshellarg($srcType . $path . $index);
        $quotedDstPath = escapeshellarg(($destformat ? ($destformat . ':') : $srcType) . $path);
        return compact('ani', 'index', 'coalesce', 'deconstruct', 'jpgQuality', 'quotedPath', 'quotedDstPath', 'interlace');
    }

    /*********************** misc *************************/

    /**
     * Find position of first occurrence of string in a string with multibyte support
     *
     * @param  string $haystack The string being checked.
     * @param  string $needle   The string to find in haystack.
     * @param  int    $offset   The search offset. If it is not specified, 0 is used.
     *
     * @return int|bool
     * @author Alexey Sukhotin
     **/
    protected function stripos($haystack, $needle, $offset = 0)
    {
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, $offset, 'UTF-8');
        } else if (function_exists('mb_strtolower') && function_exists('mb_strpos')) {
            return mb_strpos(mb_strtolower($haystack, 'UTF-8'), mb_strtolower($needle, 'UTF-8'), $offset);
        }
        return stripos($haystack, $needle, $offset);
    }

    /**
     * Default serach match method (name match)
     *
     * @param  String $name  Item name
     * @param  String $query Query word
     * @param  String $path  Item path
     *
     * @return bool @return bool
     */
    protected function searchMatchName($name, $query, $path)
    {
        return $this->stripos($name, $query) !== false;
    }

    /**
     * Get server side available archivers
     *
     * @param bool $use_cache
     *
     * @return array
     * @throws elFinderAbortException
     */
    protected function getArchivers($use_cache = true)
    {
        $sessionKey = 'archivers';
        if ($use_cache) {
            if (isset($this->options['archivers']) && is_array($this->options['archivers']) && $this->options['archivers']) {
                $cache = $this->options['archivers'];
            } else {
                $cache = elFinder::$archivers;
            }
            if ($cache) {
                return $cache;
            } else {
                if ($cache = $this->session->get($sessionKey, array())) {
                    return elFinder::$archivers = $cache;
                }
            }
        }

        $arcs = array(
            'create' => array(),
            'extract' => array()
        );

        if ($this->procExec('') === 0) {

            $this->procExec(ELFINDER_TAR_PATH . ' --version', $o, $ctar);

            if ($ctar == 0) {
                $arcs['create']['application/x-tar'] = array('cmd' => ELFINDER_TAR_PATH, 'argc' => '-chf', 'ext' => 'tar');
                $arcs['extract']['application/x-tar'] = array('cmd' => ELFINDER_TAR_PATH, 'argc' => '-xf', 'ext' => 'tar', 'toSpec' => '-C ', 'getsize' => array('argc' => '-xvf', 'toSpec' => '--to-stdout|wc -c', 'regex' => '/^.+(?:\r\n|\n|\r)[^\r\n0-9]*([0-9]+)[^\r\n]*$/s', 'replace' => '$1'));
                unset($o);
                $this->procExec(ELFINDER_GZIP_PATH . ' --version', $o, $c);
                if ($c == 0) {
                    $arcs['create']['application/x-gzip'] = array('cmd' => ELFINDER_TAR_PATH, 'argc' => '-czhf', 'ext' => 'tgz');
                    $arcs['extract']['application/x-gzip'] = array('cmd' => ELFINDER_TAR_PATH, 'argc' => '-xzf', 'ext' => 'tgz', 'toSpec' => '-C ', 'getsize' => array('argc' => '-xvf', 'toSpec' => '--to-stdout|wc -c', 'regex' => '/^.+(?:\r\n|\n|\r)[^\r\n0-9]*([0-9]+)[^\r\n]*$/s', 'replace' => '$1'));
                }
                unset($o);
                $this->procExec(ELFINDER_BZIP2_PATH . ' --version', $o, $c);
                if ($c == 0) {
                    $arcs['create']['application/x-bzip2'] = array('cmd' => ELFINDER_TAR_PATH, 'argc' => '-cjhf', 'ext' => 'tbz');
                    $arcs['extract']['application/x-bzip2'] = array('cmd' => ELFINDER_TAR_PATH, 'argc' => '-xjf', 'ext' => 'tbz', 'toSpec' => '-C ', 'getsize' => array('argc' => '-xvf', 'toSpec' => '--to-stdout|wc -c', 'regex' => '/^.+(?:\r\n|\n|\r)[^\r\n0-9]*([0-9]+)[^\r\n]*$/s', 'replace' => '$1'));
                }
                unset($o);
                $this->procExec(ELFINDER_XZ_PATH . ' --version', $o, $c);
                if ($c == 0) {
                    $arcs['create']['application/x-xz'] = array('cmd' => ELFINDER_TAR_PATH, 'argc' => '-cJhf', 'ext' => 'xz');
                    $arcs['extract']['application/x-xz'] = array('cmd' => ELFINDER_TAR_PATH, 'argc' => '-xJf', 'ext' => 'xz', 'toSpec' => '-C ', 'getsize' => array('argc' => '-xvf', 'toSpec' => '--to-stdout|wc -c', 'regex' => '/^.+(?:\r\n|\n|\r)[^\r\n0-9]*([0-9]+)[^\r\n]*$/s', 'replace' => '$1'));
                }
            }
            unset($o);
            $this->procExec(ELFINDER_ZIP_PATH . ' -h', $o, $c);
            if ($c == 0) {
                $arcs['create']['application/zip'] = array('cmd' => ELFINDER_ZIP_PATH, 'argc' => '-r9 -q', 'ext' => 'zip');
            }
            unset($o);
            $this->procExec(ELFINDER_UNZIP_PATH . ' --help', $o, $c);
            if ($c == 0) {
                $arcs['extract']['application/zip'] = array('cmd' => ELFINDER_UNZIP_PATH, 'argc' => '-q', 'ext' => 'zip', 'toSpec' => '-d ', 'getsize' => array('argc' => '-Z -t', 'regex' => '/^.+?,\s?([0-9]+).+$/', 'replace' => '$1'));
            }
            unset($o);
            $this->procExec(ELFINDER_RAR_PATH, $o, $c);
            if ($c == 0 || $c == 7) {
                $arcs['create']['application/x-rar'] = array('cmd' => ELFINDER_RAR_PATH, 'argc' => 'a -inul' . (defined('ELFINDER_RAR_MA4') && ELFINDER_RAR_MA4? ' -ma4' : '') . ' --', 'ext' => 'rar');
            }
            unset($o);
            $this->procExec(ELFINDER_UNRAR_PATH, $o, $c);
            if ($c == 0 || $c == 7) {
                $arcs['extract']['application/x-rar'] = array('cmd' => ELFINDER_UNRAR_PATH, 'argc' => 'x -y', 'ext' => 'rar', 'toSpec' => '', 'getsize' => array('argc' => 'l', 'regex' => '/^.+(?:\r\n|\n|\r)(?:(?:[^\r\n0-9]+[0-9]+[^\r\n0-9]+([0-9]+)[^\r\n]+)|(?:[^\r\n0-9]+([0-9]+)[^\r\n0-9]+[0-9]+[^\r\n]*))$/s', 'replace' => '$1'));
            }
            unset($o);
            $this->procExec(ELFINDER_7Z_PATH, $o, $c);
            if ($c == 0) {
                $arcs['create']['application/x-7z-compressed'] = array('cmd' => ELFINDER_7Z_PATH, 'argc' => 'a --', 'ext' => '7z');
                $arcs['extract']['application/x-7z-compressed'] = array('cmd' => ELFINDER_7Z_PATH, 'argc' => 'x -y', 'ext' => '7z', 'toSpec' => '-o', 'getsize' => array('argc' => 'l', 'regex' => '/^.+(?:\r\n|\n|\r)[^\r\n0-9]+([0-9]+)[^\r\n]+$/s', 'replace' => '$1'));

                if (empty($arcs['create']['application/zip'])) {
                    $arcs['create']['application/zip'] = array('cmd' => ELFINDER_7Z_PATH, 'argc' => 'a -tzip --', 'ext' => 'zip');
                }
                if (empty($arcs['extract']['application/zip'])) {
                    $arcs['extract']['application/zip'] = array('cmd' => ELFINDER_7Z_PATH, 'argc' => 'x -tzip -y', 'ext' => 'zip', 'toSpec' => '-o', 'getsize' => array('argc' => 'l', 'regex' => '/^.+(?:\r\n|\n|\r)[^\r\n0-9]+([0-9]+)[^\r\n]+$/s', 'replace' => '$1'));
                }
                if (empty($arcs['create']['application/x-tar'])) {
                    $arcs['create']['application/x-tar'] = array('cmd' => ELFINDER_7Z_PATH, 'argc' => 'a -ttar --', 'ext' => 'tar');
                }
                if (empty($arcs['extract']['application/x-tar'])) {
                    $arcs['extract']['application/x-tar'] = array('cmd' => ELFINDER_7Z_PATH, 'argc' => 'x -ttar -y', 'ext' => 'tar', 'toSpec' => '-o', 'getsize' => array('argc' => 'l', 'regex' => '/^.+(?:\r\n|\n|\r)[^\r\n0-9]+([0-9]+)[^\r\n]+$/s', 'replace' => '$1'));
                }
                if (substr(PHP_OS, 0, 3) === 'WIN' && empty($arcs['extract']['application/x-rar'])) {
                    $arcs['extract']['application/x-rar'] = array('cmd' => ELFINDER_7Z_PATH, 'argc' => 'x -trar -y', 'ext' => 'rar', 'toSpec' => '-o', 'getsize' => array('argc' => 'l', 'regex' => '/^.+(?:\r\n|\n|\r)[^\r\n0-9]+([0-9]+)[^\r\n]+$/s', 'replace' => '$1'));
                }
            }

        }

        // Use PHP ZipArchive Class
        if (class_exists('ZipArchive', false)) {
            if (empty($arcs['create']['application/zip'])) {
                $arcs['create']['application/zip'] = array('cmd' => 'phpfunction', 'argc' => array('self', 'zipArchiveZip'), 'ext' => 'zip');
            }
            if (empty($arcs['extract']['application/zip'])) {
                $arcs['extract']['application/zip'] = array('cmd' => 'phpfunction', 'argc' => array('self', 'zipArchiveUnzip'), 'ext' => 'zip');
            }
        }

        $this->session->set($sessionKey, $arcs);
        return elFinder::$archivers = $arcs;
    }

    /**
     * Resolve relative / (Unix-like)absolute path
     *
     * @param string $path target path
     * @param string $base base path
     *
     * @return string
     */
    protected function getFullPath($path, $base)
    {
        $separator = $this->separator;
        $systemroot = $this->systemRoot;
        $base = (string)$base;

        if ($base[0] === $separator && substr($base, 0, strlen($systemroot)) !== $systemroot) {
            $base = $systemroot . substr($base, 1);
        }
        if ($base !== $systemroot) {
            $base = rtrim($base, $separator);
        }

        $sepquoted = preg_quote($separator, '#');

          // normalize `//` to `/`
        $path = preg_replace('#' . $sepquoted . '+#', $separator, $path); // '#/+#'

        // remove `./`
        $path = preg_replace('#(?<=^|' . $sepquoted . ')\.' . $sepquoted . '#', '', $path); // '#(?<=^|/)\./#'

        // 'Here'
        if ($path === '') return $base;

        // join $base to $path if $path start `../`

        if (substr($path, 0, 3) === '..' . $separator) {
            $path = $base . $separator . $path;
        }
        // normalize `/../`
        $normreg = '#(' . $sepquoted . ')[^' . $sepquoted . ']+' . $sepquoted . '\.\.' . $sepquoted . '#'; // '#(/)[^\/]+/\.\./#'
        while (preg_match($normreg, $path)) {
            $path = preg_replace($normreg, '$1', $path, 1);
        }
        if ($path !== $systemroot) {
            $path = rtrim($path, $separator);
        }

        // discard the surplus `../`
        $path = str_replace('..' . $separator, '', $path);

        // Absolute path
        if ($path[0] === $separator || strpos($path, $systemroot) === 0) {
            return $path;
        }

        $preg_separator = '#' . $sepquoted . '#';

        // Relative path from 'Here'
        if (substr($path, 0, 2) === '.' . $separator || $path[0] !== '.') {
            $arrn = preg_split($preg_separator, $path, -1, PREG_SPLIT_NO_EMPTY);
            if ($arrn[0] !== '.') {
                array_unshift($arrn, '.');
            }
            $arrn[0] = rtrim($base, $separator);
            return join($separator, $arrn);
        }

        return $path;
    }

    /**
     * Remove directory recursive on local file system
     *
     * @param string $dir Target dirctory path
     *
     * @return boolean
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    public function rmdirRecursive($dir)
    {
        return self::localRmdirRecursive($dir);
    }

    /**
     * Create archive and return its path
     *
     * @param  string $dir   target dir
     * @param  array  $files files names list
     * @param  string $name  archive name
     * @param  array  $arc   archiver options
     *
     * @return string|bool
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     * @author Naoki Sawada
     */
    protected function makeArchive($dir, $files, $name, $arc)
    {
        if ($arc['cmd'] === 'phpfunction') {
            if (is_callable($arc['argc'])) {
                call_user_func_array($arc['argc'], array($dir, $files, $name));
            }
        } else {
            $cwd = getcwd();
            if (chdir($dir)) {
                foreach ($files as $i => $file) {
                    $files[$i] = '.' . DIRECTORY_SEPARATOR . basename($file);
                }
                $files = array_map('escapeshellarg', $files);
                $prefix = $switch = '';
                // The zip command accepts the "-" at the beginning of the file name as a command switch,
                // and can't use '--' before archive name, so add "./" to name for security reasons.
                if ($arc['ext'] === 'zip' && strpos($arc['argc'], '-tzip') === false) {
                    $prefix = './';
                    $switch = '-- ';
                }
                $cmd = $arc['cmd'] . ' ' . $arc['argc'] . ' ' . $prefix . escapeshellarg($name) . ' ' . $switch . implode(' ', $files);
                $err_out = '';
                $this->procExec($cmd, $o, $c, $err_out, $dir);
                chdir($cwd);
            } else {
                return false;
            }
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        return file_exists($path) ? $path : false;
    }

    /**
     * Unpack archive
     *
     * @param  string      $path archive path
     * @param  array       $arc  archiver command and arguments (same as in $this->archivers)
     * @param  bool|string $mode bool: remove archive ( unlink($path) ) | string: extract to directory
     *
     * @return void
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     * @author Naoki Sawada
     */
    protected function unpackArchive($path, $arc, $mode = true)
    {
        if (is_string($mode)) {
            $dir = $mode;
            $chdir = null;
            $remove = false;
        } else {
            $dir = dirname($path);
            $chdir = $dir;
            $remove = $mode;
        }
        $dir = realpath($dir);
        $path = realpath($path);
        if ($arc['cmd'] === 'phpfunction') {
            if (is_callable($arc['argc'])) {
                call_user_func_array($arc['argc'], array($path, $dir));
            }
        } else {
            $cwd = getcwd();
            if (!$chdir || chdir($dir)) {
                if (!empty($arc['getsize'])) {
                    // Check total file size after extraction
                    $getsize = $arc['getsize'];
                    if (is_array($getsize) && !empty($getsize['regex']) && !empty($getsize['replace'])) {
                        $cmd = $arc['cmd'] . ' ' . $getsize['argc'] . ' ' . escapeshellarg($path) . (!empty($getsize['toSpec'])? (' ' . $getsize['toSpec']): '');
                        $this->procExec($cmd, $o, $c);
                        if ($o) {
                            $size = preg_replace($getsize['regex'], $getsize['replace'], trim($o));
                            $comp = function_exists('bccomp')? 'bccomp' : 'strnatcmp';
                            if (!empty($this->options['maxArcFilesSize'])) {
                                if ($comp($size, (string)$this->options['maxArcFilesSize']) > 0) {
                                    throw new Exception(elFinder::ERROR_ARC_MAXSIZE);
                                }
                            }
                        }
                        unset($o, $c);
                    }
                }
                if ($chdir) {
                    $cmd = $arc['cmd'] . ' ' . $arc['argc'] . ' ' . escapeshellarg(basename($path));
                } else {
                    $cmd = $arc['cmd'] . ' ' . $arc['argc'] . ' ' . escapeshellarg($path) . ' ' . $arc['toSpec'] . escapeshellarg($dir);
                }
                $this->procExec($cmd, $o, $c);
                $chdir && chdir($cwd);
            }
        }
        $remove && unlink($path);
    }

    /**
     * Check and filter the extracted items
     *
     * @param  string $path   target local path
     * @param  array  $checks types to check default: ['symlink', 'name', 'writable', 'mime']
     *
     * @return array  ['symlinks' => [], 'names' => [], 'writables' => [], 'mimes' => [], 'rmNames' => [], 'totalSize' => 0]
     * @throws elFinderAbortException
     * @throws Exception
     * @author Naoki Sawada
     */
    protected function checkExtractItems($path, $checks = null)
    {
        if (is_null($checks) || !is_array($checks)) {
            $checks = array('symlink', 'name', 'writable', 'mime');
        }
        $chkSymlink = in_array('symlink', $checks);
        $chkName = in_array('name', $checks);
        $chkWritable = in_array('writable', $checks);
        $chkMime = in_array('mime', $checks);

        $res = array(
            'symlinks' => array(),
            'names' => array(),
            'writables' => array(),
            'mimes' => array(),
            'rmNames' => array(),
            'totalSize' => 0
        );

        if (is_dir($path)) {
            $files = self::localScandir($path);
        } else {
            $files = array(basename($path));
            $path = dirname($path);
        }

        foreach ($files as $name) {
            $p = $path . DIRECTORY_SEPARATOR . $name;
            $utf8Name = elFinder::$instance->utf8Encode($name);
            if ($name !== $utf8Name) {
                $fsSame = false;
                if ($this->encoding) {
                    // test as fs encoding
                    $_utf8 = @iconv($this->encoding, 'utf-8//IGNORE', $name);
                    if (@iconv('utf-8', $this->encoding.'//IGNORE', $_utf8) === $name) {
                        $fsSame = true;
                        $utf8Name = $_utf8;
                    } else {
                        $_name = $this->convEncIn($utf8Name, true);
                    }
                } else {
                    $_name = $utf8Name;
                }
                if (!$fsSame && rename($p, $path . DIRECTORY_SEPARATOR . $_name)) {
                    $name = $_name;
                    $p = $path . DIRECTORY_SEPARATOR . $name;
                }
            }
            if (!is_readable($p)) {
                // Perhaps a symbolic link to open_basedir restricted location
                self::localRmdirRecursive($p);
                $res['symlinks'][] = $p;
                $res['rmNames'][] = $utf8Name;
                continue;
            }
            if ($chkSymlink && is_link($p)) {
                self::localRmdirRecursive($p);
                $res['symlinks'][] = $p;
                $res['rmNames'][] = $utf8Name;
                continue;
            }
            $isDir = is_dir($p);
            if ($chkName && !$this->nameAccepted($name, $isDir)) {
                self::localRmdirRecursive($p);
                $res['names'][] = $p;
                $res['rmNames'][] = $utf8Name;
                continue;
            }
            if ($chkWritable && !$this->attr($p, 'write', null, $isDir)) {
                self::localRmdirRecursive($p);
                $res['writables'][] = $p;
                $res['rmNames'][] = $utf8Name;
                continue;
            }
            if ($isDir) {
                $cRes = $this->checkExtractItems($p, $checks);
                foreach ($cRes as $k => $v) {
                    if (is_array($v)) {
                        $res[$k] = array_merge($res[$k], $cRes[$k]);
                    } else {
                        $res[$k] += $cRes[$k];
                    }
                }
            } else {
                if ($chkMime && ($mimeByName = elFinderVolumeDriver::mimetypeInternalDetect($name)) && !$this->allowPutMime($mimeByName)) {
                    self::localRmdirRecursive($p);
                    $res['mimes'][] = $p;
                    $res['rmNames'][] = $utf8Name;
                    continue;
                }
                $res['totalSize'] += (int)sprintf('%u', filesize($p));
            }
        }
        $res['rmNames'] = array_unique($res['rmNames']);

        return $res;
    }

    /**
     * Return files of target directory that is dotfiles excludes.
     *
     * @param  string $dir target directory path
     *
     * @return array
     * @throws Exception
     * @author Naoki Sawada
     */
    protected static function localScandir($dir)
    {
        // PHP function scandir() is not work well in specific environment. I dont know why.
        // ref. https://github.com/Studio-42/elFinder/issues/1248
        $files = array();
        if ($dh = opendir($dir)) {
            while (false !== ($file = readdir($dh))) {
                if ($file !== '.' && $file !== '..') {
                    $files[] = $file;
                }
            }
            closedir($dh);
        } else {
            throw new Exception('Can not open local directory.');
        }
        return $files;
    }

    /**
     * Remove directory recursive on local file system
     *
     * @param string $dir Target dirctory path
     *
     * @return boolean
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    protected static function localRmdirRecursive($dir)
    {
        // try system command
        if (is_callable('exec')) {
            $o = '';
            $r = 1;
            if (substr(PHP_OS, 0, 3) === 'WIN') {
                if (!is_link($dir) && is_dir($dir)) {
                    exec('rd /S /Q ' . escapeshellarg($dir), $o, $r);
                } else {
                    exec('del /F /Q ' . escapeshellarg($dir), $o, $r);
                }
            } else {
                exec('rm -rf ' . escapeshellarg($dir), $o, $r);
            }
            if ($r === 0) {
                return true;
            }
        }
        if (!is_link($dir) && is_dir($dir)) {
            chmod($dir, 0777);
            if ($handle = opendir($dir)) {
                while (false !== ($file = readdir($handle))) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    elFinder::extendTimeLimit(30);
                    $path = $dir . DIRECTORY_SEPARATOR . $file;
                    if (!is_link($dir) && is_dir($path)) {
                        self::localRmdirRecursive($path);
                    } else {
                        chmod($path, 0666);
                        unlink($path);
                    }
                }
                closedir($handle);
            }
            return rmdir($dir);
        } else {
            chmod($dir, 0666);
            return unlink($dir);
        }
    }

    /**
     * Move item recursive on local file system
     *
     * @param string $src
     * @param string $target
     * @param bool   $overWrite
     * @param bool   $copyJoin
     *
     * @return boolean
     * @throws elFinderAbortException
     * @throws Exception
     * @author Naoki Sawada
     */
    protected static function localMoveRecursive($src, $target, $overWrite = true, $copyJoin = true)
    {
        $res = false;
        if (!file_exists($target)) {
            return rename($src, $target);
        }
        if (!$copyJoin || !is_dir($target)) {
            if ($overWrite) {
                if (is_dir($target)) {
                    $del = self::localRmdirRecursive($target);
                } else {
                    $del = unlink($target);
                }
                if ($del) {
                    return rename($src, $target);
                }
            }
        } else {
            foreach (self::localScandir($src) as $item) {
                $res |= self::localMoveRecursive($src . DIRECTORY_SEPARATOR . $item, $target . DIRECTORY_SEPARATOR . $item, $overWrite, $copyJoin);
            }
        }
        return (bool)$res;
    }

    /**
     * Create Zip archive using PHP class ZipArchive
     *
     * @param  string        $dir     target dir
     * @param  array         $files   files names list
     * @param  string|object $zipPath Zip archive name
     *
     * @return bool
     * @author Naoki Sawada
     */
    protected static function zipArchiveZip($dir, $files, $zipPath)
    {
        try {
            if ($start = is_string($zipPath)) {
                $zip = new ZipArchive();
                if ($zip->open($dir . DIRECTORY_SEPARATOR . $zipPath, ZipArchive::CREATE) !== true) {
                    $zip = false;
                }
            } else {
                $zip = $zipPath;
            }
            if ($zip) {
                foreach ($files as $file) {
                    $path = $dir . DIRECTORY_SEPARATOR . $file;
                    if (is_dir($path)) {
                        $zip->addEmptyDir($file);
                        $_files = array();
                        if ($handle = opendir($path)) {
                            while (false !== ($entry = readdir($handle))) {
                                if ($entry !== "." && $entry !== "..") {
                                    $_files[] = $file . DIRECTORY_SEPARATOR . $entry;
                                }
                            }
                            closedir($handle);
                        }
                        if ($_files) {
                            self::zipArchiveZip($dir, $_files, $zip);
                        }
                    } else {
                        $zip->addFile($path, $file);
                    }
                }
                $start && $zip->close();
            }
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Unpack Zip archive using PHP class ZipArchive
     *
     * @param  string $zipPath Zip archive name
     * @param  string $toDir   Extract to path
     *
     * @return bool
     * @author Naoki Sawada
     */
    protected static function zipArchiveUnzip($zipPath, $toDir)
    {
        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                // Check total file size after extraction
                $num = $zip->numFiles;
                $size = 0;
                $maxSize = empty(self::$maxArcFilesSize)? '' : (string)self::$maxArcFilesSize;
                $comp = function_exists('bccomp')? 'bccomp' : 'strnatcmp';
                for ($i = 0; $i < $num; $i++) {
                    $stat = $zip->statIndex($i);
                    $size += $stat['size'];
                    if (strpos((string)$size, 'E') !== false) {
                        // Cannot handle values exceeding PHP_INT_MAX
                        throw new Exception(elFinder::ERROR_ARC_MAXSIZE);
                    }
                    if (!$maxSize) {
                        if ($comp($size, $maxSize) > 0) {
                            throw new Exception(elFinder::ERROR_ARC_MAXSIZE);
                        }
                    }
                }
                // do extract
                $zip->extractTo($toDir);
                $zip->close();
            }
        } catch (Exception $e) {
            throw $e;
        }
        return true;
    }

    /**
     * Recursive symlinks search
     *
     * @param  string $path file/dir path
     *
     * @return bool
     * @throws Exception
     * @author Dmitry (dio) Levashov
     */
    protected static function localFindSymlinks($path)
    {
        if (is_link($path)) {
            return true;
        }

        if (is_dir($path)) {
            foreach (self::localScandir($path) as $name) {
                $p = $path . DIRECTORY_SEPARATOR . $name;
                if (is_link($p)) {
                    return true;
                }
                if (is_dir($p) && self::localFindSymlinks($p)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**==================================* abstract methods *====================================**/

    /*********************** paths/urls *************************/

    /**
     * Return parent directory path
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _dirname($path);

    /**
     * Return file name
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _basename($path);

    /**
     * Join dir name and file name and return full path.
     * Some drivers (db) use int as path - so we give to concat path to driver itself
     *
     * @param  string $dir  dir path
     * @param  string $name file name
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _joinPath($dir, $name);

    /**
     * Return normalized path
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _normpath($path);

    /**
     * Return file path related to root dir
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _relpath($path);

    /**
     * Convert path related to root dir into real path
     *
     * @param  string $path rel file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _abspath($path);

    /**
     * Return fake path started from root dir.
     * Required to show path on client side.
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _path($path);

    /**
     * Return true if $path is children of $parent
     *
     * @param  string $path   path to check
     * @param  string $parent parent path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _inpath($path, $parent);

    /**
     * Return stat for given path.
     * Stat contains following fields:
     * - (int)    size    file size in b. required
     * - (int)    ts      file modification time in unix time. required
     * - (string) mime    mimetype. required for folders, others - optionally
     * - (bool)   read    read permissions. required
     * - (bool)   write   write permissions. required
     * - (bool)   locked  is object locked. optionally
     * - (bool)   hidden  is object hidden. optionally
     * - (string) alias   for symlinks - link target path relative to root path. optionally
     * - (string) target  for symlinks - link target path. optionally
     * If file does not exists - returns empty array or false.
     *
     * @param  string $path file path
     *
     * @return array|false
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _stat($path);


    /***************** file stat ********************/


    /**
     * Return true if path is dir and has at least one childs directory
     *
     * @param  string $path dir path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _subdirs($path);

    /**
     * Return object width and height
     * Ususaly used for images, but can be realize for video etc...
     *
     * @param  string $path file path
     * @param  string $mime file mime type
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _dimensions($path, $mime);

    /******************** file/dir content *********************/

    /**
     * Return files list in directory
     *
     * @param  string $path dir path
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _scandir($path);

    /**
     * Open file and return file pointer
     *
     * @param  string $path file path
     * @param  string $mode open mode
     *
     * @return resource|false
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _fopen($path, $mode = "rb");

    /**
     * Close opened file
     *
     * @param  resource $fp   file pointer
     * @param  string   $path file path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _fclose($fp, $path = '');

    /********************  file/dir manipulations *************************/

    /**
     * Create dir and return created dir path or false on failed
     *
     * @param  string $path parent dir path
     * @param string  $name new directory name
     *
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _mkdir($path, $name);

    /**
     * Create file and return it's path or false on failed
     *
     * @param  string $path parent dir path
     * @param string  $name new file name
     *
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _mkfile($path, $name);

    /**
     * Create symlink
     *
     * @param  string $source    file to link to
     * @param  string $targetDir folder to create link in
     * @param  string $name      symlink name
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _symlink($source, $targetDir, $name);

    /**
     * Copy file into another file (only inside one volume)
     *
     * @param  string $source source file path
     * @param         $targetDir
     * @param  string $name   file name
     *
     * @return bool|string
     * @internal param string $target target dir path
     * @author   Dmitry (dio) Levashov
     */
    abstract protected function _copy($source, $targetDir, $name);

    /**
     * Move file into another parent dir.
     * Return new file path or false.
     *
     * @param  string $source source file path
     * @param         $targetDir
     * @param  string $name   file name
     *
     * @return bool|string
     * @internal param string $target target dir path
     * @author   Dmitry (dio) Levashov
     */
    abstract protected function _move($source, $targetDir, $name);

    /**
     * Remove file
     *
     * @param  string $path file path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _unlink($path);

    /**
     * Remove dir
     *
     * @param  string $path dir path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _rmdir($path);

    /**
     * Create new file and write into it from file pointer.
     * Return new file path or false on error.
     *
     * @param  resource $fp   file pointer
     * @param  string   $dir  target dir path
     * @param  string   $name file name
     * @param  array    $stat file stat (required by some virtual fs)
     *
     * @return bool|string
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _save($fp, $dir, $name, $stat);

    /**
     * Get file contents
     *
     * @param  string $path file path
     *
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _getContents($path);

    /**
     * Write a string to a file
     *
     * @param  string $path    file path
     * @param  string $content new file content
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    abstract protected function _filePutContents($path, $content);

    /**
     * Extract files from archive
     *
     * @param  string $path file path
     * @param  array  $arc  archiver options
     *
     * @return bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    abstract protected function _extract($path, $arc);

    /**
     * Create archive and return its path
     *
     * @param  string $dir   target dir
     * @param  array  $files files names list
     * @param  string $name  archive name
     * @param  array  $arc   archiver options
     *
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    abstract protected function _archive($dir, $files, $name, $arc);

    /**
     * Detect available archivers
     *
     * @return void
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    abstract protected function _checkArchivers();

    /**
     * Change file mode (chmod)
     *
     * @param  string $path file path
     * @param  string $mode octal string such as '0755'
     *
     * @return bool
     * @author David Bartle,
     **/
    abstract protected function _chmod($path, $mode);


} // END class

}

/* ===================== [embedded] elFinderVolumeLocalFileSystem ===================== */
if (!class_exists('elFinderVolumeLocalFileSystem', false)) {
// Implement similar functionality in PHP 5.2 or 5.3
// http://php.net/manual/class.recursivecallbackfilteriterator.php#110974
if (!class_exists('RecursiveCallbackFilterIterator', false)) {
    class RecursiveCallbackFilterIterator extends RecursiveFilterIterator
    {
        private $callback;

        public function __construct(RecursiveIterator $iterator, $callback)
        {
            $this->callback = $callback;
            parent::__construct($iterator);
        }

        public function accept()
        {
            return call_user_func($this->callback, parent::current(), parent::key(), parent::getInnerIterator());
        }

        public function getChildren()
        {
            return new self($this->getInnerIterator()->getChildren(), $this->callback);
        }
    }
}

/**
 * elFinder driver for local filesystem.
 *
 * @author Dmitry (dio) Levashov
 * @author Troex Nevelin
 **/
class elFinderVolumeLocalFileSystem extends elFinderVolumeDriver
{

    /**
     * Driver id
     * Must be started from letter and contains [a-z0-9]
     * Used as part of volume id
     *
     * @var string
     **/
    protected $driverId = 'l';

    /**
     * Required to count total archive files size
     *
     * @var int
     **/
    protected $archiveSize = 0;

    /**
     * Is checking stat owner
     *
     * @var        boolean
     */
    protected $statOwner = false;

    /**
     * Path to quarantine directory
     *
     * @var string
     */
    private $quarantine;

    /**
     * Constructor
     * Extend options with required fields
     *
     * @author Dmitry (dio) Levashov
     */
    public function __construct()
    {
        $this->options['alias'] = '';              // alias to replace root dir name
        $this->options['dirMode'] = 0755;            // new dirs mode
        $this->options['fileMode'] = 0644;            // new files mode
        $this->options['rootCssClass'] = 'elfinder-navbar-root-local';
        $this->options['followSymLinks'] = true;
        $this->options['detectDirIcon'] = '';         // file name that is detected as a folder icon e.g. '.diricon.png'
        $this->options['keepTimestamp'] = array('copy', 'move'); // keep timestamp at inner filesystem allowed 'copy', 'move' and 'upload'
        $this->options['substituteImg'] = true;       // support substitute image with dim command
        $this->options['statCorrector'] = null;       // callable to correct stat data `function(&$stat, $path, $statOwner, $volumeDriveInstance){}`
        if (DIRECTORY_SEPARATOR === '/') {
            // Linux
            $this->options['acceptedName'] = '/^[^\.\/\x00][^\/\x00]*$/';
        } else {
            // Windows
            $this->options['acceptedName'] = '/^[^\.\/\x00\\\:*?"<>|][^\/\x00\\\:*?"<>|]*$/';
        }
    }

    /*********************************************************************/
    /*                        INIT AND CONFIGURE                         */
    /*********************************************************************/

    /**
     * Prepare driver before mount volume.
     * Return true if volume is ready.
     *
     * @return bool
     **/
    protected function init()
    {
        // Normalize directory separator for windows
        if (DIRECTORY_SEPARATOR !== '/') {
            foreach (array('path', 'tmbPath', 'tmpPath', 'quarantine') as $key) {
                if (!empty($this->options[$key])) {
                    $this->options[$key] = str_replace('/', DIRECTORY_SEPARATOR, $this->options[$key]);
                }
            }
            // PHP >= 7.1 Supports UTF-8 path on Windows
            if (version_compare(PHP_VERSION, '7.1', '>=')) {
                $this->options['encoding'] = '';
                $this->options['locale'] = '';
            }
        }
        if (!$cwd = getcwd()) {
            return $this->setError('elFinder LocalVolumeDriver requires a result of getcwd().');
        }
        // detect systemRoot
        if (!isset($this->options['systemRoot'])) {
            if ($cwd[0] === DIRECTORY_SEPARATOR || $this->root[0] === DIRECTORY_SEPARATOR) {
                $this->systemRoot = DIRECTORY_SEPARATOR;
            } else if (preg_match('/^([a-zA-Z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . ')/', $this->root, $m)) {
                $this->systemRoot = $m[1];
            } else if (preg_match('/^([a-zA-Z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . ')/', $cwd, $m)) {
                $this->systemRoot = $m[1];
            }
        }
        $this->root = $this->getFullPath($this->root, $cwd);
        if (!empty($this->options['startPath'])) {
            $this->options['startPath'] = $this->getFullPath($this->options['startPath'], $this->root);
        }

        if (is_null($this->options['syncChkAsTs'])) {
            $this->options['syncChkAsTs'] = true;
        }
        if (is_null($this->options['syncCheckFunc'])) {
            $this->options['syncCheckFunc'] = array($this, 'localFileSystemInotify');
        }
        // check 'statCorrector'
        if (empty($this->options['statCorrector']) || !is_callable($this->options['statCorrector'])) {
            $this->options['statCorrector'] = null;
        }

        return true;
    }

    /**
     * Configure after successfull mount.
     *
     * @return void
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function configure()
    {
        $hiddens = array();
        $root = $this->stat($this->root);

        // check thumbnails path
        if (!empty($this->options['tmbPath'])) {
            if (strpos($this->options['tmbPath'], DIRECTORY_SEPARATOR) === false) {
                $hiddens['tmb'] = $this->options['tmbPath'];
                $this->options['tmbPath'] = $this->_abspath($this->options['tmbPath']);
            } else {
                $this->options['tmbPath'] = $this->_normpath($this->options['tmbPath']);
            }
        }
        // check temp path
        if (!empty($this->options['tmpPath'])) {
            if (strpos($this->options['tmpPath'], DIRECTORY_SEPARATOR) === false) {
                $hiddens['temp'] = $this->options['tmpPath'];
                $this->options['tmpPath'] = $this->_abspath($this->options['tmpPath']);
            } else {
                $this->options['tmpPath'] = $this->_normpath($this->options['tmpPath']);
            }
        }
        // check quarantine path
        $_quarantine = '';
        if (!empty($this->options['quarantine'])) {
            if (strpos($this->options['quarantine'], DIRECTORY_SEPARATOR) === false) {
                //$hiddens['quarantine'] = $this->options['quarantine'];
                //$this->options['quarantine'] = $this->_abspath($this->options['quarantine']);
                $_quarantine = $this->_abspath($this->options['quarantine']);
                $this->options['quarantine'] = '';
            } else {
                $this->options['quarantine'] = $this->_normpath($this->options['quarantine']);
            }
        } else {
            $_quarantine = $this->_abspath('.quarantine');
        }
        is_dir($_quarantine) && self::localRmdirRecursive($_quarantine);

        parent::configure();

        // check tmbPath
        if (!$this->tmbPath && isset($hiddens['tmb'])) {
            unset($hiddens['tmb']);
        }

        // if no thumbnails url - try detect it
        if ($root['read'] && !$this->tmbURL && $this->URL) {
            if (strpos($this->tmbPath, $this->root) === 0) {
                $this->tmbURL = $this->URL . str_replace(DIRECTORY_SEPARATOR, '/', substr($this->tmbPath, strlen($this->root) + 1));
                if (preg_match("|[^/?&=]$|", $this->tmbURL)) {
                    $this->tmbURL .= '/';
                }
            }
        }

        // set $this->tmp by options['tmpPath']
        $this->tmp = '';
        if (!empty($this->options['tmpPath'])) {
            if ((is_dir($this->options['tmpPath']) || mkdir($this->options['tmpPath'], $this->options['dirMode'], true)) && is_writable($this->options['tmpPath'])) {
                $this->tmp = $this->options['tmpPath'];
            } else {
                if (isset($hiddens['temp'])) {
                    unset($hiddens['temp']);
                }
            }
        }
        if (!$this->tmp && ($tmp = elFinder::getStaticVar('commonTempPath'))) {
            $this->tmp = $tmp;
        }

        // check quarantine dir
        $this->quarantine = '';
        if (!empty($this->options['quarantine'])) {
            if ((is_dir($this->options['quarantine']) || mkdir($this->options['quarantine'], $this->options['dirMode'], true)) && is_writable($this->options['quarantine'])) {
                $this->quarantine = $this->options['quarantine'];
            } else {
                if (isset($hiddens['quarantine'])) {
                    unset($hiddens['quarantine']);
                }
            }
        } else if ($_path = elFinder::getCommonTempPath()) {
            $this->quarantine = $_path;
        }

        if (!$this->quarantine) {
            if (!$this->tmp) {
                $this->archivers['extract'] = array();
                $this->disabled[] = 'extract';
            } else {
                $this->quarantine = $this->tmp;
            }
        }

        if ($hiddens) {
            foreach ($hiddens as $hidden) {
                $this->attributes[] = array(
                    'pattern' => '~^' . preg_quote(DIRECTORY_SEPARATOR . $hidden, '~') . '$~',
                    'read' => false,
                    'write' => false,
                    'locked' => true,
                    'hidden' => true
                );
            }
        }

        if (!empty($this->options['keepTimestamp'])) {
            $this->options['keepTimestamp'] = array_flip($this->options['keepTimestamp']);
        }

        $this->statOwner = (!empty($this->options['statOwner']));

        // enable WinRemoveTailDots plugin on Windows server
        if (DIRECTORY_SEPARATOR !== '/') {
            if (!isset($this->options['plugin'])) {
                $this->options['plugin'] = array();
            }
            $this->options['plugin']['WinRemoveTailDots'] = array('enable' => true);
        }
    }

    /**
     * Long pooling sync checker
     * This function require server command `inotifywait`
     * If `inotifywait` need full path, Please add `define('ELFINER_INOTIFYWAIT_PATH', '/PATH_TO/inotifywait');` into connector.php
     *
     * @param string $path
     * @param int    $standby
     * @param number $compare
     *
     * @return number|bool
     * @throws elFinderAbortException
     */
    public function localFileSystemInotify($path, $standby, $compare)
    {
        if (isset($this->sessionCache['localFileSystemInotify_disable'])) {
            return false;
        }
        $path = realpath($path);
        $mtime = filemtime($path);
        if (!$mtime) {
            return false;
        }
        if ($mtime != $compare) {
            return $mtime;
        }
        $inotifywait = defined('ELFINER_INOTIFYWAIT_PATH') ? ELFINER_INOTIFYWAIT_PATH : 'inotifywait';
        $standby = max(1, intval($standby));
        $cmd = $inotifywait . ' ' . escapeshellarg($path) . ' -t ' . $standby . ' -e moved_to,moved_from,move,close_write,delete,delete_self';
        $this->procExec($cmd, $o, $r);
        if ($r === 0) {
            // changed
            clearstatcache();
            if (file_exists($path)) {
                $mtime = filemtime($path); // error on busy?
                return $mtime ? $mtime : time();
            } else {
                // target was removed
                return 0;
            }
        } else if ($r === 2) {
            // not changed (timeout)
            return $compare;
        }
        // error
        // cache to $_SESSION
        $this->sessionCache['localFileSystemInotify_disable'] = true;
        $this->session->set($this->id, $this->sessionCache);
        return false;
    }

    /*********************************************************************/
    /*                               FS API                              */
    /*********************************************************************/

    /*********************** paths/urls *************************/

    /**
     * Return parent directory path
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _dirname($path)
    {
        return dirname($path);
    }

    /**
     * Return file name
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _basename($path)
    {
        return basename($path);
    }

    /**
     * Join dir name and file name and retur full path
     *
     * @param  string $dir
     * @param  string $name
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _joinPath($dir, $name)
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        $path = realpath($dir . DIRECTORY_SEPARATOR . $name);
        // realpath() returns FALSE if the file does not exist
        if ($path === false || strpos($path, $this->root) !== 0) {
            if (DIRECTORY_SEPARATOR !== '/') {
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
                $name = str_replace('/', DIRECTORY_SEPARATOR, $name);
            }
            // Directory traversal measures
            if (strpos($dir, '..' . DIRECTORY_SEPARATOR) !== false || substr($dir, -2) == '..') {
                $dir = $this->root;
            }
            if (strpos($name, '..' . DIRECTORY_SEPARATOR) !== false) {
                $name = basename($name);
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
        }
        return $path; 
    }

    /**
     * Return normalized path, this works the same as os.path.normpath() in Python
     *
     * @param  string $path path
     *
     * @return string
     * @author Troex Nevelin
     **/
    protected function _normpath($path)
    {
        if (empty($path)) {
            return '.';
        }

        $changeSep = (DIRECTORY_SEPARATOR !== '/');
        if ($changeSep) {
            $drive = '';
            if (preg_match('/^([a-zA-Z]:)(.*)/', $path, $m)) {
                $drive = $m[1];
                $path = $m[2] ? $m[2] : '/';
            }
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        }

        if (strpos($path, '/') === 0) {
            $initial_slashes = true;
        } else {
            $initial_slashes = false;
        }

        if (($initial_slashes)
            && (strpos($path, '//') === 0)
            && (strpos($path, '///') === false)) {
            $initial_slashes = 2;
        }

        $initial_slashes = (int)$initial_slashes;

        $comps = explode('/', $path);
        $new_comps = array();
        foreach ($comps as $comp) {
            if (in_array($comp, array('', '.'))) {
                continue;
            }

            if (($comp != '..')
                || (!$initial_slashes && !$new_comps)
                || ($new_comps && (end($new_comps) == '..'))) {
                array_push($new_comps, $comp);
            } elseif ($new_comps) {
                array_pop($new_comps);
            }
        }
        $comps = $new_comps;
        $path = implode('/', $comps);
        if ($initial_slashes) {
            $path = str_repeat('/', $initial_slashes) . $path;
        }

        if ($changeSep) {
            $path = $drive . str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        return $path ? $path : '.';
    }

    /**
     * Return file path related to root dir
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _relpath($path)
    {
        if ($path === $this->root) {
            return '';
        } else {
            if (strpos($path, $this->root) === 0) {
                return ltrim(substr($path, strlen($this->root)), DIRECTORY_SEPARATOR);
            } else {
                // for link
                return $path;
            }
        }
    }

    /**
     * Convert path related to root dir into real path
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _abspath($path)
    {
        if ($path === DIRECTORY_SEPARATOR) {
            return $this->root;
        } else {
            $path = $this->_normpath($path);
            if (strpos($path, $this->systemRoot) === 0) {
                return $path;
            } else if (DIRECTORY_SEPARATOR !== '/' && preg_match('/^[a-zA-Z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . '/', $path)) {
                return $path;
            } else {
                return $this->_joinPath($this->root, $path);
            }
        }
    }

    /**
     * Return fake path started from root dir
     *
     * @param  string $path file path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _path($path)
    {
        return $this->rootName . ($path == $this->root ? '' : $this->separator . $this->_relpath($path));
    }

    /**
     * Return true if $path is children of $parent
     *
     * @param  string $path   path to check
     * @param  string $parent parent path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _inpath($path, $parent)
    {
        $cwd = getcwd();
        $real_path = $this->getFullPath($path, $cwd);
        $real_parent = $this->getFullPath($parent, $cwd);
        if ($real_path && $real_parent) {
            return $real_path === $real_parent || strpos($real_path, rtrim($real_parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
        }
        return false;
    }



    /***************** file stat ********************/

    /**
     * Return stat for given path.
     * Stat contains following fields:
     * - (int)    size    file size in b. required
     * - (int)    ts      file modification time in unix time. required
     * - (string) mime    mimetype. required for folders, others - optionally
     * - (bool)   read    read permissions. required
     * - (bool)   write   write permissions. required
     * - (bool)   locked  is object locked. optionally
     * - (bool)   hidden  is object hidden. optionally
     * - (string) alias   for symlinks - link target path relative to root path. optionally
     * - (string) target  for symlinks - link target path. optionally
     * If file does not exists - returns empty array or false.
     *
     * @param  string $path file path
     *
     * @return array|false
     * @author Dmitry (dio) Levashov
     **/
    protected function _stat($path)
    {
        $stat = array();

        if (!file_exists($path) && !is_link($path)) {
            return $stat;
        }

        //Verifies the given path is the root or is inside the root. Prevents directory traveral.
        if (!$this->_inpath($path, $this->root)) {
            return $stat;
        }

        $stat['isowner'] = false;
        $linkreadable = false;
        if ($path != $this->root && is_link($path)) {
            if (!$this->options['followSymLinks']) {
                return array();
            }
            if (!($target = $this->readlink($path))
                || $target == $path) {
                if (is_null($target)) {
                    $stat = array();
                    return $stat;
                } else {
                    $stat['mime'] = 'symlink-broken';
                    $target = readlink($path);
                    $lstat = lstat($path);
                    $ostat = $this->getOwnerStat($lstat['uid'], $lstat['gid']);
                    $linkreadable = !empty($ostat['isowner']);
                }
            }
            $stat['alias'] = $this->_path($target);
            $stat['target'] = $target;
        }

        $readable = is_readable($path);

        if ($readable) {
            $size = sprintf('%u', filesize($path));
            $stat['ts'] = filemtime($path);
            if ($this->statOwner) {
                $fstat = stat($path);
                $uid = $fstat['uid'];
                $gid = $fstat['gid'];
                $stat['perm'] = substr((string)decoct($fstat['mode']), -4);
                $stat = array_merge($stat, $this->getOwnerStat($uid, $gid));
            }
        }

        if (($dir = is_dir($path)) && $this->options['detectDirIcon']) {
            $favicon = $path . DIRECTORY_SEPARATOR . $this->options['detectDirIcon'];
            if ($this->URL && file_exists($favicon)) {
                $stat['icon'] = $this->URL . str_replace(DIRECTORY_SEPARATOR, '/', substr($favicon, strlen($this->root) + 1));
            }
        }

        if (!isset($stat['mime'])) {
            $stat['mime'] = $dir ? 'directory' : $this->mimetype($path);
        }
        //logical rights first
        $stat['read'] = ($linkreadable || $readable) ? null : false;
        $stat['write'] = is_writable($path) ? null : false;

        if (is_null($stat['read'])) {
            if ($dir) {
                $stat['size'] = 0;
            } else if (isset($size)) {
                $stat['size'] = $size;
            }
        }

        if ($this->options['statCorrector']) {
            call_user_func_array($this->options['statCorrector'], array(&$stat, $path, $this->statOwner, $this));
        }

        return $stat;
    }

    /**
     * Get stat `owner`, `group` and `isowner` by `uid` and `gid`
     * Sub-fuction of _stat() and _scandir()
     *
     * @param integer $uid
     * @param integer $gid
     *
     * @return array  stat
     */
    protected function getOwnerStat($uid, $gid)
    {
        static $names = null;
        static $phpuid = null;

        if (is_null($names)) {
            $names = array('uid' => array(), 'gid' => array());
        }
        if (is_null($phpuid)) {
            if (is_callable('posix_getuid')) {
                $phpuid = posix_getuid();
            } else {
                $phpuid = 0;
            }
        }

        $stat = array();

        if ($uid) {
            $stat['isowner'] = ($phpuid == $uid);
            if (isset($names['uid'][$uid])) {
                $stat['owner'] = $names['uid'][$uid];
            } else if (is_callable('posix_getpwuid')) {
                $pwuid = posix_getpwuid($uid);
                $stat['owner'] = $names['uid'][$uid] = $pwuid['name'];
            } else {
                $stat['owner'] = $names['uid'][$uid] = $uid;
            }
        }
        if ($gid) {
            if (isset($names['gid'][$gid])) {
                $stat['group'] = $names['gid'][$gid];
            } else if (is_callable('posix_getgrgid')) {
                $grgid = posix_getgrgid($gid);
                $stat['group'] = $names['gid'][$gid] = $grgid['name'];
            } else {
                $stat['group'] = $names['gid'][$gid] = $gid;
            }
        }

        return $stat;
    }

    /**
     * Return true if path is dir and has at least one childs directory
     *
     * @param  string $path dir path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _subdirs($path)
    {

        $dirs = false;
        if (is_dir($path) && is_readable($path)) {
            if (class_exists('FilesystemIterator', false)) {
                $dirItr = new ParentIterator(
                    new RecursiveDirectoryIterator($path,
                        FilesystemIterator::SKIP_DOTS |
                        FilesystemIterator::CURRENT_AS_SELF |
                        (defined('RecursiveDirectoryIterator::FOLLOW_SYMLINKS') ?
                            RecursiveDirectoryIterator::FOLLOW_SYMLINKS : 0)
                    )
                );
                $dirItr->rewind();
                if ($dirItr->hasChildren()) {
                    $dirs = true;
                    $name = $dirItr->getSubPathName();
                    while ($dirItr->valid()) {
                        if (!$this->attr($path . DIRECTORY_SEPARATOR . $name, 'read', null, true)) {
                            $dirs = false;
                            $dirItr->next();
                            $name = $dirItr->getSubPathName();
                            continue;
                        }
                        $dirs = true;
                        break;
                    }
                }
            } else {
                $path = strtr($path, array('[' => '\\[', ']' => '\\]', '*' => '\\*', '?' => '\\?'));
                return (bool)glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            }
        }
        return $dirs;
    }

    /**
     * Return object width and height
     * Usualy used for images, but can be realize for video etc...
     *
     * @param  string $path file path
     * @param  string $mime file mime type
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _dimensions($path, $mime)
    {
        clearstatcache();
        return strpos($mime, 'image') === 0 && is_readable($path) && filesize($path) && ($s = getimagesize($path)) !== false
            ? $s[0] . 'x' . $s[1]
            : false;
    }
    /******************** file/dir content *********************/

    /**
     * Return symlink target file
     *
     * @param  string $path link path
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function readlink($path)
    {
        if (!($target = readlink($path))) {
            return null;
        }

        if (strpos($target, $this->systemRoot) !== 0) {
            $target = $this->_joinPath(dirname($path), $target);
        }

        if (!file_exists($target)) {
            return false;
        }

        return $target;
    }

    /**
     * Return files list in directory.
     *
     * @param  string $path dir path
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function _scandir($path)
    {
        elFinder::checkAborted();
        $files = array();
        $cache = array();
        $dirWritable = is_writable($path);
        $dirItr = array();
        $followSymLinks = $this->options['followSymLinks'];
        try {
            $dirItr = new DirectoryIterator($path);
        } catch (UnexpectedValueException $e) {
        }

        foreach ($dirItr as $file) {
            try {
                if ($file->isDot()) {
                    continue;
                }

                $files[] = $fpath = $file->getPathname();

                $br = false;
                $stat = array();

                $stat['isowner'] = false;
                $linkreadable = false;
                if ($file->isLink()) {
                    if (!$followSymLinks) {
                        continue;
                    }
                    if (!($target = $this->readlink($fpath))
                        || $target == $fpath) {
                        if (is_null($target)) {
                            $stat = array();
                            $br = true;
                        } else {
                            $_path = $fpath;
                            $stat['mime'] = 'symlink-broken';
                            $target = readlink($_path);
                            $lstat = lstat($_path);
                            $ostat = $this->getOwnerStat($lstat['uid'], $lstat['gid']);
                            $linkreadable = !empty($ostat['isowner']);
                            $dir = false;
                            $stat['alias'] = $this->_path($target);
                            $stat['target'] = $target;
                        }
                    } else {
                        $dir = is_dir($target);
                        $stat['alias'] = $this->_path($target);
                        $stat['target'] = $target;
                        $stat['mime'] = $dir ? 'directory' : $this->mimetype($stat['alias']);
                    }
                } else {
                    if (($dir = $file->isDir()) && $this->options['detectDirIcon']) {
                        $path = $file->getPathname();
                        $favicon = $path . DIRECTORY_SEPARATOR . $this->options['detectDirIcon'];
                        if ($this->URL && file_exists($favicon)) {
                            $stat['icon'] = $this->URL . str_replace(DIRECTORY_SEPARATOR, '/', substr($favicon, strlen($this->root) + 1));
                        }
                    }
                    $stat['mime'] = $dir ? 'directory' : $this->mimetype($fpath);
                }
                $size = sprintf('%u', $file->getSize());
                $stat['ts'] = $file->getMTime();
                if (!$br) {
                    if ($this->statOwner && !$linkreadable) {
                        $uid = $file->getOwner();
                        $gid = $file->getGroup();
                        $stat['perm'] = substr((string)decoct($file->getPerms()), -4);
                        $stat = array_merge($stat, $this->getOwnerStat($uid, $gid));
                    }

                    //logical rights first
                    $stat['read'] = ($linkreadable || $file->isReadable()) ? null : false;
                    $stat['write'] = $file->isWritable() ? null : false;
                    $stat['locked'] = $dirWritable ? null : true;

                    if (is_null($stat['read'])) {
                        $stat['size'] = $dir ? 0 : $size;
                    }

                    if ($this->options['statCorrector']) {
                        call_user_func_array($this->options['statCorrector'], array(&$stat, $fpath, $this->statOwner, $this));
                    }
                }

                $cache[] = array($fpath, $stat);
            } catch (RuntimeException $e) {
                continue;
            }
        }

        if ($cache) {
            $cache = $this->convEncOut($cache, false);
            foreach ($cache as $d) {
                $this->updateCache($d[0], $d[1]);
            }
        }

        return $files;
    }

    /**
     * Open file and return file pointer
     *
     * @param  string $path file path
     * @param string  $mode
     *
     * @return false|resource
     * @internal param bool $write open file for writing
     * @author   Dmitry (dio) Levashov
     */
    protected function _fopen($path, $mode = 'rb')
    {
        return fopen($path, $mode);
    }

    /**
     * Close opened file
     *
     * @param  resource $fp file pointer
     * @param string    $path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     */
    protected function _fclose($fp, $path = '')
    {
        return (is_resource($fp) && fclose($fp));
    }

    /********************  file/dir manipulations *************************/

    /**
     * Create dir and return created dir path or false on failed
     *
     * @param  string $path parent dir path
     * @param string  $name new directory name
     *
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _mkdir($path, $name)
    {
        $path = $this->_joinPath($path, $name);

        if (mkdir($path)) {
            chmod($path, $this->options['dirMode']);
            return $path;
        }

        return false;
    }

    /**
     * Create file and return it's path or false on failed
     *
     * @param  string $path parent dir path
     * @param string  $name new file name
     *
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _mkfile($path, $name)
    {
        $path = $this->_joinPath($path, $name);

        if (($fp = fopen($path, 'w'))) {
            fclose($fp);
            chmod($path, $this->options['fileMode']);
            return $path;
        }
        return false;
    }

    /**
     * Create symlink
     *
     * @param  string $source    file to link to
     * @param  string $targetDir folder to create link in
     * @param  string $name      symlink name
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _symlink($source, $targetDir, $name)
    {
        return $this->localFileSystemSymlink($source, $this->_joinPath($targetDir, $name));
    }

    /**
     * Copy file into another file
     *
     * @param  string $source    source file path
     * @param  string $targetDir target directory path
     * @param  string $name      new file name
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _copy($source, $targetDir, $name)
    {
        $mtime = filemtime($source);
        $target = $this->_joinPath($targetDir, $name);
        if ($ret = copy($source, $target)) {
            isset($this->options['keepTimestamp']['copy']) && $mtime && touch($target, $mtime);
        }
        return $ret;
    }

    /**
     * Move file into another parent dir.
     * Return new file path or false.
     *
     * @param  string $source source file path
     * @param         $targetDir
     * @param  string $name   file name
     *
     * @return bool|string
     * @internal param string $target target dir path
     * @author   Dmitry (dio) Levashov
     */
    protected function _move($source, $targetDir, $name)
    {
        $mtime = filemtime($source);
        $target = $this->_joinPath($targetDir, $name);
        if ($ret = rename($source, $target) ? $target : false) {
            isset($this->options['keepTimestamp']['move']) && $mtime && touch($target, $mtime);
        }
        return $ret;
    }

    /**
     * Remove file
     *
     * @param  string $path file path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _unlink($path)
    {
        return is_file($path) && unlink($path);
    }

    /**
     * Remove dir
     *
     * @param  string $path dir path
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _rmdir($path)
    {
        return rmdir($path);
    }

    /**
     * Create new file and write into it from file pointer.
     * Return new file path or false on error.
     *
     * @param  resource $fp   file pointer
     * @param  string   $dir  target dir path
     * @param  string   $name file name
     * @param  array    $stat file stat (required by some virtual fs)
     *
     * @return bool|string
     * @author Dmitry (dio) Levashov
     **/
    protected function _save($fp, $dir, $name, $stat)
    {
        $path = $this->_joinPath($dir, $name);

        $meta = stream_get_meta_data($fp);
        $uri = isset($meta['uri']) ? $meta['uri'] : '';
        if ($uri && !preg_match('#^[a-zA-Z0-9]+://#', $uri) && !is_link($uri)) {
            fclose($fp);
            $mtime = filemtime($uri);
            $isCmdPaste = ($this->ARGS['cmd'] === 'paste');
            $isCmdCopy = ($isCmdPaste && empty($this->ARGS['cut']));
            if (($isCmdCopy || !rename($uri, $path)) && !copy($uri, $path)) {
                return false;
            }
            // keep timestamp on upload
            if ($mtime && $this->ARGS['cmd'] === 'upload') {
                touch($path, isset($this->options['keepTimestamp']['upload']) ? $mtime : time());
            }
        } else {
            if (file_put_contents($path, $fp, LOCK_EX) === false) {
                return false;
            }
        }

        chmod($path, $this->options['fileMode']);
        return $path;
    }

    /**
     * Get file contents
     *
     * @param  string $path file path
     *
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    protected function _getContents($path)
    {
        return file_get_contents($path);
    }

    /**
     * Write a string to a file
     *
     * @param  string $path    file path
     * @param  string $content new file content
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _filePutContents($path, $content)
    {
        return (file_put_contents($path, $content, LOCK_EX) !== false);
    }

    /**
     * Detect available archivers
     *
     * @return void
     * @throws elFinderAbortException
     */
    protected function _checkArchivers()
    {
        $this->archivers = $this->getArchivers();
        return;
    }

    /**
     * chmod availability
     *
     * @param string $path
     * @param string $mode
     *
     * @return bool
     */
    protected function _chmod($path, $mode)
    {
        $modeOct = is_string($mode) ? octdec($mode) : octdec(sprintf("%04o", $mode));
        return chmod($path, $modeOct);
    }

    /**
     * Recursive symlinks search
     *
     * @param  string $path file/dir path
     *
     * @return bool
     * @throws Exception
     * @author Dmitry (dio) Levashov
     */
    protected function _findSymlinks($path)
    {
        return self::localFindSymlinks($path);
    }

    /**
     * Extract files from archive
     *
     * @param  string $path archive path
     * @param  array  $arc  archiver command and arguments (same as in $this->archivers)
     *
     * @return array|string|boolean
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     */
    protected function _extract($path, $arc)
    {

        if ($this->quarantine) {

            $dir = $this->quarantine . DIRECTORY_SEPARATOR . md5(basename($path) . mt_rand());
            $archive = (isset($arc['toSpec']) || $arc['cmd'] === 'phpfunction') ? '' : $dir . DIRECTORY_SEPARATOR . basename($path);

            if (!mkdir($dir)) {
                return false;
            }

            // insurance unexpected shutdown
            register_shutdown_function(array($this, 'rmdirRecursive'), realpath($dir));

            chmod($dir, 0777);

            // copy in quarantine
            if (!is_readable($path) || ($archive && !copy($path, $archive))) {
                return false;
            }

            // extract in quarantine
            try {
                $this->unpackArchive($path, $arc, $archive ? true : $dir);
            } catch(Exception $e) {
                return $this->setError($e->getMessage());
            }

            // get files list
            try {
                $ls = self::localScandir($dir);
            } catch (Exception $e) {
                return false;
            }

            // no files - extract error ?
            if (empty($ls)) {
                return false;
            }

            $this->archiveSize = 0;

            // find symlinks and check extracted items
            $checkRes = $this->checkExtractItems($dir);
            if ($checkRes['symlinks']) {
                self::localRmdirRecursive($dir);
                return $this->setError(array_merge($this->error, array(elFinder::ERROR_ARC_SYMLINKS)));
            }
            $this->archiveSize = $checkRes['totalSize'];
            if ($checkRes['rmNames']) {
                foreach ($checkRes['rmNames'] as $name) {
                    $this->addError(elFinder::ERROR_SAVE, $name);
                }
            }

            // check max files size
            if ($this->options['maxArcFilesSize'] > 0 && $this->options['maxArcFilesSize'] < $this->archiveSize) {
                $this->delTree($dir);
                return $this->setError(elFinder::ERROR_ARC_MAXSIZE);
            }

            $extractTo = $this->extractToNewdir; // 'auto', ture or false

            // archive contains one item - extract in archive dir
            $name = '';
            $src = $dir . DIRECTORY_SEPARATOR . $ls[0];
            if (($extractTo === 'auto' || !$extractTo) && count($ls) === 1 && is_file($src)) {
                $name = $ls[0];
            } else if ($extractTo === 'auto' || $extractTo) {
                // for several files - create new directory
                // create unique name for directory
                $src = $dir;
                $splits = elFinder::splitFileExtention(basename($path));
                $name = $splits[0];
                $test = dirname($path) . DIRECTORY_SEPARATOR . $name;
                if (file_exists($test) || is_link($test)) {
                    $name = $this->uniqueName(dirname($path), $name, '-', false);
                }
            }

            if ($name !== '') {
                $result = dirname($path) . DIRECTORY_SEPARATOR . $name;

                if (!rename($src, $result)) {
                    $this->delTree($dir);
                    return false;
                }
            } else {
                $dstDir = dirname($path);
                $result = array();
                foreach ($ls as $name) {
                    $target = $dstDir . DIRECTORY_SEPARATOR . $name;
                    if (self::localMoveRecursive($dir . DIRECTORY_SEPARATOR . $name, $target, true, $this->options['copyJoin'])) {
                        $result[] = $target;
                    }
                }
                if (!$result) {
                    $this->delTree($dir);
                    return false;
                }
            }

            is_dir($dir) && $this->delTree($dir);

            return (is_array($result) || file_exists($result)) ? $result : false;
        }
        //TODO: Add return statement here
        return false;
    }

    /**
     * Create archive and return its path
     *
     * @param  string $dir   target dir
     * @param  array  $files files names list
     * @param  string $name  archive name
     * @param  array  $arc   archiver options
     *
     * @return string|bool
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     */
    protected function _archive($dir, $files, $name, $arc)
    {
        return $this->makeArchive($dir, $files, $name, $arc);
    }

    /******************** Over write functions *************************/

    /**
     * File path of local server side work file path
     *
     * @param  string $path
     *
     * @return string
     * @author Naoki Sawada
     */
    protected function getWorkFile($path)
    {
        return $path;
    }

    /**
     * Delete dirctory trees
     *
     * @param string $localpath path need convert encoding to server encoding
     *
     * @return boolean
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    protected function delTree($localpath)
    {
        return $this->rmdirRecursive($localpath);
    }

    /**
     * Return fileinfo based on filename
     * For item ID based path file system
     * Please override if needed on each drivers
     *
     * @param  string $path file cache
     *
     * @return array|boolean false
     */
    protected function isNameExists($path)
    {
        $exists = file_exists($this->convEncIn($path));
        // restore locale
        $this->convEncOut();
        return $exists ? $this->stat($path) : false;
    }

    /******************** Over write (Optimized) functions *************************/

    /**
     * Recursive files search
     *
     * @param  string $path dir path
     * @param  string $q    search string
     * @param  array  $mimes
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Naoki Sawada
     */
    protected function doSearch($path, $q, $mimes)
    {
        if (!empty($this->doSearchCurrentQuery['matchMethod']) || $this->encoding || !class_exists('FilesystemIterator', false)) {
            // has custom match method or non UTF-8, use elFinderVolumeDriver::doSearch()
            return parent::doSearch($path, $q, $mimes);
        }

        $result = array();

        $timeout = $this->options['searchTimeout'] ? $this->searchStart + $this->options['searchTimeout'] : 0;
        if ($timeout && $timeout < time()) {
            $this->setError(elFinder::ERROR_SEARCH_TIMEOUT, $this->path($this->encode($path)));
            return $result;
        }
        elFinder::extendTimeLimit($this->options['searchTimeout'] + 30);

        $match = array();
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($path,
                        FilesystemIterator::KEY_AS_PATHNAME |
                        FilesystemIterator::SKIP_DOTS |
                        ((defined('RecursiveDirectoryIterator::FOLLOW_SYMLINKS') && $this->options['followSymLinks']) ?
                            RecursiveDirectoryIterator::FOLLOW_SYMLINKS : 0)
                    ),
                    array($this, 'localFileSystemSearchIteratorFilter')
                ),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $key => $node) {
                if ($timeout && ($this->error || $timeout < time())) {
                    !$this->error && $this->setError(elFinder::ERROR_SEARCH_TIMEOUT, $this->path($this->encode($node->getPath)));
                    break;
                }
                if ($node->isDir()) {
                    if ($this->stripos($node->getFilename(), $q) !== false) {
                        $match[] = $key;
                    }
                } else {
                    $match[] = $key;
                }
            }
        } catch (Exception $e) {
        }

        if ($match) {
            foreach ($match as $p) {
                if ($timeout && ($this->error || $timeout < time())) {
                    !$this->error && $this->setError(elFinder::ERROR_SEARCH_TIMEOUT, $this->path($this->encode(dirname($p))));
                    break;
                }

                $stat = $this->stat($p);

                if (!$stat) { // invalid links
                    continue;
                }

                if (!empty($stat['hidden']) || !$this->mimeAccepted($stat['mime'], $mimes)) {
                    continue;
                }

                if ((!$mimes || $stat['mime'] !== 'directory')) {
                    $stat['path'] = $this->path($stat['hash']);
                    if ($this->URL && !isset($stat['url'])) {
                        $_path = str_replace(DIRECTORY_SEPARATOR, '/', substr($p, strlen($this->root) + 1));
                        $stat['url'] = $this->URL . str_replace('%2F', '/', rawurlencode($_path));
                    }

                    $result[] = $stat;
                }
            }
        }

        return $result;
    }

    /******************** Original local functions ************************
     *
     * @param $file
     * @param $key
     * @param $iterator
     *
     * @return bool
     */

    public function localFileSystemSearchIteratorFilter($file, $key, $iterator)
    {
        /* @var FilesystemIterator $file */
        /* @var RecursiveDirectoryIterator $iterator */
        $name = $file->getFilename();
        if ($this->doSearchCurrentQuery['excludes']) {
            foreach ($this->doSearchCurrentQuery['excludes'] as $exclude) {
                if ($this->stripos($name, $exclude) !== false) {
                    return false;
                }
            }
        }
        if ($iterator->hasChildren()) {
            if ($this->options['searchExDirReg'] && preg_match($this->options['searchExDirReg'], $key)) {
                return false;
            }
            return (bool)$this->attr($key, 'read', null, true);
        }
        return ($this->stripos($name, $this->doSearchCurrentQuery['q']) === false) ? false : true;
    }

    /**
     * Creates a symbolic link
     *
     * @param      string   $target  The target
     * @param      string   $link    The link
     *
     * @return     boolean  ( result of symlink() )
     */
    protected function localFileSystemSymlink($target, $link)
    {
        $res = false;
        if (function_exists('symlink') and is_callable('symlink')) {
            $errlev = error_reporting();
            error_reporting($errlev ^ E_WARNING);
            if ($res = symlink(realpath($target), $link)) {
                $res = is_readable($link);
            }
            error_reporting($errlev);
        }
        return $res;
    }
} // END class 

}

/* ===================== [embedded] elFinder ===================== */
if (!class_exists('elFinder', false)) {
/**
 * elFinder - file manager for web.
 * Core class.
 *
 * @package elfinder
 * @author  Dmitry (dio) Levashov
 * @author  Troex Nevelin
 * @author  Alexey Sukhotin
 **/
class elFinder
{

    /**
     * API version number
     *
     * @var float
     **/
    protected static $ApiVersion = 2.1;

    /**
     * API version number
     *
     * @deprecated
     * @var string
     **/
    protected $version;

    /**
     * API revision that this connector supports all functions
     *
     * @var integer
     */
    protected static $ApiRevision = 66;

    /**
     * Storages (root dirs)
     *
     * @var array
     **/
    protected $volumes = array();

    /**
     * elFinder instance
     *
     * @var object
     */
    public static $instance = null;

    /**
     * Current request args
     *
     * @var array
     */
    public static $currentArgs = array();

    /**
     * Network mount drivers
     *
     * @var array
     */
    public static $netDrivers = array();

    /**
     * elFinder global locale
     *
     * @var string
     */
    public static $locale = '';

    /**
     * elFinderVolumeDriver default mime.type file path
     *
     * @var string
     */
    public static $defaultMimefile = '';

    /**
     * A file save destination path when a temporary content URL is required
     * on a network volume or the like
     * It can be overwritten by volume route setting
     *
     * @var string
     */
    public static $tmpLinkPath = '';

    /**
     * A file save destination URL when a temporary content URL is required
     * on a network volume or the like
     * It can be overwritten by volume route setting
     *
     * @var string
     */
    public static $tmpLinkUrl = '';

    /**
     * Temporary content URL lifetime (seconds)
     *
     * @var integer
     */
    public static $tmpLinkLifeTime = 3600;

    /**
     * MIME type list handled as a text file
     *
     * @var array
     */
    public static $textMimes = array(
        'application/dash+xml',
        'application/docbook+xml',
        'application/javascript',
        'application/json',
        'application/plt',
        'application/sat',
        'application/sql',
        'application/step',
        'application/vnd.hp-hpgl',
        'application/x-awk',
        'application/x-config',
        'application/x-csh',
        'application/x-empty',
        'application/x-mpegurl',
        'application/x-perl',
        'application/x-php',
        'application/x-web-config',
        'application/xhtml+xml',
        'application/xml',
        'audio/x-mp3-playlist',
        'image/cgm',
        'image/svg+xml',
        'image/vnd.dxf',
        'model/iges'
    );

    /**
     * Maximum memory size to be extended during GD processing
     * (0: not expanded, -1: unlimited or memory size notation)
     *
     * @var integer|string
     */
    public static $memoryLimitGD = 0;

    /**
     * Path of current request flag file for abort check
     *
     * @var string
     */
    protected static $abortCheckFile = null;

    /**
     * elFinder session wrapper object
     *
     * @var elFinderSessionInterface
     */
    protected $session;

    /**
     * elFinder global sessionCacheKey
     *
     * @deprecated
     * @var string
     */
    public static $sessionCacheKey = '';

    /**
     * Is session closed
     *
     * @deprecated
     * @var bool
     */
    private static $sessionClosed = false;

    /**
     * elFinder base64encodeSessionData
     * elFinder save session data as `UTF-8`
     * If the session storage mechanism of the system does not allow `UTF-8`
     * And it must be `true` option 'base64encodeSessionData' of elFinder
     * WARNING: When enabling this option, if saving the data passed from the user directly to the session variable,
     * it make vulnerable to the object injection attack, so use it carefully.
     * see https://github.com/Studio-42/elFinder/issues/2345
     *
     * @var bool
     */
    protected static $base64encodeSessionData = false;

    /**
     * elFinder common tempraly path
     *
     * @var string
     * @default "./.tmp" or sys_get_temp_dir()
     **/
    protected static $commonTempPath = '';

    /**
     * Callable function for URL upload filter
     * The first argument is a URL and the second argument is an instance of the elFinder class
     * A filter should be return true (to allow) / false (to disallow)
     *
     * @var callable
     * @default null
     */
    protected $urlUploadFilter = null;

    /**
     * Connection flag files path that connection check of current request
     *
     * @var string
     * @default value of $commonTempPath
     */
    protected static $connectionFlagsPath = '';

    /**
     * Additional volume root options for network mounting volume
     *
     * @var array
     */
    protected $optionsNetVolumes = array();

    /**
     * Session key of net mount volumes
     *
     * @deprecated
     * @var string
     */
    protected $netVolumesSessionKey = '';

    /**
     * Mounted volumes count
     * Required to create unique volume id
     *
     * @var int
     **/
    public static $volumesCnt = 1;

    /**
     * Default root (storage)
     *
     * @var elFinderVolumeDriver
     **/
    protected $default = null;

    /**
     * Commands and required arguments list
     *
     * @var array
     **/
    protected $commands = array(
        'abort' => array('id' => true),
        'archive' => array('targets' => true, 'type' => true, 'mimes' => false, 'name' => false),
        'callback' => array('node' => true, 'json' => false, 'bind' => false, 'done' => false),
        'chmod' => array('targets' => true, 'mode' => true),
        'dim' => array('target' => true, 'substitute' => false),
        'duplicate' => array('targets' => true, 'suffix' => false),
        'editor' => array('name' => true, 'method' => true, 'args' => false),
        'extract' => array('target' => true, 'mimes' => false, 'makedir' => false),
        'file' => array('target' => true, 'download' => false, 'cpath' => false, 'onetime' => false),
        'get' => array('target' => true, 'conv' => false),
        'info' => array('targets' => true, 'compare' => false),
        'ls' => array('target' => true, 'mimes' => false, 'intersect' => false),
        'mkdir' => array('target' => true, 'name' => false, 'dirs' => false),
        'mkfile' => array('target' => true, 'name' => true, 'mimes' => false),
        'netmount' => array('protocol' => true, 'host' => true, 'path' => false, 'port' => false, 'user' => false, 'pass' => false, 'alias' => false, 'options' => false),
        'open' => array('target' => false, 'tree' => false, 'init' => false, 'mimes' => false, 'compare' => false),
        'parents' => array('target' => true, 'until' => false),
        'paste' => array('dst' => true, 'targets' => true, 'cut' => false, 'mimes' => false, 'renames' => false, 'hashes' => false, 'suffix' => false),
        'put' => array('target' => true, 'content' => '', 'mimes' => false, 'encoding' => false),
        'rename' => array('target' => true, 'name' => true, 'mimes' => false, 'targets' => false, 'q' => false),
        'resize' => array('target' => true, 'width' => false, 'height' => false, 'mode' => false, 'x' => false, 'y' => false, 'degree' => false, 'quality' => false, 'bg' => false),
        'rm' => array('targets' => true),
        'search' => array('q' => true, 'mimes' => false, 'target' => false, 'type' => false),
        'size' => array('targets' => true),
        'subdirs' => array('targets' => true),
        'tmb' => array('targets' => true),
        'tree' => array('target' => true),
        'upload' => array('target' => true, 'FILES' => true, 'mimes' => false, 'html' => false, 'upload' => false, 'name' => false, 'upload_path' => false, 'chunk' => false, 'cid' => false, 'node' => false, 'renames' => false, 'hashes' => false, 'suffix' => false, 'mtime' => false, 'overwrite' => false, 'contentSaveId' => false),
        'url' => array('target' => true, 'options' => false),
        'zipdl' => array('targets' => true, 'download' => false)
    );

    /**
     * Plugins instance
     *
     * @var array
     **/
    protected $plugins = array();

    /**
     * Commands listeners
     *
     * @var array
     **/
    protected $listeners = array();

    /**
     * script work time for debug
     *
     * @var string
     **/
    protected $time = 0;
    /**
     * Is elFinder init correctly?
     *
     * @var bool
     **/
    protected $loaded = false;
    /**
     * Send debug to client?
     *
     * @var string
     **/
    protected $debug = false;

    /**
     * Call `session_write_close()` before exec command?
     *
     * @var bool
     */
    protected $sessionCloseEarlier = true;

    /**
     * SESSION use commands @see __construct()
     *
     * @var array
     */
    protected $sessionUseCmds = array();

    /**
     * session expires timeout
     *
     * @var int
     **/
    protected $timeout = 0;

    /**
     * Temp dir path for Upload
     *
     * @var string
     */
    protected $uploadTempPath = '';

    /**
     * Max allowed archive files size (0 - no limit)
     *
     * @var integer
     */
    protected $maxArcFilesSize = 0;

    /**
     * undocumented class variable
     *
     * @var string
     **/
    protected $uploadDebug = '';

    /**
     * Max allowed numbar of targets (0 - no limit)
     *
     * @var integer
     */
    public $maxTargets = 1000;

    /**
     * Errors from PHP
     *
     * @var array
     **/
    public static $phpErrors = array();

    /**
     * Errors from not mounted volumes
     *
     * @var array
     **/
    public $mountErrors = array();


    /**
     * Archivers cache
     *
     * @var array
     */
    public static $archivers = array();

    /**
     * URL for callback output window for CORS
     * redirect to this URL when callback output
     *
     * @var string URL
     */
    protected $callbackWindowURL = '';

    /**
     * hash of items to unlock on command completion
     *
     * @var array hashes
     */
    protected $autoUnlocks = array();

    /**
     * Item locking expiration (seconds)
     * Default: 3600 secs
     *
     * @var integer
     */
    protected $itemLockExpire = 3600;

    /**
     * Additional request querys
     *
     * @var array|null
     */
    protected $customData = null;

    /**
     * Ids to remove of session var "urlContentSaveIds" for contents uploading by URL
     *
     * @var array
     */
    protected $removeContentSaveIds = array();

    /**
     * LAN class allowed when uploading via URL
     * 
     * Array keys are 'local', 'private_a', 'private_b', 'private_c' and 'link'
     * 
     * local:     127.0.0.0/8
     * private_a: 10.0.0.0/8
     * private_b: 172.16.0.0/12
     * private_c: 192.168.0.0/16
     * link:      169.254.0.0/16
     *
     * @var        array
     */
    protected $uploadAllowedLanIpClasses = array();

    /**
     * Flag of throw Error on exec()
     *
     * @var boolean
     */
    protected $throwErrorOnExec = false;

    /**
     * Default params of toastParams
     *
     * @var        array
     */
    protected $toastParamsDefault = array(
        'mode'   => 'warning',
        'prefix' => ''
    );

    /**
     * Toast params of runtime notification
     *
     * @var        array
     */
    private $toastParams = array();

    /**
     * Toast messages of runtime notification
     *
     * @var        array
     */
    private $toastMessages = array();

    /**
     * Optional UTF-8 encoder
     *
     * @var        callable || null
     */
    private $utf8Encoder = null;

    /**
     * Seekable URL file pointer ids -  for getStreamByUrl()
     *
     * @var        array
     */
    private static $seekableUrlFps = array();

    // Errors messages
    const ERROR_ACCESS_DENIED = 'errAccess';
    const ERROR_ARC_MAXSIZE = 'errArcMaxSize';
    const ERROR_ARC_SYMLINKS = 'errArcSymlinks';
    const ERROR_ARCHIVE = 'errArchive';
    const ERROR_ARCHIVE_EXEC = 'errArchiveExec';
    const ERROR_ARCHIVE_TYPE = 'errArcType';
    const ERROR_CONF = 'errConf';
    const ERROR_CONF_NO_JSON = 'errJSON';
    const ERROR_CONF_NO_VOL = 'errNoVolumes';
    const ERROR_CONV_UTF8 = 'errConvUTF8';
    const ERROR_COPY = 'errCopy';
    const ERROR_COPY_FROM = 'errCopyFrom';
    const ERROR_COPY_ITSELF = 'errCopyInItself';
    const ERROR_COPY_TO = 'errCopyTo';
    const ERROR_CREATING_TEMP_DIR = 'errCreatingTempDir';
    const ERROR_DIR_NOT_FOUND = 'errFolderNotFound';
    const ERROR_EXISTS = 'errExists';        // 'File named "$1" already exists.'
    const ERROR_EXTRACT = 'errExtract';
    const ERROR_EXTRACT_EXEC = 'errExtractExec';
    const ERROR_FILE_NOT_FOUND = 'errFileNotFound';     // 'File not found.'
    const ERROR_FTP_DOWNLOAD_FILE = 'errFtpDownloadFile';
    const ERROR_FTP_MKDIR = 'errFtpMkdir';
    const ERROR_FTP_UPLOAD_FILE = 'errFtpUploadFile';
    const ERROR_INV_PARAMS = 'errCmdParams';
    const ERROR_INVALID_DIRNAME = 'errInvDirname';    // 'Invalid folder name.'
    const ERROR_INVALID_NAME = 'errInvName';       // 'Invalid file name.'
    const ERROR_LOCKED = 'errLocked';        // '"$1" is locked and can not be renamed, moved or removed.'
    const ERROR_MAX_TARGTES = 'errMaxTargets'; // 'Max number of selectable items is $1.'
    const ERROR_MKDIR = 'errMkdir';
    const ERROR_MKFILE = 'errMkfile';
    const ERROR_MKOUTLINK = 'errMkOutLink';        // 'Unable to create a link to outside the volume root.'
    const ERROR_MOVE = 'errMove';
    const ERROR_NETMOUNT = 'errNetMount';
    const ERROR_NETMOUNT_FAILED = 'errNetMountFailed';
    const ERROR_NETMOUNT_NO_DRIVER = 'errNetMountNoDriver';
    const ERROR_NETUNMOUNT = 'errNetUnMount';
    const ERROR_NOT_ARCHIVE = 'errNoArchive';
    const ERROR_NOT_DIR = 'errNotFolder';
    const ERROR_NOT_FILE = 'errNotFile';
    const ERROR_NOT_REPLACE = 'errNotReplace';       // Object "$1" already exists at this location and can not be replaced with object of another type.
    const ERROR_NOT_UTF8_CONTENT = 'errNotUTF8Content';
    const ERROR_OPEN = 'errOpen';
    const ERROR_PERM_DENIED = 'errPerm';
    const ERROR_REAUTH_REQUIRE = 'errReauthRequire';  // 'Re-authorization is required.'
    const ERROR_RENAME = 'errRename';
    const ERROR_REPLACE = 'errReplace';          // 'Unable to replace "$1".'
    const ERROR_RESIZE = 'errResize';
    const ERROR_RESIZESIZE = 'errResizeSize';
    const ERROR_RM = 'errRm';               // 'Unable to remove "$1".'
    const ERROR_RM_SRC = 'errRmSrc';            // 'Unable remove source file(s)'
    const ERROR_SAVE = 'errSave';
    const ERROR_SEARCH_TIMEOUT = 'errSearchTimeout';    // 'Timed out while searching "$1". Search result is partial.'
    const ERROR_SESSION_EXPIRES = 'errSessionExpires';
    const ERROR_TRGDIR_NOT_FOUND = 'errTrgFolderNotFound'; // 'Target folder "$1" not found.'
    const ERROR_UNKNOWN = 'errUnknown';
    const ERROR_UNKNOWN_CMD = 'errUnknownCmd';
    const ERROR_UNSUPPORT_TYPE = 'errUsupportType';
    const ERROR_UPLOAD = 'errUpload';           // 'Upload error.'
    const ERROR_UPLOAD_FILE = 'errUploadFile';       // 'Unable to upload "$1".'
    const ERROR_UPLOAD_FILE_MIME = 'errUploadMime';       // 'File type not allowed.'
    const ERROR_UPLOAD_FILE_SIZE = 'errUploadFileSize';   // 'File exceeds maximum allowed size.'
    const ERROR_UPLOAD_NO_FILES = 'errUploadNoFiles';    // 'No files found for upload.'
    const ERROR_UPLOAD_TEMP = 'errUploadTemp';       // 'Unable to make temporary file for upload.'
    const ERROR_UPLOAD_TOTAL_SIZE = 'errUploadTotalSize';  // 'Data exceeds the maximum allowed size.'
    const ERROR_UPLOAD_TRANSFER = 'errUploadTransfer';   // '"$1" transfer error.'
    const ERROR_MAX_MKDIRS = 'errMaxMkdirs'; // 'You can create up to $1 folders at one time.'

    /**
     * Constructor
     *
     * @param  array  elFinder and roots configurations
     *
     * @author Dmitry (dio) Levashov
     */
    public function __construct($opts)
    {
        // set default_charset
        if (version_compare(PHP_VERSION, '5.6', '>=')) {
            if (($_val = ini_get('iconv.internal_encoding')) && strtoupper($_val) !== 'UTF-8') {
                ini_set('iconv.internal_encoding', '');
            }
            if (($_val = ini_get('mbstring.internal_encoding')) && strtoupper($_val) !== 'UTF-8') {
                ini_set('mbstring.internal_encoding', '');
            }
            if (($_val = ini_get('internal_encoding')) && strtoupper($_val) !== 'UTF-8') {
                ini_set('internal_encoding', '');
            }
        } else {
            if (function_exists('iconv_set_encoding') && strtoupper(iconv_get_encoding('internal_encoding')) !== 'UTF-8') {
                iconv_set_encoding('internal_encoding', 'UTF-8');
            }
            if (function_exists('mb_internal_encoding') && strtoupper(mb_internal_encoding()) !== 'UTF-8') {
                mb_internal_encoding('UTF-8');
            }
        }
        ini_set('default_charset', 'UTF-8');

        // define accept constant of server commands path
        !defined('ELFINDER_TAR_PATH') && define('ELFINDER_TAR_PATH', 'tar');
        !defined('ELFINDER_GZIP_PATH') && define('ELFINDER_GZIP_PATH', 'gzip');
        !defined('ELFINDER_BZIP2_PATH') && define('ELFINDER_BZIP2_PATH', 'bzip2');
        !defined('ELFINDER_XZ_PATH') && define('ELFINDER_XZ_PATH', 'xz');
        !defined('ELFINDER_ZIP_PATH') && define('ELFINDER_ZIP_PATH', 'zip');
        !defined('ELFINDER_UNZIP_PATH') && define('ELFINDER_UNZIP_PATH', 'unzip');
        !defined('ELFINDER_RAR_PATH') && define('ELFINDER_RAR_PATH', 'rar');
        // Create archive in RAR4 format even when using RAR5 library (true or false)
        !defined('ELFINDER_RAR_MA4') && define('ELFINDER_RAR_MA4', false);
        !defined('ELFINDER_UNRAR_PATH') && define('ELFINDER_UNRAR_PATH', 'unrar');
        !defined('ELFINDER_7Z_PATH') && define('ELFINDER_7Z_PATH', (substr(PHP_OS, 0, 3) === 'WIN') ? '7z' : '7za');
        !defined('ELFINDER_CONVERT_PATH') && define('ELFINDER_CONVERT_PATH', 'convert');
        !defined('ELFINDER_IDENTIFY_PATH') && define('ELFINDER_IDENTIFY_PATH', 'identify');
        !defined('ELFINDER_EXIFTRAN_PATH') && define('ELFINDER_EXIFTRAN_PATH', 'exiftran');
        !defined('ELFINDER_JPEGTRAN_PATH') && define('ELFINDER_JPEGTRAN_PATH', 'jpegtran');
        !defined('ELFINDER_FFMPEG_PATH') && define('ELFINDER_FFMPEG_PATH', 'ffmpeg');

        !defined('ELFINDER_DISABLE_ZIPEDITOR') && define('ELFINDER_DISABLE_ZIPEDITOR', false);

        // enable(true)/disable(false) handling postscript on ImageMagick
        // Should be `false` as long as there is a Ghostscript vulnerability
        // see https://artifex.com/news/ghostscript-security-resolved/
        !defined('ELFINDER_IMAGEMAGICK_PS') && define('ELFINDER_IMAGEMAGICK_PS', false);

        // for backward compat
        $this->version = (string)self::$ApiVersion;

        // set error handler of WARNING, NOTICE
        $errLevel = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_RECOVERABLE_ERROR;
        if (defined('E_DEPRECATED')) {
            $errLevel |= E_DEPRECATED | E_USER_DEPRECATED;
        }
        // E_STRICT is deprecated; see https://wiki.php.net/rfc/deprecations_php_8_4#remove_e_strict_error_level_and_deprecate_e_strict_constant
        if (defined('E_STRICT')) {
            $errLevel |= @E_STRICT;
        }
        set_error_handler('elFinder::phpErrorHandler', $errLevel);

        // Associative array of file pointers to close at the end of script: ['temp file pointer' => true]
        $GLOBALS['elFinderTempFps'] = array();
        // Associative array of files to delete at the end of script: ['temp file path' => true]
        $GLOBALS['elFinderTempFiles'] = array();
        // Associative array of abort files to delete at the end of script: ['temp file path' => true]
        $GLOBALS['elFinderAbortFiles'] = array();
        // regist Shutdown function
        register_shutdown_function(array('elFinder', 'onShutdown'));

        // convert PATH_INFO to GET query
        if (!empty($_SERVER['PATH_INFO'])) {
            $_ps = explode('/', trim($_SERVER['PATH_INFO'], '/'));
            if (!isset($_GET['cmd'])) {
                $_cmd = $_ps[0];
                if (isset($this->commands[$_cmd])) {
                    $_GET['cmd'] = $_cmd;
                    $_i = 1;
                    foreach (array_keys($this->commands[$_cmd]) as $_k) {
                        if (isset($_ps[$_i])) {
                            if (!isset($_GET[$_k])) {
                                $_GET[$_k] = $_ps[$_i++];
                            }
                        } else {
                            break;
                        }
                    }
                }
            }
        }

        // set elFinder instance
        elFinder::$instance = $this;

        // setup debug mode
        $this->debug = (isset($opts['debug']) && $opts['debug'] ? true : false);
        if ($this->debug) {
            error_reporting(defined('ELFINDER_DEBUG_ERRORLEVEL') ? ELFINDER_DEBUG_ERRORLEVEL : -1);
            ini_set('display_errors', '1');
            // clear output buffer and stop output filters
            while (ob_get_level() && ob_end_clean()) {
            }
        }

        if (!interface_exists('elFinderSessionInterface')) {
            include_once dirname(__FILE__) . '/elFinderSessionInterface.php';
        }

        // session handler
        if (!empty($opts['session']) && $opts['session'] instanceof elFinderSessionInterface) {
            $this->session = $opts['session'];
        } else {
            $sessionOpts = array(
                'base64encode' => !empty($opts['base64encodeSessionData']),
                'keys' => array(
                    'default' => !empty($opts['sessionCacheKey']) ? $opts['sessionCacheKey'] : 'elFinderCaches',
                    'netvolume' => !empty($opts['netVolumesSessionKey']) ? $opts['netVolumesSessionKey'] : 'elFinderNetVolumes'
                )
            );
            if (!class_exists('elFinderSession')) {
                include_once dirname(__FILE__) . '/elFinderSession.php';
            }
            $this->session = new elFinderSession($sessionOpts);
        }
        // try session start | restart
        $this->session->start();

        // 'netmount' added to handle requests synchronously on unmount
        $sessionUseCmds = array('netmount');
        if (isset($opts['sessionUseCmds']) && is_array($opts['sessionUseCmds'])) {
            $sessionUseCmds = array_merge($sessionUseCmds, $opts['sessionUseCmds']);
        }

        // set self::$volumesCnt by HTTP header "X-elFinder-VolumesCntStart"
        if (isset($_SERVER['HTTP_X_ELFINDER_VOLUMESCNTSTART']) && ($volumesCntStart = intval($_SERVER['HTTP_X_ELFINDER_VOLUMESCNTSTART']))) {
            self::$volumesCnt = $volumesCntStart;
        }

        $this->time = $this->utime();
        $this->sessionCloseEarlier = isset($opts['sessionCloseEarlier']) ? (bool)$opts['sessionCloseEarlier'] : true;
        $this->sessionUseCmds = array_flip($sessionUseCmds);
        $this->timeout = (isset($opts['timeout']) ? $opts['timeout'] : 0);
        $this->uploadTempPath = (isset($opts['uploadTempPath']) ? $opts['uploadTempPath'] : '');
        $this->callbackWindowURL = (isset($opts['callbackWindowURL']) ? $opts['callbackWindowURL'] : '');
        $this->maxTargets = (isset($opts['maxTargets']) ? intval($opts['maxTargets']) : $this->maxTargets);
        elFinder::$commonTempPath = (isset($opts['commonTempPath']) ? realpath($opts['commonTempPath']) : dirname(__FILE__) . '/.tmp');
        if (!is_writable(elFinder::$commonTempPath)) {
            elFinder::$commonTempPath = sys_get_temp_dir();
            if (!is_writable(elFinder::$commonTempPath)) {
                elFinder::$commonTempPath = '';
            }
        }
        if (isset($opts['connectionFlagsPath']) && is_writable($opts['connectionFlagsPath'] = realpath($opts['connectionFlagsPath']))) {
            elFinder::$connectionFlagsPath = $opts['connectionFlagsPath'];
        } else {
            elFinder::$connectionFlagsPath = elFinder::$commonTempPath;
        }

        if (!empty($opts['tmpLinkPath'])) {
            elFinder::$tmpLinkPath = realpath($opts['tmpLinkPath']);
        }
        if (!empty($opts['tmpLinkUrl'])) {
            elFinder::$tmpLinkUrl = $opts['tmpLinkUrl'];
        }
        if (!empty($opts['tmpLinkLifeTime'])) {
            elFinder::$tmpLinkLifeTime = $opts['tmpLinkLifeTime'];
        }
        if (!empty($opts['textMimes']) && is_array($opts['textMimes'])) {
            elfinder::$textMimes = $opts['textMimes'];
        }
        if (!empty($opts['urlUploadFilter'])) {
            $this->urlUploadFilter = $opts['urlUploadFilter'];
        }
        $this->maxArcFilesSize = isset($opts['maxArcFilesSize']) ? intval($opts['maxArcFilesSize']) : 0;
        $this->optionsNetVolumes = (isset($opts['optionsNetVolumes']) && is_array($opts['optionsNetVolumes'])) ? $opts['optionsNetVolumes'] : array();
        if (isset($opts['itemLockExpire'])) {
            $this->itemLockExpire = intval($opts['itemLockExpire']);
        }

        if (!empty($opts['uploadAllowedLanIpClasses'])) {
            $this->uploadAllowedLanIpClasses = array_flip($opts['uploadAllowedLanIpClasses']);
        }

        // deprecated settings
        $this->netVolumesSessionKey = !empty($opts['netVolumesSessionKey']) ? $opts['netVolumesSessionKey'] : 'elFinderNetVolumes';
        self::$sessionCacheKey = !empty($opts['sessionCacheKey']) ? $opts['sessionCacheKey'] : 'elFinderCaches';

        // check session cache
        $_optsMD5 = md5(json_encode($opts['roots']));
        if ($this->session->get('_optsMD5') !== $_optsMD5) {
            $this->session->set('_optsMD5', $_optsMD5);
        }

        // setlocale and global locale regists to elFinder::locale
        self::$locale = !empty($opts['locale']) ? $opts['locale'] : (substr(PHP_OS, 0, 3) === 'WIN' ? 'C' : 'en_US.UTF-8');
        if (false === setlocale(LC_ALL, self::$locale)) {
            self::$locale = setlocale(LC_ALL, '0');
        }

        // set defaultMimefile
        elFinder::$defaultMimefile = isset($opts['defaultMimefile']) ? $opts['defaultMimefile'] : '';

        // set memoryLimitGD
        elFinder::$memoryLimitGD = isset($opts['memoryLimitGD']) ? $opts['memoryLimitGD'] : 0;

        // set flag of throwErrorOnExec
        // `true` need `try{}` block for `$connector->run();`
        $this->throwErrorOnExec = !empty($opts['throwErrorOnExec']);

        // set archivers
        elFinder::$archivers = isset($opts['archivers']) && is_array($opts['archivers']) ? $opts['archivers'] : array();

        // set utf8Encoder
        if (isset($opts['utf8Encoder']) && is_callable($opts['utf8Encoder'])) {
            $this->utf8Encoder = $opts['utf8Encoder'];
        }

        // for LocalFileSystem driver on Windows server
        if (DIRECTORY_SEPARATOR !== '/') {
            if (empty($opts['bind'])) {
                $opts['bind'] = array();
            }

            $_key = 'upload.pre mkdir.pre mkfile.pre rename.pre archive.pre ls.pre';
            if (!isset($opts['bind'][$_key])) {
                $opts['bind'][$_key] = array();
            }
            array_push($opts['bind'][$_key], 'Plugin.WinRemoveTailDots.cmdPreprocess');

            $_key = 'upload.presave paste.copyfrom';
            if (!isset($opts['bind'][$_key])) {
                $opts['bind'][$_key] = array();
            }
            array_push($opts['bind'][$_key], 'Plugin.WinRemoveTailDots.onUpLoadPreSave');
        }

        // bind events listeners
        if (!empty($opts['bind']) && is_array($opts['bind'])) {
            $_req = $_SERVER["REQUEST_METHOD"] == 'POST' ? $_POST : $_GET;
            $_reqCmd = isset($_req['cmd']) ? $_req['cmd'] : '';
            foreach ($opts['bind'] as $cmd => $handlers) {
                $doRegist = (strpos($cmd, '*') !== false);
                if (!$doRegist) {
                    $doRegist = ($_reqCmd && in_array($_reqCmd, array_map('elFinder::getCmdOfBind', explode(' ', $cmd))));
                }
                if ($doRegist) {
                    // for backward compatibility
                    if (!is_array($handlers)) {
                        $handlers = array($handlers);
                    } else {
                        if (count($handlers) === 2 && is_callable($handlers)) {
                            $handlers = array($handlers);
                        }
                    }
                    foreach ($handlers as $handler) {
                        if ($handler) {
                            if (is_string($handler) && strpos($handler, '.')) {
                                list($_domain, $_name, $_method) = array_pad(explode('.', $handler), 3, '');
                                if (strcasecmp($_domain, 'plugin') === 0) {
                                    if ($plugin = $this->getPluginInstance($_name, isset($opts['plugin'][$_name]) ? $opts['plugin'][$_name] : array())
                                        and method_exists($plugin, $_method)) {
                                        $this->bind($cmd, array($plugin, $_method));
                                    }
                                }
                            } else {
                                $this->bind($cmd, $handler);
                            }
                        }
                    }
                }
            }
        }

        if (!isset($opts['roots']) || !is_array($opts['roots'])) {
            $opts['roots'] = array();
        }

        // try to enable elFinderVolumeFlysystemZipArchiveNetmount to zip editing
        if (empty(elFinder::$netDrivers['ziparchive'])) {
            elFinder::$netDrivers['ziparchive'] = 'FlysystemZipArchiveNetmount';
        }

        // check for net volumes stored in session
        $netVolumes = $this->getNetVolumes();
        foreach ($netVolumes as $key => $root) {
            if (!isset($root['id'])) {
                // given fixed unique id
                if (!$root['id'] = $this->getNetVolumeUniqueId($netVolumes)) {
                    $this->mountErrors[] = 'Netmount Driver "' . $root['driver'] . '" : Could\'t given volume id.';
                    continue;
                }
            }
            $root['_isNetVolume'] = true;
            $opts['roots'][$key] = $root;
        }

        // "mount" volumes
        foreach ($opts['roots'] as $i => $o) {
            $class = 'elFinderVolume' . (isset($o['driver']) ? $o['driver'] : '');

            if (class_exists($class)) {
                /* @var elFinderVolumeDriver $volume */
                $volume = new $class();

                try {
                    if ($this->maxArcFilesSize && (empty($o['maxArcFilesSize']) || $this->maxArcFilesSize < $o['maxArcFilesSize'])) {
                        $o['maxArcFilesSize'] = $this->maxArcFilesSize;
                    }
                    // pass session handler
                    $volume->setSession($this->session);
                    if (!$this->default) {
                        $volume->setNeedOnline(true);
                    }
                    if ($volume->mount($o)) {
                        // unique volume id (ends on "_") - used as prefix to files hash
                        $id = $volume->id();

                        $this->volumes[$id] = $volume;
                        if ((!$this->default || $volume->root() !== $volume->defaultPath()) && $volume->isReadable()) {
                            $this->default = $volume;
                        }
                    } else {
                        if (!empty($o['_isNetVolume'])) {
                            $this->removeNetVolume($i, $volume);
                        }
                        $this->mountErrors[] = 'Driver "' . $class . '" : ' . implode(' ', $volume->error());
                    }
                } catch (Exception $e) {
                    if (!empty($o['_isNetVolume'])) {
                        $this->removeNetVolume($i, $volume);
                    }
                    $this->mountErrors[] = 'Driver "' . $class . '" : ' . $e->getMessage();
                }
            } else {
                if (!empty($o['_isNetVolume'])) {
                    $this->removeNetVolume($i, $volume);
                }
                $this->mountErrors[] = 'Driver "' . $class . '" does not exist';
            }
        }

        // if at least one readable volume - ii desu >_<
        $this->loaded = !empty($this->default);

        // restore error handler for now
        restore_error_handler();
    }

    /**
     * Return elFinder session wrapper instance
     *
     * @return  elFinderSessionInterface
     **/
    public function getSession()
    {
        return $this->session;
    }

    /**
     * Return true if fm init correctly
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    public function loaded()
    {
        return $this->loaded;
    }

    /**
     * Return version (api) number
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    public function version()
    {
        return self::$ApiVersion;
    }

    /**
     * Return revision (api) number
     *
     * @return string
     * @author Naoki Sawada
     **/
    public function revision()
    {
        return self::$ApiRevision;
    }

    /**
     * Add handler to elFinder command
     *
     * @param  string  command name
     * @param  string|array  callback name or array(object, method)
     *
     * @return elFinder
     * @author Dmitry (dio) Levashov
     **/
    public function bind($cmd, $handler)
    {
        $allCmds = array_keys($this->commands);
        $cmds = array();
        foreach (explode(' ', $cmd) as $_cmd) {
            if ($_cmd !== '') {
                if ($all = strpos($_cmd, '*') !== false) {
                    list(, $sub) = array_pad(explode('.', $_cmd), 2, '');
                    if ($sub) {
                        $sub = str_replace('\'', '\\\'', $sub);
                        $subs = array_fill(0, count($allCmds), $sub);
                        $cmds = array_merge($cmds, array_map(array('elFinder', 'addSubToBindName'), $allCmds, $subs));
                    } else {
                        $cmds = array_merge($cmds, $allCmds);
                    }
                } else {
                    $cmds[] = $_cmd;
                }
            }
        }
        $cmds = array_unique($cmds);

        foreach ($cmds as $cmd) {
            if (!isset($this->listeners[$cmd])) {
                $this->listeners[$cmd] = array();
            }

            if (is_callable($handler)) {
                $this->listeners[$cmd][] = $handler;
            }
        }

        return $this;
    }

    /**
     * Remove event (command exec) handler
     *
     * @param  string  command name
     * @param  string|array  callback name or array(object, method)
     *
     * @return elFinder
     * @author Dmitry (dio) Levashov
     **/
    public function unbind($cmd, $handler)
    {
        if (!empty($this->listeners[$cmd])) {
            foreach ($this->listeners[$cmd] as $i => $h) {
                if ($h === $handler) {
                    unset($this->listeners[$cmd][$i]);
                    return $this;
                }
            }
        }
        return $this;
    }

    /**
     * Trigger binded functions
     *
     * @param      string  $cmd     binded command name
     * @param      array   $vars    variables to pass to listeners
     * @param      array   $errors  array into which the error is written
     */
    public function trigger($cmd, $vars, &$errors)
    {
        if (!empty($this->listeners[$cmd])) {
            foreach ($this->listeners[$cmd] as $handler) {
                $_res = call_user_func_array($handler, $vars);
                if ($_res && is_array($_res)) {
                    $_err = !empty($_res['error'])? $_res['error'] : (!empty($_res['warning'])? $_res['warning'] : null);
                    if ($_err) {
                        if (is_array($_err)) {
                            $errors = array_merge($errors, $_err);
                        } else {
                            $errors[] = (string)$_err;
                        }
                        if ($_res['error']) {
                            throw new elFinderTriggerException();
                        }
                    }
                }
            }
        }
    }

    /**
     * Return true if command exists
     *
     * @param  string  command name
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    public function commandExists($cmd)
    {
        return $this->loaded && isset($this->commands[$cmd]) && method_exists($this, $cmd);
    }

    /**
     * Return root - file's owner (public func of volume())
     *
     * @param  string  file hash
     *
     * @return elFinderVolumeDriver
     * @author Naoki Sawada
     */
    public function getVolume($hash)
    {
        return $this->volume($hash);
    }

    /**
     * Return command required arguments info
     *
     * @param  string  command name
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    public function commandArgsList($cmd)
    {
        if ($this->commandExists($cmd)) {
            $list = $this->commands[$cmd];
            $list['reqid'] = false;
        } else {
            $list = array();
        }
        return $list;
    }

    private function session_expires()
    {

        if (!$last = $this->session->get(':LAST_ACTIVITY')) {
            $this->session->set(':LAST_ACTIVITY', time());
            return false;
        }

        if (($this->timeout > 0) && (time() - $last > $this->timeout)) {
            return true;
        }

        $this->session->set(':LAST_ACTIVITY', time());
        return false;
    }

    /**
     * Exec command and return result
     *
     * @param  string $cmd  command name
     * @param  array  $args command arguments
     *
     * @return array
     * @throws elFinderAbortException|Exception
     * @author Dmitry (dio) Levashov
     **/
    public function exec($cmd, $args)
    {
        // set error handler of WARNING, NOTICE
        set_error_handler('elFinder::phpErrorHandler', E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);

        // set current request args
        self::$currentArgs = $args;

        if (!$this->loaded) {
            return array('error' => $this->error(self::ERROR_CONF, self::ERROR_CONF_NO_VOL));
        }

        if ($this->session_expires()) {
            return array('error' => $this->error(self::ERROR_SESSION_EXPIRES));
        }

        if (!$this->commandExists($cmd)) {
            return array('error' => $this->error(self::ERROR_UNKNOWN_CMD));
        }

        // check request id
        $args['reqid'] = preg_replace('[^0-9a-fA-F]', '', !empty($args['reqid']) ? $args['reqid'] : (!empty($_SERVER['HTTP_X_ELFINDERREQID']) ? $_SERVER['HTTP_X_ELFINDERREQID'] : ''));

        // to abort this request
        if ($cmd === 'abort') {
            $this->abort($args);
            return array('error' => 0);
        }

        // make flag file and set self::$abortCheckFile
        if ($args['reqid']) {
            $this->abort(array('makeFile' => $args['reqid']));
        }

        if (!empty($args['mimes']) && is_array($args['mimes'])) {
            foreach ($this->volumes as $id => $v) {
                $this->volumes[$id]->setMimesFilter($args['mimes']);
            }
        }

        // regist shutdown function as fallback
        register_shutdown_function(array($this, 'itemAutoUnlock'));

        // detect destination dirHash and volume
        $dstVolume = false;
        $dst = !empty($args['target']) ? $args['target'] : (!empty($args['dst']) ? $args['dst'] : '');
        if ($dst) {
            $dstVolume = $this->volume($dst);
        } else if (isset($args['targets']) && is_array($args['targets']) && isset($args['targets'][0])) {
            $dst = $args['targets'][0];
            $dstVolume = $this->volume($dst);
            if ($dstVolume && ($_stat = $dstVolume->file($dst)) && !empty($_stat['phash'])) {
                $dst = $_stat['phash'];
            } else {
                $dst = '';
            }
        } else if ($cmd === 'open') {
            // for initial open without args `target`
            $dstVolume = $this->default;
            $dst = $dstVolume->defaultPath();
        }

        $result = null;

        // call pre handlers for this command
        $args['sessionCloseEarlier'] = isset($this->sessionUseCmds[$cmd]) ? false : $this->sessionCloseEarlier;
        if (!empty($this->listeners[$cmd . '.pre'])) {
            foreach ($this->listeners[$cmd . '.pre'] as $handler) {
                $_res = call_user_func_array($handler, array($cmd, &$args, $this, $dstVolume));
                if (is_array($_res)) {
                    if (!empty($_res['preventexec'])) {
                        $result = array('error' => true);
                        if ($cmd === 'upload' && !empty($args['node'])) {
                            $result['callback'] = array(
                                'node' => $args['node'],
                                'bind' => $cmd
                            );
                        }
                        if (!empty($_res['results']) && is_array($_res['results'])) {
                            $result = array_merge($result, $_res['results']);
                        }
                        break;
                    }
                }
            }
        }

        // unlock session data for multiple access
        if ($this->sessionCloseEarlier && $args['sessionCloseEarlier']) {
            $this->session->close();
            // deprecated property
            elFinder::$sessionClosed = true;
        }

        if (substr(PHP_OS, 0, 3) === 'WIN') {
            // set time out
            elFinder::extendTimeLimit(300);
        }

        if (!is_array($result)) {
            try {
                $result = $this->$cmd($args);
            } catch (elFinderAbortException $e) {
                throw $e;
            } catch (Exception $e) {
                $error_res = json_decode($e->getMessage());
                $message = isset($error_res->error->message) ? $error_res->error->message : $e->getMessage();
                $result = array(
                    'error' => htmlspecialchars($message),
                    'sync' => true
                );
                if ($this->throwErrorOnExec) {
                    throw $e;
                }
            }
        }

        // check change dstDir
        $changeDst = false;
        if ($dst && $dstVolume && (!empty($result['added']) || !empty($result['removed']))) {
            $changeDst = true;
        }

        foreach ($this->volumes as $volume) {
            $removed = $volume->removed();
            if (!empty($removed)) {
                if (!isset($result['removed'])) {
                    $result['removed'] = array();
                }
                $result['removed'] = array_merge($result['removed'], $removed);
                if (!$changeDst && $dst && $dstVolume && $volume === $dstVolume) {
                    $changeDst = true;
                }
            }
            $added = $volume->added();
            if (!empty($added)) {
                if (!isset($result['added'])) {
                    $result['added'] = array();
                }
                $result['added'] = array_merge($result['added'], $added);
                if (!$changeDst && $dst && $dstVolume && $volume === $dstVolume) {
                    $changeDst = true;
                }
            }
            $volume->resetResultStat();
        }

        // dstDir is changed
        if ($changeDst) {
            if ($dstDir = $dstVolume->dir($dst)) {
                if (!isset($result['changed'])) {
                    $result['changed'] = array();
                }
                $result['changed'][] = $dstDir;
            }
        }

        // call handlers for this command
        if (!empty($this->listeners[$cmd])) {
            foreach ($this->listeners[$cmd] as $handler) {
                if (call_user_func_array($handler, array($cmd, &$result, $args, $this, $dstVolume))) {
                    // handler return true to force sync client after command completed
                    $result['sync'] = true;
                }
            }
        }

        // replace removed files info with removed files hashes
        if (!empty($result['removed'])) {
            $removed = array();
            foreach ($result['removed'] as $file) {
                $removed[] = $file['hash'];
            }
            $result['removed'] = array_unique($removed);
        }
        // remove hidden files and filter files by mimetypes
        if (!empty($result['added'])) {
            $result['added'] = $this->filter($result['added']);
        }
        // remove hidden files and filter files by mimetypes
        if (!empty($result['changed'])) {
            $result['changed'] = $this->filter($result['changed']);
        }
        // add toasts
        if ($this->toastMessages) {
            $result['toasts'] = array_merge(((isset($result['toasts']) && is_array($result['toasts']))? $result['toasts'] : array()), $this->toastMessages);
        }

        if ($this->debug || !empty($args['debug'])) {
            $result['debug'] = array(
                'connector' => 'php',
                'phpver' => PHP_VERSION,
                'time' => $this->utime() - $this->time,
                'memory' => (function_exists('memory_get_peak_usage') ? ceil(memory_get_peak_usage() / 1024) . 'Kb / ' : '') . ceil(memory_get_usage() / 1024) . 'Kb / ' . ini_get('memory_limit'),
                'upload' => $this->uploadDebug,
                'volumes' => array(),
                'mountErrors' => $this->mountErrors
            );

            foreach ($this->volumes as $id => $volume) {
                $result['debug']['volumes'][] = $volume->debug();
            }
        }

        // remove sesstion var 'urlContentSaveIds'
        if ($this->removeContentSaveIds) {
            $urlContentSaveIds = $this->session->get('urlContentSaveIds', array());
            foreach (array_keys($this->removeContentSaveIds) as $contentSaveId) {
                if (isset($urlContentSaveIds[$contentSaveId])) {
                    unset($urlContentSaveIds[$contentSaveId]);
                }
            }
            if ($urlContentSaveIds) {
                $this->session->set('urlContentSaveIds', $urlContentSaveIds);
            } else {
                $this->session->remove('urlContentSaveIds');
            }
        }

        foreach ($this->volumes as $volume) {
            $volume->saveSessionCache();
            $volume->umount();
        }

        // unlock locked items
        $this->itemAutoUnlock();

        // custom data
        if ($this->customData !== null) {
            $result['customData'] = $this->customData ? json_encode($this->customData) : '';
        }

        if (!empty($result['debug'])) {
            $result['debug']['backendErrors'] = elFinder::$phpErrors;
        }
        elFinder::$phpErrors = array();
        restore_error_handler();

        if (!empty($result['callback'])) {
            $result['callback']['json'] = json_encode($result);
            $this->callback($result['callback']);
            return array();
        } else {
            return $result;
        }
    }

    /**
     * Return file real path
     *
     * @param  string $hash file hash
     *
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    public function realpath($hash)
    {
        if (($volume = $this->volume($hash)) == false) {
            return false;
        }
        return $volume->realpath($hash);
    }

    /**
     * Sets custom data(s).
     *
     * @param  string|array $key The key or data array
     * @param  mixed        $val The value
     *
     * @return self    ( elFinder instance )
     */
    public function setCustomData($key, $val = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->customData[$k] = $v;
            }
        } else {
            $this->customData[$key] = $val;
        }
        return $this;
    }

    /**
     * Removes a custom data.
     *
     * @param  string $key The key
     *
     * @return self    ( elFinder instance )
     */
    public function removeCustomData($key)
    {
        $this->customData[$key] = null;
        return $this;
    }

    /**
     * Update sesstion value of a NetVolume option
     *
     * @param string $netKey
     * @param string $optionKey
     * @param mixed  $val
     *
     * @return bool
     */
    public function updateNetVolumeOption($netKey, $optionKey, $val)
    {
        $netVolumes = $this->getNetVolumes();
        if (is_string($netKey) && isset($netVolumes[$netKey]) && is_string($optionKey)) {
            $netVolumes[$netKey][$optionKey] = $val;
            $this->saveNetVolumes($netVolumes);
            return true;
        }
        return false;
    }

    /**
     * remove of session var "urlContentSaveIds"
     *
     * @param string $id
     */
    public function removeUrlContentSaveId($id)
    {
        $this->removeContentSaveIds[$id] = true;
    }

    /**
     * Return network volumes config.
     *
     * @return array
     * @author Dmitry (dio) Levashov
     */
    protected function getNetVolumes()
    {
        if ($data = $this->session->get('netvolume', array())) {
            return $data;
        }
        return array();
    }

    /**
     * Save network volumes config.
     *
     * @param  array $volumes volumes config
     *
     * @return void
     * @author Dmitry (dio) Levashov
     */
    protected function saveNetVolumes($volumes)
    {
        $this->session->set('netvolume', $volumes);
    }

    /**
     * Remove netmount volume
     *
     * @param string $key    netvolume key
     * @param object $volume volume driver instance
     *
     * @return bool
     */
    protected function removeNetVolume($key, $volume)
    {
        $netVolumes = $this->getNetVolumes();
        $res = true;
        if (is_object($volume) && method_exists($volume, 'netunmount')) {
            $res = $volume->netunmount($netVolumes, $key);
            $volume->clearSessionCache();
        }
        if ($res) {
            if (is_string($key) && isset($netVolumes[$key])) {
                unset($netVolumes[$key]);
                $this->saveNetVolumes($netVolumes);
                return true;
            }
        }
        return false;
    }

    /**
     * Get plugin instance & set to $this->plugins
     *
     * @param  string $name Plugin name (dirctory name)
     * @param  array  $opts Plugin options (optional)
     *
     * @return object | bool Plugin object instance Or false
     * @author Naoki Sawada
     */
    protected function getPluginInstance($name, $opts = array())
    {
        $key = strtolower($name);
        if (!isset($this->plugins[$key])) {
            $class = 'elFinderPlugin' . $name;
            // to try auto load
            if (!class_exists($class)) {
                $p_file = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'plugin.php';
                if (is_file($p_file)) {
                    include_once $p_file;
                }
            }
            if (class_exists($class, false)) {
                $this->plugins[$key] = new $class($opts);
            } else {
                $this->plugins[$key] = false;
            }
        }
        return $this->plugins[$key];
    }

    /***************************************************************************/
    /*                                 commands                                */
    /***************************************************************************/

    /**
     * Normalize error messages
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    public function error()
    {
        $errors = array();

        foreach (func_get_args() as $msg) {
            if (is_array($msg)) {
                $errors = array_merge($errors, $msg);
            } else {
                $errors[] = $msg;
            }
        }

        return count($errors) ? $errors : array(self::ERROR_UNKNOWN);
    }

    /**
     * @param $args
     *
     * @return array
     * @throws elFinderAbortException
     */
    protected function netmount($args)
    {
        $options = array();
        $protocol = $args['protocol'];
        $toast = '';

        if ($protocol === 'netunmount') {
            if (!empty($args['user']) && $volume = $this->volume($args['user'])) {
                if ($this->removeNetVolume($args['host'], $volume)) {
                    return array('removed' => array(array('hash' => $volume->root())));
                }
            }
            return array('sync' => true, 'error' => $this->error(self::ERROR_NETUNMOUNT));
        }

        $driver = isset(self::$netDrivers[$protocol]) ? self::$netDrivers[$protocol] : '';
        $class = 'elFinderVolume' . $driver;

        if (!class_exists($class)) {
            return array('error' => $this->error(self::ERROR_NETMOUNT, $args['host'], self::ERROR_NETMOUNT_NO_DRIVER));
        }

        if (!$args['path']) {
            $args['path'] = '/';
        }

        foreach ($args as $k => $v) {
            if ($k != 'options' && $k != 'protocol' && $v) {
                $options[$k] = $v;
            }
        }

        if (is_array($args['options'])) {
            foreach ($args['options'] as $key => $value) {
                $options[$key] = $value;
            }
        }

        /* @var elFinderVolumeDriver $volume */
        $volume = new $class();

        // pass session handler
        $volume->setSession($this->session);

        $volume->setNeedOnline(true);

        if (is_callable(array($volume, 'netmountPrepare'))) {
            $options = $volume->netmountPrepare($options);
            if (isset($options['exit'])) {
                if ($options['exit'] === 'callback') {
                    $this->callback($options['out']);
                }
                return $options;
            }
            if (!empty($options['toast'])) {
                $toast = $options['toast'];
                unset($options['toast']);
            }
        }

        $netVolumes = $this->getNetVolumes();

        if (!isset($options['id'])) {
            // given fixed unique id
            if (!$options['id'] = $this->getNetVolumeUniqueId($netVolumes)) {
                return array('error' => $this->error(self::ERROR_NETMOUNT, $args['host'], 'Could\'t given volume id.'));
            }
        }

        // load additional volume root options
        if (!empty($this->optionsNetVolumes['*'])) {
            $options = array_merge($this->optionsNetVolumes['*'], $options);
        }
        if (!empty($this->optionsNetVolumes[$protocol])) {
            $options = array_merge($this->optionsNetVolumes[$protocol], $options);
        }

        if (!$key = $volume->netMountKey) {
            $key = md5($protocol . '-' . serialize($options));
        }
        $options['netkey'] = $key;

        if (!isset($netVolumes[$key]) && $volume->mount($options)) {
            // call post-process function of netmount
            if (is_callable(array($volume, 'postNetmount'))) {
                $volume->postNetmount($options);
            }
            $options['driver'] = $driver;
            $netVolumes[$key] = $options;
            $this->saveNetVolumes($netVolumes);
            $rootstat = $volume->file($volume->root());
            $res = array('added' => array($rootstat));
            if ($toast) {
                $res['toast'] = $toast;
            }
            return $res;
        } else {
            $this->removeNetVolume(null, $volume);
            return array('error' => $this->error(self::ERROR_NETMOUNT, $args['host'], implode(' ', $volume->error())));
        }
    }

    /**
     * "Open" directory
     * Return array with following elements
     *  - cwd          - opened dir info
     *  - files        - opened dir content [and dirs tree if $args[tree]]
     *  - api          - api version (if $args[init])
     *  - uplMaxSize   - if $args[init]
     *  - error        - on failed
     *
     * @param  array  command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function open($args)
    {
        $target = $args['target'];
        $init = !empty($args['init']);
        $tree = !empty($args['tree']);
        $volume = $this->volume($target);
        $cwd = $volume ? $volume->dir($target) : false;
        $hash = $init ? 'default folder' : '#' . $target;
        $compare = '';

        // on init request we can get invalid dir hash -
        // dir which can not be opened now, but remembered by client,
        // so open default dir
        if ((!$cwd || !$cwd['read']) && $init) {
            $volume = $this->default;
            $target = $volume->defaultPath();
            $cwd = $volume->dir($target);
        }

        if (!$cwd) {
            return array('error' => $this->error(self::ERROR_OPEN, $hash, self::ERROR_DIR_NOT_FOUND));
        }
        if (!$cwd['read']) {
            return array('error' => $this->error(self::ERROR_OPEN, $hash, self::ERROR_PERM_DENIED));
        }

        $files = array();

        // get current working directory files list
        if (($ls = $volume->scandir($cwd['hash'])) === false) {
            return array('error' => $this->error(self::ERROR_OPEN, $cwd['name'], $volume->error()));
        }

        if (isset($cwd['dirs']) && $cwd['dirs'] != 1) {
            $cwd = $volume->dir($target);
        }

        // get other volume root
        if ($tree) {
            foreach ($this->volumes as $id => $v) {
                $files[] = $v->file($v->root());
            }
        }

        // long polling mode
        if ($args['compare']) {
            $sleep = max(1, (int)$volume->getOption('lsPlSleep'));
            $standby = (int)$volume->getOption('plStandby');
            if ($standby > 0 && $sleep > $standby) {
                $standby = $sleep;
            }
            $limit = max(0, floor($standby / $sleep)) + 1;
            do {
                elFinder::extendTimeLimit(30 + $sleep);
                $_mtime = 0;
                foreach ($ls as $_f) {
                    if (isset($_f['ts'])) {
                        $_mtime = max($_mtime, $_f['ts']);
                    }
                }
                $compare = strval(count($ls)) . ':' . strval($_mtime);
                if ($compare !== $args['compare']) {
                    break;
                }
                if (--$limit) {
                    sleep($sleep);
                    $volume->clearstatcache();
                    if (($ls = $volume->scandir($cwd['hash'])) === false) {
                        break;
                    }
                }
            } while ($limit);
            if ($ls === false) {
                return array('error' => $this->error(self::ERROR_OPEN, $cwd['name'], $volume->error()));
            }
        }

        if ($ls) {
            if ($files) {
                $files = array_merge($files, $ls);
            } else {
                $files = $ls;
            }
        }

        $result = array(
            'cwd' => $cwd,
            'options' => $volume->options($cwd['hash']),
            'files' => $files
        );

        if ($compare) {
            $result['cwd']['compare'] = $compare;
        }

        if (!empty($args['init'])) {
            $result['api'] = sprintf('%.1F%03d', self::$ApiVersion, self::$ApiRevision);
            $result['uplMaxSize'] = ini_get('upload_max_filesize');
            $result['uplMaxFile'] = ini_get('max_file_uploads');
            $result['netDrivers'] = array_keys(self::$netDrivers);
            $result['maxTargets'] = $this->maxTargets;
            if ($volume) {
                $result['cwd']['root'] = $volume->root();
            }
            if (elfinder::$textMimes) {
                $result['textMimes'] = elfinder::$textMimes;
            }
        }

        return $result;
    }

    /**
     * Return dir files names list
     *
     * @param  array  command arguments
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function ls($args)
    {
        $target = $args['target'];
        $intersect = isset($args['intersect']) ? $args['intersect'] : array();

        if (($volume = $this->volume($target)) == false
            || ($list = $volume->ls($target, $intersect)) === false) {
            return array('error' => $this->error(self::ERROR_OPEN, '#' . $target));
        }
        return array('list' => $list);
    }

    /**
     * Return subdirs for required directory
     *
     * @param  array  command arguments
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function tree($args)
    {
        $target = $args['target'];

        if (($volume = $this->volume($target)) == false
            || ($tree = $volume->tree($target)) == false) {
            return array('error' => $this->error(self::ERROR_OPEN, '#' . $target));
        }

        return array('tree' => $tree);
    }

    /**
     * Return parents dir for required directory
     *
     * @param  array  command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function parents($args)
    {
        $target = $args['target'];
        $until = $args['until'];

        if (($volume = $this->volume($target)) == false
            || ($tree = $volume->parents($target, false, $until)) == false) {
            return array('error' => $this->error(self::ERROR_OPEN, '#' . $target));
        }

        return array('tree' => $tree);
    }

    /**
     * Return new created thumbnails list
     *
     * @param  array  command arguments
     *
     * @return array
     * @throws ImagickException
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function tmb($args)
    {

        $result = array('images' => array());
        $targets = $args['targets'];

        foreach ($targets as $target) {
            elFinder::checkAborted();

            if (($volume = $this->volume($target)) != false
                && (($tmb = $volume->tmb($target)) != false)) {
                $result['images'][$target] = $tmb;
            }
        }
        return $result;
    }

    /**
     * Download files/folders as an archive file
     * 1st: Return srrsy contains download archive file info
     * 2nd: Return array contains opened file pointer, root itself and required headers
     *
     * @param  array  command arguments
     *
     * @return array
     * @throws Exception
     * @author Naoki Sawada
     */
    protected function zipdl($args)
    {
        $targets = $args['targets'];
        $download = !empty($args['download']);
        $h404 = 'HTTP/1.x 404 Not Found';
        $CriOS = isset($_SERVER['HTTP_USER_AGENT'])? (strpos($_SERVER['HTTP_USER_AGENT'], 'CriOS') !== false) : false;

        if (!$download) {
            //1st: Return array contains download archive file info
            $error = array(self::ERROR_ARCHIVE);
            if (($volume = $this->volume($targets[0])) !== false) {
                if ($dlres = $volume->zipdl($targets)) {
                    $path = $dlres['path'];
                    register_shutdown_function(array('elFinder', 'rmFileInDisconnected'), $path);
                    if (count($targets) === 1) {
                        $name = basename($volume->path($targets[0]));
                    } else {
                        $name = $dlres['prefix'] . '_Files';
                    }
                    $name .= '.' . $dlres['ext'];
                    $uniqid = uniqid();
					if(ZEND_THREAD_SAFE){
						set_transient("zipdl$uniqid", basename($path),MINUTE_IN_SECONDS);
					} else {
						$this->session->set('zipdl' . $uniqid, basename($path));
					}
                    $result = array(
                        'zipdl' => array(
                            'file' => $CriOS? basename($path) : $uniqid,
                            'name' => $name,
                            'mime' => $dlres['mime']
                        )
                    );
                    return $result;
                }
                $error = array_merge($error, $volume->error());
            }
            return array('error' => $error);
        } else {
            // 2nd: Return array contains opened file session key, root itself and required headers

            // Detect Chrome on iOS
            // It has access twice on downloading
            $CriOSinit = false;
            if ($CriOS) {
                $accept = isset($_SERVER['HTTP_ACCEPT'])? $_SERVER['HTTP_ACCEPT'] : '';
                if ($accept && $accept !== '*' && $accept !== '*/*') {
                    $CriOSinit = true;
                }
            }
            // data check
            if (count($targets) !== 4 || ($volume = $this->volume($targets[0])) == false || !($file = $CriOS? $targets[1] : ( ZEND_THREAD_SAFE ? get_transient( "zipdl$targets[1]" ) : $this->session->get('zipdl' . $targets[1] ) ) )) {
                return array('error' => 'File not found', 'header' => $h404, 'raw' => true);
            }
            $path = $volume->getTempPath() . DIRECTORY_SEPARATOR . basename($file);
            // remove session data of "zipdl..."
	        if(ZEND_THREAD_SAFE){
		        delete_transient("zipdl$targets[1]");
	        } else {
		        $this->session->remove('zipdl' . $targets[1]);
	        }
            if (!$CriOSinit) {
                // register auto delete on shutdown
                $GLOBALS['elFinderTempFiles'][$path] = true;
            }
            if ($volume->commandDisabled('zipdl')) {
                return array('error' => 'File not found', 'header' => $h404, 'raw' => true);
            }
            if (!is_readable($path) || !is_writable($path)) {
                return array('error' => 'File not found', 'header' => $h404, 'raw' => true);
            }
            // for HTTP headers
            $name = $targets[2];
            $mime = $targets[3];

            $filenameEncoded = rawurlencode($name);
            if (strpos($filenameEncoded, '%') === false) { // ASCII only
                $filename = 'filename="' . $name . '"';
            } else {
                $ua = $_SERVER['HTTP_USER_AGENT'];
                if (preg_match('/MSIE [4-8]/', $ua)) { // IE < 9 do not support RFC 6266 (RFC 2231/RFC 5987)
                    $filename = 'filename="' . $filenameEncoded . '"';
                } elseif (strpos($ua, 'Chrome') === false && strpos($ua, 'Safari') !== false && preg_match('#Version/[3-5]#', $ua)) { // Safari < 6
                    $filename = 'filename="' . str_replace('"', '', $name) . '"';
                } else { // RFC 6266 (RFC 2231/RFC 5987)
                    $filename = 'filename*=UTF-8\'\'' . $filenameEncoded;
                }
            }

            $fp = fopen($path, 'rb');
            $file = fstat($fp);
            $result = array(
                'pointer' => $fp,
                'header' => array(
                    'Content-Type: ' . $mime,
                    'Content-Disposition: attachment; ' . $filename,
                    'Content-Transfer-Encoding: binary',
                    'Content-Length: ' . $file['size'],
                    'Accept-Ranges: none',
                    'Connection: close'
                )
            );
            // add cache control headers
            if ($cacheHeaders = $volume->getOption('cacheHeaders')) {
                $result['header'] = array_merge($result['header'], $cacheHeaders);
            }
            return $result;
        }
    }

    /**
     * Required to output file in browser when volume URL is not set
     * Return array contains opened file pointer, root itself and required headers
     *
     * @param  array  command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function file($args)
    {
        $target = $args['target'];
        $download = !empty($args['download']);
        $onetime = !empty($args['onetime']);
        //$h304     = 'HTTP/1.1 304 Not Modified';
        $h403 = 'HTTP/1.0 403 Access Denied';
        $a403 = array('error' => 'Access Denied', 'header' => $h403, 'raw' => true);
        $h404 = 'HTTP/1.0 404 Not Found';
        $a404 = array('error' => 'File not found', 'header' => $h404, 'raw' => true);

        if ($onetime) {
            $volume = null;
            $tmpdir = elFinder::$commonTempPath;
            if (!$tmpdir || !is_file($tmpf = $tmpdir . DIRECTORY_SEPARATOR . 'ELF' . $target)) {
                return $a404;
            }
            $GLOBALS['elFinderTempFiles'][$tmpf] = true;
            if ($file = json_decode(file_get_contents($tmpf), true)) {
                $src = base64_decode($file['file']);
                if (!is_file($src) || !($fp = fopen($src, 'rb'))) {
                    return $a404;
                }
                if (strpos($src, $tmpdir) === 0) {
                    $GLOBALS['elFinderTempFiles'][$src] = true;
                }
                unset($file['file']);
                $file['read'] = true;
                $file['size'] = filesize($src);
            } else {
                return $a404;
            }
        } else {
            if (($volume = $this->volume($target)) == false) {
                return $a404;
            }

            if ($volume->commandDisabled('file')) {
                return $a403;
            }

            if (($file = $volume->file($target)) == false) {
                return $a404;
            }

            if (!$file['read']) {
                return $a404;
            }

            $opts = array();
            if (!empty($_SERVER['HTTP_RANGE'])) {
                $opts['httpheaders'] = array('Range: ' . $_SERVER['HTTP_RANGE']);
            }
            if (($fp = $volume->open($target, $opts)) == false) {
                return $a404;
            }
        }

        // check aborted by user
        elFinder::checkAborted();

        // allow change MIME type by 'file.pre' callback functions
        $mime = isset($args['mime']) ? $args['mime'] : $file['mime'];
        if ($download || $onetime) {
            $disp = 'attachment';
        } else {
            $dispInlineRegex = $volume->getOption('dispInlineRegex');
            $inlineRegex = false;
            if ($dispInlineRegex) {
                $inlineRegex = '#' . str_replace('#', '\\#', $dispInlineRegex) . '#';
                try {
                    preg_match($inlineRegex, '');
                } catch (Exception $e) {
                    $inlineRegex = false;
                }
            }
            if (!$inlineRegex) {
                $inlineRegex = '#^(?:(?:image|text)|application/x-shockwave-flash$)#';
            }
            $disp = preg_match($inlineRegex, $mime) ? 'inline' : 'attachment';
        }

        $filenameEncoded = rawurlencode($file['name']);
        if (strpos($filenameEncoded, '%') === false) { // ASCII only
            $filename = 'filename="' . $file['name'] . '"';
        } else {
            $ua = isset($_SERVER['HTTP_USER_AGENT'])? $_SERVER['HTTP_USER_AGENT'] : '';
            if (preg_match('/MSIE [4-8]/', $ua)) { // IE < 9 do not support RFC 6266 (RFC 2231/RFC 5987)
                $filename = 'filename="' . $filenameEncoded . '"';
            } elseif (strpos($ua, 'Chrome') === false && strpos($ua, 'Safari') !== false && preg_match('#Version/[3-5]#', $ua)) { // Safari < 6
                $filename = 'filename="' . str_replace('"', '', $file['name']) . '"';
            } else { // RFC 6266 (RFC 2231/RFC 5987)
                $filename = 'filename*=UTF-8\'\'' . $filenameEncoded;
            }
        }

        if ($args['cpath'] && $args['reqid']) {
            setcookie('elfdl' . $args['reqid'], '1', 0, $args['cpath']);
        }

        $result = array(
            'volume' => $volume,
            'pointer' => $fp,
            'info' => $file,
            'header' => array(
                'Content-Type: ' . $mime,
                'Content-Disposition: ' . $disp . '; ' . $filename,
                'Content-Transfer-Encoding: binary',
                'Content-Length: ' . $file['size'],
                'Last-Modified: ' . gmdate('D, d M Y H:i:s T', $file['ts']),
                'Connection: close'
            )
        );

        if (!$onetime) {
            // add cache control headers
            if ($cacheHeaders = $volume->getOption('cacheHeaders')) {
                $result['header'] = array_merge($result['header'], $cacheHeaders);
            }

            // check 'xsendfile'
            $xsendfile = $volume->getOption('xsendfile');
            $path = null;
            if ($xsendfile) {
                $info = stream_get_meta_data($fp);
                if ($path = empty($info['uri']) ? null : $info['uri']) {
                    $basePath = rtrim($volume->getOption('xsendfilePath'), DIRECTORY_SEPARATOR);
                    if ($basePath) {
                        $root = rtrim($volume->getRootPath(), DIRECTORY_SEPARATOR);
                        if (strpos($path, $root) === 0) {
                            $path = $basePath . substr($path, strlen($root));
                        } else {
                            $path = null;
                        }
                    }
                }
            }
            if ($path) {
                $result['header'][] = $xsendfile . ': ' . $path;
                $result['info']['xsendfile'] = $xsendfile;
            }
        }

        // add "Content-Location" if file has url data
        if (isset($file['url']) && $file['url'] && $file['url'] != 1) {
            $result['header'][] = 'Content-Location: ' . $file['url'];
        }
        return $result;
    }

    /**
     * Count total files size
     *
     * @param  array  command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function size($args)
    {
        $size = 0;
        $files = 0;
        $dirs = 0;
        $itemCount = true;
        $sizes = array();

        foreach ($args['targets'] as $target) {
            elFinder::checkAborted();
            if (($volume = $this->volume($target)) == false
                || ($file = $volume->file($target)) == false
                || !$file['read']) {
                return array('error' => $this->error(self::ERROR_OPEN, '#' . $target));
            }

            $volRes = $volume->size($target);
            if (is_array($volRes)) {
                $sizeInfo = array('size' => 0, 'fileCnt' => 0, 'dirCnt' => 0);
                if (!empty($volRes['size'])) {
                    $sizeInfo['size'] = $volRes['size'];
                    $size += $volRes['size'];
                }
                if (!empty($volRes['files'])) {
                    $sizeInfo['fileCnt'] = $volRes['files'];
                }
                if (!empty($volRes['dirs'])) {
                    $sizeInfo['dirCnt'] = $volRes['dirs'];
                }
                if ($itemCount) {
                    $files += $sizeInfo['fileCnt'];
                    $dirs += $sizeInfo['dirCnt'];
                }
                $sizes[$target] = $sizeInfo;
            } else if (is_numeric($volRes)) {
                $size += $volRes;
                $files = $dirs = 'unknown';
                $itemCount = false;
            }
        }
        return array('size' => $size, 'fileCnt' => $files, 'dirCnt' => $dirs, 'sizes' => $sizes);
    }

    /**
     * Create directory
     *
     * @param  array  command arguments
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function mkdir($args)
    {
        $target = $args['target'];
        $name = $args['name'];
        $dirs = $args['dirs'];
        if ($name === '' && !$dirs) {
            return array('error' => $this->error(self::ERROR_INV_PARAMS, 'mkdir'));
        }

        if (strpos($name,'..') !== false) {
            return array('error' => $this->error('Invalid request', 'mkdir'));
        }

        if (($volume = $this->volume($target)) == false) {
            return array('error' => $this->error(self::ERROR_MKDIR, $name, self::ERROR_TRGDIR_NOT_FOUND, '#' . $target));
        }
        if ($dirs) {
            $maxDirs = $volume->getOption('uploadMaxMkdirs');
            if ($maxDirs && $maxDirs < count($dirs)) {
                return array('error' => $this->error(self::ERROR_MAX_MKDIRS, $maxDirs));
            }
            sort($dirs);
            $reset = null;
            $mkdirs = array();
            foreach ($dirs as $dir) {
                if(strpos($dir,'..') !== false){
                    return array('error' => $this->error('Invalid request', 'mkdir'));
                }
                $tgt =& $mkdirs;
                $_names = explode('/', trim($dir, '/'));
                foreach ($_names as $_key => $_name) {
                    if (!isset($tgt[$_name])) {
                        $tgt[$_name] = array();
                    }
                    $tgt =& $tgt[$_name];
                }
                $tgt =& $reset;
            }
            $res = $this->ensureDirsRecursively($volume, $target, $mkdirs);
            $ret = array(
                'added' => $res['stats'],
                'hashes' => $res['hashes']
            );
            if ($res['error']) {
                $ret['warning'] = $this->error(self::ERROR_MKDIR, $res['error'][0], $volume->error());
            }
            return $ret;
        } else {
            return ($dir = $volume->mkdir($target, $name)) == false
                ? array('error' => $this->error(self::ERROR_MKDIR, $name, $volume->error()))
                : array('added' => array($dir));
        }
    }

    /**
     * Create empty file
     *
     * @param  array  command arguments
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function mkfile($args)
    {
        $target = $args['target'];
        $name = str_replace('..', '', $args['name']);

        if (($volume = $this->volume($target)) == false) {
            return array('error' => $this->error(self::ERROR_MKFILE, $name, self::ERROR_TRGDIR_NOT_FOUND, '#' . $target));
        }

        return ($file = $volume->mkfile($target, $name)) == false
            ? array('error' => $this->error(self::ERROR_MKFILE, $name, $volume->error()))
            : array('added' => array($file));
    }

    /**
     * Rename file, Accept multiple items >= API 2.1031
     *
     * @param  array $args
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Naoki Sawada
     */
    protected function rename($args)
    {
        $target = $args['target'];
        $name = $args['name'];
        $query = (!empty($args['q']) && strpos($args['q'], '*') !== false) ? $args['q'] : '';
        $targets = !empty($args['targets'])? $args['targets'] : false;
        $rms = array();
        $notfounds = array();
        $locked = array();
        $errs = array();
        $files = array();
        $removed = array();
        $res = array();
        $type = 'normal';

        if (!($volume = $this->volume($target))) {
            return array('error' => $this->error(self::ERROR_RENAME, '#' . $target, self::ERROR_FILE_NOT_FOUND));
        }

        if (strpos($name,'..') !== false) {
            return array('error' => $this->error('Invalid request', 'rename'));
        }

        if ($targets) {
            array_unshift($targets, $target);
            foreach ($targets as $h) {
                if ($rm = $volume->file($h)) {
                    if ($this->itemLocked($h)) {
                        $locked[] = $rm['name'];
                    } else {
                        $rm['realpath'] = $volume->realpath($h);
                        $rms[] = $rm;
                    }
                } else {
                    $notfounds[] = '#' . $h;
                }
            }
            if (!$rms) {
                $res['error'] = array();
                if ($notfounds) {
                    $res['error'] = array(self::ERROR_RENAME, join(', ', $notfounds), self::ERROR_FILE_NOT_FOUND);
                }
                if ($locked) {
                    array_push($res['error'], self::ERROR_LOCKED, join(', ', $locked));
                }
                return $res;
            }

            $res['warning'] = array();
            if ($notfounds) {
                array_push($res['warning'], self::ERROR_RENAME, join(', ', $notfounds), self::ERROR_FILE_NOT_FOUND);
            }
            if ($locked) {
                array_push($res['warning'], self::ERROR_LOCKED, join(', ', $locked));
            }

            if ($query) {
                // batch rename
                $splits = elFinder::splitFileExtention($query);
                if ($splits[1] && $splits[0] === '*') {
                    $type = 'extention';
                    $name = $splits[1];
                } else if (strlen($splits[0]) > 1) {
                    if (substr($splits[0], -1) === '*') {
                        $type = 'prefix';
                        $name = substr($splits[0], 0, strlen($splits[0]) - 1);
                    } else if (substr($splits[0], 0, 1) === '*') {
                        $type = 'suffix';
                        $name = substr($splits[0], 1);
                    }
                }
                if ($type !== 'normal') {
                    if (!empty($this->listeners['rename.pre'])) {
                        $_args = array('name' => $name);
                        foreach ($this->listeners['rename.pre'] as $handler) {
                            $_res = call_user_func_array($handler, array('rename', &$_args, $this, $volume));
                            if (!empty($_res['preventexec'])) {
                                break;
                            }
                        }
                        $name = $_args['name'];
                    }
                }
            }
            foreach ($rms as $rm) {
                if ($type === 'normal') {
                    $rname = $volume->uniqueName($volume->realpath($rm['phash']), $name, '', false);
                } else {
                    $rname = $name;
                    if ($type === 'extention') {
                        $splits = elFinder::splitFileExtention($rm['name']);
                        $rname = $splits[0] . '.' . $name;
                    } else if ($type === 'prefix') {
                        $rname = $name . $rm['name'];
                    } else if ($type === 'suffix') {
                        $splits = elFinder::splitFileExtention($rm['name']);
                        $rname = $splits[0] . $name . ($splits[1] ? ('.' . $splits[1]) : '');
                    }
                    $rname = $volume->uniqueName($volume->realpath($rm['phash']), $rname, '', true);
                }
                if ($file = $volume->rename($rm['hash'], $rname)) {
                    $files[] = $file;
                    $removed[] = $rm;
                } else {
                    $errs[] = $rm['name'];
                }
            }

            if (!$files) {
                $res['error'] = $this->error(self::ERROR_RENAME, join(', ', $errs), $volume->error());
                if (!$res['warning']) {
                    unset($res['warning']);
                }
                return $res;
            }
            if ($errs) {
                array_push($res['warning'], self::ERROR_RENAME, join(', ', $errs), $volume->error());
            }
            if (!$res['warning']) {
                unset($res['warning']);
            }
            $res['added'] = $files;
            $res['removed'] = $removed;
            return $res;
        } else {
            if (!($rm = $volume->file($target))) {
                return array('error' => $this->error(self::ERROR_RENAME, '#' . $target, self::ERROR_FILE_NOT_FOUND));
            }
            if ($this->itemLocked($target)) {
                return array('error' => $this->error(self::ERROR_LOCKED, $rm['name']));
            }
            $rm['realpath'] = $volume->realpath($target);

            $file = $volume->rename($target, $name);
            if ($file === false) {
                return array('error' => $this->error(self::ERROR_RENAME, $rm['name'], $volume->error()));
            } else {
                if ($file['hash'] !== $rm['hash']) {
                    return array('added' => array($file), 'removed' => array($rm));
                } else {
                    return array('changed' => array($file));
                }
            }
        }
    }

    /**
     * Duplicate file - create copy with "copy %d" suffix
     *
     * @param array $args command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function duplicate($args)
    {
        $targets = is_array($args['targets']) ? $args['targets'] : array();
        $result = array();
        $suffix = empty($args['suffix']) ? 'copy' : $args['suffix'];

        $this->itemLock($targets);

        foreach ($targets as $target) {
            elFinder::checkAborted();

            if (($volume = $this->volume($target)) == false
                || ($src = $volume->file($target)) == false) {
                $result['warning'] = $this->error(self::ERROR_COPY, '#' . $target, self::ERROR_FILE_NOT_FOUND);
                break;
            }

            if (($file = $volume->duplicate($target, $suffix)) == false) {
                $result['warning'] = $this->error($volume->error());
                break;
            }
        }

        return $result;
    }

    /**
     * Remove dirs/files
     *
     * @param array  command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function rm($args)
    {
        $targets = is_array($args['targets']) ? $args['targets'] : array();
        $result = array('removed' => array());

        foreach ($targets as $target) {
            elFinder::checkAborted();

            if (($volume = $this->volume($target)) == false) {
                $result['warning'] = $this->error(self::ERROR_RM, '#' . $target, self::ERROR_FILE_NOT_FOUND);
                break;
            }

            if ($this->itemLocked($target)) {
                $rm = $volume->file($target);
                $result['warning'] = $this->error(self::ERROR_LOCKED, $rm['name']);
                break;
            }

            if (!$volume->rm($target)) {
                $result['warning'] = $this->error($volume->error());
                break;
            }
        }

        return $result;
    }

    /**
     * Return has subdirs
     *
     * @param  array  command arguments
     *
     * @return array
     * @author Dmitry Naoki Sawada
     **/
    protected function subdirs($args)
    {

        $result = array('subdirs' => array());
        $targets = $args['targets'];

        foreach ($targets as $target) {
            if (($volume = $this->volume($target)) !== false) {
                $result['subdirs'][$target] = $volume->subdirs($target) ? 1 : 0;
            }
        }
        return $result;
    }

    /**
     * Gateway for custom contents editor
     *
     * @param  array $args command arguments
     *
     * @return array
     * @author Naoki Sawada
     */
    protected function editor($args = array())
    {
        /* @var elFinderEditor $editor */
        $name = $args['name'];
        if (is_array($name)) {
            $res = array();
            foreach ($name as $c) {
                $class = 'elFinderEditor' . $c;
                if (class_exists($class)) {
                    $editor = new $class($this, $args['args']);
                    $res[$c] = $editor->enabled();
                } else {
                    $res[$c] = 0;
                }
            }
            return $res;
        } else {
            $class = 'elFinderEditor' . $name;
            $method = '';
            if (class_exists($class)) {
                $editor = new $class($this, $args['args']);
                $method = $args['method'];
                if ($editor->isAllowedMethod($method) && method_exists($editor, $method)) {
                    return $editor->$method();
                }
            }
            return array('error', $this->error(self::ERROR_UNKNOWN_CMD, 'editor.' . $name . '.' . $method));
        }
    }

    /**
     * Abort current request and make flag file to running check
     *
     * @param array $args
     *
     * @return void
     */
    protected function abort($args = array())
    {
        if (!elFinder::$connectionFlagsPath || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
            return;
        }
        $flagFile = elFinder::$connectionFlagsPath . DIRECTORY_SEPARATOR . 'elfreq%s';
        if (!empty($args['makeFile'])) {
            self::$abortCheckFile = sprintf($flagFile, self::filenameDecontaminate($args['makeFile']));
            touch(self::$abortCheckFile);
            $GLOBALS['elFinderAbortFiles'][self::$abortCheckFile] = true;
            return;
        }

        $file = !empty($args['id']) ? sprintf($flagFile, self::filenameDecontaminate($args['id'])) : self::$abortCheckFile;
        $file && is_file($file) && unlink($file);
    }

    /**
     * Validate an URL to prevent SSRF attacks.
     *
     * To prevent any risk of DNS rebinding, always use the IP address resolved by
     * this method, as returned in the array entry `ip`.
     *
     * @param string $url
     *
     * @return false|array
     */
    protected function validate_address($url)
    {
        $info = parse_url($url);
        $host = trim(strtolower($info['host']), '.');
        // do not support IPv6 address
        if (preg_match('/^\[.*\]$/', $host)) {
            return false;
        }
        // do not support non dot host
        if (strpos($host, '.') === false) {
            return false;
        }
        // do not support URL-encoded host
        if (strpos($host, '%') !== false) {
            return false;
        }
        // disallow including "localhost" and "localdomain"
        if (preg_match('/\b(?:localhost|localdomain)\b/', $host)) {
            return false;
        }
        // check IPv4 local loopback, private network and link local
        $ip = gethostbyname($host);
        if (preg_match('/^0x[0-9a-f]+|[0-9]+(?:\.(?:0x[0-9a-f]+|[0-9]+)){1,3}$/', $ip, $m)) {
            $long = (int)sprintf('%u', ip2long($ip));
            if (!$long) {
                return false;
            }
            $local = (int)sprintf('%u', ip2long('127.255.255.255')) >> 24;
            $prv1  = (int)sprintf('%u', ip2long('10.255.255.255')) >> 24;
            $prv2  = (int)sprintf('%u', ip2long('172.31.255.255')) >> 20;
            $prv3  = (int)sprintf('%u', ip2long('192.168.255.255')) >> 16;
            $link  = (int)sprintf('%u', ip2long('169.254.255.255')) >> 16;

            if (!isset($this->uploadAllowedLanIpClasses['local']) && $long >> 24 === $local) {
                return false;
            }
            if (!isset($this->uploadAllowedLanIpClasses['private_a']) && $long >> 24 === $prv1) {
                return false;
            }
            if (!isset($this->uploadAllowedLanIpClasses['private_b']) && $long >> 20 === $prv2) {
                return false;
            }
            if (!isset($this->uploadAllowedLanIpClasses['private_c']) && $long >> 16 === $prv3) {
                return false;
            }
            if (!isset($this->uploadAllowedLanIpClasses['link']) && $long >> 16 === $link) {
                return false;
            }
            $info['ip'] = long2ip($long);
            if (!isset($info['port'])) {
                $info['port'] = $info['scheme'] === 'https' ? 443 : 80;
            }
            if (!isset($info['path'])) {
                $info['path'] = '/';
            }
            return $info;
        } else {
            return false;
        }
    }

    /**
     * Get remote contents
     *
     * @param  string   $url          target url
     * @param  int      $timeout      timeout (sec)
     * @param  int      $redirect_max redirect max count
     * @param  string   $ua
     * @param  resource $fp
     *
     * @return string, resource or bool(false)
     * @retval  string contents
     * @retval  resource conttents
     * @rettval false  error
     * @author  Naoki Sawada
     **/
    protected function get_remote_contents(&$url, $timeout = 30, $redirect_max = 5, $ua = 'Mozilla/5.0', $fp = null)
    {
        if (preg_match('~^(?:ht|f)tps?://[-_.!\~*\'()a-z0-9;/?:\@&=+\$,%#\*\[\]]+~i', $url)) {
            $info = $this->validate_address($url);
            if ($info === false) {
                return false;
            }
            // dose not support 'user' and 'pass' for security reasons
            $url = $info['scheme'].'://'.$info['host'].(!empty($info['port'])? (':'.$info['port']) : '').$info['path'].(!empty($info['query'])? ('?'.$info['query']) : '').(!empty($info['fragment'])? ('#'.$info['fragment']) : '');
            // check by URL upload filter
            if ($this->urlUploadFilter && is_callable($this->urlUploadFilter)) {
                if (!call_user_func_array($this->urlUploadFilter, array($url, $this))) {
                    return false;
                }
            }
            $method = (function_exists('curl_exec')) ? 'curl_get_contents' : 'fsock_get_contents';
            return $this->$method($url, $timeout, $redirect_max, $ua, $fp, $info);
        }
        return false;
    }

    /**
     * Get remote contents with cURL
     *
     * @param  string   $url          target url
     * @param  int      $timeout      timeout (sec)
     * @param  int      $redirect_max redirect max count
     * @param  string   $ua
     * @param  resource $outfp
     *
     * @return string, resource or bool(false)
     * @retval string contents
     * @retval resource conttents
     * @retval false  error
     * @author Naoki Sawada
     **/
    protected function curl_get_contents(&$url, $timeout, $redirect_max, $ua, $outfp, $info)
    {
        if ($redirect_max == 0) {
            return false;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        if ($outfp) {
            curl_setopt($ch, CURLOPT_FILE, $outfp);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        curl_setopt($ch, CURLOPT_RESOLVE, array($info['host'] . ':' . $info['port'] . ':' . $info['ip']));
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http_code == 301 || $http_code == 302) {
            $new_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $info = $this->validate_address($new_url);
            if ($info === false) {
                return false;
            }
            return $this->curl_get_contents($new_url, $timeout, $redirect_max - 1, $ua, $outfp, $info);
        }
        curl_close($ch);
        return $outfp ? $outfp : $result;
    }

    /**
     * Get remote contents with fsockopen()
     *
     * @param  string   $url          url
     * @param  int      $timeout      timeout (sec)
     * @param  int      $redirect_max redirect max count
     * @param  string   $ua
     * @param  resource $outfp
     *
     * @return string, resource or bool(false)
     * @retval string contents
     * @retval resource conttents
     * @retval false  error
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    protected function fsock_get_contents(&$url, $timeout, $redirect_max, $ua, $outfp, $info)
    {
        $connect_timeout = 3;
        $connect_try = 3;
        $method = 'GET';
        $readsize = 4096;
        $ssl = '';

        $getSize = null;
        $headers = '';

        $arr = $info;
        if ($arr['scheme'] === 'https') {
            $ssl = 'ssl://';
        }

        // query
        $arr['query'] = isset($arr['query']) ? '?' . $arr['query'] : '';

        $url_base = $arr['scheme'] . '://' . $info['host'] . ':' . $info['port'];
        $url_path = isset($arr['path']) ? $arr['path'] : '/';
        $uri = $url_path . $arr['query'];

        $query = $method . ' ' . $uri . " HTTP/1.0\r\n";
        $query .= "Host: " . $arr['host'] . "\r\n";
        $query .= "Accept: */*\r\n";
        $query .= "Connection: close\r\n";
        if (!empty($ua)) $query .= "User-Agent: " . $ua . "\r\n";
        if (!is_null($getSize)) $query .= 'Range: bytes=0-' . ($getSize - 1) . "\r\n";

        $query .= $headers;

        $query .= "\r\n";

        $fp = $connect_try_count = 0;
        while (!$fp && $connect_try_count < $connect_try) {

            $errno = 0;
            $errstr = "";
            $fp = fsockopen(
                $ssl . $arr['host'],
                $arr['port'],
                $errno, $errstr, $connect_timeout);
            if ($fp) break;
            $connect_try_count++;
            if (connection_aborted()) {
                throw new elFinderAbortException();
            }
            sleep(1); // wait 1sec
        }

        if (!$fp) {
            return false;
        }

        $fwrite = 0;
        for ($written = 0; $written < strlen($query); $written += $fwrite) {
            $fwrite = fwrite($fp, substr($query, $written));
            if (!$fwrite) {
                break;
            }
        }

        if ($timeout) {
            socket_set_timeout($fp, $timeout);
        }

        $_response = '';
        $header = '';
        while ($_response !== "\r\n") {
            $_response = fgets($fp, $readsize);
            $header .= $_response;
        };

        $rccd = array_pad(explode(' ', $header, 2), 2, ''); // array('HTTP/1.1','200')
        $rc = (int)$rccd[1];

        $ret = false;
        // Redirect
        switch ($rc) {
            case 307: // Temporary Redirect
            case 303: // See Other
            case 302: // Moved Temporarily
            case 301: // Moved Permanently
                $matches = array();
                if (preg_match('/^Location: (.+?)(#.+)?$/im', $header, $matches) && --$redirect_max > 0) {
                    $_url = $url;
                    $url = trim($matches[1]);
                    if (!preg_match('/^https?:\//', $url)) { // no scheme
                        if ($url[0] != '/') { // Relative path
                            // to Absolute path
                            $url = substr($url_path, 0, strrpos($url_path, '/')) . '/' . $url;
                        }
                        // add sheme,host
                        $url = $url_base . $url;
                    }
                    if ($_url === $url) {
                        sleep(1);
                    }
                    fclose($fp);
                    $info = $this->validate_address($url);
                    if ($info === false) {
                        return false;
                    }
                    return $this->fsock_get_contents($url, $timeout, $redirect_max, $ua, $outfp, $info);
                }
                break;
            case 200:
                $ret = true;
        }
        if (!$ret) {
            fclose($fp);
            return false;
        }

        $body = '';
        if (!$outfp) {
            $outfp = fopen('php://temp', 'rwb');
            $body = true;
        }
        while (fwrite($outfp, fread($fp, $readsize))) {
            if ($timeout) {
                $_status = socket_get_status($fp);
                if ($_status['timed_out']) {
                    fclose($outfp);
                    fclose($fp);
                    return false; // Request Time-out
                }
            }
        }
        if ($body) {
            rewind($outfp);
            $body = stream_get_contents($outfp);
            fclose($outfp);
            $outfp = null;
        }

        fclose($fp);

        return $outfp ? $outfp : $body; // Data
    }

    /**
     * Parse Data URI scheme
     *
     * @param  string $str
     * @param  array  $extTable
     * @param  array  $args
     *
     * @return array
     * @author Naoki Sawada
     */
    protected function parse_data_scheme($str, $extTable, $args = null)
    {
        $data = $name = $mime = '';
        // Scheme 'data://' require `allow_url_fopen` and `allow_url_include`
        if ($fp = fopen('data://' . substr($str, 5), 'rb')) {
            if ($data = stream_get_contents($fp)) {
                $meta = stream_get_meta_data($fp);
                $mime = $meta['mediatype'];
            }
            fclose($fp);
        } else if (preg_match('~^data:(.+?/.+?)?(?:;charset=.+?)?;base64,~', substr($str, 0, 128), $m)) {
            $data = base64_decode(substr($str, strlen($m[0])));
            if ($m[1]) {
                $mime = $m[1];
            }
        }
        if ($data) {
            $ext = ($mime && isset($extTable[$mime])) ? '.' . $extTable[$mime] : '';
            // Set name if name eq 'image.png' and $args has 'name' array, e.g. clipboard data
            if (is_array($args['name']) && isset($args['name'][0])) {
                $name = $args['name'][0];
                if ($ext) {
                    $name = preg_replace('/\.[^.]*$/', '', $name);
                }
            } else {
                $name = substr(md5($data), 0, 8);
            }
            $name .= $ext;
        } else {
            $data = $name = '';
        }
        return array($data, $name);
    }

    /**
     * Detect file MIME Type by local path
     *
     * @param  string $path Local path
     *
     * @return string file MIME Type
     * @author Naoki Sawada
     */
    protected function detectMimeType($path)
    {
        static $type, $finfo;
        if (!$type) {
            if (class_exists('finfo', false)) {
                $tmpFileInfo = explode(';', finfo_file(finfo_open(FILEINFO_MIME), __FILE__));
            } else {
                $tmpFileInfo = false;
            }
            $regexp = '/text\/x\-(php|c\+\+)/';
            if ($tmpFileInfo && preg_match($regexp, array_shift($tmpFileInfo))) {
                $type = 'finfo';
                $finfo = finfo_open(FILEINFO_MIME);
            } elseif (function_exists('mime_content_type')
                && ($_ctypes = explode(';', mime_content_type(__FILE__)))
                && preg_match($regexp, array_shift($_ctypes))) {
                $type = 'mime_content_type';
            } elseif (function_exists('getimagesize')) {
                $type = 'getimagesize';
            } else {
                $type = 'none';
            }
        }

        $mime = '';
        if ($type === 'finfo') {
            $mime = finfo_file($finfo, $path);
        } elseif ($type === 'mime_content_type') {
            $mime = mime_content_type($path);
        } elseif ($type === 'getimagesize') {
            if ($img = getimagesize($path)) {
                $mime = $img['mime'];
            }
        }

        if ($mime) {
            $mime = explode(';', $mime);
            $mime = trim($mime[0]);

            if (in_array($mime, array('application/x-empty', 'inode/x-empty'))) {
                // finfo return this mime for empty files
                $mime = 'text/plain';
            } elseif ($mime == 'application/x-zip') {
                // http://elrte.org/redmine/issues/163
                $mime = 'application/zip';
            }
        }

        return $mime ? $mime : 'unknown';
    }

    /**
     * Detect file type extension by local path
     *
     * @param  object $volume elFinderVolumeDriver instance
     * @param  string $path   Local path
     * @param  string $name   Filename to save
     *
     * @return string file type extension with dot
     * @author Naoki Sawada
     */
    protected function detectFileExtension($volume, $path, $name)
    {
        $mime = $this->detectMimeType($path);
        if ($mime === 'unknown') {
            $mime = 'application/octet-stream';
        }
        $ext = $volume->getExtentionByMime($volume->mimeTypeNormalize($mime, $name));
        return $ext ? ('.' . $ext) : '';
    }

    /**
     * Get temporary directory path
     *
     * @param  string $volumeTempPath
     *
     * @return string
     * @author Naoki Sawada
     */
    private function getTempDir($volumeTempPath = null)
    {
        $testDirs = array();
        if ($this->uploadTempPath) {
            $testDirs[] = rtrim(realpath($this->uploadTempPath), DIRECTORY_SEPARATOR);
        }
        if ($volumeTempPath) {
            $testDirs[] = rtrim(realpath($volumeTempPath), DIRECTORY_SEPARATOR);
        }
        if (elFinder::$commonTempPath) {
            $testDirs[] = elFinder::$commonTempPath;
        }
        $tempDir = '';
        foreach ($testDirs as $testDir) {
            if (!$testDir || !is_dir($testDir)) continue;
            if (is_writable($testDir)) {
                $tempDir = $testDir;
                $gc = time() - 3600;
                foreach (glob($tempDir . DIRECTORY_SEPARATOR . 'ELF*') as $cf) {
                    if (filemtime($cf) < $gc) {
                        unlink($cf);
                    }
                }
                break;
            }
        }
        return $tempDir;
    }

    /**
     * chmod
     *
     * @param array  command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author David Bartle
     */
    protected function chmod($args)
    {
        $targets = $args['targets'];
        $mode = intval((string)$args['mode'], 8);

        if (!is_array($targets)) {
            $targets = array($targets);
        }

        $result = array();

        if (($volume = $this->volume($targets[0])) == false) {
            $result['error'] = $this->error(self::ERROR_CONF_NO_VOL);
            return $result;
        }

        $this->itemLock($targets);

        $files = array();
        $errors = array();
        foreach ($targets as $target) {
            elFinder::checkAborted();

            $file = $volume->chmod($target, $mode);
            if ($file) {
                $files = array_merge($files, is_array($file) ? $file : array($file));
            } else {
                $errors = array_merge($errors, $volume->error());
            }
        }

        if ($files) {
            $result['changed'] = $files;
            if ($errors) {
                $result['warning'] = $this->error($errors);
            }
        } else {
            $result['error'] = $this->error($errors);
        }

        return $result;
    }

    /**
     * Check chunked upload files
     *
     * @param string $tmpname uploaded temporary file path
     * @param string $chunk   uploaded chunk file name
     * @param string $cid     uploaded chunked file id
     * @param string $tempDir temporary dirctroy path
     * @param null   $volume
     *
     * @return array|null
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    private function checkChunkedFile($tmpname, $chunk, $cid, $tempDir, $volume = null)
    {
        /* @var elFinderVolumeDriver $volume */
        if (preg_match('/^(.+)(\.\d+_(\d+))\.part$/s', $chunk, $m)) {
            $fname = $m[1];
            $encname = md5($cid . '_' . $fname);
            $base = $tempDir . DIRECTORY_SEPARATOR . 'ELF' . $encname;
            $clast = intval($m[3]);
            if (is_null($tmpname)) {
                ignore_user_abort(true);
                // chunked file upload fail
                foreach (glob($base . '*') as $cf) {
                    unlink($cf);
                }
                ignore_user_abort(false);
                return null;
            }

            $range = isset($_POST['range']) ? trim($_POST['range']) : '';
            if ($range && preg_match('/^(\d+),(\d+),(\d+)$/', $range, $ranges)) {
                $start = $ranges[1];
                $len = $ranges[2];
                $size = $ranges[3];
                $tmp = $base . '.part';
                $csize = filesize($tmpname);

                $tmpExists = is_file($tmp);
                if (!$tmpExists) {
                    // check upload max size
                    $uploadMaxSize = $volume ? $volume->getUploadMaxSize() : 0;
                    if ($uploadMaxSize > 0 && $size > $uploadMaxSize) {
                        return array(self::ERROR_UPLOAD_FILE_SIZE, false);
                    }
                    // make temp file
                    $ok = false;
                    if ($fp = fopen($tmp, 'wb')) {
                        flock($fp, LOCK_EX);
                        $ok = ftruncate($fp, $size);
                        flock($fp, LOCK_UN);
                        fclose($fp);
                        touch($base);
                    }
                    if (!$ok) {
                        unlink($tmp);
                        return array(self::ERROR_UPLOAD_TEMP, false);
                    }
                } else {
                    // wait until makeing temp file (for anothor session)
                    $cnt = 1200; // Time limit 120 sec
                    while (!is_file($base) && --$cnt) {
                        usleep(100000); // wait 100ms
                    }
                    if (!$cnt) {
                        return array(self::ERROR_UPLOAD_TEMP, false);
                    }
                }

                // check size info
                if ($len != $csize || $start + $len > $size || ($tmpExists && $size != filesize($tmp))) {
                    return array(self::ERROR_UPLOAD_TEMP, false);
                }

                // write chunk data
                $src = fopen($tmpname, 'rb');
                $fp = fopen($tmp, 'cb');
                fseek($fp, $start);
                $writelen = stream_copy_to_stream($src, $fp, $len);
                fclose($fp);
                fclose($src);

                try {
                    // to check connection is aborted
                    elFinder::checkAborted();
                } catch (elFinderAbortException $e) {
                    unlink($tmpname);
                    is_file($tmp) && unlink($tmp);
                    is_file($base) && unlink($base);
                    throw $e;
                }

                if ($writelen != $len) {
                    return array(self::ERROR_UPLOAD_TEMP, false);
                }

                // write counts
                file_put_contents($base, "\0", FILE_APPEND | LOCK_EX);

                if (filesize($base) >= $clast + 1) {
                    // Completion
                    unlink($base);
                    return array($tmp, $fname);
                }
            } else {
                // old way
                $part = $base . $m[2];
                if (move_uploaded_file($tmpname, $part)) {
                    chmod($part, 0600);
                    if ($clast < count(glob($base . '*'))) {
                        $parts = array();
                        for ($i = 0; $i <= $clast; $i++) {
                            $name = $base . '.' . $i . '_' . $clast;
                            if (is_readable($name)) {
                                $parts[] = $name;
                            } else {
                                $parts = null;
                                break;
                            }
                        }
                        if ($parts) {
                            if (!is_file($base)) {
                                touch($base);
                                if ($resfile = tempnam($tempDir, 'ELF')) {
                                    $target = fopen($resfile, 'wb');
                                    foreach ($parts as $f) {
                                        $fp = fopen($f, 'rb');
                                        while (!feof($fp)) {
                                            fwrite($target, fread($fp, 8192));
                                        }
                                        fclose($fp);
                                        unlink($f);
                                    }
                                    fclose($target);
                                    unlink($base);
                                    return array($resfile, $fname);
                                }
                                unlink($base);
                            }
                        }
                    }
                }
            }
        }
        return array('', '');
    }

    /**
     * Save uploaded files
     *
     * @param  array
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function upload($args)
    {
        $ngReg = '/[\/\\?*:|"<>]/';
        $target = $args['target'];
        $volume = $this->volume($target);
        $files = isset($args['FILES']['upload']) && is_array($args['FILES']['upload']) ? $args['FILES']['upload'] : array();
        $header = empty($args['html']) ? array() : array('header' => 'Content-Type: text/html; charset=utf-8');
        $result = array_merge(array('added' => array()), $header);
        $paths = $args['upload_path'] ? $args['upload_path'] : array();
        $chunk = $args['chunk'] ? $args['chunk'] : '';
        $cid = $args['cid'] ? (int)$args['cid'] : '';
        $mtimes = $args['mtime'] ? $args['mtime'] : array();
        $tmpfname = '';

        if (!$volume) {
            return array_merge(array('error' => $this->error(self::ERROR_UPLOAD, self::ERROR_TRGDIR_NOT_FOUND, '#' . $target)), $header);
        }

        // check $chunk
        if (strpos($chunk, '/') !== false || strpos($chunk, '\\') !== false) {
            return array('error' => $this->error(self::ERROR_UPLOAD));
        }

        if ($args['overwrite'] !== '') {
            $volume->setUploadOverwrite($args['overwrite']);
        }

        $renames = $hashes = array();
        $suffix = '~';
        if ($args['renames'] && is_array($args['renames'])) {
            $renames = array_flip($args['renames']);
            if (is_string($args['suffix']) && !preg_match($ngReg, $args['suffix'])) {
                $suffix = $args['suffix'];
            }
        }
        if ($args['hashes'] && is_array($args['hashes'])) {
            $hashes = array_flip($args['hashes']);
        }

        $this->itemLock($target);

        // file extentions table by MIME
        $extTable = array_flip(array_unique($volume->getMimeTable()));

        if (empty($files)) {
            if (isset($args['upload']) && is_array($args['upload']) && ($tempDir = $this->getTempDir($volume->getTempPath()))) {
                $names = array();
                foreach ($args['upload'] as $i => $url) {
                    // check chunked file upload commit
                    if ($chunk) {
                        if ($url === 'chunkfail' && $args['mimes'] === 'chunkfail') {
                            $this->checkChunkedFile(null, $chunk, $cid, $tempDir);
                            if (preg_match('/^(.+)(\.\d+_(\d+))\.part$/s', $chunk, $m)) {
                                $result['warning'] = $this->error(self::ERROR_UPLOAD_FILE, $m[1], self::ERROR_UPLOAD_TEMP);
                            }
                            return $result;
                        } else {
                            $tmpfname = $tempDir . '/' . $chunk;
                            $files['tmp_name'][$i] = $tmpfname;
                            $files['name'][$i] = $url;
                            $files['error'][$i] = 0;
                            $GLOBALS['elFinderTempFiles'][$tmpfname] = true;
                            break;
                        }
                    }

                    $tmpfname = $tempDir . DIRECTORY_SEPARATOR . 'ELF_FATCH_' . md5($url . microtime(true));
                    $GLOBALS['elFinderTempFiles'][$tmpfname] = true;

                    $_name = '';
                    // check is data:
                    if (substr($url, 0, 5) === 'data:') {
                        list($data, $args['name'][$i]) = $this->parse_data_scheme($url, $extTable, $args);
                    } else {
                        $fp = fopen($tmpfname, 'wb');
                        if ($data = $this->get_remote_contents($url, 30, 5, 'Mozilla/5.0', $fp)) {
                            // to check connection is aborted
                            try {
                                elFinder::checkAborted();
                            } catch(elFinderAbortException $e) {
                                fclose($fp);
                                throw $e;
                            }
                            if (strpos($url, '%') !== false) {
                                $url = rawurldecode($url);
                            }
                            if (is_callable('mb_convert_encoding') && is_callable('mb_detect_encoding')) {
                                $url = mb_convert_encoding($url, 'UTF-8', mb_detect_encoding($url));
                            }
                            $url = iconv('UTF-8', 'UTF-8//IGNORE', $url);
                            $_name = preg_replace('~^.*?([^/#?]+)(?:\?.*)?(?:#.*)?$~', '$1', $url);
                            // Check `Content-Disposition` response header
                            if (($headers = get_headers($url, true)) && !empty($headers['Content-Disposition'])) {
                                if (preg_match('/filename\*=(?:([a-zA-Z0-9_-]+?)\'\')"?([a-z0-9_.~%-]+)"?/i', $headers['Content-Disposition'], $m)) {
                                    $_name = rawurldecode($m[2]);
                                    if ($m[1] && strtoupper($m[1]) !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                                        $_name = mb_convert_encoding($_name, 'UTF-8', $m[1]);
                                    }
                                } else if (preg_match('/filename="?([ a-z0-9_.~%-]+)"?/i', $headers['Content-Disposition'], $m)) {
                                    $_name = rawurldecode($m[1]);
                                }
                            }
                        } else {
                            fclose($fp);
                        }
                    }
                    if ($data) {
                        if (isset($args['name'][$i])) {
                            $_name = $args['name'][$i];
                        }
                        if ($_name) {
                            $_ext = '';
                            if (preg_match('/(\.[a-z0-9]{1,7})$/', $_name, $_match)) {
                                $_ext = $_match[1];
                            }
                            if ((is_resource($data) && fclose($data)) || file_put_contents($tmpfname, $data)) {
                                $GLOBALS['elFinderTempFiles'][$tmpfname] = true;
                                $_name = preg_replace($ngReg, '_', $_name);
                                list($_a, $_b) = array_pad(explode('.', $_name, 2), 2, '');
                                if ($_b === '') {
                                    if ($_ext) {
                                        rename($tmpfname, $tmpfname . $_ext);
                                        $tmpfname = $tmpfname . $_ext;
                                    }
                                    $_b = $this->detectFileExtension($volume, $tmpfname, $_name);
                                    $_name = $_a . $_b;
                                } else {
                                    $_b = '.' . $_b;
                                }
                                if (isset($names[$_name])) {
                                    $_name = $_a . '_' . $names[$_name]++ . $_b;
                                } else {
                                    $names[$_name] = 1;
                                }
                                $files['tmp_name'][$i] = $tmpfname;
                                $files['name'][$i] = $_name;
                                $files['error'][$i] = 0;
                                // set to auto rename
                                $volume->setUploadOverwrite(false);
                            } else {
                                unlink($tmpfname);
                            }
                        }
                    }
                }
            }
            if (empty($files)) {
                return array_merge(array('error' => $this->error(self::ERROR_UPLOAD, self::ERROR_UPLOAD_NO_FILES)), $header);
            }
        }

        $addedDirs = array();
        $errors = array();
        foreach ($files['name'] as $i => $name) {
            if (($error = $files['error'][$i]) > 0) {
                $result['warning'] = $this->error(self::ERROR_UPLOAD_FILE, $name, $error == UPLOAD_ERR_INI_SIZE || $error == UPLOAD_ERR_FORM_SIZE ? self::ERROR_UPLOAD_FILE_SIZE : self::ERROR_UPLOAD_TRANSFER, $error);
                $this->uploadDebug = 'Upload error code: ' . $error;
                break;
            }

            $tmpname = $files['tmp_name'][$i];
            $thash = ($paths && isset($paths[$i])) ? $paths[$i] : $target;
            $mtime = isset($mtimes[$i]) ? $mtimes[$i] : 0;
            if ($name === 'blob') {
                if ($chunk) {
                    if ($tempDir = $this->getTempDir($volume->getTempPath())) {
                        list($tmpname, $name) = $this->checkChunkedFile($tmpname, $chunk, $cid, $tempDir, $volume);
                        if ($tmpname) {
                            if ($name === false) {
                                preg_match('/^(.+)(\.\d+_(\d+))\.part$/s', $chunk, $m);
                                $result['error'] = $this->error(self::ERROR_UPLOAD_FILE, $m[1], $tmpname);
                                $result['_chunkfailure'] = true;
                                $this->uploadDebug = 'Upload error: ' . $tmpname;
                            } else if ($name) {
                                $result['_chunkmerged'] = basename($tmpname);
                                $result['_name'] = $name;
                                $result['_mtime'] = $mtime;
                            }
                        }
                    } else {
                        $result['error'] = $this->error(self::ERROR_UPLOAD_FILE, $chunk, self::ERROR_UPLOAD_TEMP);
                        $this->uploadDebug = 'Upload error: unable open tmp file';
                    }
                    return $result;
                } else {
                    // for form clipboard with Google Chrome or Opera
                    $name = 'image.png';
                }
            }

            // Set name if name eq 'image.png' and $args has 'name' array, e.g. clipboard data
            if (strtolower(substr($name, 0, 5)) === 'image' && is_array($args['name']) && isset($args['name'][$i])) {
                $type = $files['type'][$i];
                $name = $args['name'][$i];
                $ext = isset($extTable[$type]) ? '.' . $extTable[$type] : '';
                if ($ext) {
                    $name = preg_replace('/\.[^.]*$/', '', $name);
                }
                $name .= $ext;
            }

            // do hook function 'upload.presave'
            try {
                $this->trigger('upload.presave', array(&$thash, &$name, $tmpname, $this, $volume), $errors);
            } catch (elFinderTriggerException $e) {
                if (!is_uploaded_file($tmpname) && unlink($tmpname) && $tmpfname) {
                    unset($GLOBALS['elFinderTempFiles'][$tmpfname]);
                }
                continue;
            }

            clearstatcache();
            if ($mtime && is_file($tmpname)) {
                // for keep timestamp option in the LocalFileSystem volume
                touch($tmpname, $mtime);
            }

            $fp = null;
            if (!is_file($tmpname) || ($fp = fopen($tmpname, 'rb')) === false) {
                $errors = array_merge($errors, array(self::ERROR_UPLOAD_FILE, $name, ($fp === false? self::ERROR_UPLOAD_TEMP : self::ERROR_UPLOAD_TRANSFER)));
                $this->uploadDebug = 'Upload error: unable open tmp file';
                if (!is_uploaded_file($tmpname)) {
                    if (unlink($tmpname) && $tmpfname) unset($GLOBALS['elFinderTempFiles'][$tmpfname]);
                    continue;
                }
                break;
            }
            $rnres = array();
            if ($thash !== '' && $thash !== $target) {
                if ($dir = $volume->dir($thash)) {
                    $_target = $thash;
                    if (!isset($addedDirs[$thash])) {
                        $addedDirs[$thash] = true;
                        $result['added'][] = $dir;
                        // to support multi-level directory creation
                        $_phash = isset($dir['phash']) ? $dir['phash'] : null;
                        while ($_phash && !isset($addedDirs[$_phash]) && $_phash !== $target) {
                            if ($_dir = $volume->dir($_phash)) {
                                $addedDirs[$_phash] = true;
                                $result['added'][] = $_dir;
                                $_phash = isset($_dir['phash']) ? $_dir['phash'] : null;
                            } else {
                                break;
                            }
                        }
                    }
                } else {
                    $result['error'] = $this->error(self::ERROR_UPLOAD, self::ERROR_TRGDIR_NOT_FOUND, 'hash@' . $thash);
                    break;
                }
            } else {
                $_target = $target;
                // file rename for backup
                if (isset($renames[$name])) {
                    $dir = $volume->realpath($_target);
                    if (isset($hashes[$name])) {
                        $hash = $hashes[$name];
                    } else {
                        $hash = $volume->getHash($dir, $name);
                    }
                    $rnres = $this->rename(array('target' => $hash, 'name' => $volume->uniqueName($dir, $name, $suffix, true, 0)));
                    if (!empty($rnres['error'])) {
                        $result['warning'] = $rnres['error'];
                        if (!is_array($rnres['error'])) {
                            $errors = array_push($errors, $rnres['error']);
                        } else {
                            $errors = array_merge($errors, $rnres['error']);
                        }
                        continue;
                    }
                }
            }
            if (!$_target || ($file = $volume->upload($fp, $_target, $name, $tmpname, ($_target === $target) ? $hashes : array())) === false) {
                $errors = array_merge($errors, $this->error(self::ERROR_UPLOAD_FILE, $name, $volume->error()));
                fclose($fp);
                if (!is_uploaded_file($tmpname) && unlink($tmpname)) {
                    unset($GLOBALS['elFinderTempFiles'][$tmpname]);
                }
                continue;
            }

            is_resource($fp) && fclose($fp);
            if (!is_uploaded_file($tmpname)) {
                clearstatcache();
                if (!is_file($tmpname) || unlink($tmpname)) {
                    unset($GLOBALS['elFinderTempFiles'][$tmpname]);
                }
            }
            $result['added'][] = $file;
            if ($rnres) {
                $result = array_merge_recursive($result, $rnres);
            }
        }

        if ($errors) {
            $result['warning'] = $errors;
        }

        if ($GLOBALS['elFinderTempFiles']) {
            foreach (array_keys($GLOBALS['elFinderTempFiles']) as $_temp) {
                is_file($_temp) && is_writable($_temp) && unlink($_temp);
            }
        }
        $result['removed'] = $volume->removed();

        if (!empty($args['node'])) {
            $result['callback'] = array(
                'node' => $args['node'],
                'bind' => 'upload'
            );
        }
        return $result;
    }

    /**
     * Copy/move files into new destination
     *
     * @param  array  command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function paste($args)
    {
        $dst = $args['dst'];
        $targets = is_array($args['targets']) ? $args['targets'] : array();
        $cut = !empty($args['cut']);
        $error = $cut ? self::ERROR_MOVE : self::ERROR_COPY;
        $result = array('changed' => array(), 'added' => array(), 'removed' => array(), 'warning' => array());

        if (($dstVolume = $this->volume($dst)) == false) {
            return array('error' => $this->error($error, '#' . $targets[0], self::ERROR_TRGDIR_NOT_FOUND, '#' . $dst));
        }

        $this->itemLock($dst);

        $hashes = $renames = array();
        $suffix = '~';
        if (!empty($args['renames'])) {
            $renames = array_flip($args['renames']);
            if (is_string($args['suffix']) && !preg_match('/[\/\\?*:|"<>]/', $args['suffix'])) {
                $suffix = $args['suffix'];
            }
        }
        if (!empty($args['hashes'])) {
            $hashes = array_flip($args['hashes']);
        }

        foreach ($targets as $target) {
            elFinder::checkAborted();

            if (($srcVolume = $this->volume($target)) == false) {
                $result['warning'] = array_merge($result['warning'], $this->error($error, '#' . $target, self::ERROR_FILE_NOT_FOUND));
                continue;
            }

            $rnres = array();
            if ($renames) {
                $file = $srcVolume->file($target);
                if (isset($renames[$file['name']])) {
                    $dir = $dstVolume->realpath($dst);
                    $dstName = $file['name'];
                    if ($srcVolume !== $dstVolume) {
                        $errors = array();
                        try {
                            $this->trigger('paste.copyfrom', array(&$dst, &$dstName, '', $this, $dstVolume), $errors);
                        } catch (elFinderTriggerException $e) {
                            $result['warning'] = array_merge($result['warning'], $errors);
                            continue;
                        }
                    }
                    if (isset($hashes[$file['name']])) {
                        $hash = $hashes[$file['name']];
                    } else {
                        $hash = $dstVolume->getHash($dir, $dstName);
                    }
                    $rnres = $this->rename(array('target' => $hash, 'name' => $dstVolume->uniqueName($dir, $dstName, $suffix, true, 0)));
                    if (!empty($rnres['error'])) {
                        $result['warning'] = array_merge($result['warning'], $rnres['error']);
                        continue;
                    }
                }
            }

            if ($cut && $this->itemLocked($target)) {
                $rm = $srcVolume->file($target);
                $result['warning'] = array_merge($result['warning'], $this->error(self::ERROR_LOCKED, $rm['name']));
                continue;
            }

            if (($file = $dstVolume->paste($srcVolume, $target, $dst, $cut, $hashes)) == false) {
                $result['warning'] = array_merge($result['warning'], $this->error($dstVolume->error()));
                continue;
            }

            if ($error = $dstVolume->error()) {
                $result['warning'] = array_merge($result['warning'], $this->error($error));
            }

            if ($rnres) {
                $result = array_merge_recursive($result, $rnres);
            }
        }
        if (count($result['warning']) < 1) {
            unset($result['warning']);
        } else {
            $result['sync'] = true;
        }

        return $result;
    }

    /**
     * Return file content
     *
     * @param  array $args command arguments
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function get($args)
    {
        $target = $args['target'];
        $volume = $this->volume($target);
        $enc = false;

        if (!$volume || ($file = $volume->file($target)) == false) {
            return array('error' => $this->error(self::ERROR_OPEN, '#' . $target, self::ERROR_FILE_NOT_FOUND));
        }

        if ($volume->commandDisabled('get')) {
            return array('error' => $this->error(self::ERROR_OPEN, '#' . $target, self::ERROR_ACCESS_DENIED));
        }

        if (($content = $volume->getContents($target)) === false) {
            return array('error' => $this->error(self::ERROR_OPEN, $volume->path($target), $volume->error()));
        }

        $mime = isset($file['mime']) ? $file['mime'] : '';
        if ($mime && (strtolower(substr($mime, 0, 4)) === 'text' || in_array(strtolower($mime), self::$textMimes))) {
            $enc = '';
            if ($content !== '') {
                if (!$args['conv'] || $args['conv'] == '1') {
                    // detect encoding
                    if (function_exists('mb_detect_encoding')) {
                        if ($enc = mb_detect_encoding($content, mb_detect_order(), true)) {
                            $encu = strtoupper($enc);
                            if ($encu === 'UTF-8' || $encu === 'ASCII') {
                                $enc = '';
                            }
                        } else {
                            $enc = 'unknown';
                        }
                    } else if (!preg_match('//u', $content)) {
                        $enc = 'unknown';
                    }
                    if ($enc === 'unknown') {
                        $enc = $volume->getOption('encoding');
                        if (!$enc || strtoupper($enc) === 'UTF-8') {
                            $enc = 'unknown';
                        }
                    }
                    // call callbacks 'get.detectencoding'
                    if (!empty($this->listeners['get.detectencoding'])) {
                        foreach ($this->listeners['get.detectencoding'] as $handler) {
                            call_user_func_array($handler, array('get', &$enc, array_merge($args, array('content' => $content)), $this, $volume));
                        }
                    }
                    if ($enc && $enc !== 'unknown') {
                        $errlev = error_reporting();
                        error_reporting($errlev ^ E_NOTICE);
                        $utf8 = iconv($enc, 'UTF-8', $content);
                        if ($utf8 === false && function_exists('mb_convert_encoding')) {
                            error_reporting($errlev ^ E_WARNING);
                            $utf8 = mb_convert_encoding($content, 'UTF-8', $enc);
                            if (mb_convert_encoding($utf8, $enc, 'UTF-8') !== $content) {
                                $enc = 'unknown';
                            }
                        } else {
                            if ($utf8 === false || iconv('UTF-8', $enc, $utf8) !== $content) {
                                $enc = 'unknown';
                            }
                        }
                        error_reporting($errlev);
                        if ($enc !== 'unknown') {
                            $content = $utf8;
                        }
                    }
                    if ($enc) {
                        if ($args['conv'] == '1') {
                            $args['conv'] = '';
                            if ($enc === 'unknown') {
                                $content = false;
                            }
                        } else if ($enc === 'unknown') {
                            return array('doconv' => $enc);
                        }
                    }
                    if ($args['conv'] == '1') {
                        $args['conv'] = '';
                    }
                }
                if ($args['conv']) {
                    $enc = $args['conv'];
                    if (strtoupper($enc) !== 'UTF-8') {
                        $_content = $content;
                        $errlev = error_reporting();
                        $this->setToastErrorHandler(array(
                            'prefix' => 'Notice: '
                        ));
                        error_reporting($errlev | E_NOTICE | E_WARNING);
                        $content = iconv($enc, 'UTF-8//TRANSLIT', $content);
                        if ($content === false && function_exists('mb_convert_encoding')) {
                            $content = mb_convert_encoding($_content, 'UTF-8', $enc);
                        }
                        error_reporting($errlev);
                        $this->setToastErrorHandler(false);
                    } else {
                        $enc = '';
                    }
                }
            }
        } else {
            $content = 'data:' . ($mime ? $mime : 'application/octet-stream') . ';base64,' . base64_encode($content);
        }

        if ($enc !== false) {
            $json = false;
            if ($content !== false) {
                $json = json_encode($content);
            }
            if ($content === false || $json === false || strlen($json) < strlen($content)) {
                return array('doconv' => 'unknown');
            }
        }

        $res = array(
            'header' => array(
                'Content-Type: application/json'
            ),
            'content' => $content
        );

        // add cache control headers
        if ($cacheHeaders = $volume->getOption('cacheHeaders')) {
            $res['header'] = array_merge($res['header'], $cacheHeaders);
        }

        if ($enc) {
            $res['encoding'] = $enc;
        }
        return $res;
    }

    /**
     * Save content into text file
     *
     * @param $args
     *
     * @return array
     * @author Dmitry (dio) Levashov
     */
    protected function put($args)
    {
        $target = $args['target'];
        $encoding = isset($args['encoding']) ? $args['encoding'] : '';

        if (($volume = $this->volume($target)) == false
            || ($file = $volume->file($target)) == false) {
            return array('error' => $this->error(self::ERROR_SAVE, '#' . $target, self::ERROR_FILE_NOT_FOUND));
        }

        $this->itemLock($target);

        if ($encoding === 'scheme') {
            if (preg_match('~^https?://~i', $args['content'])) {
                /** @var resource $fp */
                $fp = $this->get_remote_contents($args['content'], 30, 5, 'Mozilla/5.0', $volume->tmpfile());
                if (!$fp) {
                    return array('error' => self::ERROR_SAVE, $args['content'], self::ERROR_FILE_NOT_FOUND);
                }
                $fmeta = stream_get_meta_data($fp);
                $mime = $this->detectMimeType($fmeta['uri']);
                if ($mime === 'unknown') {
                    $mime = 'application/octet-stream';
                }
                $mime = $volume->mimeTypeNormalize($mime, $file['name']);
                $args['content'] = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fmeta['uri']));
            }
            $encoding = '';
            $args['content'] = "\0" . $args['content'];
        } else if ($encoding === 'hash') {
            $_hash = $args['content'];
            if ($_src = $this->getVolume($_hash)) {
                if ($_file = $_src->file($_hash)) {
                    if ($_data = $_src->getContents($_hash)) {
                        $args['content'] = 'data:' . $file['mime'] . ';base64,' . base64_encode($_data);
                    }
                }
            }
            $encoding = '';
            $args['content'] = "\0" . $args['content'];
        }
        if ($encoding) {
            $content = iconv('UTF-8', $encoding, $args['content']);
            if ($content === false && function_exists('mb_detect_encoding')) {
                $content = mb_convert_encoding($args['content'], $encoding, 'UTF-8');
            }
            if ($content !== false) {
                $args['content'] = $content;
            }
        }
        if (($file = $volume->putContents($target, $args['content'])) == false) {
            return array('error' => $this->error(self::ERROR_SAVE, $volume->path($target), $volume->error()));
        }

        return array('changed' => array($file));
    }

    /**
     * Extract files from archive
     *
     * @param  array $args command arguments
     *
     * @return array
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function extract($args)
    {
        $target = $args['target'];
        $makedir = isset($args['makedir']) ? (bool)$args['makedir'] : null;

        if(strpos($target,'..') !== false){
            return array('error' => $this->error(self::ERROR_EXTRACT, '#' . $target, self::ERROR_FILE_NOT_FOUND));
        }

        if (($volume = $this->volume($target)) == false
            || ($file = $volume->file($target)) == false) {
            return array('error' => $this->error(self::ERROR_EXTRACT, '#' . $target, self::ERROR_FILE_NOT_FOUND));
        }

        $res = array();
        if ($file = $volume->extract($target, $makedir)) {
            $res['added'] = isset($file['read']) ? array($file) : $file;
            if ($err = $volume->error()) {
                $res['warning'] = $err;
            }
        } else {
            $res['error'] = $this->error(self::ERROR_EXTRACT, $volume->path($target), $volume->error());
        }
        return $res;
    }

    /**
     * Create archive
     *
     * @param  array $args command arguments
     *
     * @return array
     * @throws Exception
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     */
    protected function archive($args)
    {
        $targets = isset($args['targets']) && is_array($args['targets']) ? $args['targets'] : array();
        $name = isset($args['name']) ? $args['name'] : '';

        if(strpos($name,'..') !== false){
            return $this->error('Invalid Request.', self::ERROR_TRGDIR_NOT_FOUND);
        }

        $targets = array_filter($targets, array($this, 'volume'));
        if (!$targets || ($volume = $this->volume($targets[0])) === false) {
            return $this->error(self::ERROR_ARCHIVE, self::ERROR_TRGDIR_NOT_FOUND);
        }

        foreach ($targets as $target) {
            $explodedStr = explode('l1_', $target);
            $targetFolderName = base64_decode($explodedStr[1]);
            if(strpos($targetFolderName,'..') !== false){
                return $this->error('Invalid Request.', self::ERROR_TRGDIR_NOT_FOUND);
            }
            $this->itemLock($target);
        }

        return ($file = $volume->archive($targets, $args['type'], $name))
            ? array('added' => array($file))
            : array('error' => $this->error(self::ERROR_ARCHIVE, $volume->error()));
    }

    /**
     * Search files
     *
     * @param  array $args command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry Levashov
     */
    protected function search($args)
    {
        $q = trim($args['q']);
        $mimes = !empty($args['mimes']) && is_array($args['mimes']) ? $args['mimes'] : array();
        $target = !empty($args['target']) ? $args['target'] : null;
        $type = !empty($args['type']) ? $args['type'] : null;
        $result = array();
        $errors = array();

        if ($target) {
            if ($volume = $this->volume($target)) {
                $result = $volume->search($q, $mimes, $target, $type);
                $errors = array_merge($errors, $volume->error());
            }
        } else {
            foreach ($this->volumes as $volume) {
                $result = array_merge($result, $volume->search($q, $mimes, null, $type));
                $errors = array_merge($errors, $volume->error());
            }
        }

        $result = array('files' => $result);
        if ($errors) {
            $result['warning'] = $errors;
        }
        return $result;
    }

    /**
     * Return file info (used by client "places" ui)
     *
     * @param  array $args command arguments
     *
     * @return array
     * @throws elFinderAbortException
     * @author Dmitry Levashov
     */
    protected function info($args)
    {
        $files = array();
        $compare = null;
        // long polling mode
        if ($args['compare'] && count($args['targets']) === 1) {
            $compare = intval($args['compare']);
            $hash = $args['targets'][0];
            if ($volume = $this->volume($hash)) {
                $standby = (int)$volume->getOption('plStandby');
                $_compare = false;
                if (($syncCheckFunc = $volume->getOption('syncCheckFunc')) && is_callable($syncCheckFunc)) {
                    $_compare = call_user_func_array($syncCheckFunc, array($volume->realpath($hash), $standby, $compare, $volume, $this));
                }
                if ($_compare !== false) {
                    $compare = $_compare;
                } else {
                    $sleep = max(1, (int)$volume->getOption('tsPlSleep'));
                    $limit = max(1, $standby / $sleep) + 1;
                    do {
                        elFinder::extendTimeLimit(30 + $sleep);
                        $volume->clearstatcache();
                        if (($info = $volume->file($hash)) != false) {
                            if ($info['ts'] != $compare) {
                                $compare = $info['ts'];
                                break;
                            }
                        } else {
                            $compare = 0;
                            break;
                        }
                        if (--$limit) {
                            sleep($sleep);
                        }
                    } while ($limit);
                }
            }
        } else {
            foreach ($args['targets'] as $hash) {
                elFinder::checkAborted();
                if (($volume = $this->volume($hash)) != false
                    && ($info = $volume->file($hash)) != false) {
                    $info['path'] = $volume->path($hash);
                    $files[] = $info;
                }
            }
        }

        $result = array('files' => $files);
        if (!is_null($compare)) {
            $result['compare'] = strval($compare);
        }
        return $result;
    }

    /**
     * Return image dimensions
     *
     * @param  array $args command arguments
     *
     * @return array
     * @throws ImagickException
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function dim($args)
    {
        $res = array();
        $target = $args['target'];

        if (($volume = $this->volume($target)) != false) {
            if ($dim = $volume->dimensions($target, $args)) {
                if (is_array($dim) && isset($dim['dim'])) {
                    $res = $dim;
                } else {
                    $res = array('dim' => $dim);
                    if ($subImgLink = $volume->getSubstituteImgLink($target, explode('x', $dim))) {
                        $res['url'] = $subImgLink;
                    }
                }
            }
        }

        return $res;
    }

    /**
     * Resize image
     *
     * @param  array  command arguments
     *
     * @return array
     * @throws ImagickException
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     */
    protected function resize($args)
    {
        $target = $args['target'];
        $width = (int)$args['width'];
        $height = (int)$args['height'];
        $x = (int)$args['x'];
        $y = (int)$args['y'];
        $mode = $args['mode'];
        $bg = $args['bg'];
        $degree = (int)$args['degree'];
        $quality = (int)$args['quality'];

        if (($volume = $this->volume($target)) == false
            || ($file = $volume->file($target)) == false) {
            return array('error' => $this->error(self::ERROR_RESIZE, '#' . $target, self::ERROR_FILE_NOT_FOUND));
        }

        if ($mode !== 'rotate' && ($width < 1 || $height < 1)) {
            return array('error' => $this->error(self::ERROR_RESIZESIZE));
        }
        return ($file = $volume->resize($target, $width, $height, $x, $y, $mode, $bg, $degree, $quality))
            ? (!empty($file['losslessRotate']) ? $file : array('changed' => array($file)))
            : array('error' => $this->error(self::ERROR_RESIZE, $volume->path($target), $volume->error()));
    }

    /**
     * Return content URL
     *
     * @param  array $args command arguments
     *
     * @return array
     * @author Naoki Sawada
     **/
    protected function url($args)
    {
        $target = $args['target'];
        $options = isset($args['options']) ? $args['options'] : array();
        if (($volume = $this->volume($target)) != false) {
            if (!$volume->commandDisabled('url')) {
                $url = $volume->getContentUrl($target, $options);
                return $url ? array('url' => $url) : array();
            }
        }
        return array();
    }

    /**
     * Output callback result with JavaScript that control elFinder
     * or HTTP redirect to callbackWindowURL
     *
     * @param  array  command arguments
     *
     * @throws elFinderAbortException
     * @author Naoki Sawada
     */
    protected function callback($args)
    {
        $checkReg = '/[^a-zA-Z0-9;._-]/';
        $node = (isset($args['node']) && !preg_match($checkReg, $args['node'])) ? $args['node'] : '';
        $json = (isset($args['json']) && json_decode($args['json'])) ? $args['json'] : '{}';
        $bind = (isset($args['bind']) && !preg_match($checkReg, $args['bind'])) ? $args['bind'] : '';
        $done = (!empty($args['done']));

        while (ob_get_level()) {
            if (!ob_end_clean()) {
                break;
            }
        }

        if ($done || !$this->callbackWindowURL) {
            $script = '';
            if ($node) {
                if ($bind) {
                    $trigger = 'elf.trigger(\'' . $bind . '\', data);';
                    $triggerdone = 'elf.trigger(\'' . $bind . 'done\');';
                    $triggerfail = 'elf.trigger(\'' . $bind . 'fail\', data);';
                } else {
                    $trigger = $triggerdone = $triggerfail = '';
                }
                $origin = isset($_SERVER['HTTP_ORIGIN'])? str_replace('\'', '\\\'', $_SERVER['HTTP_ORIGIN']) : '*';
                $script .= '
var go = function() {
    var w = window.opener || window.parent || window,
        close = function(){
            window.open("about:blank","_self").close();
            return false;
        };
    try {
        var elf = w.document.getElementById(\'' . $node . '\').elfinder;
        if (elf) {
            var data = ' . $json . ';
            if (data.error) {
                ' . $triggerfail . '
                elf.error(data.error);
            } else {
                data.warning && elf.error(data.warning);
                data.removed && data.removed.length && elf.remove(data);
                data.added   && data.added.length   && elf.add(data);
                data.changed && data.changed.length && elf.change(data);
                ' . $trigger . '
                ' . $triggerdone . '
                data.sync && elf.sync();
            }
        }
    } catch(e) {
        // for CORS
        w.postMessage && w.postMessage(JSON.stringify({bind:\'' . $bind . '\',data:' . $json . '}), \'' . $origin . '\');
    }
    close();
    setTimeout(function() {
        var msg = document.getElementById(\'msg\');
        msg.style.display = \'inline\';
        msg.onclick = close;
    }, 100);
};
';
            }

            $out = '<!DOCTYPE html><html lang="en"><head><meta http-equiv="X-UA-Compatible" content="IE=edge"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=2"><script>' . $script . '</script></head><body><h2 id="msg" style="display:none;"><a href="#">Please close this tab.</a></h2><script>go();</script></body></html>';

            header('Content-Type: text/html; charset=utf-8');
            header('Content-Length: ' . strlen($out));
            header('Cache-Control: private');
            header('Pragma: no-cache');

            echo $out;

        } else {
            $url = $this->callbackWindowURL;
            $url .= ((strpos($url, '?') === false) ? '?' : '&')
                . '&node=' . rawurlencode($node)
                . (($json !== '{}') ? ('&json=' . rawurlencode($json)) : '')
                . ($bind ? ('&bind=' . rawurlencode($bind)) : '')
                . '&done=1';

            header('Location: ' . $url);

        }
        throw new elFinderAbortException();
    }

    /**
     * Error handler for send toast message to client side
     *
     * @param int    $errno
     * @param string $errstr
     * @param string $errfile
     * @param int    $errline
     *
     * @return boolean
     */
    protected function toastErrorHandler($errno, $errstr, $errfile, $errline)
    {
        $proc = false;
        if (!(error_reporting() & $errno)) {
            return $proc;
        }
        $toast = array();
        $toast['mode'] = $this->toastParams['mode'];
        $toast['msg'] = $this->toastParams['prefix'] . $errstr;
        $this->toastMessages[] = $toast;
        return true;
    }

    /**
     * PHP error handler, catch error types only E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE
     *
     * @param int    $errno
     * @param string $errstr
     * @param string $errfile
     * @param int    $errline
     *
     * @return boolean
     */
    public static function phpErrorHandler($errno, $errstr, $errfile, $errline)
    {
        static $base = null;

        $proc = false;

        if (is_null($base)) {
            $base = dirname(__FILE__) . DIRECTORY_SEPARATOR;
        }

        if (!(error_reporting() & $errno)) {
            return $proc;
        }

        // Do not report real path
        if (strpos($errfile, $base) === 0) {
            $errfile = str_replace($base, '', $errfile);
        } else if ($pos = strrpos($errfile, '/vendor/')) {
            $errfile = substr($errfile, $pos + 1);
        } else {
            $errfile = basename($errfile);
        }

        switch ($errno) {
            case E_WARNING:
            case E_USER_WARNING:
                elFinder::$phpErrors[] = "WARNING: $errstr in $errfile line $errline.";
                $proc = true;
                break;

            case E_NOTICE:
            case E_USER_NOTICE:
                elFinder::$phpErrors[] = "NOTICE: $errstr in $errfile line $errline.";
                $proc = true;
                break;

            case E_RECOVERABLE_ERROR:
                elFinder::$phpErrors[] = "RECOVERABLE_ERROR: $errstr in $errfile line $errline.";
                $proc = true;
                break;
        }

        // E_STRICT is deprecated; see https://wiki.php.net/rfc/deprecations_php_8_4#remove_e_strict_error_level_and_deprecate_e_strict_constant
        if (defined('E_STRICT') && $errno === @E_STRICT) {
            elFinder::$phpErrors[] = "STRICT: $errstr in $errfile line $errline.";
            $proc = true;
        }

        if (defined('E_DEPRECATED')) {
            switch ($errno) {
                case E_DEPRECATED:
                case E_USER_DEPRECATED:
                    elFinder::$phpErrors[] = "DEPRECATED: $errstr in $errfile line $errline.";
                    $proc = true;
                    break;
            }
        }

        return $proc;
    }

    /***************************************************************************/
    /*                                   utils                                 */
    /***************************************************************************/

    /**
     * Return root - file's owner
     *
     * @param  string  file hash
     *
     * @return elFinderVolumeDriver|boolean (false)
     * @author Dmitry (dio) Levashov
     **/
    protected function volume($hash)
    {
        foreach ($this->volumes as $id => $v) {
            if (strpos('' . $hash, $id) === 0) {
                return $this->volumes[$id];
            }
        }
        return false;
    }

    /**
     * Return files info array
     *
     * @param  array $data one file info or files info
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function toArray($data)
    {
        return isset($data['hash']) || !is_array($data) ? array($data) : $data;
    }

    /**
     * Return fils hashes list
     *
     * @param  array $files files info
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function hashes($files)
    {
        $ret = array();
        foreach ($files as $file) {
            $ret[] = $file['hash'];
        }
        return $ret;
    }

    /**
     * Remove from files list hidden files and files with required mime types
     *
     * @param  array $files files info
     *
     * @return array
     * @author Dmitry (dio) Levashov
     **/
    protected function filter($files)
    {
        $exists = array();
        foreach ($files as $i => $file) {
            if (isset($file['hash'])) {
                if (isset($exists[$file['hash']]) || !empty($file['hidden']) || !$this->default->mimeAccepted($file['mime'])) {
                    unset($files[$i]);
                }
                $exists[$file['hash']] = true;
            }
        }
        return array_values($files);
    }

    protected function utime()
    {
        $time = explode(" ", microtime());
        return (double)$time[1] + (double)$time[0];
    }

    /**
     * Return Network mount volume unique ID
     *
     * @param  array  $netVolumes Saved netvolumes array
     * @param  string $prefix     Id prefix
     *
     * @return string|false
     * @author Naoki Sawada
     **/
    protected function getNetVolumeUniqueId($netVolumes = null, $prefix = 'nm')
    {
        if (is_null($netVolumes)) {
            $netVolumes = $this->getNetVolumes();
        }
        $ids = array();
        foreach ($netVolumes as $vOps) {
            if (isset($vOps['id']) && strpos($vOps['id'], $prefix) === 0) {
                $ids[$vOps['id']] = true;
            }
        }
        if (!$ids) {
            $id = $prefix . '1';
        } else {
            $i = 0;
            while (isset($ids[$prefix . ++$i]) && $i < 10000) ;
            $id = $prefix . $i;
            if (isset($ids[$id])) {
                $id = false;
            }
        }
        return $id;
    }

    /**
     * Is item locked?
     *
     * @param string $hash
     *
     * @return boolean
     */
    protected function itemLocked($hash)
    {
        if (!elFinder::$commonTempPath) {
            return false;
        }
        $lock = elFinder::$commonTempPath . DIRECTORY_SEPARATOR . self::filenameDecontaminate($hash) . '.lock';
        if (file_exists($lock)) {
            if (filemtime($lock) + $this->itemLockExpire < time()) {
                unlink($lock);
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Do lock target item
     *
     * @param array|string $hashes
     * @param boolean      $autoUnlock
     *
     * @return void
     */
    protected function itemLock($hashes, $autoUnlock = true)
    {
        if (!elFinder::$commonTempPath) {
            return;
        }
        if (!is_array($hashes)) {
            $hashes = array($hashes);
        }
        foreach ($hashes as $hash) {
            $lock = elFinder::$commonTempPath . DIRECTORY_SEPARATOR . self::filenameDecontaminate($hash) . '.lock';
            if ($this->itemLocked($hash)) {
                $cnt = file_get_contents($lock) + 1;
            } else {
                $cnt = 1;
            }
            if (file_put_contents($lock, $cnt, LOCK_EX)) {
                if ($autoUnlock) {
                    $this->autoUnlocks[] = $hash;
                }
            }
        }
    }

    /**
     * Do unlock target item
     *
     * @param string $hash
     *
     * @return boolean
     */
    protected function itemUnlock($hash)
    {
        if (!$this->itemLocked($hash)) {
            return true;
        }
        $lock = elFinder::$commonTempPath . DIRECTORY_SEPARATOR . $hash . '.lock';
        $cnt = file_get_contents($lock);
        if (--$cnt < 1) {
            unlink($lock);
            return true;
        } else {
            file_put_contents($lock, $cnt, LOCK_EX);
            return false;
        }
    }

    /**
     * unlock locked items on command completion
     *
     * @return void
     */
    public function itemAutoUnlock()
    {
        if ($this->autoUnlocks) {
            foreach ($this->autoUnlocks as $hash) {
                $this->itemUnlock($hash);
            }
            $this->autoUnlocks = array();
        }
    }

    /**
     * Ensure directories recursively
     *
     * @param  object $volume Volume object
     * @param  string $target Target hash
     * @param  array  $dirs   Array of directory tree to ensure
     * @param  string $path   Relative path form target hash
     *
     * @return array|false      array('stats' => array([stat of maked directory]), 'hashes' => array('[path]' => '[hash]'), 'makes' => array([New directory hashes]), 'error' => array([Error name]))
     * @author Naoki Sawada
     **/
    protected function ensureDirsRecursively($volume, $target, $dirs, $path = '')
    {
        $res = array('stats' => array(), 'hashes' => array(), 'makes' => array(), 'error' => array());
        foreach ($dirs as $name => $sub) {
            $name = (string)$name;
            $dir = $newDir = null;
            if ((($parent = $volume->realpath($target)) && ($dir = $volume->dir($volume->getHash($parent, $name)))) || ($newDir = $volume->mkdir($target, $name))) {
                $_path = $path . '/' . $name;
                if ($newDir) {
                    $res['makes'][] = $newDir['hash'];
                    $dir = $newDir;
                }
                $res['stats'][] = $dir;
                $res['hashes'][$_path] = $dir['hash'];
                if (count($sub)) {
                    $res = array_merge_recursive($res, $this->ensureDirsRecursively($volume, $dir['hash'], $sub, $_path));
                }
            } else {
                $res['error'][] = $name;
            }
        }
        return $res;
    }

    /**
     * Sets the toast error handler.
     *
     * @param array $opts The options
     */
    public function setToastErrorHandler($opts)
    {
        $this->toastParams = $this->toastParamsDefault;
        if (!$opts) {
            restore_error_handler();
        } else {
            $this->toastParams = array_merge($this->toastParams, $opts);
            set_error_handler(array($this, 'toastErrorHandler'));
        }
    }

    /**
     * String encode convert to UTF-8
     *
     * @param      string  $str  Input string
     *
     * @return     string  UTF-8 string
     */
    public function utf8Encode($str)
    {
        static $mbencode = null;
        $str = (string) $str;
        if (@iconv('utf-8', 'utf-8//IGNORE', $str) === $str) {
            return $str;
        }

        if ($this->utf8Encoder) {
            return $this->utf8Encoder($str);
        }

        if ($mbencode === null) {
            $mbencode = function_exists('mb_convert_encoding') && function_exists('mb_detect_encoding');
        }

        if ($mbencode) {
            if ($enc = mb_detect_encoding($str, mb_detect_order(), true)) {
                $_str = mb_convert_encoding($str, 'UTF-8', $enc);
                if (@iconv('utf-8', 'utf-8//IGNORE', $_str) === $_str) {
                    return $_str;
                }
            }
        }
        return utf8_encode($str);
    }

    /***************************************************************************/
    /*                           static  utils                                 */
    /***************************************************************************/

    /**
     * Return full version of API that this connector supports all functions
     *
     * @return string
     */
    public static function getApiFullVersion()
    {
        return (string)self::$ApiVersion . '.' . (string)self::$ApiRevision;
    }

    /**
     * Return self::$commonTempPath
     *
     * @return     string  The common temporary path.
     */
    public static function getCommonTempPath()
    {
        return self::$commonTempPath;
    }

    /**
     * Return Is Animation Gif
     *
     * @param  string $path server local path of target image
     *
     * @return bool
     */
    public static function isAnimationGif($path)
    {
        list(, , $type) = getimagesize($path);
        switch ($type) {
            case IMAGETYPE_GIF:
                break;
            default:
                return false;
        }

        $imgcnt = 0;
        $fp = fopen($path, 'rb');
        fread($fp, 4);
        $c = fread($fp, 1);
        if (ord($c) != 0x39) {  // GIF89a
            return false;
        }

        while (!feof($fp)) {
            do {
                $c = fread($fp, 1);
            } while (ord($c) != 0x21 && !feof($fp));

            if (feof($fp)) {
                break;
            }

            $c2 = fread($fp, 2);
            if (bin2hex($c2) == "f904") {
                $imgcnt++;
                if ($imgcnt === 2) {
                    break;
                }
            }

            if (feof($fp)) {
                break;
            }
        }

        if ($imgcnt > 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Return Is Animation Png
     *
     * @param  string $path server local path of target image
     *
     * @return bool
     */
    public static function isAnimationPng($path)
    {
        list(, , $type) = getimagesize($path);
        switch ($type) {
            case IMAGETYPE_PNG:
                break;
            default:
                return false;
        }

        $fp = fopen($path, 'rb');
        $img_bytes = fread($fp, 1024);
        fclose($fp);
        if ($img_bytes) {
            if (strpos(substr($img_bytes, 0, strpos($img_bytes, 'IDAT')), 'acTL') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return Is seekable stream resource
     *
     * @param resource $resource
     *
     * @return bool
     */
    public static function isSeekableStream($resource)
    {
        $metadata = stream_get_meta_data($resource);
        return $metadata['seekable'];
    }

    /**
     * Rewind stream resource
     *
     * @param resource $resource
     *
     * @return void
     */
    public static function rewind($resource)
    {
        self::isSeekableStream($resource) && rewind($resource);
    }

    /**
     * Determines whether the specified resource is seekable url.
     *
     * @param      <type>   $resource  The resource
     *
     * @return     boolean  True if the specified resource is seekable url, False otherwise.
     */
    public static function isSeekableUrl($resource)
    {
        $id = (int)$resource;
        if (isset(elFinder::$seekableUrlFps[$id])) {
            return elFinder::$seekableUrlFps[$id];
        }
        return null;
    }

    /**
     * serialize and base64_encode of session data (If needed)
     *
     * @deprecated
     *
     * @param  mixed $var target variable
     *
     * @author Naoki Sawada
     * @return mixed|string
     */
    public static function sessionDataEncode($var)
    {
        if (self::$base64encodeSessionData) {
            $var = base64_encode(serialize($var));
        }
        return $var;
    }

    /**
     * base64_decode and unserialize of session data  (If needed)
     *
     * @deprecated
     *
     * @param  mixed $var     target variable
     * @param  bool  $checkIs data type for check (array|string|object|int)
     *
     * @author Naoki Sawada
     * @return bool|mixed
     */
    public static function sessionDataDecode(&$var, $checkIs = null)
    {
        if (self::$base64encodeSessionData) {
            $data = unserialize(base64_decode($var));
        } else {
            $data = $var;
        }
        $chk = true;
        if ($checkIs) {
            switch ($checkIs) {
                case 'array':
                    $chk = is_array($data);
                    break;
                case 'string':
                    $chk = is_string($data);
                    break;
                case 'object':
                    $chk = is_object($data);
                    break;
                case 'int':
                    $chk = is_int($data);
                    break;
            }
        }
        if (!$chk) {
            unset($var);
            return false;
        }
        return $data;
    }

    /**
     * Call session_write_close() if session is restarted
     *
     * @deprecated
     * @return void
     */
    public static function sessionWrite()
    {
        if (session_id()) {
            session_write_close();
        }
    }

    /**
     * Return elFinder static variable
     *
     * @param $key
     *
     * @return mixed|null
     */
    public static function getStaticVar($key)
    {
        return isset(elFinder::$$key) ? elFinder::$$key : null;
    }

    /**
     * Extend PHP execution time limit and also check connection is aborted
     *
     * @param Int $time
     *
     * @return void
     * @throws elFinderAbortException
     */
    public static function extendTimeLimit($time = null)
    {
        static $defLimit = null;
        if (!self::aborted()) {
            if (is_null($defLimit)) {
                $defLimit = ini_get('max_execution_time');
            }
            if ($defLimit != 0) {
                $time = is_null($time) ? $defLimit : max($defLimit, $time);
                set_time_limit($time);
            }
        } else {
            throw new elFinderAbortException();
        }
    }

    /**
     * Check connection is aborted
     * Script stop immediately if connection aborted
     *
     * @return void
     * @throws elFinderAbortException
     */
    public static function checkAborted()
    {
        elFinder::extendTimeLimit();
    }

    /**
     * Return bytes from php.ini value
     *
     * @param string $iniName
     * @param string $val
     *
     * @return number
     */
    public static function getIniBytes($iniName = '', $val = '')
    {
        if ($iniName !== '') {
            $val = ini_get($iniName);
            if ($val === false) {
                return 0;
            }
        }
        $val = trim($val, "bB \t\n\r\0\x0B");
        $last = strtolower($val[strlen($val) - 1]);
        $val = sprintf('%u', $val);
        switch ($last) {
            case 'y':
                $val = elFinder::xKilobyte($val);
            case 'z':
                $val = elFinder::xKilobyte($val);
            case 'e':
                $val = elFinder::xKilobyte($val);
            case 'p':
                $val = elFinder::xKilobyte($val);
            case 't':
                $val = elFinder::xKilobyte($val);
            case 'g':
                $val = elFinder::xKilobyte($val);
            case 'm':
                $val = elFinder::xKilobyte($val);
            case 'k':
                $val = elFinder::xKilobyte($val);
        }
        return $val;
    }

    /**
     * Return X 1KByte
     *
     * @param      integer|string  $val    The value
     *
     * @return     number
     */
    public static function xKilobyte($val)
    {
        if (strpos((string)$val * 1024, 'E') !== false) {
            if (strpos((string)$val * 1.024, 'E') === false) {
                $val *= 1.024;
            }
            $val .= '000';
        } else {
            $val *= 1024;
        }
        return $val;
    }

    /**
     * Get script url.
     *
     * @return string full URL
     * @author Naoki Sawada
     */
    public static function getConnectorUrl()
    {
        if (defined('ELFINDER_CONNECTOR_URL')) {
            return ELFINDER_CONNECTOR_URL;
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
        $url = ($https ? 'https://' : 'http://')
            . $_SERVER['SERVER_NAME']                                              // host
            . ((empty($_SERVER['SERVER_PORT']) || (!$https && $_SERVER['SERVER_PORT'] == 80) || ($https && $_SERVER['SERVER_PORT'] == 443)) ? '' : (':' . $_SERVER['SERVER_PORT']))  // port
            . $_SERVER['REQUEST_URI'];                                             // path & query
        list($url) = explode('?', $url);

        return $url;
    }

    /**
     * Get stream resource pointer by URL
     *
     * @param array $data array('target'=>'URL', 'headers' => array())
     * @param int   $redirectLimit
     *
     * @return resource|boolean
     * @author Naoki Sawada
     */
    public static function getStreamByUrl($data, $redirectLimit = 5)
    {
        if (isset($data['target'])) {
            $data = array(
                'cnt' => 0,
                'url' => $data['target'],
                'headers' => isset($data['headers']) ? $data['headers'] : array(),
                'postData' => isset($data['postData']) ? $data['postData'] : array(),
                'cookies' => array(),
            );
        }
        if ($data['cnt'] > $redirectLimit) {
            return false;
        }
        $dlurl = $data['url'];
        $data['url'] = '';
        $headers = $data['headers'];

        if ($dlurl) {
            $url = parse_url($dlurl);
            $ports = array(
                'http' => '80',
                'https' => '443',
                'ftp' => '21'
            );
            $url['scheme'] = strtolower($url['scheme']);
            if (!isset($url['port']) && isset($ports[$url['scheme']])) {
                $url['port'] = $ports[$url['scheme']];
            }
            if (!isset($url['port'])) {
                return false;
            }
            $cookies = array();
            if ($data['cookies']) {
                foreach ($data['cookies'] as $d => $c) {
                    if (strpos($url['host'], $d) !== false) {
                        $cookies[] = $c;
                    }
                }
            }

            $transport = ($url['scheme'] === 'https') ? 'ssl' : 'tcp';
            $query = isset($url['query']) ? '?' . $url['query'] : '';
            if (!($stream = stream_socket_client($transport . '://' . $url['host'] . ':' . $url['port']))) {
                return false;
            }

            $body = '';
            if (!empty($data['postData'])) {
                $method = 'POST';
                if (is_array($data['postData'])) {
                    $body = http_build_query($data['postData']);
                } else {
                    $body = $data['postData'];
                }
            } else {
                $method = 'GET';
            }

            $sends = array();
            $sends[] = "$method {$url['path']}{$query} HTTP/1.1";
            $sends[] = "Host: {$url['host']}";
            foreach ($headers as $header) {
                $sends[] = trim($header, "\r\n");
            }
            $sends[] = 'Connection: Close';
            if ($cookies) {
                $sends[] = 'Cookie: ' . implode('; ', $cookies);
            }
            if ($method === 'POST') {
                $sends[] = 'Content-Type: application/x-www-form-urlencoded';
                $sends[] = 'Content-Length: ' . strlen($body);
            }
            $sends[] = "\r\n" . $body;

            stream_set_timeout($stream, 300);
            fputs($stream, join("\r\n", $sends) . "\r\n");

            while (($res = trim(fgets($stream))) !== '') {
                // find redirect
                if (preg_match('/^Location: (.+)$/i', $res, $m)) {
                    $data['url'] = $m[1];
                }
                // fetch cookie
                if (strpos($res, 'Set-Cookie:') === 0) {
                    $domain = $url['host'];
                    if (preg_match('/^Set-Cookie:(.+)(?:domain=\s*([^ ;]+))?/i', $res, $c1)) {
                        if (!empty($c1[2])) {
                            $domain = trim($c1[2]);
                        }
                        if (preg_match('/([^ ]+=[^;]+)/', $c1[1], $c2)) {
                            $data['cookies'][$domain] = $c2[1];
                        }
                    }
                }
                // is seekable url
                if (preg_match('/^(Accept-Ranges|Content-Range): bytes/i', $res)) {
                    elFinder::$seekableUrlFps[(int)$stream] = true;
                }
            }
            if ($data['url']) {
                ++$data['cnt'];
                fclose($stream);

                return self::getStreamByUrl($data, $redirectLimit);
            }

            return $stream;
        }

        return false;
    }

    /**
     * Gets the fetch cookie file for curl.
     *
     * @return string  The fetch cookie file.
     */
    public function getFetchCookieFile()
    {
        $file = '';
        if ($tmpDir = $this->getTempDir()) {
            $file = $tmpDir . '/.elFinderAnonymousCookie';
        }
        return $file;
    }

    /**
     * Call curl_exec() with supported redirect on `safe_mode` or `open_basedir`
     *
     * @param resource $curl
     * @param array    $options
     * @param array    $headers
     * @param array    $postData
     *
     * @throws \Exception
     * @return mixed
     * @author Naoki Sawada
     */
    public static function curlExec($curl, $options = array(), $headers = array(), $postData = array())
    {
        $followLocation = (!ini_get('safe_mode') && !ini_get('open_basedir'));
        if ($followLocation) {
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        }

        if ($options) {
            curl_setopt_array($curl, $options);
        }

        if ($headers) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        $result = curl_exec($curl);

        if (!$followLocation && $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL)) {
            if ($stream = self::getStreamByUrl(array('target' => $redirect, 'headers' => $headers, 'postData' => $postData))) {
                $result = stream_get_contents($stream);
            }
        }

        if ($result === false) {
            if (curl_errno($curl)) {
                throw new \Exception('curl_exec() failed: ' . curl_error($curl));
            } else {
                throw new \Exception('curl_exec(): empty response');
            }
        }

        curl_close($curl);

        return $result;
    }

    /**
     * Return bool that current request was aborted by client side
     *
     * @return boolean
     */
    public static function aborted()
    {
        if ($file = self::$abortCheckFile) {
            (version_compare(PHP_VERSION, '5.3.0') >= 0) ? clearstatcache(true, $file) : clearstatcache();
            if (!is_file($file)) {
                // GC (expire 12h)
                list($ptn) = explode('elfreq', $file);
                self::GlobGC($ptn . 'elfreq*', 43200);
                return true;
            }
        }
        return false;
    }

    /**
     * Return array ["name without extention", "extention"] by filename
     *
     * @param string $name
     *
     * @return array
     */
    public static function splitFileExtention($name)
    {
        if (preg_match('/^(.+?)?\.((?:tar\.(?:gz|bz|bz2|z|lzo))|cpio\.gz|ps\.gz|xcf\.(?:gz|bz2)|[a-z0-9]{1,10})$/i', $name, $m)) {
            return array((string)$m[1], $m[2]);
        } else {
            return array($name, '');
        }
    }

    /**
     * Gets the memory size by imageinfo.
     *
     * @param      array $imgInfo array that result of getimagesize()
     *
     * @return     integer  The memory size by imageinfo.
     */
    public static function getMemorySizeByImageInfo($imgInfo)
    {
        $width = $imgInfo[0];
        $height = $imgInfo[1];
        $bits = isset($imgInfo['bits']) ? $imgInfo['bits'] : 24;
        $channels = isset($imgInfo['channels']) ? $imgInfo['channels'] : 3;
        return round(($width * $height * $bits * $channels / 8 + Pow(2, 16)) * 1.65);
    }

    /**
     * Auto expand memory for GD processing
     *
     * @param      array $imgInfos The image infos
     */
    public static function expandMemoryForGD($imgInfos)
    {
        if (elFinder::$memoryLimitGD != 0 && $imgInfos && is_array($imgInfos)) {
            if (!is_array($imgInfos[0])) {
                $imgInfos = array($imgInfos);
            }
            $limit = self::getIniBytes('', elFinder::$memoryLimitGD);
            $memLimit = self::getIniBytes('memory_limit');
            $needs = 0;
            foreach ($imgInfos as $info) {
                $needs += self::getMemorySizeByImageInfo($info);
            }
            $needs += memory_get_usage();
            if ($needs > $memLimit && ($limit == -1 || $limit > $needs)) {
                ini_set('memory_limit', $needs);
            }
        }
    }

    /**
     * Decontaminate of filename
     *
     * @param      String  $name   The name
     *
     * @return     String  Decontaminated filename
     */
    public static function filenameDecontaminate($name)
    {
        // Directory traversal defense
        if (DIRECTORY_SEPARATOR === '\\') {
            $name = str_replace('\\', '/', $name);
        }
        $parts = explode('/', trim($name, '/'));
        $name = array_pop($parts); 
        return $name;
    }

    /**
     * Execute shell command
     *
     * @param  string $command      command line
     * @param  string $output       stdout strings
     * @param  int    $return_var   process exit code
     * @param  string $error_output stderr strings
     * @param  null   $cwd          cwd
     *
     * @return int exit code
     * @throws elFinderAbortException
     * @author Alexey Sukhotin
     */
    public static function procExec($command, &$output = '', &$return_var = -1, &$error_output = '', $cwd = null)
    {

        static $allowed = null;

        if ($allowed === null) {
            if ($allowed = function_exists('proc_open')) {
                if ($disabled = ini_get('disable_functions')) {
                    $funcs = array_map('trim', explode(',', $disabled));
                    $allowed = !in_array('proc_open', $funcs);
                }
            }
        }

        if (!$allowed) {
            $return_var = -1;
            return $return_var;
        }

        if (!$command) {
            $return_var = 0;
            return $return_var;
        }

        $descriptorspec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w")   // stderr
        );

        $process = proc_open($command, $descriptorspec, $pipes, $cwd, null);

        if (is_resource($process)) {
            stream_set_blocking($pipes[1], 0);
            stream_set_blocking($pipes[2], 0);

            fclose($pipes[0]);

            $tmpout = '';
            $tmperr = '';
            while (feof($pipes[1]) === false || feof($pipes[2]) === false) {
                elFinder::extendTimeLimit();
                $read = array($pipes[1], $pipes[2]);
                $write = null;
                $except = null;
                $ret = stream_select($read, $write, $except, 1);
                if ($ret === false) {
                    // error
                    break;
                } else if ($ret === 0) {
                    // timeout
                    continue;
                } else {
                    foreach ($read as $sock) {
                        if ($sock === $pipes[1]) {
                            $tmpout .= fread($sock, 4096);
                        } else if ($sock === $pipes[2]) {
                            $tmperr .= fread($sock, 4096);
                        }
                    }
                }
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            $output = $tmpout;
            $error_output = $tmperr;
            $return_var = proc_close($process);

        } else {
            $return_var = -1;
        }

        return $return_var;

    }

    /***************************************************************************/
    /*                                 callbacks                               */
    /***************************************************************************/

    /**
     * Get command name of binded "commandName.subName"
     *
     * @param string $cmd
     *
     * @return string
     */
    protected static function getCmdOfBind($cmd)
    {
        list($ret) = explode('.', $cmd);
        return trim($ret);
    }

    /**
     * Add subName to commandName
     *
     * @param string $cmd
     * @param string $sub
     *
     * @return string
     */
    protected static function addSubToBindName($cmd, $sub)
    {
        return $cmd . '.' . trim($sub);
    }

    /**
     * Remove a file if connection is disconnected
     *
     * @param string $file
     */
    public static function rmFileInDisconnected($file)
    {
        (connection_aborted() || connection_status() !== CONNECTION_NORMAL) && is_file($file) && unlink($file);
    }

    /**
     * Call back function on shutdown
     *  - delete files in $GLOBALS['elFinderTempFiles']
     */
    public static function onShutdown()
    {
        self::$abortCheckFile = null;
        if (!empty($GLOBALS['elFinderTempFps'])) {
            foreach (array_keys($GLOBALS['elFinderTempFps']) as $fp) {
                is_resource($fp) && fclose($fp);
            }
        }
        if (!empty($GLOBALS['elFinderTempFiles'])) {
            foreach (array_keys($GLOBALS['elFinderTempFiles']) as $f) {
                //Make sure paths are safe before deleting them
                $tf = elFinder::$commonTempPath . DIRECTORY_SEPARATOR . basename($f);
                is_file($tf) && is_writable($tf) && unlink($tf);
            }
        unset($f);
        }

        //Delete abort file paths
        if(!empty($GLOBALS['elFinderAbortFiles'])) {
            foreach (array_keys($GLOBALS['elFinderAbortFiles']) as $f) {
                //Make sure paths are safe before deleting them
                $tf = elFinder::$connectionFlagsPath . DIRECTORY_SEPARATOR . basename($f);
                is_file($tf) && is_writable($tf) && unlink($tf);
            }
            unset($f);
        }

    }

    /**
     * Garbage collection with glob
     *
     * @param string  $pattern
     * @param integer $time
     */
    public static function GlobGC($pattern, $time)
    {
        $now = time();
        foreach (glob($pattern) as $file) {
            (filemtime($file) < ($now - $time)) && unlink($file);
        }
    }

} // END class

/**
 * Custom exception class for aborting request
 */
class elFinderAbortException extends Exception
{
}

class elFinderTriggerException extends Exception
{
}

}

/* ===================== [embedded] elFinderConnector ===================== */
if (!class_exists('elFinderConnector', false)) {
/**
 * Default elFinder connector
 *
 * @author Dmitry (dio) Levashov
 **/
class elFinderConnector
{
    /**
     * elFinder instance
     *
     * @var elFinder
     **/
    protected $elFinder;

    /**
     * Options
     *
     * @var array
     **/
    protected $options = array();

    /**
     * Must be use output($data) $data['header']
     *
     * @var string
     * @deprecated
     **/
    protected $header = '';

    /**
     * HTTP request method
     *
     * @var string
     */
    protected $reqMethod = '';

    /**
     * Content type of output JSON
     *
     * @var string
     */
    protected static $contentType = 'Content-Type: application/json; charset=utf-8';

    /**
     * Constructor
     *
     * @param      $elFinder
     * @param bool $debug
     *
     * @author Dmitry (dio) Levashov
     */
    public function __construct($elFinder, $debug = false)
    {

        $this->elFinder = $elFinder;
        $this->reqMethod = strtoupper($_SERVER["REQUEST_METHOD"]);
        if ($debug) {
            self::$contentType = 'Content-Type: text/plain; charset=utf-8';
        }
    }

    /**
     * Execute elFinder command and output result
     *
     * @return void
     * @throws Exception
     * @author Dmitry (dio) Levashov
     */
    public function run()
    {
        $isPost = $this->reqMethod === 'POST';
        $src = $isPost ? array_merge($_GET, $_POST) : $_GET;
        $maxInputVars = (!$src || isset($src['targets'])) ? ini_get('max_input_vars') : null;
        if ((!$src || $maxInputVars) && $rawPostData = file_get_contents('php://input')) {
            // for max_input_vars and supports IE XDomainRequest()
            $parts = explode('&', $rawPostData);
            if (!$src || $maxInputVars < count($parts)) {
                $src = array();
                foreach ($parts as $part) {
                    list($key, $value) = array_pad(explode('=', $part), 2, '');
                    $key = rawurldecode($key);
                    if (preg_match('/^(.+?)\[([^\[\]]*)\]$/', $key, $m)) {
                        $key = $m[1];
                        $idx = $m[2];
                        if (!isset($src[$key])) {
                            $src[$key] = array();
                        }
                        if ($idx) {
                            $src[$key][$idx] = rawurldecode($value);
                        } else {
                            $src[$key][] = rawurldecode($value);
                        }
                    } else {
                        $src[$key] = rawurldecode($value);
                    }
                }
                $_POST = $this->input_filter($src);
                $_REQUEST = $this->input_filter(array_merge_recursive($src, $_REQUEST));
            }
        }

        if (isset($src['targets']) && $this->elFinder->maxTargets && count($src['targets']) > $this->elFinder->maxTargets) {
            $this->output(array('error' => $this->elFinder->error(elFinder::ERROR_MAX_TARGTES)));
        }

        $cmd = isset($src['cmd']) ? $src['cmd'] : '';
        $args = array();

        if (!function_exists('json_encode')) {
            $error = $this->elFinder->error(elFinder::ERROR_CONF, elFinder::ERROR_CONF_NO_JSON);
            $this->output(array('error' => '{"error":["' . implode('","', $error) . '"]}', 'raw' => true));
        }

        if (!$this->elFinder->loaded()) {
            $this->output(array('error' => $this->elFinder->error(elFinder::ERROR_CONF, elFinder::ERROR_CONF_NO_VOL), 'debug' => $this->elFinder->mountErrors));
        }

        // telepat_mode: on
        if (!$cmd && $isPost) {
            $this->output(array('error' => $this->elFinder->error(elFinder::ERROR_UPLOAD, elFinder::ERROR_UPLOAD_TOTAL_SIZE), 'header' => 'Content-Type: text/html'));
        }
        // telepat_mode: off

        if (!$this->elFinder->commandExists($cmd)) {
            $this->output(array('error' => $this->elFinder->error(elFinder::ERROR_UNKNOWN_CMD)));
        }

        // collect required arguments to exec command
        $hasFiles = false;
        foreach ($this->elFinder->commandArgsList($cmd) as $name => $req) {
            if ($name === 'FILES') {
                if (isset($_FILES)) {
                    $hasFiles = true;
                } elseif ($req) {
                    $this->output(array('error' => $this->elFinder->error(elFinder::ERROR_INV_PARAMS, $cmd)));
                }
            } else {
                $arg = isset($src[$name]) ? $src[$name] : '';

                if (!is_array($arg) && $req !== '') {
                    $arg = trim($arg);
                }
                if ($req && $arg === '') {
                    $this->output(array('error' => $this->elFinder->error(elFinder::ERROR_INV_PARAMS, $cmd)));
                }
                $args[$name] = $arg;
            }
        }

        $args['debug'] = isset($src['debug']) ? !!$src['debug'] : false;

        $args = $this->input_filter($args);
        if ($hasFiles) {
            $args['FILES'] = $_FILES;
        }

        try {
            $this->output($this->elFinder->exec($cmd, $args));
        } catch (elFinderAbortException $e) {
            // connection aborted
            // unlock session data for multiple access
            $this->elFinder->getSession()->close();
            // HTTP response code
            header('HTTP/1.0 204 No Content');
            // clear output buffer
            while (ob_get_level() && ob_end_clean()) {
            }
            exit();
        }
    }

    /**
     * Sets the header.
     *
     * @param array|string  $value HTTP header(s)
     */
    public function setHeader($value)
    {
        $this->header = $value;
    }

    /**
     * Output json
     *
     * @param  array  data to output
     *
     * @return void
     * @throws elFinderAbortException
     * @author Dmitry (dio) Levashov
     */
    protected function output(array $data)
    {
        // unlock session data for multiple access
        $this->elFinder->getSession()->close();
        // client disconnect should abort
        ignore_user_abort(false);

        if ($this->header) {
            self::sendHeader($this->header);
        }

        if (isset($data['pointer'])) {
            // set time limit to 0
            elFinder::extendTimeLimit(0);

            // send optional header
            if (!empty($data['header'])) {
                self::sendHeader($data['header']);
            }

            // clear output buffer
            while (ob_get_level() && ob_end_clean()) {
            }

            $toEnd = true;
            $fp = $data['pointer'];
            $sendData = !($this->reqMethod === 'HEAD' || !empty($data['info']['xsendfile']));
            $psize = null;
            if (($this->reqMethod === 'GET' || !$sendData)
                && (elFinder::isSeekableStream($fp) || elFinder::isSeekableUrl($fp))
                && (array_search('Accept-Ranges: none', headers_list()) === false)) {
                header('Accept-Ranges: bytes');
                if (!empty($_SERVER['HTTP_RANGE'])) {
                    $size = $data['info']['size'];
                    $end = $size - 1;
                    if (preg_match('/bytes=(\d*)-(\d*)(,?)/i', $_SERVER['HTTP_RANGE'], $matches)) {
                        if (empty($matches[3])) {
                            if (empty($matches[1]) && $matches[1] !== '0') {
                                $start = $size - $matches[2];
                            } else {
                                $start = intval($matches[1]);
                                if (!empty($matches[2])) {
                                    $end = intval($matches[2]);
                                    if ($end >= $size) {
                                        $end = $size - 1;
                                    }
                                    $toEnd = ($end == ($size - 1));
                                }
                            }
                            $psize = $end - $start + 1;

                            header('HTTP/1.1 206 Partial Content');
                            header('Content-Length: ' . $psize);
                            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);

                            // Apache mod_xsendfile dose not support range request
                            if (isset($data['info']['xsendfile']) && strtolower($data['info']['xsendfile']) === 'x-sendfile') {
                                if (function_exists('header_remove')) {
                                    header_remove($data['info']['xsendfile']);
                                } else {
                                    header($data['info']['xsendfile'] . ':');
                                }
                                unset($data['info']['xsendfile']);
                                if ($this->reqMethod !== 'HEAD') {
                                    $sendData = true;
                                }
                            }

                            $sendData && !elFinder::isSeekableUrl($fp) && fseek($fp, $start);
                        }
                    }
                }
                if ($sendData && is_null($psize)) {
                    elFinder::rewind($fp);
                }
            } else {
                header('Accept-Ranges: none');
                if (isset($data['info']) && !$data['info']['size']) {
                    if (function_exists('header_remove')) {
                        header_remove('Content-Length');
                    } else {
                        header('Content-Length:');
                    }
                }
            }

            if ($sendData) {
                if ($toEnd || elFinder::isSeekableUrl($fp)) {
                    // PHP < 5.6 has a bug of fpassthru
                    // see https://bugs.php.net/bug.php?id=66736
                    if (version_compare(PHP_VERSION, '5.6', '<')) {
                        file_put_contents('php://output', $fp);
                    } else {
                        if(function_exists('fpassthru')) {
                            fpassthru($fp);
                        } else {
                            file_put_contents('php://output', $fp);
                        }
                    }
                } else {
                    $out = fopen('php://output', 'wb');
                    stream_copy_to_stream($fp, $out, $psize);
                    fclose($out);
                }
            }

            if (!empty($data['volume'])) {
                $data['volume']->close($fp, $data['info']['hash']);
            } else {
                fclose($fp);
            }
            exit();
        } else {
            self::outputJson($data);
            exit(0);
        }
    }

    /**
     * Remove null & stripslashes applies on "magic_quotes_gpc"
     *
     * @param  mixed $args
     *
     * @return mixed
     * @author Naoki Sawada
     */
    protected function input_filter($args)
    {
        static $magic_quotes_gpc = NULL;

        if ($magic_quotes_gpc === NULL)
            $magic_quotes_gpc = (version_compare(PHP_VERSION, '5.4', '<') && get_magic_quotes_gpc());

        if (is_array($args)) {
            return array_map(array(& $this, 'input_filter'), $args);
        }
        $res = str_replace("\0", '', $args);
        $magic_quotes_gpc && ($res = stripslashes($res));
        $res = stripslashes($res);
        return $res;
    }

    /**
     * Send HTTP header
     *
     * @param string|array $header optional header
     */
    protected static function sendHeader($header = null)
    {
        if ($header) {
            if (is_array($header)) {
                foreach ($header as $h) {
                    header($h);
                }
            } else {
                header($header);
            }
        }
    }

    /**
     * Output JSON
     *
     * @param array $data
     */
    public static function outputJson($data)
    {
        // send header
        $header = isset($data['header']) ? $data['header'] : self::$contentType;
        self::sendHeader($header);

        unset($data['header']);

        if (!empty($data['raw']) && isset($data['error'])) {
            $out = $data['error'];
        } else {
            if (isset($data['debug']) && isset($data['debug']['backendErrors'])) {
                $data['debug']['backendErrors'] = array_merge($data['debug']['backendErrors'], elFinder::$phpErrors);
            }
            $out = json_encode($data);
        }

        // clear output buffer
        while (ob_get_level() && ob_end_clean()) {
        }

        header('Content-Length: ' . strlen($out));

        echo $out;

        flush();
    }
}// END class 

}

/* ============================== CONNECTOR ============================== */
if (fox_is_connector()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    if (!fox_authed()) {
        http_response_code(403);
        echo json_encode(array('error' => array('errAccess'), 'errorData' => array('Access denied')));
        exit;
    }

    $fox_tmb = sys_get_temp_dir() . '/foxmanager_tmb';
    if (!is_dir($fox_tmb)) {
        @mkdir($fox_tmb, 0777, true);
    }
    if (!is_writable($fox_tmb)) {
        $fox_tmb = __DIR__;
    }

    $attributes = array(
        array('pattern' => '#/\.tmb/#i',        'read' => false, 'write' => false, 'hidden' => true, 'locked' => true),
        array('pattern' => '#/\.quarantine/#i', 'read' => false, 'write' => false, 'hidden' => true, 'locked' => true),
    );
    foreach ($FOX['hidden'] as $fox_pat) {
        $attributes[] = array('pattern' => $fox_pat, 'read' => false, 'write' => false, 'hidden' => true, 'locked' => false);
    }

    $fox_disabled = array('netmount', 'zipdl', 'help');
    if ($FOX['read_only']) {
        $fox_disabled = array_merge($fox_disabled, array(
            'upload', 'mkdir', 'mkfile', 'rm', 'rename', 'paste',
            'duplicate', 'edit', 'resize', 'chmod', 'archive', 'extract', 'cut', 'copy'
        ));
    }

    $opts = array(
        'debug' => false,
        'roots' => array(array(
            'driver'       => 'LocalFileSystem',
            'path'         => $FOX_ROOT,
            'URL'          => $FOX_URL,
            'winHashFix'   => DIRECTORY_SEPARATOR !== '/',
            'uploadDeny'   => array(),
            'uploadAllow'  => array('all'),
            'uploadOrder'  => array('deny', 'allow'),
            'disabled'     => $fox_disabled,
            'attributes'   => $attributes,
            'tmbPath'      => $fox_tmb,
            'tmbURL'       => 'self',
            'tmbSize'      => 96,
            'acceptedName' => '/^[^\.\/\x00][^\/\x00]*$/',
        )),
    );

    $fox_connector = new elFinderConnector(new elFinder($opts));
    $fox_connector->run();
    exit;
}

/* ============================== HALAMAN UI ============================== */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$fox_authed = fox_authed();
$fox_self   = fox_self_url();
$fox_locale = preg_replace('/[^a-zA-Z0-9_-]/', '', $FOX['locale']);
if ($fox_locale === '') {
    $fox_locale = 'en';
}
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($fox_locale); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>FoxManager</title>
<style>
:root{
  --bg:#f3f4f6; --panel:#ffffff; --panel2:#f1f3f6; --line:#e3e5ea;
  --text:#1f2329; --muted:#6b7280; --accent:#f97316; --accent2:#fb923c;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;height:100%;background:var(--bg);color:var(--text);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}
a{color:var(--accent)}
.fm-top{height:52px;display:flex;align-items:center;gap:14px;padding:0 18px;
  background:var(--panel);border-bottom:1px solid var(--line);}
.fm-logo{font-weight:800;font-size:17px;letter-spacing:.3px;display:flex;align-items:center;gap:9px;}
.fm-logo .dot{width:12px;height:12px;border-radius:3px;background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:inline-block;box-shadow:0 0 12px rgba(249,115,22,.35)}
.fm-root{font-size:12px;color:var(--muted);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.fm-btn{text-decoration:none;font-size:13px;font-weight:600;color:var(--text);
  background:var(--panel2);border:1px solid var(--line);padding:7px 14px;border-radius:8px;}
.fm-btn:hover{border-color:var(--accent);color:var(--accent)}
#fox-elfinder{height:calc(100vh - 52px)}
/* login */
.login-wrap{height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.login-card{width:360px;max-width:100%;background:var(--panel);border:1px solid var(--line);
  border-radius:14px;padding:34px 30px;box-shadow:0 18px 50px rgba(0,0,0,.08)}
.login-card h1{margin:0 0 6px;font-size:22px;display:flex;align-items:center;gap:10px}
.login-card .sub{color:var(--muted);font-size:13px;margin:0 0 24px}
.login-card input[type=password]{width:100%;padding:12px 14px;border-radius:9px;border:1px solid var(--line);
  background:var(--panel2);color:var(--text);font-size:15px;outline:none}
.login-card input[type=password]:focus{border-color:var(--accent)}
.login-card button{width:100%;margin-top:14px;padding:12px;border-radius:9px;border:none;cursor:pointer;
  background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:15px;font-weight:700}
.login-card button:disabled{opacity:.6;cursor:default}
.login-err{color:#dc2626;font-size:13px;margin-top:12px;min-height:18px}
.login-foot{margin-top:20px;font-size:11px;color:var(--muted);text-align:center}
</style>
</head>
<body>
<?php if (!$fox_authed): ?>
<div class="login-wrap">
  <form class="login-card" id="fox-login" autocomplete="off">
    <h1><span class="dot"></span>FoxManager</h1>
    <p class="sub">Masukkan password untuk mengakses file manager.</p>
    <input type="password" name="fox_password" id="fox-password" placeholder="Password" autofocus>
    <button type="submit" id="fox-submit">Masuk</button>
    <div class="login-err" id="fox-err"></div>
    <div class="login-foot">FoxManager &middot; single-file web file manager</div>
  </form>
</div>
<script>
(function(){
  var form=document.getElementById('fox-login'),
      pass=document.getElementById('fox-password'),
      btn=document.getElementById('fox-submit'),
      err=document.getElementById('fox-err');
  form.addEventListener('submit',function(e){
    e.preventDefault();
    err.textContent='';
    btn.disabled=true; btn.textContent='Memproses...';
    var body=new URLSearchParams();
    body.append('fox_action','login');
    body.append('fox_password',pass.value);
    fetch(window.location.href,{method:'POST',body:body,
      headers:{'Content-Type':'application/x-www-form-urlencoded'}})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d && d.ok){ window.location.reload(); }
        else { err.textContent=(d&&d.error)?d.error:'Password salah.'; btn.disabled=false; btn.textContent='Masuk'; }
      })
      .catch(function(){ err.textContent='Gagal terhubung.'; btn.disabled=false; btn.textContent='Masuk'; });
  });
})();
</script>
<?php else: ?>
<div class="fm-top">
  <div class="fm-logo"><span class="dot"></span>FoxManager</div>
  <div class="fm-root" title="<?php echo htmlspecialchars($FOX_ROOT); ?>"><?php echo htmlspecialchars($FOX_ROOT); ?></div>
  <a class="fm-btn" href="<?php echo htmlspecialchars($fox_self); ?>?fox_action=logout">Logout</a>
</div>
<div id="fox-elfinder"></div>
<link rel="stylesheet" href="<?php echo htmlspecialchars($FOX['elfinder_cdn']); ?>css/elfinder.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars($FOX['elfinder_cdn']); ?>css/theme.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>lib/codemirror.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>theme/monokai.min.css">
<script src="<?php echo htmlspecialchars($FOX['jquery_cdn']); ?>"></script>
<script src="<?php echo htmlspecialchars($FOX['jquery_ui_js']); ?>"></script>
<script src="<?php echo htmlspecialchars($FOX['elfinder_cdn']); ?>js/elfinder.min.js"></script>
<script src="<?php echo htmlspecialchars($FOX['elfinder_cdn']); ?>js/i18n/elfinder.<?php echo htmlspecialchars($fox_locale); ?>.js"></script>
<script src="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>lib/codemirror.min.js"></script>
<script src="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>mode/clike/clike.min.js"></script>
<script src="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>mode/xml/xml.min.js"></script>
<script src="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>mode/javascript/javascript.min.js"></script>
<script src="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>mode/css/css.min.js"></script>
<script src="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>mode/htmlmixed/htmlmixed.min.js"></script>
<script src="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>mode/php/php.min.js"></script>
<script src="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>mode/sql/sql.min.js"></script>
<script src="<?php echo htmlspecialchars($FOX['codemirror_cdn']); ?>mode/markdown/markdown.min.js"></script>
<script>
jQuery(function($){
  $('#fox-elfinder').elfinder({
    url: <?php echo json_encode($fox_self); ?>,
    lang: <?php echo json_encode($fox_locale); ?>,
    baseUrl: <?php echo json_encode($FOX['elfinder_cdn']); ?>,
    soundPath: <?php echo json_encode($FOX['elfinder_cdn'] . 'sounds/'); ?>,
    height: '100%',
    resizable: false,
    cssAutoLoad: false,
    commandsOptions: {
      edit: {
        mimes: [],
        editors: [{
          info: { id: 'codemirror', name: 'CodeMirror', canMakeEmpty: true },
          mimes: [
            'text/plain','text/html','text/css','text/javascript','application/javascript',
            'text/x-php','application/x-php','application/x-httpd-php',
            'text/xml','application/xml','application/xhtml+xml',
            'text/x-sql','text/x-markdown','text/x-c','text/x-csrc','text/x-c++',
            'text/x-c++src','text/x-java','application/json','text/x-shellscript',
            'text/x-python','text/x-ruby','text/x-perl','application/x-sh','text/x-sh'
          ],
          load: function(textarea) {
            var modeMap = {
              'text/x-php':'php','application/x-php':'php','application/x-httpd-php':'php',
              'text/html':'htmlmixed','application/xhtml+xml':'htmlmixed',
              'text/css':'css',
              'text/javascript':'javascript','application/javascript':'javascript','application/json':'javascript',
              'text/xml':'xml','application/xml':'xml',
              'text/x-sql':'sql',
              'text/x-markdown':'markdown',
              'text/x-c':'clike','text/x-csrc':'clike','text/x-c++':'clike','text/x-c++src':'clike','text/x-java':'clike',
              'text/x-shellscript':'shell','application/x-sh':'shell','text/x-sh':'shell',
              'text/x-python':'python','text/x-ruby':'ruby','text/x-perl':'perl'
            };
            var mode = modeMap[this.file.mime] || null;
            var editor = CodeMirror.fromTextArea(textarea, {
              mode: mode,
              indentUnit: 4,
              lineNumbers: true,
              lineWrapping: true,
              theme: 'monokai',
              viewportMargin: Infinity
            });
            return editor;
          },
          close: function(textarea, instance) {},
          save: function(textarea, editor) {
            jQuery(textarea).val(editor.getValue());
          }
        }]
      }
    }
  }).elfinder('instance');
});
</script>
<?php endif; ?>
</body>
</html>
