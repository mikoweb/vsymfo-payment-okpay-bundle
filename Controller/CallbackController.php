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

namespace vSymfo\Payment\OkPayBundle\Controller;

use JMS\Payment\CoreBundle\Entity\PaymentInstruction;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use vSymfo\Payment\OkPayBundle\Client\CallbackResponse;
use vSymfo\Payment\OkPayBundle\Client\OkPayClient;

/**
 * @author Rafał Mikołajun <rafal@vision-web.pl>
 * @package vSymfoPaymentOkPayBundle
 */
class CallbackController extends Controller
{
    /**
     * @param Request $request
     * @param PaymentInstruction $instruction
     * @return Response
     * @throws \Exception
     */
    public function callbackAction(Request $request, PaymentInstruction $instruction)
    {
        if (null === $transaction = $instruction->getPendingTransaction()) {
            return new Response('No pending transaction found for the payment instruction', 500);
        }

        $doctrine = $this->getDoctrine();
        $client = $this->get('payment.okpay.client');
        $response = $client->getCallbackResponse($request);
        $post = $request->request;

        if ($response->getResult() != CallbackResponse::RESULT_VERIFIED) {
            throw new \Exception("Transaction is not verified. Result: " . $response->getResult(), 1);
        }

        if ($response->isValid()) {
            if ($post->get("ok_txn_status") != CallbackResponse::STATUS_COMPLETED) {
                throw new \Exception("Invalid transaction status. Status: " . $post->get("ok_txn_status"), 2);
            }

            if ($post->get("ok_receiver_wallet") != $client->getWalletId()) {
                throw new \Exception("Invalid wallet id.", 3);
            }

            $exist = $doctrine->getRepository('JMSPaymentCoreBundle:FinancialTransaction')
                ->findOneByReferenceNumber(OkPayClient::REF_NUM_PREFIX . $post->get("ok_txn_id"));
            ;

            if (!is_null($exist)) {
                throw new \Exception("Transaction " . $post->get("ok_txn_id") . " has been previously processed", 4);
            }

            $em = $this->getDoctrine()->getManager();
            $transaction->getExtendedData()->set("ok_txn_status", $post->get("ok_txn_status"));
            $transaction->getExtendedData()->set("ok_txn_id", $post->get("ok_txn_id"));
            $transaction->getExtendedData()->set("ok_receiver", $post->get("ok_receiver"));
            $transaction->getExtendedData()->set("ok_receiver_email", $post->get("ok_receiver_email"));
            $transaction->getExtendedData()->set("ok_receiver_id", $post->get("ok_receiver_id"));
            $transaction->getExtendedData()->set("ok_receiver_wallet", $post->get("ok_receiver_wallet"));
            $transaction->getExtendedData()->set("ok_txn_gross", $post->get("ok_txn_gross"));
            $transaction->getExtendedData()->set("ok_txn_currency", $post->get("ok_txn_currency"));
            $em->persist($transaction);
        } else {
            $exceptions = $response->getValidationExceptions();
            throw new $exceptions[0];
        }

        $payment = $transaction->getPayment();
        $result = $this->get('payment.plugin_controller')->approveAndDeposit($payment->getId(), (float)$post->get("ok_txn_currency"));
        if (is_object($ex = $result->getPluginException())) {
            throw $ex;
        }

        $em->flush();

        return new Response('OK');
    }
}
