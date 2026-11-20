<?php
declare(strict_types=1);

/** Autentikasi berbasis sesi. Kata sandi disimpan sebagai hash (UU PDP Pasal 35, 39; B4). */
final class Auth {
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }
    }

    public static function login(string $username, string $password): array {
        $st = Db::pdo()->prepare(
            'SELECT u.user_id, u.username, u.nama, u.password_hash, u.status_aktif, r.nama_role AS role
             FROM app_user u JOIN role r ON r.role_id = u.role_id WHERE u.username = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        // Pesan sengaja sama untuk "pengguna tidak ada" dan "sandi salah" (tidak membocorkan nama pengguna).
        if (!$u || !password_verify($password, $u['password_hash'])) {
            throw new ApiError(401, 'LOGIN_GAGAL', 'Nama pengguna atau kata sandi salah.');
        }
        if ((int)$u['status_aktif'] !== 1) {
            throw new ApiError(403, 'AKUN_NONAKTIF', 'Akun tidak aktif.');
        }
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user'] = ['user_id' => (int)$u['user_id'], 'username' => $u['username'], 'nama' => $u['nama'], 'role' => $u['role']];
        return $_SESSION['user'];
    }

    public static function logout(): void {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function user(): array {
        self::startSession();
        $u = $_SESSION['user'] ?? null;
        session_write_close(); // lepaskan kunci sesi: rute selain login/logout hanya membaca sesi, jadi tidak perlu mengantre
        if (empty($u)) throw new ApiError(401, 'BELUM_LOGIN', 'Silakan login terlebih dahulu.');
        return $u;
    }
}
