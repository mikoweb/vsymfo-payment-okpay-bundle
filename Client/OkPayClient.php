<?php

/*
 * This file is part of the vSymfo package.
 *
 * website: www.vision-web.pl
 * (c) Rafał Mikołajun <rafal@vision-web.pl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace vSymfo\Payment\OkPayBundle\Client;

use Symfony\Component\HttpFoundation\Request;

/**
 * Klient OKPAY
 * @author Rafał Mikołajun <rafal@vision-web.pl>
 * @package vSymfoPaymentOkPayBundle
 */
class OkPayClient
{
    const REF_NUM_PREFIX = "OKPAY__";

    /**
     * @var string
     */
    private $walletId;

    /**
     * @var string
     */
    private $apiPassword;

    /**
     * @param string $walletId
     * @param string $apiPassword
     */
    public function __construct($walletId, $apiPassword)
    {
        if (!is_string($walletId)) {
            throw new \InvalidArgumentException('$walletId is not string');
        }

        if (!is_string($apiPassword)) {
            throw new \InvalidArgumentException('$apiPassword is not string');
        }

        $this->walletId = $walletId;
        $this->apiPassword = $apiPassword;
    }

    /**
     * @return string
     */
    public function getWalletId()
    {
        return $this->walletId;
    }

    /**
     * @return string
     */
    public function getApiPassword()
    {
        return $this->apiPassword;
    }

    /**
     * @param Request $request
     * @return CallbackResponse
     */
    public function getCallbackResponse(Request $request)
    {
        return new CallbackResponse($request);
    }
}
