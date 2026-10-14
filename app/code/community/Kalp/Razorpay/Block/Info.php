<?php

/**
 * SPDX-FileCopyrightText: 2026 Kalp Razorpay Contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Kalp_Razorpay
 */

declare(strict_types=1);

final class Kalp_Razorpay_Block_Info extends Mage_Payment_Block_Info
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setTemplate('kalp/razorpay/info.phtml');
    }

    #[\Override]
    protected function _prepareSpecificInformation($transport = null)
    {
        if ($this->_paymentSpecificInformation !== null) {
            return $this->_paymentSpecificInformation;
        }

        $transport = parent::_prepareSpecificInformation($transport);
        $info = $this->getInfo();
        /** @var Kalp_Razorpay_Helper_Data $helper */
        $helper = Mage::helper('razorpay');

        $extra = array_filter([
            (string) $helper->__('Razorpay Order ID') => $info->getAdditionalInformation('razorpay_order_id'),
            (string) $helper->__('Razorpay Payment ID') => $info->getAdditionalInformation('razorpay_payment_id'),
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        return $transport->setData([...$extra, ...$transport->getData()]);
    }
}
