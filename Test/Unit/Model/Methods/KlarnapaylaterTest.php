<?php

namespace Mollie\Payment\Test\Unit\Model\Methods;

use Mollie\Payment\Model\Methods\Klarnapaylater;
use Mollie\Payment\Test\Unit\Model\Methods\AbstractMethodTest;

class KlarnapaylaterTest extends AbstractMethodTest
{
    protected $instance = Klarnapaylater::class;

    protected $code = 'klarnapaylater';
}
