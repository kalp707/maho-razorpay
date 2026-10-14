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
    protected $_canRefund = false;
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
}
