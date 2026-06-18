<?php

namespace App\Application;

use App\Models\Profile;
use App\Models\User;
use Core\Database;
use App\Infrastructure\GoogleAuthenticator;

class ProfileService
{
    /**
     * Lấy profile của user
     */
    public function getProfile(int $userId)
    {
        $db = Database::getInstance();

        $userStmt = $db->prepare('SELECT id, full_name, email, status, two_factor_enabled FROM `user` WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        $profile = Profile::firstWhere('user_id', $userId);
        $profileData = $profile ? $profile->toArray() : ['user_id' => $userId, 'phone' => null, 'address' => null, 'avatar' => null, 'birthdate' => null];

        $addresses = [];
        try {
            $addressService = new AddressService();
            $addresses = $addressService->getAddresses($userId);
        } catch (\Throwable $e) {
            $addresses = [];
        }

        $ordersStmt = $db->prepare(
            'SELECT o.id, o.order_number, o.status, o.total_amount, o.placed_at, o.production_step,
                    MAX(p.payment_method) AS payment_method, COALESCE(MAX(p.status), o.status) AS payment_status
             FROM `order` o
             LEFT JOIN payment p ON p.order_id = o.id
             WHERE o.user_id = ?
             GROUP BY o.id, o.order_number, o.status, o.total_amount, o.placed_at, o.production_step
             ORDER BY o.placed_at DESC
             LIMIT 5'
        );
        $ordersStmt->execute([$userId]);
        $recentOrders = $ordersStmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_merge($profileData, [
            'user' => $user,
            'recent_orders' => $recentOrders,
            'addresses' => $addresses,
            'billing_address' => $profileData['address'] ?? null,
        ]);
    }

    /**
     * Cập nhật thông tin profile
     */
    public function updateProfile(int $userId, array $data)
    {
        $db = Database::getInstance();

        if (isset($data['full_name'])) {
            $fullName = trim((string) $data['full_name']);
            if ($fullName === '') {
                throw new \Exception('Full name cannot be empty');
            }

            $updateUserStmt = $db->prepare('UPDATE `user` SET full_name = ? WHERE id = ?');
            $updateUserStmt->execute([$fullName, $userId]);
        }

        if (isset($data['two_factor_enabled'])) {
            $twoFactorEnabled = (int)$data['two_factor_enabled'] ? 1 : 0;
            if ($twoFactorEnabled === 0) {
                $this->disable2FA($userId);
            } else {
                throw new \Exception('Please use the 2FA verification flow to enable Google Authenticator.');
            }
        }

        $profile = Profile::firstWhere('user_id', $userId);

        if (!$profile) {
            $profile = Profile::create([
                'user_id' => $userId,
                'phone' => (isset($data['phone']) && trim((string)$data['phone']) !== '') ? $data['phone'] : null,
                'address' => (isset($data['address']) && trim((string)$data['address']) !== '') ? $data['address'] : null,
                'birthdate' => (isset($data['birthdate']) && trim((string)$data['birthdate']) !== '') ? $data['birthdate'] : null,
            ]);
            return $this->getProfile($userId);
        }

        $updateData = [];
        if (isset($data['phone'])) $updateData['phone'] = trim((string)$data['phone']) === '' ? null : $data['phone'];
        if (isset($data['address'])) $updateData['address'] = trim((string)$data['address']) === '' ? null : $data['address'];
        if (isset($data['birthdate'])) $updateData['birthdate'] = trim((string)$data['birthdate']) === '' ? null : $data['birthdate'];

        if (!empty($updateData)) {
            $profile->update($updateData);
        }

        return $this->getProfile($userId);
    }

    /**
     * Upload avatar
     */
    public function uploadAvatar(int $userId, string $filePath)
    {
        $profile = Profile::firstWhere('user_id', $userId);

        if (!$profile) {
            $profile = Profile::create([
                'user_id' => $userId,
                'phone' => null,
                'address' => null,
                'birthdate' => null,
                'avatar' => $filePath,
            ]);
            return $profile->toArray();
        }

        $profile->update(['avatar' => $filePath]);
        return $profile->toArray();
    }

    /**
     * Setup 2FA: Generate a TOTP secret and return QR code URL
     */
    public function setup2FA(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT email FROM `user` WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new \Exception('User not found');
        }

        $secret = GoogleAuthenticator::generateSecret();
        
        // Save the secret temporarily, but keep enabled = 0
        $update = $db->prepare('UPDATE `user` SET two_factor_secret = ? WHERE id = ?');
        $update->execute([$secret, $userId]);

        $qrCodeUrl = GoogleAuthenticator::getQrCodeUrl('EVELENS', $user['email'], $secret);
        // Render QR using qrserver API
        $qrCodeImgUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrCodeUrl);

        return [
            'secret' => $secret,
            'qr_code_url' => $qrCodeImgUrl
        ];
    }

    /**
     * Enable 2FA: Verify a TOTP code and enable 2FA
     */
    public function enable2FA(int $userId, string $code): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT two_factor_secret FROM `user` WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || empty($user['two_factor_secret'])) {
            throw new \Exception('2FA setup is not initialized.');
        }

        if (!GoogleAuthenticator::verifyCode($user['two_factor_secret'], $code)) {
            throw new \Exception('Invalid verification code.');
        }

        $update = $db->prepare('UPDATE `user` SET two_factor_enabled = 1 WHERE id = ?');
        $update->execute([$userId]);
    }

    /**
     * Disable 2FA: Disable 2FA and clear secret
     */
    public function disable2FA(int $userId): void
    {
        $db = Database::getInstance();
        $update = $db->prepare('UPDATE `user` SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?');
        $update->execute([$userId]);
    }
}