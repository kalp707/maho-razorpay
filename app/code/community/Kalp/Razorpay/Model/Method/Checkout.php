<?php

/**
 * SPDX-FileCopyrightText: 2026 Kalp Razorpay Contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Kalp_Razorpay
 */

declare(strict_types=1);

class Kalp_Razorpay_Model_Method_Checkout extends Mage_Payment_Model_Method_Abstract
{
    public const string METHOD_CODE = 'razorpay';

    protected $_code = self::METHOD_CODE;
    protected $_formBlockType = 'razorpay/form';
    protected $_infoBlockType = 'razorpay/info';

    protected $_isGateway = true;
    protected $_canOrder = false;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_isInitializeNeeded = true;
    protected $_canManageRecurringProfiles = false;

    /** @var list<string> */
    protected $_debugReplacePrivateDataKeys = ['key_secret', 'webhook_secret'];

    #[\Override]
    public function isAvailable($quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }

        /** @var Kalp_Razorpay_Helper_Data $helper */
        $helper = Mage::helper('razorpay');
        $storeId = $quote?->getStoreId() !== null ? (int) $quote->getStoreId() : null;

        return $helper->hasApiKeys($storeId);
    }

    #[\Override]
    public function canUseForCurrency($currencyCode)
    {
        return true;
    }

    #[\Override]
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject
            ->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            ->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            ->setIsNotified(false);

        return $this;
    }

    #[\Override]
    public function getConfigPaymentAction()
    {
        return $this->getConfigData('payment_action') ?: self::ACTION_AUTHORIZE;
    }

    public function getOrderPlaceRedirectUrl(): string
    {
        return Mage::getUrl('razorpay/payment/redirect', ['_secure' => true]);
    }

    public function getCallbackUrl(): string
    {
        return Mage::getUrl('razorpay/payment/callback', ['_secure' => true]);
    }

    public function getCancelUrl(): string
    {
        return Mage::getUrl('razorpay/payment/cancel', ['_secure' => true]);
    }

    /**
     * Online refund via Razorpay API. Magento passes the base currency amount;
     * Razorpay expects order-currency subunits, so prefer the creditmemo grand total.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     */
    #[\Override]
    public function refund(\Maho\DataObject $payment, $amount)
    {
        parent::refund($payment, $amount);

        /** @var Mage_Sales_Model_Order_Payment $payment */
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();
        /** @var Kalp_Razorpay_Helper_Data $helper */
        $helper = Mage::helper('razorpay');

        $razorpayPaymentId = (string) $payment->getAdditionalInformation('razorpay_payment_id');
        if ($razorpayPaymentId === '') {
            $razorpayPaymentId = (string) ($payment->getParentTransactionId() ?: $payment->getLastTransId());
        }
        if ($razorpayPaymentId === '') {
            Mage::throwException($helper->__('No Razorpay payment ID found for refund.'));
        }

        $currency = strtoupper((string) $order->getOrderCurrencyCode());
        $creditmemo = $payment->getCreditmemo();
        $refundAmount = $creditmemo instanceof Mage_Sales_Model_Order_Creditmemo
            ? (float) $creditmemo->getGrandTotal()
            : (float) $amount;

        if ($refundAmount <= 0) {
            Mage::throwException($helper->__('Refund amount must be greater than zero.'));
        }

        $subunits = $helper->toSubunits($refundAmount, $currency);

        /** @var Kalp_Razorpay_Model_Api_Client $client */
        $client = Mage::getModel('razorpay/api_client', ['store_id' => $storeId]);

        try {
            $result = $client->refundPayment($razorpayPaymentId, [
                'amount' => $subunits,
                'notes' => [
                    'magento_order_id' => (string) $order->getIncrementId(),
                ],
            ]);
        } catch (Throwable $e) {
            $helper->debug('Razorpay refund failed', ['message' => $e->getMessage()]);
            Mage::throwException(
                $helper->__('Razorpay refund failed: %s', $e->getMessage()),
            );
        }

        $refundId = (string) ($result['id'] ?? '');
        if ($refundId === '') {
            Mage::throwException($helper->__('Razorpay did not return a refund id.'));
        }

        $payment
            ->setTransactionId($refundId)
            ->setIsTransactionClosed(true)
            ->setAdditionalInformation('razorpay_refund_id', $refundId);

        // Keep parent capture open while the invoice can still be partially refunded.
        $invoice = $creditmemo instanceof Mage_Sales_Model_Order_Creditmemo
            ? $creditmemo->getInvoice()
            : null;
        if ($invoice) {
            $remaining = (float) $invoice->getBaseGrandTotal() - (float) $invoice->getBaseTotalRefunded() - (float) $amount;
            $payment->setShouldCloseParentTransaction($remaining < 0.0001);
        }

        $helper->debug('Razorpay refund stored', [
            'refund_id' => $refundId,
            'payment_id' => $razorpayPaymentId,
            'amount' => $refundAmount,
            'currency' => $currency,
        ]);

        return $this;
    }
}
