<?php

declare(strict_types=1);

namespace App\Pegasus;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Calls Pegasus createOrder with HTTP Basic auth.
 */
final class PegasusOrderApiClient
{
    private const HTTP_TIMEOUT_SECONDS = 120;

    private const RESPONSE_PREVIEW_MAX = 2000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PegasusOrderPayloadBuilder $payloadBuilder,
        private readonly string $apiBase,
        private readonly string $apiUser,
        private readonly string $apiSecret,
        private readonly string $createOrderPath,
        private readonly string $itemSku,
        private readonly string $itemEan,
    ) {
    }

    /**
     * Whether Pegasus API base URL is set (use .env.local). User/secret may come from the UI request.
     */
    public function isConfigured(): bool
    {
        return $this->apiBase !== '';
    }

    /**
     * Optional default SKU from env (prefill in UI).
     */
    public function getDefaultItemSku(): string
    {
        return $this->itemSku;
    }

    /**
     * Default API user from env (UI prefill / fallback).
     */
    public function getDefaultApiUser(): string
    {
        return $this->apiUser;
    }

    /**
     * Default API secret from env (fallback when UI password field is empty).
     */
    public function getDefaultApiSecret(): string
    {
        return $this->apiSecret;
    }

    public function getDefaultItemEan(): string
    {
        return $this->itemEan;
    }

    /**
     * Creates one dummy order; generates unique external ids server-side.
     */
    public function createDummyOrder(string $itemSku, string $itemEan, string $apiUser, string $apiSecret): CreateOrderResult
    {
        $itemSku = trim($itemSku);
        if ($itemSku === '') {
            return new CreateOrderResult(false, 0, '', '', 'itemSku is empty', '');
        }

        $suffix = bin2hex(random_bytes(8));
        $payload = $this->payloadBuilder->build($suffix, $itemSku, $itemEan);
        $externalOrderId = (string) $payload[PegasusOrderPayloadBuilder::KEY_EXTERNAL_ORDER_ID];
        $externalOrderNumber = (string) $payload[PegasusOrderPayloadBuilder::KEY_EXTERNAL_ORDER_NUMBER];
        $url = rtrim($this->apiBase, '/') . $this->createOrderPath;

        try {
            return $this->postCreateOrder($url, $payload, $externalOrderId, $externalOrderNumber, $apiUser, $apiSecret);
        } catch (Throwable $e) {
            return new CreateOrderResult(false, 0, $externalOrderId, $externalOrderNumber, $e->getMessage(), '');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postCreateOrder(
        string $url,
        array $payload,
        string $externalOrderId,
        string $externalOrderNumber,
        string $apiUser,
        string $apiSecret,
    ): CreateOrderResult {

        $response = $this->httpClient->request('POST', $url, [
            'auth_basic' => [$apiUser, $apiSecret],
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
        ]);

        return $this->createResultFromHttpResponse($response, $externalOrderId, $externalOrderNumber);
    }

    private function createResultFromHttpResponse(
        ResponseInterface $response,
        string $externalOrderId,
        string $externalOrderNumber,
    ): CreateOrderResult {
        $raw = $response->getContent(false);

        $status = $response->getStatusCode();
        if ('' === $raw) {
            $raw = $this->jsonWhenPegasusBodyEmpty($response, $status);
        }
        $responseBody = $this->truncateBody($raw);


        $success = $status >= 200 && $status < 300;
        $message = $success ? 'OK' : $responseBody;

        return new CreateOrderResult($success, $status, $externalOrderId, $externalOrderNumber, $message, $responseBody);
    }

    /**
     * Pegasus sometimes returns HTTP 200 with an empty body; still expose something in the UI log.
     */
    private function jsonWhenPegasusBodyEmpty(ResponseInterface $response, int $status): string
    {
        $contentType = '';
        try {
            $headers = $response->getHeaders(false);
            $contentType = isset($headers['content-type'][0]) ? (string) $headers['content-type'][0] : '';
        } catch (Throwable) {
        }

        return json_encode(
            [
                '_pegasusTestTool' => 'Pegasus returned an empty response body',
                'httpStatus' => $status,
                'contentType' => $contentType,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    private function truncateBody(string $body): string
    {
        if (strlen($body) <= self::RESPONSE_PREVIEW_MAX) {
            return $body;
        }

        return substr($body, 0, self::RESPONSE_PREVIEW_MAX) . '…';
    }
}
