<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

// @codingStandardsIgnoreFile

namespace Yaoli\Paypals\Model\Api;

use Magento\Payment\Model\Cart;
use Magento\Payment\Model\Method\Logger;
use Yaoli\Sendorder\Model\RabbitMQ;

/**
 * NVP API wrappers model
 * @TODO: move some parts to abstract, don't hesitate to throw exceptions on api calls
 *
 * @method string getToken()
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Nvp extends \Magento\Paypal\Model\Api\Nvp
{
    /**
     * Line items export mapping settings
     * @var array
     */
    protected $_lineItemExportItemsFormat = [
        'id' => 'L_NUMBER%d',
        'name' => ''/*'L_NAME%d'*/,
        'qty' => 'L_QTY%d',
        'amount' => 'L_AMT%d',
    ];

    /**
     * Get Paypal Pay Total
     * @return float
     */
    public function getPayTotal($price)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager  = $objectManager->get('Magento\Store\Model\StoreManagerInterface');

        $priceHelper = $objectManager->create('Magento\Directory\Helper\Data');

        $currency    = $storeManager->getStore()->getCurrentCurrency()->getCode();

        $baseCurcy   = $storeManager->getStore()->getBaseCurrency()->getCode();

        $finalPrice  =  round($price * $priceHelper->currencyConvert((float)$this->_cart->getAmounts(), $baseCurcy, $currency), 2);

        if ($currency == 'TWD')
            return intval($finalPrice);

        return $finalPrice;
    }

    public function getAmtList($request)
    {
        $i = 0;
        $ret = [];
        while (array_key_exists("L_AMT{$i}", $request))
        {
            $ret[] = "{$i}";
            $i++;
        }

        return $ret;
    }

    /**
     * SetExpressCheckout call
     *
     * TODO: put together style and giropay settings
     *
     * @return void
     * @link https://cms.paypal.com/us/cgi-bin/?&cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_SetExpressCheckout
     */
    public function callSetExpressCheckout()
    {
        $this->_prepareExpressCheckoutCallRequest($this->_setExpressCheckoutRequest);
        $request = $this->_exportToRequest($this->_setExpressCheckoutRequest);
        $this->_exportLineItems($request);

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager  = $objectManager->get('Magento\Store\Model\StoreManagerInterface');
        $currency      = $storeManager->getStore()->getCurrentCurrency()->getCode();

        if ($currency == 'TWD')
        {
            $request['AMT'] = intval($request['AMT']);
        }

        $request['ITEMAMT'] = $request['AMT'];
        
        $indexes = $this->getAmtList($request);
        foreach ($indexes as $i => $index)
        {
            $request['L_AMT'.$index] = $this->getPayTotal($request['L_AMT'.$index]);
        }

        // import/suppress shipping address, if any
        $options = $this->getShippingOptions();
        if ($this->getAddress()) {
            $request = $this->_importAddresses($request);
            $request['ADDROVERRIDE'] = 1;
        } elseif ($options && count($options) <= 10) {
            // doesn't support more than 10 shipping options
            $request['CALLBACK'] = $this->getShippingOptionsCallbackUrl();
            $request['CALLBACKTIMEOUT'] = 6;
            // max value
            $request['MAXAMT'] = $request['AMT'] + 999.00;
            // it is impossible to calculate max amount
            $this->_exportShippingOptions($request);
        }

        $response = $this->call(self::SET_EXPRESS_CHECKOUT, $request);
        $this->_importFromResponse($this->_setExpressCheckoutResponse, $response);
    }

    /**
     * DoExpressCheckout call
     *
     * @return void
     * @link https://cms.paypal.com/us/cgi-bin/?&cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_DoExpressCheckoutPayment
     */
    public function callDoExpressCheckoutPayment()
    {
        $this->_prepareExpressCheckoutCallRequest($this->_doExpressCheckoutPaymentRequest);
        $request = $this->_exportToRequest($this->_doExpressCheckoutPaymentRequest);
        $this->_exportLineItems($request);

        if ($this->getAddress()) {
            $request = $this->_importAddresses($request);
            $request['ADDROVERRIDE'] = 1;
        }

        $indexes = $this->getAmtList($request);
        $requestAmount = 0;
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager  = $objectManager->get('Magento\Store\Model\StoreManagerInterface');
        $currency      = $storeManager->getStore()->getCurrentCurrency()->getCode();

        if ($currency == 'TWD') {
            $requestAmount = intval($this->getPayTotal($request['AMT']));

            foreach ($indexes as $i => $index)
            {
                unset($request['L_AMT'.$index]);
                unset($request['L_QTY'.$index]);
            }
        } else {
            foreach ($indexes as $i => $index)
            {
                $request['L_AMT'.$index] = $this->getPayTotal($request['L_AMT'.$index]);
                $requestAmount += $request['L_AMT'.$index] * $request['L_QTY'.$index];
            }
        }

        $request['AMT']     = $requestAmount;
        $request['ITEMAMT'] = $requestAmount;

        $response = $this->call(self::DO_EXPRESS_CHECKOUT_PAYMENT, $request);

        $this->sendInpDataToOa($response);

        $this->_importFromResponse($this->_paymentInformationResponse, $response);
        $this->_importFromResponse($this->_doExpressCheckoutPaymentResponse, $response);
        $this->_importFromResponse($this->_createBillingAgreementResponse, $response);
    }

    /**
     * send pp ipn to amqp
     *
     * @auther bizhongjun
     */
    protected function sendInpDataToOa($response)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $_scopeConfig  = $objectManager->create('Magento\Framework\App\Config\ScopeConfigInterface');

        if (!$_scopeConfig->getValue('yaoli_paypals/general/enable_return')) return;
        
        //$_data['id'] = $_data['business'] == "gloryprofit@outlook.com" ? 28 : 2;
        $_paymentId = 46;
        
        $_data = [
            'ipn' => [
                'payment_status' => $response['PAYMENTSTATUS'],
                'txn_id'         => $response['TRANSACTIONID'],
                'mc_currency'    => $response['CURRENCYCODE'],
                'mc_gross'       => $response['AMT'],
            ],
            'id'  => $_paymentId
        ];

        $url = $_scopeConfig->getValue('yaoli_paypals/general/links');
        $amqp = RabbitMQ::create('ipn', $url);
        $result = $amqp->publish($_data);
    }
}
