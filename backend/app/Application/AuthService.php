<?php
namespace App\Application;

use Core\Database;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Infrastructure\GoogleAuthenticator;

class AuthService
{
    private const RESET_TOKEN_TTL_SECONDS = 3600;

    public function register(array $data)
    {
        $db = Database::getInstance();

        // 1. Check Role 'CUSTOMER'
        $roleStmt = $db->prepare('SELECT id FROM role WHERE name = ?');
        $roleStmt->execute(['CUSTOMER']);
        $role = $roleStmt->fetch();
        if (!$role) {
            $db->exec("INSERT IGNORE INTO role (name, description) VALUES ('ADMIN', 'Administrator'), ('MANAGER', 'Manager'), ('SALES_STAFF', 'Sales'), ('OPERATIONS_STAFF', 'Operations'), ('CUSTOMER', 'Customer')");
            $roleStmt->execute(['CUSTOMER']);
            $role = $roleStmt->fetch();
        }
        $roleId = $role['id'];

        // 2. Check email in user table
        $stmt = $db->prepare('SELECT id, status, verify_token, full_name FROM `user` WHERE email = ?');
        $stmt->execute([$data['email']]);
        $existingUser = $stmt->fetch();
        if ($existingUser) {
            throw new \Exception('This email is already registered. Please sign in.');
        }

        $hash = password_hash($data['password'], PASSWORD_DEFAULT);

        // 3. Insert into user table with 'active' status directly
        $stmt = $db->prepare('INSERT INTO `user` (full_name, email, password_hash, verify_token, status) VALUES (?, ?, ?, NULL, ?)');
        $stmt->execute([$data['name'], $data['email'], $hash, 'active']);
        $userId = $db->lastInsertId();

        // 4. Assign default role (Customer)
        $roleInsert = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $roleInsert->execute([$userId, $roleId]);

        return [
            'id' => $userId,
            'name' => $data['name'],
            'email' => $data['email'],
            'roles' => ['customer'],
            'verification_url' => null,
            'email_sent' => false,
        ];
    }

    public function login(array $credentials)
    {
        $db = Database::getInstance();
        
        // Truy vấn user
        $stmt = $db->prepare('SELECT * FROM `user` WHERE email = ?');
        $stmt->execute([$credentials['email']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($credentials['password'], $user['password_hash'])) {
            throw new \Exception('Invalid credentials');
        }

        if ($user['status'] !== 'active') {
            throw new \Exception('Please verify your email or contact admin.');
        }

        if (isset($user['two_factor_enabled']) && (int)$user['two_factor_enabled'] === 1) {
            $tempToken = base64_encode('2fa_temp:' . $user['id'] . ':' . time());
            
            return [
                'requires_2fa' => true,
                'temp_token' => $tempToken,
                'email' => $user['email']
            ];
        }

        // Get all Roles of User
        $roleStmt = $db->prepare('SELECT r.name FROM role r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?');
        $roleStmt->execute([$user['id']]);
        $roles = $roleStmt->fetchAll(\PDO::FETCH_COLUMN);

        // Get all Permissions via Role
        $permStmt = $db->prepare("
            SELECT DISTINCT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            JOIN user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $permStmt->execute([$user['id']]);
        $permissions = $permStmt->fetchAll(\PDO::FETCH_COLUMN);

        $avatarStmt = $db->prepare('SELECT avatar FROM profiles WHERE user_id = ? LIMIT 1');
        $avatarStmt->execute([$user['id']]);
        $avatar = $avatarStmt->fetchColumn();

        $tokenBody = $user['id'] . ':' . implode(',', $roles) . ':' . time();
        $token = base64_encode($tokenBody);

        return [
            'user' => [
                'id' => $user['id'],
                'name' => $user['full_name'],
                'email' => $user['email'],
                'roles' => $roles,
                'permissions' => $permissions,
                'avatar' => $avatar ?: null,
            ],
            'token' => $token
        ];
    }

    public function googleLogin(string $idToken): array
    {
        // 1. Verify token with Google
        $googleUser = $this->verifyGoogleIdToken($idToken);
        $email = $googleUser['email'];
        $name = $googleUser['name'];
        
        $db = Database::getInstance();
        
        // 2. Look up user in database
        $stmt = $db->prepare('SELECT * FROM `user` WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // User does not exist -> auto-register them
            // A. Get CUSTOMER role ID
            $roleStmt = $db->prepare('SELECT id FROM role WHERE name = ?');
            $roleStmt->execute(['CUSTOMER']);
            $role = $roleStmt->fetch();
            if (!$role) {
                $db->exec("INSERT IGNORE INTO role (name, description) VALUES ('ADMIN', 'Administrator'), ('MANAGER', 'Manager'), ('SALES_STAFF', 'Sales'), ('OPERATIONS_STAFF', 'Operations'), ('CUSTOMER', 'Customer')");
                $roleStmt->execute(['CUSTOMER']);
                $role = $roleStmt->fetch();
            }
            $roleId = $role['id'];
            
            // B. Create a random password since they use Google
            $randomPassword = bin2hex(random_bytes(16));
            $hash = password_hash($randomPassword, PASSWORD_DEFAULT);
            
            // C. Insert user
            $insertStmt = $db->prepare('INSERT INTO `user` (full_name, email, password_hash, verify_token, status) VALUES (?, ?, ?, NULL, ?)');
            $insertStmt->execute([$name, $email, $hash, 'active']);
            $userId = $db->lastInsertId();
            
            // D. Assign CUSTOMER role
            $roleInsert = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
            $roleInsert->execute([$userId, $roleId]);
            
            // Re-fetch user
            $stmt->execute([$email]);
            $user = $stmt->fetch();
        } else {
            // User exists -> verify status
            if ($user['status'] !== 'active') {
                throw new \Exception('Your account is blocked or inactive. Please contact admin.');
            }
        }
        
        // 3. Get user roles, permissions, avatar (same as normal login)
        $roleStmt = $db->prepare('SELECT r.name FROM role r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?');
        $roleStmt->execute([$user['id']]);
        $roles = $roleStmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $permStmt = $db->prepare("
            SELECT DISTINCT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            JOIN user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $permStmt->execute([$user['id']]);
        $permissions = $permStmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $avatarStmt = $db->prepare('SELECT avatar FROM profiles WHERE user_id = ? LIMIT 1');
        $avatarStmt->execute([$user['id']]);
        $avatar = $avatarStmt->fetchColumn();
        
        // Generate stateless token
        $tokenBody = $user['id'] . ':' . implode(',', $roles) . ':' . time();
        $token = base64_encode($tokenBody);
        
        return [
            'user' => [
                'id' => $user['id'],
                'name' => $user['full_name'],
                'email' => $user['email'],
                'roles' => $roles,
                'permissions' => $permissions,
                'avatar' => $avatar ?: null,
            ],
            'token' => $token
        ];
    }

    private function verifyGoogleIdToken(string $idToken): array
    {
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'ignore_errors' => true,
                'timeout' => 10,
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \Exception('Failed to connect to Google authentication server.');
        }
        
        $data = json_decode($response, true);
        if (empty($data) || isset($data['error']) || isset($data['error_description'])) {
            $errorMsg = $data['error_description'] ?? $data['error'] ?? 'Invalid Google token.';
            throw new \Exception('Google token validation failed: ' . $errorMsg);
        }
        
        if (($data['email_verified'] ?? 'false') !== 'true' && ($data['email_verified'] ?? false) !== true) {
            throw new \Exception('Google email is not verified.');
        }
        
        return [
            'email' => $data['email'],
            'name' => $data['name'] ?? $data['given_name'] ?? $data['email'],
        ];
    }

    public function verifyTwoFactorCode(string $tempToken, string $code): array
    {
        $decoded = base64_decode($tempToken, true);
        if ($decoded === false) {
            throw new \Exception('Invalid session.');
        }
        
        $parts = explode(':', $decoded);
        if (count($parts) !== 3 || $parts[0] !== '2fa_temp') {
            throw new \Exception('Invalid session.');
        }
        
        $userId = (int)$parts[1];
        $timestamp = (int)$parts[2];
        
        if (time() - $timestamp > 900) {
            throw new \Exception('Session expired. Please log in again.');
        }
        
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM `user` WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new \Exception('User not found.');
        }
        
        if (empty($user['two_factor_secret'])) {
            throw new \Exception('Two-factor authentication secret is not set.');
        }
        
        if (!GoogleAuthenticator::verifyCode($user['two_factor_secret'], $code)) {
            throw new \Exception('Invalid verification code.');
        }
        
        $roleStmt = $db->prepare('SELECT r.name FROM role r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?');
        $roleStmt->execute([$user['id']]);
        $roles = $roleStmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $permStmt = $db->prepare("
            SELECT DISTINCT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            JOIN user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $permStmt->execute([$user['id']]);
        $permissions = $permStmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $avatarStmt = $db->prepare('SELECT avatar FROM profiles WHERE user_id = ? LIMIT 1');
        $avatarStmt->execute([$user['id']]);
        $avatar = $avatarStmt->fetchColumn();
        
        $tokenBody = $user['id'] . ':' . implode(',', $roles) . ':' . time();
        $token = base64_encode($tokenBody);
        
        return [
            'user' => [
                'id' => $user['id'],
                'name' => $user['full_name'],
                'email' => $user['email'],
                'roles' => $roles,
                'permissions' => $permissions,
                'avatar' => $avatar ?: null,
            ],
            'token' => $token
        ];
    }

    private function sendTwoFactorEmail(string $email, string $name, string $code): void
    {
        $config = $this->loadEnvConfig();
        $mailHost = $config['MAIL_HOST'] ?? 'smtp.gmail.com';
        $mailPort = $config['MAIL_PORT'] ?? 587;
        $mailEncryption = $config['MAIL_ENCRYPTION'] ?? 'tls';
        $mailUsername = $config['MAIL_USERNAME'] ?? '';
        $mailPassword = $config['MAIL_PASSWORD'] ?? '';
        $mailFrom = $config['MAIL_FROM_ADDRESS'] ?? $mailUsername;
        $mailFromName = $config['MAIL_FROM_NAME'] ?? 'Eyewear System';

        if (!$mailUsername || !$mailPassword) {
            throw new \Exception('SMTP email configuration is missing.');
        }

        if (!class_exists(PHPMailer::class)) {
            require_once dirname(__DIR__, 2) . '/PHPMailer/Exception.php';
            require_once dirname(__DIR__, 2) . '/PHPMailer/PHPMailer.php';
            require_once dirname(__DIR__, 2) . '/PHPMailer/SMTP.php';
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $mailHost;
        $mail->SMTPAuth = true;
        $mail->Username = $mailUsername;
        $mail->Password = $mailPassword;
        $mail->SMTPSecure = $mailEncryption;
        $mail->Port = (int) $mailPort;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($mailFrom, $mailFromName);
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = '[EVLS] Two-Factor Authentication Code';
        $logoHtml = $this->buildEmbeddedLogoHtml($mail);
        
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $logoBlock = $logoHtml === '' ? '' : '<div style="text-align: center; padding: 20px 28px 8px 28px;">' . $logoHtml . '</div>';

        $mail->Body = <<<HTML
<div style="font-family: Arial, Helvetica, sans-serif; line-height: 1.7; color: #1f2937; background: #f7faf9; padding: 24px;">
    <div style="max-width: 620px; margin: 0 auto; background: #ffffff; border: 1px solid #e4ede9; border-radius: 18px; overflow: hidden;">
        {$logoBlock}
        <div style="background: linear-gradient(135deg, #0f8b7c, #0b6f63); color: #fff; padding: 22px 28px;">
            <h2 style="margin: 0; font-size: 22px;">[EVLS] Your Security Verification Code</h2>
        </div>
        <div style="padding: 28px; font-size: 15px;">
            <p style="margin: 0 0 14px 0;">Hello {$safeName},</p>
            <p style="margin: 0 0 14px 0;">A login request was made for your EVLS account. Please use the following verification code to complete your sign-in:</p>
            <p style="text-align: center; margin: 28px 0;">
                <span style="display: inline-block; background: #f3f4f6; color: #0f8b7c; font-size: 32px; font-weight: 700; letter-spacing: 6px; padding: 12px 36px; border-radius: 8px; border: 1px dashed #0f8b7c;">{$safeCode}</span>
            </p>
            <p style="margin: 0 0 14px 0; color: #ef4444;">This code is valid for 5 minutes. Do not share this code with anyone.</p>
            <p style="margin: 24px 0 0 0; font-size: 13px; color: #6b7280;">If you did not make this request, please ignore this email and change your password immediately.</p>
        </div>
    </div>
</div>
HTML;

        if (!$mail->send()) {
            throw new \Exception('Could not send two-factor email.');
        }
    }

    public function logout(?string $token = null): bool
    {
        // Stateless token approach: logout is handled client-side by removing the token.
        return true;
    }

    public function getCurrentUser(): ?array
    {
        $userId = $this->resolveUserIdFromAuthorization();
        if (!$userId) {
            return null;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, full_name, email, status, two_factor_enabled FROM `user` WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        $roleStmt = $db->prepare('SELECT r.name FROM role r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?');
        $roleStmt->execute([$userId]);
        $user['roles'] = $roleStmt->fetchAll(\PDO::FETCH_COLUMN);

        // Get all Permissions
        $permStmt = $db->prepare("
            SELECT DISTINCT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            JOIN user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $permStmt->execute([$userId]);
        $user['permissions'] = $permStmt->fetchAll(\PDO::FETCH_COLUMN);

        $avatarStmt = $db->prepare('SELECT avatar FROM profiles WHERE user_id = ? LIMIT 1');
        $avatarStmt->execute([$userId]);
        $user['avatar'] = $avatarStmt->fetchColumn() ?: null;

        return $user;
    }

    private function resolveUserIdFromAuthorization(): ?int
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($authHeader, 7));
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode(':', $decoded);
        if (count($parts) < 1 || !is_numeric($parts[0])) {
            return null;
        }

        return (int)$parts[0];
    }

    public function verifyEmail(string $token): string
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, email FROM `user` WHERE verify_token = ?');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new \Exception('Invalid or expired verification token.');
        }

        $update = $db->prepare("UPDATE `user` SET status = 'active', verify_token = NULL WHERE id = ?");
        $update->execute([$user['id']]);

        return $user['email'];
    }

    public function requestPasswordReset(string $email): array
    {
        $db = Database::getInstance();
        $normalizedEmail = strtolower(trim($email));

        $stmt = $db->prepare('SELECT id, full_name, email, status FROM `user` WHERE email = ? LIMIT 1');
        $stmt->execute([$normalizedEmail]);
        $user = $stmt->fetch();

        if (!$user) {
            return [
                'email_exists' => false,
                'email_sent' => false,
                'message' => 'Email is not registered. Please sign up.',
            ];
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $upsert = $db->prepare(
            'INSERT INTO password_reset_tokens (email, token, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = VALUES(created_at)'
        );
        $upsert->execute([$normalizedEmail, $tokenHash]);

        $resetUrl = $this->buildResetPasswordUrl($normalizedEmail, $token);

        try {
            $this->sendResetPasswordEmail($normalizedEmail, $user['full_name'] ?: $normalizedEmail, $resetUrl);
            return [
                'email_exists' => true,
                'email_sent' => true,
                'message' => 'Email already exists. Password reset link has been sent.',
            ];
        } catch (\Exception $e) {
            return [
                'email_exists' => true,
                'email_sent' => false,
                'message' => 'Email already exists, but we could not send the reset link. Please try again later.',
                'reset_url' => $resetUrl,
                'email_error' => $e->getMessage(),
            ];
        }
    }

    public function resetPassword(array $data): void
    {
        $email = strtolower(trim($data['email'] ?? ''));
        $token = trim((string) ($data['token'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $passwordConfirmation = (string) ($data['password_confirmation'] ?? '');

        // Guard against token corruption from copied URLs (spaces/newlines/encoded chars).
        $normalizedToken = preg_replace('/\s+/', '', rawurldecode($token));

        if ($email === '' || $normalizedToken === '') {
            throw new \Exception('Invalid email or reset token.');
        }

        if (strlen($password) < 6) {
            throw new \Exception('New password must be at least 6 characters.');
        }

        if ($password !== $passwordConfirmation) {
            throw new \Exception('Password confirmation does not match.');
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT email, token, created_at FROM password_reset_tokens WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $record = $stmt->fetch();

        if (!$record) {
            throw new \Exception('Invalid or expired password reset request.');
        }

        $createdAt = strtotime((string) $record['created_at']);
        if (!$createdAt || ($createdAt + self::RESET_TOKEN_TTL_SECONDS) < time()) {
            $this->deleteResetToken($email);
            throw new \Exception('Password reset link has expired. Please request a new one.');
        }

        $incomingHash = hash('sha256', $normalizedToken);
        $storedToken = (string) $record['token'];
        $isValidToken = hash_equals($storedToken, $incomingHash) || hash_equals($storedToken, $normalizedToken);

        if (!$isValidToken) {
            throw new \Exception('Invalid password reset token.');
        }

        $userStmt = $db->prepare('SELECT id FROM `user` WHERE email = ? LIMIT 1');
        $userStmt->execute([$email]);
        $user = $userStmt->fetch();

        if (!$user) {
            $this->deleteResetToken($email);
            throw new \Exception('Account does not exist.');
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $update = $db->prepare('UPDATE `user` SET password_hash = ? WHERE id = ?');
        $update->execute([$newHash, $user['id']]);

        $this->deleteResetToken($email);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT password_hash FROM `user` WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            throw new \Exception('Current password is incorrect.');
        }

        if (strlen($newPassword) < 6) {
            throw new \Exception('New password must be at least 6 characters.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $db->prepare('UPDATE `user` SET password_hash = ? WHERE id = ?');
        $update->execute([$newHash, $userId]);
    }

    public function getUserIdFromToken(string $token): ?int
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) return null;
        $parts = explode(':', $decoded);
        return (count($parts) >= 1 && is_numeric($parts[0])) ? (int)$parts[0] : null;
    }

    public function getUserById(int $userId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, full_name, email, status FROM `user` WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) return null;

        $avatarStmt = $db->prepare('SELECT avatar FROM profiles WHERE user_id = ? LIMIT 1');
        $avatarStmt->execute([$userId]);
        $user['avatar'] = $avatarStmt->fetchColumn() ?: null;

        $roleStmt = $db->prepare('SELECT r.name FROM role r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?');
        $roleStmt->execute([$userId]);
        $user['roles'] = $roleStmt->fetchAll(\PDO::FETCH_COLUMN);

        return $user;
    }

    private function sendVerificationEmail(string $email, string $name, string $token): void
    {
        $config = $this->loadEnvConfig();
        $mailHost = $config['MAIL_HOST'] ?? 'smtp.gmail.com';
        $mailPort = $config['MAIL_PORT'] ?? 587;
        $mailEncryption = $config['MAIL_ENCRYPTION'] ?? 'tls';
        $mailUsername = $config['MAIL_USERNAME'] ?? '';
        $mailPassword = $config['MAIL_PASSWORD'] ?? '';
        $mailFrom = $config['MAIL_FROM_ADDRESS'] ?? $mailUsername;
        $mailFromName = $config['MAIL_FROM_NAME'] ?? 'Eyewear System';

        if (!$mailUsername || !$mailPassword) {
            throw new \Exception('SMTP email configuration is missing.');
        }

        if (!class_exists(PHPMailer::class)) {
            // Đảm bảo đường dẫn này đúng với project của bạn
            require_once dirname(__DIR__, 2) . '/PHPMailer/Exception.php';
            require_once dirname(__DIR__, 2) . '/PHPMailer/PHPMailer.php';
            require_once dirname(__DIR__, 2) . '/PHPMailer/SMTP.php';
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $mailHost;
        $mail->SMTPAuth = true;
        $mail->Username = $mailUsername;
        $mail->Password = $mailPassword;
        $mail->SMTPSecure = $mailEncryption;
        $mail->Port = (int) $mailPort;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($mailFrom, $mailFromName);
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = '[EVLS] Verify your account';

        $verifyLink = $this->buildVerificationUrl($token);
        $logoHtml = $this->buildEmbeddedLogoHtml($mail);
        $mail->Body = $this->buildVerificationEmailHtml($name, $verifyLink, $logoHtml);

        if (!$mail->send()) {
            throw new \Exception('Could not send verification email.');
        }
    }

    private function sendResetPasswordEmail(string $email, string $name, string $resetUrl): void
    {
        $config = $this->loadEnvConfig();
        $mailHost = $config['MAIL_HOST'] ?? 'smtp.gmail.com';
        $mailPort = $config['MAIL_PORT'] ?? 587;
        $mailEncryption = $config['MAIL_ENCRYPTION'] ?? 'tls';
        $mailUsername = $config['MAIL_USERNAME'] ?? '';
        $mailPassword = $config['MAIL_PASSWORD'] ?? '';
        $mailFrom = $config['MAIL_FROM_ADDRESS'] ?? $mailUsername;
        $mailFromName = $config['MAIL_FROM_NAME'] ?? 'Eyewear System';

        if (!$mailUsername || !$mailPassword) {
            throw new \Exception('SMTP email configuration is missing.');
        }

        if (!class_exists(PHPMailer::class)) {
            require_once dirname(__DIR__, 2) . '/PHPMailer/Exception.php';
            require_once dirname(__DIR__, 2) . '/PHPMailer/PHPMailer.php';
            require_once dirname(__DIR__, 2) . '/PHPMailer/SMTP.php';
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $mailHost;
        $mail->SMTPAuth = true;
        $mail->Username = $mailUsername;
        $mail->Password = $mailPassword;
        $mail->SMTPSecure = $mailEncryption;
        $mail->Port = (int) $mailPort;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($mailFrom, $mailFromName);
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = '[EVLS] Reset your password';
        $logoHtml = $this->buildEmbeddedLogoHtml($mail);
        $mail->Body = $this->buildResetPasswordEmailHtml($name, $resetUrl, $logoHtml);

        if (!$mail->send()) {
            throw new \Exception('Could not send reset password email.');
        }
    }

    private function buildVerificationUrl(string $token): string
    {
        $config = $this->loadEnvConfig();
        $frontendUrl = rtrim($config['FRONTEND_URL'] ?? 'http://localhost:5500', '/');
        $frontendUrl = preg_replace('#/frontend$#', '', $frontendUrl) ?? $frontendUrl;
        return $frontendUrl . '/pages/auth/?token=' . urlencode($token);
    }

        private function buildVerificationEmailHtml(string $name, string $verifyLink, string $logoHtml = ''): string
        {
                $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                $safeLink = htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8');
                $logoBlock = $logoHtml === '' ? '' : '<div style="text-align: center; padding: 20px 28px 8px 28px;">' . $logoHtml . '</div>';

                return <<<HTML
<div style="font-family: Arial, Helvetica, sans-serif; line-height: 1.7; color: #1f2937; background: #f7faf9; padding: 24px;">
    <div style="max-width: 620px; margin: 0 auto; background: #ffffff; border: 1px solid #e4ede9; border-radius: 18px; overflow: hidden;">
        {$logoBlock}
        <div style="background: linear-gradient(135deg, #0f8b7c, #0b6f63); color: #fff; padding: 22px 28px;">
            <h2 style="margin: 0; font-size: 22px;">[EVLS] Verify your account</h2>
        </div>
        <div style="padding: 28px; font-size: 15px;">
            <p style="margin: 0 0 14px 0;">Hello {$safeName},</p>
            <p style="margin: 0 0 14px 0;">Thank you for choosing EVLS.</p>
            <p style="margin: 0 0 18px 0;">To complete your registration and start shopping, please click the button below to verify your email:</p>
            <p style="text-align: center; margin: 28px 0;">
                <a href="{$safeLink}" style="display: inline-block; background: #0f8b7c; color: #fff; text-decoration: none; font-weight: 700; padding: 14px 24px; border-radius: 999px;">Verify My Email</a>
            </p>
            <p style="margin: 0 0 14px 0;">This verification helps secure your account and ensures you don't miss any exclusive offers from EVLS.</p>
            <p style="margin: 24px 0 0 0; font-size: 13px; color: #6b7280;">If the button doesn't work, you can open the following link: <br><a href="{$safeLink}" style="color: #0f8b7c; word-break: break-all;">{$safeLink}</a></p>
        </div>
    </div>
</div>
HTML;
        }

    private function buildResetPasswordEmailHtml(string $name, string $resetUrl, string $logoHtml = ''): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $logoBlock = $logoHtml === '' ? '' : '<div style="text-align: center; padding: 20px 28px 8px 28px;">' . $logoHtml . '</div>';

        return <<<HTML
        return <<<HTML
<div style="font-family: Arial, Helvetica, sans-serif; line-height: 1.7; color: #1f2937; background: #f7faf9; padding: 24px;">
    <div style="max-width: 620px; margin: 0 auto; background: #ffffff; border: 1px solid #e4ede9; border-radius: 18px; overflow: hidden;">
        {$logoBlock}
        <div style="background: linear-gradient(135deg, #0f8b7c, #0b6f63); color: #fff; padding: 22px 28px;">
            <h2 style="margin: 0; font-size: 22px;">[EVLS] Reset your password</h2>
        </div>
        <div style="padding: 28px; font-size: 15px;">
            <p style="margin: 0 0 14px 0;">Hello {$safeName},</p>
            <p style="margin: 0 0 14px 0;">You just requested a password reset for your EVLS account.</p>
            <p style="margin: 0 0 18px 0;">To continue, please click the button below. This link is valid for 60 minutes:</p>
            <p style="text-align: center; margin: 28px 0;">
                <a href="{$safeLink}" style="display: inline-block; background: #0f8b7c; color: #fff; text-decoration: none; font-weight: 700; padding: 14px 24px; border-radius: 999px;">Reset Password</a>
            </p>
            <p style="margin: 0 0 14px 0;">If you did not request this, please ignore this email to protect your account.</p>
            <p style="margin: 24px 0 0 0; font-size: 13px; color: #6b7280;">If the button doesn't work, you can open the following link:<br><a href="{$safeLink}" style="color: #0f8b7c; word-break: break-all;">{$safeLink}</a></p>
        </div>
    </div>
</div>
HTML;
    }

    private function buildEmbeddedLogoHtml(PHPMailer $mail): string
    {
        $logoPath = dirname(__DIR__, 2) . '/frontend/assets/images/logo.png';
        if (!is_file($logoPath)) {
            return '';
        }

        $cid = 'evls-logo';
        $mail->addEmbeddedImage($logoPath, $cid, 'logo.png', 'base64', 'image/png');
        return '<img src="cid:' . $cid . '" alt="EVLS" style="max-width: 240px; width: 100%; height: auto; display: inline-block;" />';
    }

    private function buildResetPasswordUrl(string $email, string $token): string
    {
        $frontendUrl = $this->resolveFrontendBaseUrl();
        return $frontendUrl . '/pages/auth/reset-password.html?email=' . urlencode($email) . '&token=' . urlencode($token);
    }

    private function resolveFrontendBaseUrl(): string
    {
        $config = $this->loadEnvConfig();
        $frontendUrl = rtrim($config['FRONTEND_URL'] ?? 'http://localhost:5500', '/');
        return preg_replace('#/frontend$#', '', $frontendUrl) ?? $frontendUrl;
    }

    private function deleteResetToken(string $email): void
    {
        $db = Database::getInstance();
        $delete = $db->prepare('DELETE FROM password_reset_tokens WHERE email = ?');
        $delete->execute([$email]);
    }

    private function parseEnvFile(string $path): array
    {
        $result = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return [];

        foreach ($lines as $line) {
            if (empty(trim($line)) || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $result[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }
        return $result;
    }

    private function loadEnvConfig(): array
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        $envLocalPath = dirname(__DIR__, 2) . '/.env.local';
        $config = is_file($envPath) ? $this->parseEnvFile($envPath) : [];
        $localConfig = is_file($envLocalPath) ? $this->parseEnvFile($envLocalPath) : [];
        return array_merge($config, $localConfig);
    }
}