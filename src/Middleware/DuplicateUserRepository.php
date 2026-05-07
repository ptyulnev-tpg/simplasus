<?php

declare(strict_types=1);

namespace App\Middleware;

use PDO;

final class DuplicateUserRepository
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
     * Returns duplicate email groups ordered by count descending.
     *
     * @return list<array{email: string, count: int}>
     */
    public function findDuplicates(): array
    {
        $stmt = $this->connect()->query(
            'SELECT email, COUNT(*) AS cnt
             FROM tpg_erp.middleware_merchants_user
             GROUP BY email
             HAVING COUNT(*) > 1
             ORDER BY 2 DESC',
        );

        return array_map(
            static fn(array $row): array => [
                'email' => (string) $row['email'],
                'count' => (int) $row['cnt'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Deletes duplicate rows keeping the one with the lowest id per email.
     * Returns number of deleted rows.
     */
    public function deleteDuplicates(): int
    {
        $stmt = $this->connect()->query(
            'DELETE m1
             FROM tpg_erp.middleware_merchants_user m1
             INNER JOIN tpg_erp.middleware_merchants_user m2
                ON m1.email = m2.email AND m1.id > m2.id',
        );

        return $stmt->rowCount();
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
