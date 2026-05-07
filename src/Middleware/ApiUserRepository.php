<?php

declare(strict_types=1);

namespace App\Middleware;

use PDO;

final class ApiUserRepository
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        private readonly string $password,
        private readonly string $schema,
        private readonly int $port = 3306,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->user !== '' && $this->schema !== '';
    }

    /**
     * Returns ready-to-display INSERT statements (for preview).
     *
     * @return list<string>
     */
    public function generateInserts(string $apiPrefix, string $password, string $merchantFilter): array
    {
        $userPrefix = 'API-' . $apiPrefix . '-';
        $candidates = $this->fetchCandidates($userPrefix, $merchantFilter);

        $inserts = [];
        foreach ($candidates as $row) {
            ['username' => $username, 'email' => $email, 'merchant_id' => $merchantId] = $row;

            $inserts[] = sprintf(
                "INSERT INTO `tpg_erp`.`middleware_merchants_user` "
                . "(`merchant_id`, `user`, `password`, `telefon`, `email`, `role_ids`, `logins`, "
                . "`account_owner`, `account_id`, `join_date`, `lastlogin`, `failed_logins`, "
                . "`locked_until`, `created`, `modified`, `active`, `is_deleted`, `picture`, `fb_uid`, `language`) "
                . "VALUES ('%s', '%s', '%s', '', '%s', '4', 1, 0, 0, 0, 1701380568, 3, 0, 0, 0, 1, 0, '', '', 'de');",
                addslashes($merchantId),
                addslashes($username),
                addslashes($password),
                addslashes($email),
            );
        }

        return $inserts;
    }

    /**
     * Executes the INSERTs via prepared statements and returns per-row results.
     *
     * @return list<array{ok: bool, user: string, merchant_id: string, error: string}>
     */
    public function executeInserts(string $apiPrefix, string $password, string $emailOverride, string $merchantFilter): array
    {
        $userPrefix = 'API-' . $apiPrefix . '-';
        $candidates = $this->fetchCandidates($userPrefix, $merchantFilter);

        if ($candidates === []) {
            return [];
        }

        $pdo  = $this->connect();
        $stmt = $pdo->prepare(
            'INSERT INTO `tpg_erp`.`middleware_merchants_user`
                (`merchant_id`, `user`, `password`, `telefon`, `email`, `role_ids`, `logins`,
                 `account_owner`, `account_id`, `join_date`, `lastlogin`, `failed_logins`,
                 `locked_until`, `created`, `modified`, `active`, `is_deleted`, `picture`, `fb_uid`, `language`)
             VALUES
                (:merchant_id, :user, :password, \'\', :email, \'4\', 1, 0, 0, 0, 1701380568, 3, 0, 0, 0, 1, 0, \'\', \'\', \'de\')',
        );

        $results = [];
        foreach ($candidates as $row) {
            ['username' => $username, 'email' => $generatedEmail, 'merchant_id' => $merchantId] = $row;
            $email = $emailOverride !== '' ? $emailOverride : $generatedEmail;
            try {
                $stmt->execute([
                    ':merchant_id' => $merchantId,
                    ':user'        => $username,
                    ':password'    => $password,
                    ':email'       => $email,
                ]);
                $results[] = ['ok' => true,  'user' => $username, 'merchant_id' => $merchantId, 'error' => ''];
            } catch (\Throwable $e) {
                $results[] = ['ok' => false, 'user' => $username, 'merchant_id' => $merchantId, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @return list<array{merchant_id: string, username: string, email: string}>
     */
    private function fetchCandidates(string $userPrefix, string $merchantFilter): array
    {
        $pdo = $this->connect();

        $sql = "
            WITH b AS (
                SELECT
                    dr.UMLFIRMA AS merchant_id,
                    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        TRIM(LOWER(dr.firma)),
                        ' ',''),'+',''),'-',''),'.',''),',',''),'&',''),'ö','oe'),'ü','ue'),'ä','ae'
                    ) AS slug
                FROM tpg_b2b.data_retailer dr
            )
            SELECT b.merchant_id, b.slug
            FROM b
            WHERE NOT EXISTS (
                SELECT 1
                FROM tpg_erp.middleware_merchants_user mmu
                WHERE mmu.merchant_id = b.merchant_id
                  AND mmu.`user` LIKE :user_prefix_like
            )
        ";

        $params = [':user_prefix_like' => $userPrefix . '%'];

        if ($merchantFilter !== '') {
            $sql .= ' AND FIND_IN_SET(b.merchant_id, :merchant_filter)';
            $params[':merchant_filter'] = $merchantFilter;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            fn(array $row): array => [
                'merchant_id' => (string) $row['merchant_id'],
                'username'    => $userPrefix . (string) $row['slug'],
                'email'       => 'noreply+' . $userPrefix . (string) $row['slug'] . '@the-platform-group.com',
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function connect(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->host,
                $this->port,
                $this->schema,
            );
            $this->pdo = new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return $this->pdo;
    }
}
