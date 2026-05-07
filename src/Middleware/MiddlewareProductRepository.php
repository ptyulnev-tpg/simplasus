<?php

declare(strict_types=1);

namespace App\Middleware;

use PDO;
use Throwable;

final class MiddlewareProductRepository
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
     * Returns in-stock products for the given API-user email.
     * Lookup chain: middleware_merchants_user.email → merchant_id
     *   → middleware_mapping (product_sku, product_ean)
     *   → middleware_stock_stores (stock > 0)
     *
     * @return list<array{sku: string, ean: string, stock: int}>
     */
    public function findInStockProducts(string $apiUserEmail, string $skuSearch = '', int $limit = 100, int $offset = 0): array
    {
        $pdo = $this->connect();

        $sql = '
            SELECT mm.product_sku AS sku, mm.product_ean AS ean, SUM(mss.stock) AS total_stock
            FROM middleware_mapping mm
            JOIN middleware_merchants_user mmu
                ON mmu.merchant_id = CAST(mm.merchant_id AS UNSIGNED)
            JOIN middleware_stock_stores mss
                ON mss.merchant_id = CAST(mm.merchant_id AS UNSIGNED)
                AND mss.product_sku = mm.product_sku
            WHERE mmu.email = :email
              AND mss.stock > 0
        ';

        $params = ['email' => $apiUserEmail];

        if ($skuSearch !== '') {
            $sql .= ' AND mm.product_sku LIKE :search';
            $params['search'] = '%' . $skuSearch . '%';
        }

        $sql .= '
            GROUP BY mm.product_sku, mm.product_ean
            ORDER BY mm.product_sku
            LIMIT :limit OFFSET :offset
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach (['email', 'search'] as $key) {
            if (isset($params[$key])) {
                $stmt->bindValue(':' . $key, $params[$key]);
            }
        }
        $stmt->execute();

        return array_map(
            static fn(array $row): array => [
                'sku' => (string) $row['sku'],
                'ean' => (string) ($row['ean'] ?? ''),
                'stock' => (int) $row['total_stock'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function countInStockProducts(string $apiUserEmail, string $skuSearch = ''): int
    {
        $pdo = $this->connect();

        $sql = '
            SELECT COUNT(*) FROM (
                SELECT mm.product_sku
                FROM middleware_mapping mm
                JOIN middleware_merchants_user mmu
                    ON mmu.merchant_id = CAST(mm.merchant_id AS UNSIGNED)
                JOIN middleware_stock_stores mss
                    ON mss.merchant_id = CAST(mm.merchant_id AS UNSIGNED)
                    AND mss.product_sku = mm.product_sku
                WHERE mmu.email = :email
                  AND mss.stock > 0
        ';

        $params = ['email' => $apiUserEmail];

        if ($skuSearch !== '') {
            $sql .= ' AND mm.product_sku LIKE :search';
            $params['search'] = '%' . $skuSearch . '%';
        }

        $sql .= ' GROUP BY mm.product_sku) sub';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return $this->pdo;
    }
}
