<?php

/**
 * SPDX-FileCopyrightText: 2026 Kalp Razorpay Contributors
 * SPDX-License-Identifier: OSL-3.0
 * @package Kalp_Razorpay
 */

declare(strict_types=1);

use Razorpay\Api\Errors\SignatureVerificationError;

class Kalp_Razorpay_WebhookController extends Mage_Core_Controller_Front_Action
{
    private const string EVENT_PAYMENT_CAPTURED = 'payment.captured';

    #[Maho\Config\Route('/razorpay/webhook', methods: ['POST'])]
    public function indexAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->respond(405);
            return;
        }

        $payload = (string) $this->getRequest()->getRawBody();
        $signature = (string) $this->getRequest()->getHeader('X-Razorpay-Signature');

        /** @var Kalp_Razorpay_Helper_Data $helper */
        $helper = Mage::helper('razorpay');
        $secret = $helper->getWebhookSecret();

        if ($secret === '') {
            $helper->debug('Webhook received but webhook secret is not configured');
            $this->respond(400, 'Webhook secret not configured');
            return;
        }

        try {
            /** @var Kalp_Razorpay_Model_Api_Client $client */
            $client = Mage::getModel('razorpay/api_client');
            $client->verifyWebhookSignature($payload, $signature, $secret);

            $data = Mage::helper('core')->jsonDecode($payload);
            $event = is_array($data) ? (string) ($data['event'] ?? '') : '';

            if ($event === self::EVENT_PAYMENT_CAPTURED) {
                $paymentEntity = is_array($data) ? ($data['payload']['payment']['entity'] ?? null) : null;
                if (is_array($paymentEntity)) {
                    /** @var Kalp_Razorpay_Model_Payment_Processor $processor */
                    $processor = Mage::getModel('razorpay/payment_processor');
                    $processor->processWebhookPaymentCaptured($paymentEntity);
                }
            }

            $this->respond(200, 'OK');
        } catch (SignatureVerificationError $e) {
            $helper->debug('Webhook signature verification failed', ['message' => $e->getMessage()]);
            $this->respond(401, 'Invalid signature');
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->respond(500, 'Error');
        }
    }

    private function respond(int $statusCode, string $body = ''): void
    {
        $this->getResponse()
            ->setHttpResponseCode($statusCode)
            ->setBody($body);
    }
}
