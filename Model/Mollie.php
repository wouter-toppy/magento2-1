<?php
/**
 * Copyright © 2018 Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Mollie\Payment\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderFactory;
use Mollie\Api\MollieApiClient;
use Mollie\Payment\Config;
use Mollie\Payment\Helper\General as MollieHelper;
use Mollie\Payment\Model\Client\Orders as OrdersApi;
use Mollie\Payment\Model\Client\Payments as PaymentsApi;
use Mollie\Payment\Service\Mollie\Timeout;

/**
 * Class Mollie
 *
 * @package Mollie\Payment\Model
 */
class Mollie extends AbstractMethod
{
    /**
     * Enable Initialize
     *
     * @var bool
     */
    protected $_isInitializeNeeded = true;
    /**
     * Enable Gateway
     *
     * @var bool
     */
    protected $_isGateway = true;
    /**
     * Enable Refund
     *
     * @var bool
     */
    protected $_canRefund = true;
    /**
     * Enable Partial Refund
     *
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseInternal = false;
    /**
     * @var Config
     */
    protected $config;
    /**
     * @var array
     */
    private $issuers = [];
    /**
     * @var MollieHelper
     */
    private $mollieHelper;
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    /**
     * @var OrderRepository
     */
    private $orderRepository;
    /**
     * @var OrderFactory
     */
    private $orderFactory;
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;
    /**
     * @var OrdersApi
     */
    private $ordersApi;
    /**
     * @var PaymentsApi
     */
    private $paymentsApi;
    /**
     * @var AssetRepository
     */
    private $assetRepository;
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;
    /**
     * @var Timeout
     */
    private $timeout;

    /**
     * Mollie constructor.
     *
     * @param Context                    $context
     * @param Registry                   $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory      $customAttributeFactory
     * @param Data                       $paymentData
     * @param ScopeConfigInterface       $scopeConfig
     * @param Logger                     $logger
     * @param OrderRepository            $orderRepository
     * @param OrderFactory               $orderFactory
     * @param OrdersApi                  $ordersApi
     * @param PaymentsApi                $paymentsApi
     * @param MollieHelper               $mollieHelper
     * @param CheckoutSession            $checkoutSession
     * @param SearchCriteriaBuilder      $searchCriteriaBuilder
     * @param AssetRepository            $assetRepository
     * @param ResourceConnection         $resourceConnection
     * @param Config                     $config
     * @param Timeout                    $timeout
     * @param AbstractResource|null      $resource
     * @param AbstractDb|null            $resourceCollection
     * @param array                      $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        OrderRepository $orderRepository,
        OrderFactory $orderFactory,
        OrdersApi $ordersApi,
        PaymentsApi $paymentsApi,
        MollieHelper $mollieHelper,
        CheckoutSession $checkoutSession,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        AssetRepository $assetRepository,
        ResourceConnection $resourceConnection,
        Config $config,
        Timeout $timeout,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->paymentsApi = $paymentsApi;
        $this->ordersApi = $ordersApi;
        $this->mollieHelper = $mollieHelper;
        $this->checkoutSession = $checkoutSession;
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->orderFactory = $orderFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->assetRepository = $assetRepository;
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
        $this->timeout = $timeout;
    }

    /**
     * Extra checks for method availability
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     *
     * @return bool
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote == null) {
            $quote = $this->checkoutSession->getQuote();
        }

        if (!$this->mollieHelper->isAvailable($quote->getStoreId())) {
            return false;
        }

        $activeMethods = $this->mollieHelper->getAllActiveMethods($quote->getStoreId());
        if ($this->_code != 'mollie_methods_paymentlink' && !array_key_exists($this->_code, $activeMethods)) {
            return false;
        }

        if (!$this->canUseForCountry($quote->getShippingAddress()->getCountryId())) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $this->getInfoInstance();

        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $status = $this->mollieHelper->getStatusPending($order->getStoreId());
        $stateObject->setState(Order::STATE_NEW);
        $stateObject->setStatus($status);
        $stateObject->setIsNotified(false);
    }

    /**
     * @param Order $order
     *
     * @return bool|void
     * @throws LocalizedException
     * @throws \Mollie\Api\Exceptions\ApiException
     */
    public function startTransaction(Order $order)
    {
        $storeId = $order->getStoreId();
        if (!$apiKey = $this->mollieHelper->getApiKey($storeId)) {
            return false;
        }

        $mollieApi = $this->loadMollieApi($apiKey);
        $method = $this->mollieHelper->getApiMethod($order);

        if ($method == 'order') {
            return $this->startTransactionUsingTheOrdersApi($order, $mollieApi);
        }

        return $this->timeout->retry( function () use ($order, $mollieApi) {
            return $this->paymentsApi->startTransaction($order, $mollieApi);
        });
    }

    private function startTransactionUsingTheOrdersApi(Order $order, MollieApiClient $mollieApi)
    {
        try {
            return $this->timeout->retry( function () use ($order, $mollieApi) {
                return $this->ordersApi->startTransaction($order, $mollieApi);
            });
        } catch (\Exception $exception) {
            $this->mollieHelper->addTolog('error', $exception->getMessage());
        }

        $methodCode = $this->mollieHelper->getMethodCode($order);
        if ($methodCode == 'klarnapaylater' || $methodCode == 'klarnasliceit' || $methodCode == 'voucher') {
            throw new LocalizedException(__($exception->getMessage()));
        }

        // Retry the order using the "payment" method.
        return $this->timeout->retry( function () use ($order, $mollieApi) {
            return $this->paymentsApi->startTransaction($order, $mollieApi);
        });
    }

    /**
     * @param $apiKey
     *
     * @return MollieApiClient
     * @throws \Mollie\Api\Exceptions\ApiException
     * @throws LocalizedException
     */
    public function loadMollieApi($apiKey)
    {
        if (class_exists('Mollie\Api\MollieApiClient')) {
            $mollieApiClient = new MollieApiClient();
            $mollieApiClient->setApiKey($apiKey);
            $mollieApiClient->addVersionString('Magento/' . $this->mollieHelper->getMagentoVersion());
            $mollieApiClient->addVersionString('MollieMagento2/' . $this->mollieHelper->getExtensionVersion());
            return $mollieApiClient;
        } else {
            throw new LocalizedException(__('Class Mollie\Api\MollieApiClient does not exist'));
        }
    }

    /**
     * @param $storeId
     * @return MollieApiClient|null
     */
    public function getMollieApi($storeId)
    {
        $apiKey = $this->mollieHelper->getApiKey($storeId);

        try {
            return $this->loadMollieApi($apiKey);
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', $e->getMessage());
            return null;
        }
    }

    /**
     * @param        $orderId
     * @param string $type
     * @param null   $paymentToken
     *
     * @return array
     * @throws LocalizedException
     * @throws \Mollie\Api\Exceptions\ApiException
     */
    public function processTransaction($orderId, $type = 'webhook', $paymentToken = null)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->orderRepository->get($orderId);
        if (empty($order)) {
            $msg = ['error' => true, 'msg' => __('Order not found')];
            $this->mollieHelper->addTolog('error', $msg);
            return $msg;
        }

        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            $msg = ['error' => true, 'msg' => __('Transaction ID not found')];
            $this->mollieHelper->addTolog('error', $msg);
            return $msg;
        }

        $storeId = $order->getStoreId();
        if (!$apiKey = $this->mollieHelper->getApiKey($storeId)) {
            $msg = ['error' => true, 'msg' => __('API Key not found')];
            $this->mollieHelper->addTolog('error', $msg);
            return $msg;
        }

        $mollieApi = $this->loadMollieApi($apiKey);

        // Defaults to the "default" connection when there is not connection available named "sales".
        // This is required for stores with a split database (Enterprise only):
        // https://devdocs.magento.com/guides/v2.3/config-guide/multi-master/multi-master.html
        $connection = $this->resourceConnection->getConnection('sales');

        try {
            $connection->beginTransaction();

            if (preg_match('/^ord_\w+$/', $transactionId)) {
                $result = $this->ordersApi->processTransaction($order, $mollieApi, $type, $paymentToken);
            } else {
                $result = $this->paymentsApi->processTransaction($order, $mollieApi, $type, $paymentToken);
            }

            $connection->commit();
            return $result;
        } catch (\Exception $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    public function orderHasUpdate($orderId)
    {
        $order = $this->orderRepository->get($orderId);

        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            $msg = ['error' => true, 'msg' => __('Transaction ID not found')];
            $this->mollieHelper->addTolog('error', $msg);
            return $msg;
        }

        $storeId = $order->getStoreId();
        if (!$apiKey = $this->mollieHelper->getApiKey($storeId)) {
            $msg = ['error' => true, 'msg' => __('API Key not found')];
            $this->mollieHelper->addTolog('error', $msg);
            return $msg;
        }

        $mollieApi = $this->loadMollieApi($apiKey);

        if (preg_match('/^ord_\w+$/', $transactionId)) {
            return $this->ordersApi->orderHasUpdate($order, $mollieApi);
        } else {
            return $this->paymentsApi->orderHasUpdate($order, $mollieApi);
        }
    }

    /**
     * @param DataObject $data
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getAdditionalData();
        if (isset($additionalData['selected_issuer'])) {
            $this->getInfoInstance()->setAdditionalInformation('selected_issuer', $additionalData['selected_issuer']);
        }

        if (isset($additionalData['card_token'])) {
            $this->getInfoInstance()->setAdditionalInformation('card_token', $additionalData['card_token']);
        }

        if (isset($additionalData['applepay_payment_token'])) {
            $this->getInfoInstance()->setAdditionalInformation('applepay_payment_token', $additionalData['applepay_payment_token']);
        }

        return $this;
    }

    /**
     * @param Order\Shipment $shipment
     * @param Order          $order
     *
     * @return OrdersApi
     * @throws LocalizedException
     */
    public function createShipment(Order\Shipment $shipment, Order $order)
    {
        return $this->ordersApi->createShipment($shipment, $order);
    }

    /**
     * @param Order\Shipment       $shipment
     * @param Order\Shipment\Track $track
     * @param Order                $order
     *
     * @return OrdersApi
     * @throws LocalizedException
     */
    public function updateShipmentTrack(Order\Shipment $shipment, Order\Shipment\Track $track, Order $order)
    {
        return $this->ordersApi->updateShipmentTrack($shipment, $track, $order);
    }

    /**
     * @param Order $order
     *
     * @return OrdersApi
     * @throws LocalizedException
     */
    public function cancelOrder(Order $order)
    {
        return $this->ordersApi->cancelOrder($order);
    }

    /**
     * @param Order\Creditmemo $creditmemo
     * @param Order            $order
     *
     * @return $this
     * @throws LocalizedException
     */
    public function createOrderRefund(Order\Creditmemo $creditmemo, Order $order)
    {
        $this->ordersApi->createOrderRefund($creditmemo, $order);
    }

    /**
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float                                $amount
     *
     * @return $this
     * @throws LocalizedException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();
        $storeId = $order->getStoreId();

        /**
         * Order Api does not use amount to refund, but refunds per itemLine
         * See SalesOrderCreditmemoSaveAfter Observer for logic.
         */
        $checkoutType = $this->mollieHelper->getCheckoutType($order);
        if ($checkoutType == 'order') {
            $this->_registry->register('online_refund', true);
            return $this;
        }

        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            $msg = ['error' => true, 'msg' => __('Transaction ID not found')];
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        $apiKey = $this->mollieHelper->getApiKey($storeId);
        if (empty($apiKey)) {
            $msg = ['error' => true, 'msg' => __('Api key not found')];
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        try {
            $mollieApi = $this->loadMollieApi($apiKey);
            $payment = $mollieApi->payments->get($transactionId);
            $payment->refund([
                "amount" => [
                    "currency" => $order->getOrderCurrencyCode(),
                    "value"    => $this->mollieHelper->formatCurrencyValue($amount, $order->getOrderCurrencyCode())
                ]
            ]);
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', $e->getMessage());
            throw new LocalizedException(__('Error: not possible to create an online refund: %1', $e->getMessage()));
        }

        return $this;
    }

    /**
     * Get order by TransactionId
     *
     * @param $transactionId
     *
     * @return mixed
     */
    public function getOrderIdByTransactionId($transactionId)
    {
        $orderId = $this->orderFactory->create()
            ->addFieldToFilter('mollie_transaction_id', $transactionId)
            ->getFirstItem()
            ->getId();

        if ($orderId) {
            return $orderId;
        } else {
            $this->mollieHelper->addTolog('error', __('No order found for transaction id %1', $transactionId));
            return false;
        }
    }

    /**
     * Get list of Issuers from API
     *
     * @param $mollieApi
     * @param $method
     * @param $issuerListType
     *
     * @return array|null
     */
    public function getIssuers($mollieApi, $method, $issuerListType)
    {
        $issuers = [];

        if (empty($mollieApi) || $issuerListType == 'none') {
            return $issuers;
        }

        $methodCode = str_replace('mollie_methods_', '', $method);

        try {
            $issuersList = $mollieApi->methods->get($methodCode, ["include" => "issuers"])->issuers;
            if (!$issuersList) {
                return null;
            }

            foreach ($issuersList as $issuer) {
                $issuers[] = $issuer;
            }
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', $e->getMessage());
        }

        if ($issuers && $issuerListType == 'dropdown') {
            array_unshift($issuers, [
                'resource' => 'issuer',
                'id'       => '',
                'name'     => __('-- Please Select --')
            ]);
        }

        if ($this->mollieHelper->addQrOption() && $methodCode == 'ideal') {
            $issuers[] = [
                'resource' => 'issuer',
                'id'       => '',
                'name'     => __('QR Code'),
                'image'    => ['size2x' => $this->assetRepository->getUrl("Mollie_Payment::images/qr-select.png")]
            ];
        }

        return $issuers;
    }

    /**
     * Get list payment methods by Store ID.
     * Used in Observer/ConfigObserver to validate payment methods.
     *
     * @param $storeId
     *
     * @return bool|\Mollie\Api\Resources\MethodCollection
     * @throws \Mollie\Api\Exceptions\ApiException
     * @throws LocalizedException
     */
    public function getPaymentMethods($storeId)
    {
        $apiKey = $this->mollieHelper->getApiKey($storeId);

        if (empty($apiKey)) {
            return false;
        }

        $mollieApi = $this->loadMollieApi($apiKey);

        return $mollieApi->methods->all([
            'resource' => 'orders',
            'includeWallets' => 'applepay',
        ]);
    }
}
