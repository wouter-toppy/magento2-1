<?php
/**
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Mollie\Payment\Service\Order;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface as StoreScope;
use Mollie\Payment\Config;
use Mollie\Payment\Model\Adminhtml\Source\WebhookUrlOptions;

class Transaction
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var Encryptor
     */
    private $encryptor;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(
        Config $config,
        Context $context,
        Encryptor $encryptor,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->config = $config;
        $this->urlBuilder = $context->getUrlBuilder();
        $this->encryptor = $encryptor;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param OrderInterface $order
     * @param string $paymentToken
     * @return string
     */
    public function getRedirectUrl(OrderInterface $order, string $paymentToken): string
    {
        $storeId = $order->getStoreId();
        $useCustomUrl = $this->config->useCustomRedirectUrl($storeId);
        $customUrl = $this->config->customRedirectUrl($storeId);

        if ($useCustomUrl && $customUrl) {
            return $this->addParametersToCustomUrl($order, $paymentToken, $storeId);
        }

        $parameters = 'order_id=' . intval($order->getId()) . '&payment_token=' . $paymentToken . '&utm_nooverride=1';

        $this->urlBuilder->setScope($storeId);
        return $this->urlBuilder->getUrl(
            'mollie/checkout/process/',
            ['_query' => $parameters]
        );
    }

    /**
     * @param null|int|string $storeId
     * @return string
     */
    public function getWebhookUrl($storeId = null)
    {
        if ($this->config->isProductionMode($storeId) ||
            $this->config->useWebhooks($storeId) == WebhookUrlOptions::ENABLED) {
            return $this->urlBuilder->getUrl('mollie/checkout/webhook/', ['_query' => 'isAjax=1']);
        }

        if ($this->config->useWebhooks($storeId) == WebhookUrlOptions::DISABLED) {
            return '';
        }

        return $this->config->customWebhookUrl($storeId);
    }

    private function addParametersToCustomUrl(OrderInterface $order, string $paymentToken, int $storeId = null)
    {
        $replacements = [
            '{{order_id}}' => $order->getId(),
            '{{increment_id}}' => $order->getIncrementId(),
            '{{payment_token}}' => $paymentToken,
            '{{order_hash}}' => base64_encode($this->encryptor->encrypt((string)$order->getId())),
            '{{base_url}}' => $this->scopeConfig->getValue('web/unsecure/base_url', StoreScope::SCOPE_STORE, $storeId),
            '{{unsecure_base_url}}' => $this->scopeConfig->getValue('web/unsecure/base_url', StoreScope::SCOPE_STORE, $storeId),
            '{{secure_base_url}}' => $this->scopeConfig->getValue('web/secure/base_url', StoreScope::SCOPE_STORE, $storeId),
        ];

        $customUrl = $this->config->customRedirectUrl($storeId);
        $customUrl = str_ireplace(
            array_keys($replacements),
            array_values($replacements),
            $customUrl
        );

        return $customUrl;
    }
}
