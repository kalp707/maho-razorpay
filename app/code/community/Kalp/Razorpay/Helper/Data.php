<?php

/**
 * SPDX-FileCopyrightText: 2026 Kalp Razorpay Contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Kalp_Razorpay
 */

declare(strict_types=1);

final class Kalp_Razorpay_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const string XML_PATH_ACTIVE = 'payment/razorpay/active';
    public const string XML_PATH_KEY_ID = 'payment/razorpay/key_id';
    public const string XML_PATH_KEY_SECRET = 'payment/razorpay/key_secret';
    public const string XML_PATH_WEBHOOK_SECRET = 'payment/razorpay/webhook_secret';
    public const string XML_PATH_MERCHANT_NAME = 'payment/razorpay/merchant_name';
    public const string XML_PATH_THEME_COLOR = 'payment/razorpay/theme_color';
    public const string XML_PATH_DEBUG = 'payment/razorpay/debug';

    /** @var list<string> */
    private const array ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public function isActive(?int $storeId = null): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_ACTIVE, $storeId);
    }

    public function getKeyId(?int $storeId = null): string
    {
        return trim((string) Mage::getStoreConfig(self::XML_PATH_KEY_ID, $storeId));
    }

    public function getKeySecret(?int $storeId = null): string
    {
        return trim((string) Mage::getStoreConfig(self::XML_PATH_KEY_SECRET, $storeId));
    }

    public function getWebhookSecret(?int $storeId = null): string
    {
        return trim((string) Mage::getStoreConfig(self::XML_PATH_WEBHOOK_SECRET, $storeId));
    }

    public function getMerchantName(?int $storeId = null): string
    {
        $name = trim((string) Mage::getStoreConfig(self::XML_PATH_MERCHANT_NAME, $storeId));

        return $name !== ''
            ? $name
            : (string) Mage::getStoreConfig('general/store_information/name', $storeId);
    }

    public function getThemeColor(?int $storeId = null): string
    {
        $color = trim((string) Mage::getStoreConfig(self::XML_PATH_THEME_COLOR, $storeId));

        return $color !== '' ? $color : '#3399cc';
    }

    public function isDebug(?int $storeId = null): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_DEBUG, $storeId);
    }

    public function hasApiKeys(?int $storeId = null): bool
    {
        return $this->getKeyId($storeId) !== '' && $this->getKeySecret($storeId) !== '';
    }

    /** Convert a store amount to Razorpay's smallest currency subunit. */
    public function toSubunits(float $amount, string $currencyCode): int
    {
        $currency = strtoupper($currencyCode);

        return $this->isZeroDecimal($currency)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    /** Convert Razorpay subunit amount back to store currency amount. */
    public function fromSubunits(int $amount, string $currencyCode): float
    {
        $currency = strtoupper($currencyCode);

        return $this->isZeroDecimal($currency)
            ? (float) $amount
            : round($amount / 100, 2);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        if (!$this->isDebug()) {
            return;
        }

        $payload = $context === []
            ? $message
            : $message . ' ' . Mage::helper('core')->jsonEncode($context);

        Mage::log($payload, Mage::LOG_DEBUG, 'payment_razorpay.log', true);
    }

    private function isZeroDecimal(string $currency): bool
    {
        return in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true);
    }
}
