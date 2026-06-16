<?php

namespace App\Application;

use App\Models\Profile;
use App\Models\User;
use Core\Database;

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
            $updateUser2FA = $db->prepare('UPDATE `user` SET two_factor_enabled = ? WHERE id = ?');
            $updateUser2FA->execute([$twoFactorEnabled, $userId]);
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
}