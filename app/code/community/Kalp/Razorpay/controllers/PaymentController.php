<?php

/**
 * SPDX-FileCopyrightText: 2026 Kalp Razorpay Contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Kalp_Razorpay
 */

declare(strict_types=1);

class Kalp_Razorpay_PaymentController extends Mage_Core_Controller_Front_Action
{
    #[Maho\Config\Route('/razorpay/payment/redirect', methods: ['GET', 'POST'])]
    public function redirectAction(): void
    {
        $order = $this->resolvePendingOrder();
        if ($order === null) {
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            $this->loadLayout();
            $block = $this->getLayout()->getBlock('razorpay.redirect');
            if ($block instanceof Kalp_Razorpay_Block_Redirect) {
                $block->setOrder($order);
            }
            $this->renderLayout();
        } catch (Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('checkout/session')->addError(
                Mage::helper('razorpay')->__('Unable to start Razorpay checkout: %s', $e->getMessage()),
            );
            $this->_redirect('checkout/cart');
        }
    }

    #[Maho\Config\Route('/razorpay/payment/callback', methods: ['GET', 'POST'])]
    public function callbackAction(): void
    {
        $payload = $this->readCallbackPayload();
        $order = $this->resolvePendingOrder()
            ?? ($payload['razorpay_order_id'] !== ''
                ? $this->loadOrderByRazorpayOrderId($payload['razorpay_order_id'])
                : null);

        if ($order === null) {
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            /** @var Kalp_Razorpay_Model_Payment_Processor $processor */
            $processor = Mage::getModel('razorpay/payment_processor');
            $processor->processCallback($order, $payload);

            Mage::getSingleton('checkout/session')
                ->setLastSuccessQuoteId($order->getQuoteId())
                ->setLastQuoteId($order->getQuoteId())
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId());

            $this->_redirect('checkout/onepage/success', ['_secure' => true]);
        } catch (Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('checkout/session')->addError(
                Mage::helper('razorpay')->__('Payment verification failed: %s', $e->getMessage()),
            );
            $this->_redirect('razorpay/payment/cancel');
        }
    }

    #[Maho\Config\Route('/razorpay/payment/cancel', methods: ['GET', 'POST'])]
    public function cancelAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $order = $this->resolvePendingOrder();

        if ($order?->canCancel()) {
            try {
                $order->cancel();
                $order->addStatusHistoryComment(
                    Mage::helper('razorpay')->__('Razorpay payment was cancelled or failed.'),
                );
                $order->save();
            } catch (Throwable $e) {
                Mage::logException($e);
            }
        }

        $session->addError(
            Mage::helper('razorpay')->__('Your Razorpay payment was cancelled. You can place the order again.'),
        );
        $this->_redirect('checkout/cart');
    }

    /** @return array{razorpay_payment_id: string, razorpay_order_id: string, razorpay_signature: string} */
    private function readCallbackPayload(): array
    {
        $request = $this->getRequest();

        return [
            'razorpay_payment_id' => $this->readParam($request, 'razorpay_payment_id'),
            'razorpay_order_id' => $this->readParam($request, 'razorpay_order_id'),
            'razorpay_signature' => $this->readParam($request, 'razorpay_signature'),
        ];
    }

    private function readParam(Mage_Core_Controller_Request_Http $request, string $key): string
    {
        $post = $request->getPost($key);
        if (is_string($post) && $post !== '') {
            return $post;
        }

        return (string) $request->getParam($key, '');
    }

    private function resolvePendingOrder(): ?Mage_Sales_Model_Order
    {
        $incrementId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        if (!$incrementId) {
            return null;
        }

        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
        if (!$order->getId()) {
            return null;
        }

        return $order->getPayment()->getMethod() === Kalp_Razorpay_Model_Method_Checkout::METHOD_CODE
            ? $order
            : null;
    }

    private function loadOrderByRazorpayOrderId(string $razorpayOrderId): ?Mage_Sales_Model_Order
    {
        /** @var Kalp_Razorpay_Model_Payment_Processor $processor */
        $processor = Mage::getModel('razorpay/payment_processor');

        return $processor->loadOrderByRazorpayOrderId($razorpayOrderId);
    }
}
