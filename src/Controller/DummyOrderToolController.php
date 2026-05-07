<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\MiddlewareProductRepository;
use App\Pegasus\CreateOrderResult;
use App\Pegasus\PegasusOrderApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DummyOrderToolController extends AbstractController
{
    private const ERROR_NOT_CONFIGURED = 'Pegasus API base URL missing. Set PEGASUS_API_BASE in .env.local.';

    private const ERROR_ITEM_SKU = 'itemSku is required (request body or PEGASUS_ITEM_SKU default).';

    private const ERROR_CREDENTIALS = 'apiUser and apiSecret required (JSON body or PEGASUS_API_USER / PEGASUS_API_SECRET in .env.local).';

    #[Route('/', name: 'app_dummy_orders', methods: ['GET'])]
    public function index(PegasusOrderApiClient $pegasusOrderApiClient, MiddlewareProductRepository $productRepository): Response
    {
        return $this->render('dummy_orders/index.html.twig', [
            'pegasusConfigured' => $pegasusOrderApiClient->isConfigured(),
            'defaultItemSku' => $pegasusOrderApiClient->getDefaultItemSku(),
            'defaultItemEan' => $pegasusOrderApiClient->getDefaultItemEan(),
            'defaultApiUser' => $pegasusOrderApiClient->getDefaultApiUser(),
            'dbConfigured' => $productRepository->isConfigured(),
        ]);
    }

    #[Route('/dummy-orders/stock-products', name: 'app_stock_products', methods: ['GET'])]
    public function stockProducts(Request $request, MiddlewareProductRepository $productRepository): JsonResponse
    {
        if (!$productRepository->isConfigured()) {
            return $this->json(['ok' => false, 'error' => 'DB not configured. Set PEGASUS_STAGING_DB_* in .env.local.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $apiUser = trim((string) $request->query->get('apiUser', ''));
        $search = trim((string) $request->query->get('search', ''));
        $offset = max(0, (int) $request->query->get('offset', 0));
        $limit = min(200, max(1, (int) $request->query->get('limit', 100)));

        if ($apiUser === '') {
            return $this->json(['ok' => false, 'error' => 'apiUser query param required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $products = $productRepository->findInStockProducts($apiUser, $search, $limit, $offset);
            $total = $productRepository->countInStockProducts($apiUser, $search);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'ok' => true,
            'products' => $products,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    /**
     * Creates a single dummy order (call repeatedly from the UI for batch progress).
     */
    #[Route('/dummy-orders/create-one', name: 'app_dummy_orders_create_one', methods: ['POST'])]
    public function createOne(Request $request, PegasusOrderApiClient $pegasusOrderApiClient): JsonResponse
    {
        if (!$pegasusOrderApiClient->isConfigured()) {
            return $this->json(
                [
                    'ok' => false,
                    'error' => self::ERROR_NOT_CONFIGURED,
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $this->createOneFromPayload($this->parseJsonBody($request), $pegasusOrderApiClient);
    }

    private function createOneFromPayload(array $data, PegasusOrderApiClient $pegasusOrderApiClient): JsonResponse
    {
        $itemSku = $this->resolveField($data, 'itemSku', 'item_sku', $pegasusOrderApiClient->getDefaultItemSku());
        if ($itemSku === null) {
            return $this->json(['ok' => false, 'error' => self::ERROR_ITEM_SKU], Response::HTTP_BAD_REQUEST);
        }

        $itemEan = $this->resolveField($data, 'itemEan', 'item_ean', $pegasusOrderApiClient->getDefaultItemEan()) ?? '';

        $apiUser = $this->resolveField($data, 'apiUser', 'api_user', $pegasusOrderApiClient->getDefaultApiUser());
        $apiSecret = $this->resolveField($data, 'apiSecret', 'api_secret', $pegasusOrderApiClient->getDefaultApiSecret());
        if ($apiUser === null || $apiSecret === null) {
            return $this->json(['ok' => false, 'error' => self::ERROR_CREDENTIALS], Response::HTTP_BAD_REQUEST);
        }

        return $this->jsonForCreateResult(
            $pegasusOrderApiClient->createDummyOrder($itemSku, $itemEan, $apiUser, $apiSecret),
        );
    }

    private function jsonForCreateResult(CreateOrderResult $result): JsonResponse
    {
        $payload = [
            'ok' => $result->success,
            'statusCode' => $result->statusCode,
            'externalOrderId' => $result->externalOrderId,
            'externalOrderNumber' => $result->externalOrderNumber,
            'message' => $result->message,
            'responseBody' => $result->responseBody,
        ];
        $status = $result->success ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY;

        return $this->json($payload, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonBody(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveField(array $data, string $camelKey, string $snakeKey, string $envDefault): ?string
    {
        $fromRequest = trim((string) (($data[$camelKey] ?? null) ?? ($data[$snakeKey] ?? null) ?? ''));
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        $fromEnv = trim($envDefault);

        return $fromEnv !== '' ? $fromEnv : null;
    }
}
