<?php

/**
 * SPDX-FileCopyrightText: 2026 Kalp Razorpay Contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Kalp_Razorpay
 */

declare(strict_types=1);

final readonly class Kalp_Razorpay_Model_Checkout_Options
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $razorpayOrderId,
        public int $amount,
        public string $currency,
        public string $keyId,
        public array $options,
    ) {}

    /** @return array{razorpay_order_id: string, amount: int, currency: string, key_id: string, options: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'razorpay_order_id' => $this->razorpayOrderId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'key_id' => $this->keyId,
            'options' => $this->options,
        ];
    }
}
