<?php

/**
 * SPDX-FileCopyrightText: 2026 Kalp Razorpay Contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Kalp_Razorpay
 */

declare(strict_types=1);

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

final class Kalp_Razorpay_Model_Api_Client
{
    private ?Api $api = null;
    private ?int $storeId = null;

    /** @param array{store_id?: int|string} $args */
    public function __construct(array $args = [])
    {
        if (array_key_exists('store_id', $args)) {
            $this->storeId = (int) $args['store_id'];
        }
    }

    public function setStoreId(?int $storeId): self
    {
        $this->storeId = $storeId;
        $this->api = null;

        return $this;
    }

    public function getApi(): Api
    {
        return $this->api ??= $this->createApi();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOrder(array $payload): array
    {
        $helper = $this->helper();
        $helper->debug('Creating Razorpay order', [
            'amount' => $payload['amount'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'receipt' => $payload['receipt'] ?? null,
        ]);

        $data = $this->getApi()->order->create($payload)->toArray();
        $helper->debug('Razorpay order created', ['id' => $data['id'] ?? null]);

        return $data;
    }

    /** @return array<string, mixed> */
    public function fetchPayment(string $paymentId): array
    {
        return $this->getApi()->payment->fetch($paymentId)->toArray();
    }

    /**
     * @param array{razorpay_order_id: string, razorpay_payment_id: string, razorpay_signature: string} $attributes
     * @throws SignatureVerificationError
     */
    public function verifyPaymentSignature(array $attributes): void
    {
        $this->getApi()->utility->verifyPaymentSignature($attributes);
    }

    /** @throws SignatureVerificationError */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): void
    {
        $this->getApi()->utility->verifyWebhookSignature($payload, $signature, $secret);
    }

    private function createApi(): Api
    {
        $helper = $this->helper();
        $keyId = $helper->getKeyId($this->storeId);
        $keySecret = $helper->getKeySecret($this->storeId);

        if ($keyId === '' || $keySecret === '') {
            Mage::throwException($helper->__('Razorpay API keys are not configured.'));
        }

        return new Api($keyId, $keySecret);
    }

    private function helper(): Kalp_Razorpay_Helper_Data
    {
        /** @var Kalp_Razorpay_Helper_Data $helper */
        $helper = Mage::helper('razorpay');

        return $helper;
    }
}
