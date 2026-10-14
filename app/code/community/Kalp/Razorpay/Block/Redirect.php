<?php

/**
 * SPDX-FileCopyrightText: 2026 Kalp Razorpay Contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Kalp_Razorpay
 */

declare(strict_types=1);

final class Kalp_Razorpay_Block_Redirect extends Mage_Core_Block_Template
{
    /** @var array{razorpay_order_id: string, amount: int, currency: string, key_id: string, options: array<string, mixed>}|null */
    private ?array $checkoutData = null;

    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setTemplate('kalp/razorpay/redirect.phtml');
    }

    public function getOrder(): ?Mage_Sales_Model_Order
    {
        $order = $this->getData('order');

        return $order instanceof Mage_Sales_Model_Order ? $order : null;
    }

    /** @return array{razorpay_order_id?: string, amount?: int, currency?: string, key_id?: string, options?: array<string, mixed>} */
    public function getCheckoutData(): array
    {
        if ($this->checkoutData !== null) {
            return $this->checkoutData;
        }

        $order = $this->getOrder();
        if ($order === null) {
            return [];
        }

        /** @var Kalp_Razorpay_Model_Payment_Processor $processor */
        $processor = Mage::getModel('razorpay/payment_processor');
        $this->checkoutData = $processor->prepareCheckout($order);

        return $this->checkoutData;
    }

    public function getCheckoutOptionsJson(): string
    {
        return Mage::helper('core')->jsonEncode($this->getCheckoutData()['options'] ?? []);
    }

    public function getCancelUrl(): string
    {
        return Mage::getUrl('razorpay/payment/cancel', ['_secure' => true]);
    }
}
