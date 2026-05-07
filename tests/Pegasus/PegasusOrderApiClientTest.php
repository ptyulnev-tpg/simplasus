<?php

declare(strict_types=1);

namespace App\Tests\Pegasus;

use App\Pegasus\PegasusOrderApiClient;
use App\Pegasus\PegasusOrderPayloadBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PegasusOrderApiClientTest extends TestCase
{
    public function testCreateDummyOrderReturnsSuccessOnHttpTwoHundred(): void
    {
        $lastRequest = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$lastRequest): MockResponse {
            $lastRequest = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"accepted":true}', ['http_code' => 200]);
        });

        $client = new PegasusOrderApiClient(
            $mock,
            new PegasusOrderPayloadBuilder(),
            'https://pegasus.test',
            'u',
            'secret',
            '/api/retailerorder/createOrder',
            'SKU-1',
        );

        $result = $client->createDummyOrder('SKU-1', 'u', 'secret');

        self::assertNotNull($lastRequest);
        self::assertSame('POST', $lastRequest['method']);
        self::assertStringContainsString('https://pegasus.test/api/retailerorder/createOrder', $lastRequest['url']);
        $headers = $lastRequest['options']['normalized_headers']['authorization'] ?? [];
        self::assertNotEmpty($headers);
        self::assertStringContainsString('Basic ' . base64_encode('u:secret'), $headers[0]);

        self::assertTrue($result->success);
        self::assertSame(200, $result->statusCode);
        self::assertStringStartsWith('dummy-', $result->externalOrderId);
        self::assertStringStartsWith('DUMMY-', $result->externalOrderNumber);
        self::assertSame('{"accepted":true}', $result->responseBody);
    }

    public function testIsConfiguredIsFalseWhenBaseMissing(): void
    {
        $client = new PegasusOrderApiClient(
            new MockHttpClient(),
            new PegasusOrderPayloadBuilder(),
            '',
            'u',
            'secret',
            '/path',
            'SKU',
        );

        self::assertFalse($client->isConfigured());
    }

    public function testIsConfiguredIsTrueWhenBaseUrlSet(): void
    {
        $client = new PegasusOrderApiClient(
            new MockHttpClient(),
            new PegasusOrderPayloadBuilder(),
            'https://x',
            '',
            '',
            '/path',
            '',
        );

        self::assertTrue($client->isConfigured());
    }

    public function testCreateDummyOrderUsesRequestCredentialsForBasicAuth(): void
    {
        $lastRequest = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$lastRequest): MockResponse {
            $lastRequest = $options;

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $client = new PegasusOrderApiClient(
            $mock,
            new PegasusOrderPayloadBuilder(),
            'https://pegasus.test',
            'envUser',
            'envSecret',
            '/api/retailerorder/createOrder',
            'SKU-1',
        );

        $client->createDummyOrder('SKU-1', 'wrongUser', 'wrongPass');
        self::assertNotNull($lastRequest);
        $headers = $lastRequest['normalized_headers']['authorization'] ?? [];
        self::assertStringContainsString(
            'Basic ' . base64_encode('wrongUser:wrongPass'),
            $headers[0],
        );
    }

    public function testCreateDummyOrderUsesPlaceholderWhenPegasusBodyEmpty(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('', ['http_code' => 200]),
        ]);

        $client = new PegasusOrderApiClient(
            $mock,
            new PegasusOrderPayloadBuilder(),
            'https://pegasus.test',
            'u',
            'secret',
            '/api/retailerorder/createOrder',
            'SKU-1',
        );

        $result = $client->createDummyOrder('SKU-1', 'u', 'secret');

        self::assertTrue($result->success);
        self::assertStringContainsString('_pegasusTestTool', $result->responseBody);
        self::assertStringContainsString('empty', strtolower($result->responseBody));
    }

    public function testCreateDummyOrderFailsWhenItemSkuEmpty(): void
    {
        $client = new PegasusOrderApiClient(
            new MockHttpClient(),
            new PegasusOrderPayloadBuilder(),
            'https://pegasus.test',
            'u',
            'secret',
            '/api/x',
            'fallback',
        );

        $result = $client->createDummyOrder('  ', 'u', 'secret');

        self::assertFalse($result->success);
        self::assertSame(0, $result->statusCode);
        self::assertStringContainsString('empty', $result->message);
        self::assertSame('', $result->responseBody);
    }
}
