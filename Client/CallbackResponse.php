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
 * @author Rafał Mikołajun <rafal@vision-web.pl>
 * @url https://dev.okpay.com/pl/guides/accepting-payments.html#code
 * @package vSymfoPaymentBlockchainBundle
 */
class CallbackResponse
{
    const RESULT_VERIFIED = 'VERIFIED';
    const RESULT_INVALID = 'INVALID';
    const RESULT_TEST = 'TEST';
    const STATUS_COMPLETED = 'completed';

    /**
     * VERIFIED|INVALID|TEST
     * @var string
     */
    private $result = null;

    /**
     * @var Request
     */
    private $request;

    /**
     * Wyjątki walidacji
     * @var array
     */
    private $validationExceptions = array();

    /**
     * @param Request $request
     * @throws \Exception
     */
    public function __construct(Request $request)
    {
        if (!function_exists('curl_version')) {
            throw new \Exception('curl not found');
        }

        $this->request = $request;
        $this->validation();

        $post = $request->request->all();
        $postData = 'ok_verify=true';
        foreach ($post as $key => $value) {
            $value = urlencode(stripslashes($value));
            $postData .= "&$key=$value";
        }

        $ch = curl_init('https://www.okpay.com/ipn-verify.html');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $this->result = curl_exec($ch);
        curl_close($ch);
    }

    /**
     * @return string
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return array
     */
    public function getValidationExceptions()
    {
        return $this->validationExceptions;
    }

    /**
     * Walidowanie requesta
     */
    private function validation()
    {
        $post = $this->getRequest()->request;

        if (!$post->has("ok_txn_status") || !$post->has("ok_txn_id") || !$post->has("ok_receiver")
            || !$post->has("ok_receiver_email") || !$post->has("ok_receiver_id") || !$post->has("ok_receiver_wallet")
            || !$post->has("ok_txn_gross") || !$post->has("ok_txn_currency")
        ) {
            $this->validationExceptions[] = new \Exception("Invalid response.", 100);
        }
    }

    /**
     * Czy request przeszedł walidację
     * @return bool
     */
    public function isValid()
    {
        $exceptions = $this->getValidationExceptions();
        return empty($exceptions);
    }
}
