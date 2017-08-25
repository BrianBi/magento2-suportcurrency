<?php 

namespace Yaoli\Paypals\Model;
use Yaoli\Sendorder\Model\RabbitMQ;

class Express extends Magento\Paypal\Model\Ipn
{
	/**
     * Get ipn data, send verification to PayPal, run corresponding handler
     *
     * @return void
     * @throws Exception
     */
    public function processIpnRequest()
    {
        $this->_addDebugData('ipn', $this->getRequestData());

        try {
            $this->_getConfig();
            $this->_postBack();
            $this->_processOrder();
            /** Push Paypal Ipn To AMQP */
            $this->sendInpDataToOa(array('ipn' => $this->getRequestData()));
        } catch (Exception $e) {
            $this->_addDebugData('exception', $e->getMessage());
            $this->_debug();
            throw $e;
        }
        $this->_debug();
    }

     /**
     * send pp ipn to amqp
     *
     * @auther bizhongjun
     */
    protected function sendInpDataToOa($_data)
    {
        //$_data['id'] = $_data['business'] == "gloryprofit@outlook.com" ? 28 : 2;
        $_data['id']   = 46;
        $url  = 'amqp://apwsaghf:OZCCS8xRMg4qFeRuZTs6ov2pqleHF-n_@orangutan.rmq.cloudamqp.com/apwsaghf';
        $amqp = RabbitMQ::create('ipn', $url);
        $result = $amqp->publish($_data);
    }

    /**
     * Process completed payment (either full or partial)
     *
     * @param bool $skipFraudDetection
     * @return void
     */
    protected function _registerPaymentCapture($skipFraudDetection = false)
    {
        if ($this->getRequestData('transaction_entity') == 'auth') {
            return;
        }
        $parentTransactionId = $this->getRequestData('parent_txn_id');
        $this->_importPaymentInformation();
        $payment = $this->_order->getPayment();
        $payment->setTransactionId(
            $this->getRequestData('txn_id')
        );
        $payment->setCurrencyCode(
            $this->getRequestData('mc_currency')
        );
        $payment->setPreparedMessage(
            $this->_createIpnComment('')
        );
        $payment->setParentTransactionId(
            $parentTransactionId
        );
        $payment->setShouldCloseParentTransaction(
            'Completed' === $this->getRequestData('auth_status')
        );
        $payment->setIsTransactionClosed(
            0
        );
        $payment->registerCaptureNotification(
            $this->getRequestData('mc_gross')
        );
        $this->_order->save();

        // notify customer
        $invoice = $payment->getCreatedInvoice();
        if ($invoice && !$this->_order->getEmailSent()) {
            $this->orderSender->send($this->_order);
            $this->_order->addStatusHistoryComment(
                __('You notified customer about invoice #%1.', $invoice->getIncrementId())
            )->setIsCustomerNotified(
                true
            )->save();
        }
    }
}