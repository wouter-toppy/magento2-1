<?php
/**
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Mollie\Payment\Service\PaymentFee\Types;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address\Total;
use Mollie\Payment\Service\Config\PaymentFee;
use Mollie\Payment\Service\PaymentFee\Result;
use Mollie\Payment\Service\PaymentFee\ResultFactory;
use Mollie\Payment\Service\Tax\TaxCalculate;

class Percentage implements TypeInterface
{
    /**
     * @var ResultFactory
     */
    private $resultFactory;

    /**
     * @var PaymentFee
     */
    private $config;

    /**
     * @var TaxCalculate
     */
    private $taxCalculate;

    public function __construct(
        ResultFactory $resultFactory,
        PaymentFee $config,
        TaxCalculate $taxCalculate
    ) {
        $this->resultFactory = $resultFactory;
        $this->config = $config;
        $this->taxCalculate = $taxCalculate;
    }

    /**
     * @inheritDoc
     */
    public function calculate(CartInterface $cart, Total $total)
    {
        $percentage = $this->config->getPercentage($cart->getPayment()->getMethod(), $cart->getStoreId());

        $shipping = $total->getBaseShippingInclTax();
        $subtotal = $total->getData('base_subtotal_incl_tax');
        if (!$subtotal) {
            $subtotal = $total->getTotalAmount('subtotal');
        }

        $amount = $subtotal;
        if ($this->config->includeShippingInSurcharge($cart->getStoreId())) {
            $amount = $subtotal + $shipping;
        }

        $calculatedResult = ($amount / 100) * $percentage;
        $tax = $this->taxCalculate->getTaxFromAmountIncludingTax($cart, $calculatedResult);

        /** @var Result $result */
        $result = $this->resultFactory->create();
        $result->setAmount($calculatedResult - $tax);
        $result->setTaxAmount($tax);

        return $result;
    }
}
