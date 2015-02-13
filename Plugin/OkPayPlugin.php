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

namespace vSymfo\Payment\OkPayBundle\Plugin;

use JMS\Payment\CoreBundle\Model\ExtendedDataInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\Plugin\Exception\BlockedException;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use JMS\Payment\CoreBundle\Util\Number;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Router;
use vSymfo\Component\Payments\EventDispatcher\PaymentEvent;
use vSymfo\Payment\OkPayBundle\Client\CallbackResponse;
use vSymfo\Payment\OkPayBundle\Client\OkPayClient;

/**
 * Plugin płatności OKPAY
 * @author Rafał Mikołajun <rafal@vision-web.pl>
 * @package vSymfoPaymentOkPayBundle
 */
class OkPayPlugin extends AbstractPlugin
{
    /**
     * @var OkPayClient
     */
    private $client;

    /**
     * @var Router
     */
    private $router;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @param Router $router The router
     * @param OkpayClient $client
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(Router $router, OkPayClient $client, EventDispatcherInterface $dispatcher)
    {
        $this->client = $client;
        $this->router = $router;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Nazwa płatności
     * @return string
     */
    public function getName()
    {
        return 'okpay_payment';
    }

    /**
     * {@inheritdoc}
     */
    public function processes($name)
    {
        return $this->getName() === $name;
    }

    /**
     * {@inheritdoc}
     */
    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        if ($transaction->getState() === FinancialTransactionInterface::STATE_NEW) {
            throw $this->createOkPayRedirect($transaction);
        }

        $this->approve($transaction, $retry);
        $this->deposit($transaction, $retry);
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @return ActionRequiredException
     */
    public function createOkPayRedirect(FinancialTransactionInterface $transaction)
    {
        $actionRequest = new ActionRequiredException('Redirecting to OKPAY.');
        $actionRequest->setFinancialTransaction($transaction);
        $instruction = $transaction->getPayment()->getPaymentInstruction();
        $extendedData = $transaction->getExtendedData();

        if (!$extendedData->has('success_url')) {
            throw new \RuntimeException('You must configure a success_url.');
        }

        if (!$extendedData->has('fail_url')) {
            throw new \RuntimeException('You must configure a fail_url.');
        }

        $query = 'ok_receiver=' . $this->client->getWalletId()
            . '&ok_item_1_price=' . $transaction->getRequestedAmount()
            . '&ok_currency=' . $instruction->getCurrency()
            . '&ok_item_1_name=' . ($extendedData->has('description') ? $extendedData->get('description') : '')
            . '&ok_ipn=' . $this->router->generate('vsymfo_payment_okpay_callback', array(
                'id' => $instruction->getId()
            ), true)
            . '&ok_return_fail=' . $extendedData->get('fail_url')
            . '&ok_return_success=' . $extendedData->get('success_url')
        ;

        $actionRequest->setAction(new VisitUrl("https://www.okpay.com/process.html?" . $query));

        return $actionRequest;
    }

    /**
     * Check that the extended data contains the needed values
     * before approving and depositing the transation
     *
     * @param ExtendedDataInterface $data
     * @throws BlockedException
     */
    protected function checkExtendedDataBeforeApproveAndDeposit(ExtendedDataInterface $data)
    {
        if (!$data->has('ok_txn_status') || !$data->has('ok_txn_id') || !$data->has('ok_txn_gross') || !$data->has('ok_txn_currency')) {
            throw new BlockedException("Awaiting extended data from OkPay");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function approve(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();
        $this->checkExtendedDataBeforeApproveAndDeposit($data);

        if ($data->get('ok_txn_status') == CallbackResponse::STATUS_COMPLETED) {
            $transaction->setReferenceNumber(OkPayClient::REF_NUM_PREFIX . $data->get("ok_txn_id"));
            $transaction->setProcessedAmount($data->get('ok_txn_gross'));
            $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
            $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
        } else {
            $e = new FinancialException('Payment status unknow: ' . $data->get('ok_txn_status'));
            $e->setFinancialTransaction($transaction);
            $transaction->setResponseCode('Unknown');
            $transaction->setReasonCode($data->get('ok_txn_status'));
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deposit(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();

        if ($transaction->getResponseCode() !== PluginInterface::RESPONSE_CODE_SUCCESS
            || $transaction->getReasonCode() !== PluginInterface::REASON_CODE_SUCCESS
        ) {
            $e = new FinancialException('Peyment is not completed');
            $e->setFinancialTransaction($transaction);
            throw $e;
        }

        // różnica kwoty zatwierdzonej i kwoty wymaganej musi być równa zero
        // && nazwa waluty musi się zgadzać
        if (Number::compare($transaction->getProcessedAmount(), $transaction->getRequestedAmount()) === 0
            && $transaction->getPayment()->getPaymentInstruction()->getCurrency() == $data->get('ok_txn_currency')
        ) {
            // wszystko ok
            // można zakakceptować zamówienie
            $event = new PaymentEvent($this->getName(), $transaction, $transaction->getPayment()->getPaymentInstruction());
            $this->dispatcher->dispatch('deposit', $event);
        } else {
            // coś się nie zgadza, nie można tego zakaceptować
            $e = new FinancialException('The deposit has not passed validation');
            $e->setFinancialTransaction($transaction);
            $transaction->setResponseCode('Unknown');
            $transaction->setReasonCode($data->get('ok_txn_status'));
            throw $e;
        }
    }
}
