<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

// @codingStandardsIgnoreFile

namespace Yaoli\Paypals\Model\Api;

use Magento\Payment\Model\Cart;
use Magento\Payment\Model\Method\Logger;

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
    public function getPayTotal()
    {
        var_dump($this->_cart->getCartAllItems());die;
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager  = $objectManager->get('Magento\Store\Model\StoreManagerInterface');

        $priceHelper = $objectManager->create('Magento\Directory\Helper\Data');

        $currency    = $storeManager->getStore()->getCurrentCurrency()->getCode();

        echo round(((float)$this->_cart->getAmounts() * 10) * $priceHelper->currencyConvert((float)$this->_cart->getAmounts(), 'USD', $currency), 2);
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

        $request['ITEMAMT'] = $request['AMT'];

        if (isset($request['L_AMT1'])) 
            $request['L_AMT0'] = ($request['AMT'] + abs($request['L_AMT1'])) / $request['L_QTY0'];
        else
            $request['L_AMT0']  = $request['AMT'] / $request['L_QTY0'];

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

        $request['ITEMAMT'] = $request['AMT'];
        
        if (isset($request['L_AMT1'])) 
            $request['L_AMT0'] = ($request['AMT'] + abs($request['L_AMT1'])) / $request['L_QTY0'];
        else
            $request['L_AMT0']  = $request['AMT'] / $request['L_QTY0'];

        $response = $this->call(self::DO_EXPRESS_CHECKOUT_PAYMENT, $request);
        $this->_importFromResponse($this->_paymentInformationResponse, $response);
        $this->_importFromResponse($this->_doExpressCheckoutPaymentResponse, $response);
        $this->_importFromResponse($this->_createBillingAgreementResponse, $response);
    }
}
