<?php
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Mollie\Payment\GraphQL;

use Magento\QuoteGraphQl\Model\Cart\Payment\AdditionalDataProviderInterface;

class DataProvider implements AdditionalDataProviderInterface
{
    public function getData(array $data): array
    {
        return [
            'selected_issuer' => $data['mollie_selected_issuer'] ?? null,
            'card_token' => $data['mollie_card_token'] ?? null,
            'applepay_payment_token' => $data['mollie_applepay_payment_token'] ?? null,
        ];
    }
}
