<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Mollie\Payment\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

class OrdersApiClient implements ClientInterface
{
    public function placeRequest(TransferInterface $transferObject)
    {
        return [];
    }
}
