<?php

/**
 * SPDX-FileCopyrightText: 2026 Kalp Razorpay Contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Kalp_Razorpay
 */

declare(strict_types=1);

use Razorpay\Api\Errors\SignatureVerificationError;

final class Kalp_Razorpay_Model_Payment_Processor
{
    /**
     * Create (or reuse) a Razorpay Order for a pending Magento order.
     *
     * @return array{razorpay_order_id: string, amount: int, currency: string, key_id: string, options: array<string, mixed>}
     */
    public function prepareCheckout(Mage_Sales_Model_Order $order): array
    {
        return $this->buildCheckoutOptions($order)->toArray();
    }

    public function buildCheckoutOptions(Mage_Sales_Model_Order $order): Kalp_Razorpay_Model_Checkout_Options
    {
        $this->assertPayable($order);

        $storeId = (int) $order->getStoreId();
        $helper = $this->helper();
        $payment = $order->getPayment();
        $currency = strtoupper((string) $order->getOrderCurrencyCode());
        $amount = $helper->toSubunits((float) $order->getGrandTotal(), $currency);
        $razorpayOrderId = (string) $payment->getAdditionalInformation('razorpay_order_id');

        if ($razorpayOrderId === '') {
            $razorpayOrderId = $this->createAndPersistRazorpayOrder($order, $amount, $currency, $storeId);
        }

        /** @var Kalp_Razorpay_Model_Method_Checkout $method */
        $method = $payment->getMethodInstance();
        $billing = $order->getBillingAddress();
        $keyId = $helper->getKeyId($storeId);

        $options = [
            'key' => $keyId,
            'amount' => $amount,
            'currency' => $currency,
            'name' => $helper->getMerchantName($storeId),
            'description' => $helper->__('Order %s', $order->getIncrementId()),
            'order_id' => $razorpayOrderId,
            'callback_url' => $method->getCallbackUrl(),
            'redirect' => true,
            'prefill' => [
                'name' => trim($billing->getFirstname() . ' ' . $billing->getLastname()),
                'email' => (string) $order->getCustomerEmail(),
                'contact' => (string) $billing->getTelephone(),
            ],
            'notes' => [
                'magento_order_id' => (string) $order->getIncrementId(),
            ],
            'theme' => [
                'color' => $helper->getThemeColor($storeId),
            ],
        ];

        return new Kalp_Razorpay_Model_Checkout_Options(
            razorpayOrderId: $razorpayOrderId,
            amount: $amount,
            currency: $currency,
            keyId: $keyId,
            options: $options,
        );
    }

    /**
     * @param array{razorpay_payment_id?: string, razorpay_order_id?: string, razorpay_signature?: string} $payload
     */
    public function processCallback(Mage_Sales_Model_Order $order, array $payload): void
    {
        $paymentId = trim((string) ($payload['razorpay_payment_id'] ?? ''));
        $razorpayOrderId = trim((string) ($payload['razorpay_order_id'] ?? ''));
        $signature = trim((string) ($payload['razorpay_signature'] ?? ''));

        if ($paymentId === '' || $razorpayOrderId === '' || $signature === '') {
            Mage::throwException($this->helper()->__('Incomplete Razorpay payment response.'));
        }

        $storedOrderId = (string) $order->getPayment()->getAdditionalInformation('razorpay_order_id');
        if ($storedOrderId === '' || !hash_equals($storedOrderId, $razorpayOrderId)) {
            Mage::throwException($this->helper()->__('Razorpay order id mismatch.'));
        }

        if ($order->hasInvoices()) {
            $this->helper()->debug('Order already invoiced, skipping callback', [
                'increment_id' => $order->getIncrementId(),
                'payment_id' => $paymentId,
            ]);
            return;
        }

        /** @var Kalp_Razorpay_Model_Api_Client $client */
        $client = Mage::getModel('razorpay/api_client', ['store_id' => (int) $order->getStoreId()]);

        try {
            $client->verifyPaymentSignature([
                'razorpay_order_id' => $storedOrderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature,
            ]);
        } catch (SignatureVerificationError $e) {
            $this->helper()->debug('Signature verification failed', ['message' => $e->getMessage()]);
            Mage::throwException($this->helper()->__('Payment signature verification failed.'));
        }

        $this->captureOrder($order, $paymentId, $razorpayOrderId);
    }

    /** @param array<string, mixed> $paymentEntity */
    public function processWebhookPaymentCaptured(array $paymentEntity): void
    {
        $paymentId = (string) ($paymentEntity['id'] ?? '');
        if ($paymentId === '') {
            return;
        }

        $razorpayOrderId = (string) ($paymentEntity['order_id'] ?? '');
        $notes = $paymentEntity['notes'] ?? [];
        $incrementId = is_array($notes) ? (string) ($notes['magento_order_id'] ?? '') : '';

        $order = $incrementId !== ''
            ? Mage::getModel('sales/order')->loadByIncrementId($incrementId)
            : null;

        if ((!$order || !$order->getId()) && $razorpayOrderId !== '') {
            $order = $this->loadOrderByRazorpayOrderId($razorpayOrderId);
        }

        if (!$order?->getId()) {
            $this->helper()->debug('Webhook payment.captured: order not found', [
                'payment_id' => $paymentId,
                'order_id' => $razorpayOrderId,
            ]);
            return;
        }

        if (!$this->webhookMatchesStoredPayment($order, $paymentEntity, $razorpayOrderId)) {
            return;
        }

        if ($order->hasInvoices()) {
            return;
        }

        if (!$this->isAwaitingPayment($order)) {
            $this->flagPaidButNotCapturable($order, $paymentId, 'webhook');
            return;
        }

        $this->captureOrder($order, $paymentId, $razorpayOrderId !== '' ? $razorpayOrderId : null);
    }

    /**
     * Ensure the webhook payment belongs to this Magento order (order id, amount, currency).
     * Prevents cross-store capture when increment IDs collide on a shared Razorpay account.
     *
     * @param array<string, mixed> $paymentEntity
     */
    private function webhookMatchesStoredPayment(
        Mage_Sales_Model_Order $order,
        array $paymentEntity,
        string $razorpayOrderId,
    ): bool {
        $payment = $order->getPayment();
        $storedOrderId = (string) $payment->getAdditionalInformation('razorpay_order_id');
        $storedAmount = $payment->getAdditionalInformation('razorpay_amount');
        $storedCurrency = strtoupper((string) $payment->getAdditionalInformation('razorpay_currency'));

        if ($storedOrderId === '' || $razorpayOrderId === '' || !hash_equals($storedOrderId, $razorpayOrderId)) {
            $this->helper()->debug('Webhook payment.captured: razorpay_order_id mismatch', [
                'increment_id' => $order->getIncrementId(),
                'stored' => $storedOrderId,
                'webhook' => $razorpayOrderId,
                'payment_id' => (string) ($paymentEntity['id'] ?? ''),
            ]);
            return false;
        }

        $webhookAmount = isset($paymentEntity['amount']) ? (int) $paymentEntity['amount'] : null;
        $webhookCurrency = strtoupper((string) ($paymentEntity['currency'] ?? ''));

        if ($storedAmount === null || $storedAmount === '' || $webhookAmount === null
            || (int) $storedAmount !== $webhookAmount
        ) {
            $this->helper()->debug('Webhook payment.captured: amount mismatch', [
                'increment_id' => $order->getIncrementId(),
                'stored' => $storedAmount,
                'webhook' => $webhookAmount,
                'payment_id' => (string) ($paymentEntity['id'] ?? ''),
            ]);
            return false;
        }

        if ($storedCurrency === '' || $webhookCurrency === ''
            || !hash_equals($storedCurrency, $webhookCurrency)
        ) {
            $this->helper()->debug('Webhook payment.captured: currency mismatch', [
                'increment_id' => $order->getIncrementId(),
                'stored' => $storedCurrency,
                'webhook' => $webhookCurrency,
                'payment_id' => (string) ($paymentEntity['id'] ?? ''),
            ]);
            return false;
        }

        return true;
    }

    public function loadOrderByRazorpayOrderId(string $razorpayOrderId): ?Mage_Sales_Model_Order
    {
        if ($razorpayOrderId === '') {
            return null;
        }

        // Maho stores additional_information as JSON (via Mage::helper('core')->jsonEncode()).
        // Escape LIKE wildcards in the order id before building the SQL pattern.
        $escapedOrderId = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $razorpayOrderId);
        $jsonNeedle = '"razorpay_order_id":"' . $escapedOrderId . '"';

        /** @var Mage_Sales_Model_Resource_Order_Payment_Collection $payments */
        $payments = Mage::getResourceModel('sales/order_payment_collection');
        $payments
            ->addFieldToFilter('method', Kalp_Razorpay_Model_Method_Checkout::METHOD_CODE)
            ->addFieldToFilter('additional_information', ['like' => '%' . $jsonNeedle . '%']);

        foreach ($payments as $payment) {
            /** @var Mage_Sales_Model_Order_Payment $payment */
            $info = $payment->getAdditionalInformation();
            if (!is_array($info) || ($info['razorpay_order_id'] ?? '') !== $razorpayOrderId) {
                continue;
            }

            $order = Mage::getModel('sales/order')->load($payment->getParentId());
            if ($order->getId()) {
                return $order;
            }
        }

        return null;
    }

    private function assertPayable(Mage_Sales_Model_Order $order): void
    {
        if ($order->getPayment()->getMethod() !== Kalp_Razorpay_Model_Method_Checkout::METHOD_CODE) {
            Mage::throwException($this->helper()->__('This order is not payable with Razorpay.'));
        }

        if (!$this->isAwaitingPayment($order)) {
            Mage::throwException($this->helper()->__('This order is not awaiting payment.'));
        }
    }

    private function isAwaitingPayment(Mage_Sales_Model_Order $order): bool
    {
        return match ($order->getState()) {
            Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
            Mage_Sales_Model_Order::STATE_NEW => true,
            default => false,
        };
    }

    /**
     * Payment arrived for an order that is no longer awaiting payment (e.g. cancelled).
     * Do not revive the order; leave a history note for the merchant.
     */
    private function flagPaidButNotCapturable(
        Mage_Sales_Model_Order $order,
        string $paymentId,
        string $source,
    ): void {
        $message = $this->helper()->__(
            'Razorpay payment %s was received via %s, but the order is in state "%s" and was not captured automatically. Review and refund or reinstate manually.',
            $paymentId,
            $source,
            (string) $order->getState(),
        );

        $order->addStatusHistoryComment($message);
        $order->save();

        $this->helper()->debug('Paid but not capturable', [
            'increment_id' => $order->getIncrementId(),
            'payment_id' => $paymentId,
            'state' => $order->getState(),
            'source' => $source,
        ]);
    }

    private function createAndPersistRazorpayOrder(
        Mage_Sales_Model_Order $order,
        int $amount,
        string $currency,
        int $storeId,
    ): string {
        /** @var Kalp_Razorpay_Model_Api_Client $client */
        $client = Mage::getModel('razorpay/api_client', ['store_id' => $storeId]);

        $razorpayOrder = $client->createOrder([
            'receipt' => substr((string) $order->getIncrementId(), 0, 40),
            'amount' => $amount,
            'currency' => $currency,
            'notes' => [
                'magento_order_id' => (string) $order->getIncrementId(),
                'store_id' => (string) $storeId,
            ],
        ]);

        $razorpayOrderId = (string) ($razorpayOrder['id'] ?? '');
        if ($razorpayOrderId === '') {
            Mage::throwException($this->helper()->__('Razorpay did not return an order id.'));
        }

        $payment = $order->getPayment();
        $payment
            ->setAdditionalInformation('razorpay_order_id', $razorpayOrderId)
            ->setAdditionalInformation('razorpay_amount', $amount)
            ->setAdditionalInformation('razorpay_currency', $currency)
            ->save();

        return $razorpayOrderId;
    }

    private function captureOrder(
        Mage_Sales_Model_Order $order,
        string $paymentId,
        ?string $razorpayOrderId = null,
    ): void {
        $orderId = (int) $order->getId();
        if ($orderId <= 0 || $paymentId === '') {
            return;
        }

        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $orderTable = $resource->getTableName('sales/order');

        // Serialize callback + webhook: lock the order row, reload, then capture once.
        $write->beginTransaction();
        try {
            $write->fetchOne(
                $write->select()
                    ->from($orderTable, ['entity_id'])
                    ->where('entity_id = ?', $orderId)
                    ->forUpdate(true),
            );

            $order->load($orderId);
            if (!$order->getId()) {
                $write->commit();
                return;
            }

            if ($this->alreadyCaptured($order, $paymentId)) {
                $this->helper()->debug('Order already captured, skipping', [
                    'increment_id' => $order->getIncrementId(),
                    'payment_id' => $paymentId,
                ]);
                $write->commit();
                return;
            }

            if (!$this->isAwaitingPayment($order)) {
                $this->flagPaidButNotCapturable($order, $paymentId, 'capture');
                $write->commit();
                return;
            }

            $payment = $order->getPayment();
            $payment
                ->setTransactionId($paymentId)
                ->setAdditionalInformation('razorpay_payment_id', $paymentId)
                ->setIsTransactionClosed(true);

            if ($razorpayOrderId !== null && $razorpayOrderId !== '') {
                $payment->setAdditionalInformation('razorpay_order_id', $razorpayOrderId);
            }

            $payment->registerCaptureNotification((float) $order->getGrandTotal(), true);

            $order->setState(
                Mage_Sales_Model_Order::STATE_PROCESSING,
                true,
                $this->helper()->__('Razorpay payment captured. Payment ID: %s', $paymentId),
                true,
            );
            $order->save();

            $write->commit();
        } catch (Throwable $e) {
            $write->rollBack();
            throw $e;
        }

        if ($order->getCanSendNewEmailFlag()) {
            try {
                $order->queueNewOrderEmail();
            } catch (Throwable $e) {
                Mage::logException($e);
            }
        }

        $this->helper()->debug('Order captured', [
            'increment_id' => $order->getIncrementId(),
            'payment_id' => $paymentId,
        ]);
    }

    private function alreadyCaptured(Mage_Sales_Model_Order $order, string $paymentId): bool
    {
        if ($order->hasInvoices()) {
            return true;
        }

        $storedPaymentId = (string) $order->getPayment()->getAdditionalInformation('razorpay_payment_id');
        if ($storedPaymentId !== '' && hash_equals($storedPaymentId, $paymentId)) {
            return true;
        }

        /** @var Mage_Sales_Model_Order_Payment_Transaction $txn */
        $txn = Mage::getModel('sales/order_payment_transaction');
        $txn->setOrderPaymentObject($order->getPayment());
        $txn->loadByTxnId($paymentId);

        return (bool) $txn->getId();
    }

    private function helper(): Kalp_Razorpay_Helper_Data
    {
        /** @var Kalp_Razorpay_Helper_Data $helper */
        $helper = Mage::helper('razorpay');

        return $helper;
    }
}
