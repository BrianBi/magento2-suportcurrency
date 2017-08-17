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
        $_data['id'] = $_data['business'] == "gloryprofit@outlook.com" ? 28 : 2;
        $url = 'amqp://apwsaghf:OZCCS8xRMg4qFeRuZTs6ov2pqleHF-n_@orangutan.rmq.cloudamqp.com/apwsaghf';
        $amqp = RabbitMQ::create('ipn', $url);
        $result = $amqp->publish($_data);
    }
}