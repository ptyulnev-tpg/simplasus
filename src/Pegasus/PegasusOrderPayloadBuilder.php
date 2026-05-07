<?php

declare(strict_types=1);

namespace App\Pegasus;

/**
 * Builds JSON payload for Pegasus retailer createOrder API.
 */
final class PegasusOrderPayloadBuilder
{
    public const KEY_EXTERNAL_ORDER_ID = 'externalOrderId';

    public const KEY_EXTERNAL_ORDER_NUMBER = 'externalOrderNumber';

    private const KEY_ORDER_CUSTOMER = 'orderCustomer';

    private const KEY_SHIPPING_CUSTOMER = 'shippingCustomer';

    private const KEY_ORDER = 'order';

    private const KEY_PRICE = 'price';

    private const KEY_SHIPPING_COSTS = 'shippingCosts';

    private const KEY_PAYMENT_STATE = 'paymentState';

    private const KEY_ITEM_LIST = 'item_list';

    private const PAYMENT_STATE_PAID = 'Bezahlt';

    private const DEFAULT_COUNTRY = 'DE';

    private const DEFAULT_ZIP = '65185';

    private const DEFAULT_CITY = 'Wiesbaden';

    private const DEFAULT_STREET = 'Musterstr. 1';

    private const DEFAULT_EMAIL = 'test@example.com';

    private const DEFAULT_EAN = '4251728907553';

    /**
     * @return array<string, mixed>
     */
    public function build(string $orderSuffix, string $itemSku, string $itemEan = ''): array
    {
        $externalOrderId = 'dummy-' . $orderSuffix;
        $externalOrderNumber = 'DUMMY-' . $orderSuffix;

        $ean = $itemEan !== '' ? $itemEan : self::DEFAULT_EAN;

        return [
            self::KEY_EXTERNAL_ORDER_ID => $externalOrderId,
            self::KEY_EXTERNAL_ORDER_NUMBER => $externalOrderNumber,
            self::KEY_ORDER_CUSTOMER => $this->customerBlock('C-001'),
            self::KEY_SHIPPING_CUSTOMER => $this->shippingBlock(),
            self::KEY_ORDER => $this->orderBlock($itemSku, $orderSuffix, $ean),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerBlock(string $customerNumber): array
    {
        return $this->personBlock() + [
            'customerNumber' => $customerNumber,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function shippingBlock(): array
    {
        return $this->personBlock();
    }

    /**
     * @return array<string, string>
     */
    private function personBlock(): array
    {
        return [
            'firstName' => 'Test',
            'lastName' => 'Kunde',
            'street' => self::DEFAULT_STREET,
            'zip' => self::DEFAULT_ZIP,
            'city' => self::DEFAULT_CITY,
            'countryIso' => self::DEFAULT_COUNTRY,
            'email' => self::DEFAULT_EMAIL,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderBlock(string $itemSku, string $lineSuffix, string $ean): array
    {
        return [
            self::KEY_PRICE => $this->money(11.9, 10.0, 19),
            self::KEY_SHIPPING_COSTS => $this->money(4.99, 4.19, 19),
            self::KEY_PAYMENT_STATE => self::PAYMENT_STATE_PAID,
            self::KEY_ITEM_LIST => [$this->lineItem($itemSku, $lineSuffix, $ean)],
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function money(float $total, float $net, int $taxRate): array
    {
        return [
            'totalPrice' => $total,
            'netPrice' => $net,
            'taxRate' => $taxRate,
        ];
    }

    /**
     * @return array<string, float|int|string>
     */
    private function lineItem(string $itemSku, string $lineSuffix, string $ean): array
    {
        return [
            'item' => $itemSku,
            'name' => 'Artikel',
            'manufacturer' => 'Hersteller',
            'size' => 'M',
            'color' => 'rot',
            'ean' => $ean,
            'manufacturer_number' => 'MFR-1',
            'reference_price' => 6.9,
            'net' => 6.9,
            'total' => 6.9,
            'qty' => 1,
            'externalLineIdentifier' => 'L-' . $lineSuffix,
            'selfShipping' => false,
        ];
    }
}
