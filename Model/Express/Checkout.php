<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Yaoli\Paypals\Model\Express;

use Magento\Customer\Api\Data\CustomerInterface as CustomerDataObject;
use Magento\Customer\Model\AccountManagement;
use Magento\Paypal\Model\Config as PaypalConfig;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Quote\Model\Quote\Address;
use Magento\Framework\DataObject;
use Magento\Paypal\Model\Cart as PaypalCart;

/**
 * Wrapper that performs Paypal Express and Checkout communication
 * Use current Paypal Express method instance
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Checkout extends \Magento\Paypal\Model\Express\Checkout
{
    /**
     * Reserve order ID for specified quote and start checkout on PayPal
     *
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param bool|null $button
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function start($returnUrl, $cancelUrl, $button = null)
    {
        $this->_quote->collectTotals();

        if (!$this->_quote->getGrandTotal()) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __(
                    'PayPal can\'t process orders with a zero balance due. '
                    . 'To finish your purchase, please go through the standard checkout process.'
                )
            );
        }

        $this->_quote->reserveOrderId();
        $this->quoteRepository->save($this->_quote);
        // prepare API
        $this->_getApi();
        $solutionType = $this->_config->getMerchantCountry() == 'DE'
            ? \Magento\Paypal\Model\Config::EC_SOLUTION_TYPE_MARK
            : $this->_config->getValue('solutionType');

        /** @ $currentCurrencyCode Get Current Store Currency Code */
        $currentCurrencyCode = $this->_storeManager->getStore()->getCurrentCurrency()->getCode();
        
        /*$this->_api->setAmount($this->_quote->getBaseGrandTotal())
            ->setCurrencyCode($this->_quote->getBaseCurrencyCode())*/
        $this->_api->setAmount($this->_quote->getGrandTotal())
            ->setCurrencyCode($currentCurrencyCode)
            ->setInvNum($this->_quote->getReservedOrderId())
            ->setReturnUrl($returnUrl)
            ->setCancelUrl($cancelUrl)
            ->setSolutionType($solutionType)
            ->setPaymentAction($this->_config->getValue('paymentAction'));
        if ($this->_giropayUrls) {
            list($successUrl, $cancelUrl, $pendingUrl) = $this->_giropayUrls;
            $this->_api->addData(
                [
                    'giropay_cancel_url' => $cancelUrl,
                    'giropay_success_url' => $successUrl,
                    'giropay_bank_txn_pending_url' => $pendingUrl,
                ]
            );
        }

        if ($this->_isBml) {
            $this->_api->setFundingSource('BML');
        }

        $this->_setBillingAgreementRequest();

        if ($this->_config->getValue('requireBillingAddress') == PaypalConfig::REQUIRE_BILLING_ADDRESS_ALL) {
            $this->_api->setRequireBillingAddress(1);
        }

        // suppress or export shipping address
        $address = null;
        if ($this->_quote->getIsVirtual()) {
            if ($this->_config->getValue('requireBillingAddress')
                == PaypalConfig::REQUIRE_BILLING_ADDRESS_VIRTUAL
            ) {
                $this->_api->setRequireBillingAddress(1);
            }
            $this->_api->setSuppressShipping(true);
        } else {
            $address = $this->_quote->getShippingAddress();
            $isOverridden = 0;
            if (true === $address->validate()) {
                $isOverridden = 1;
                $this->_api->setAddress($address);
            }
            $this->_quote->getPayment()->setAdditionalInformation(
                self::PAYMENT_INFO_TRANSPORT_SHIPPING_OVERRIDDEN,
                $isOverridden
            );
            $this->_quote->getPayment()->save();
        }

        /** @var $cart \Magento\Payment\Model\Cart */
        $cart = $this->_cartFactory->create(['salesModel' => $this->_quote]);

        $this->_api->setPaypalCart($cart);

        if (!$this->_taxData->getConfig()->priceIncludesTax()) {
            $this->setShippingOptions($cart, $address);
        }

        $this->_config->exportExpressCheckoutStyleSettings($this->_api);

        /* Temporary solution. @TODO: do not pass quote into Nvp model */
        $this->_api->setQuote($this->_quote);
        $this->_api->callSetExpressCheckout();

        $token = $this->_api->getToken();

        $this->_setRedirectUrl($button, $token);

        $payment = $this->_quote->getPayment();
        $payment->unsAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_BILLING_AGREEMENT);
        // Set flag that we came from Express Checkout button
        if (!empty($button)) {
            $payment->setAdditionalInformation(self::PAYMENT_INFO_BUTTON, 1);
        } elseif ($payment->hasAdditionalInformation(self::PAYMENT_INFO_BUTTON)) {
            $payment->unsAdditionalInformation(self::PAYMENT_INFO_BUTTON);
        }
        $payment->save();

        return $token;
    }

    /**
     * Set shipping options to api
     * @param \Magento\Paypal\Model\Cart $cart
     * @param \Magento\Quote\Model\Quote\Address|null $address
     * @return void
     */
    private function setShippingOptions(PaypalCart $cart, Address $address = null)
    {
        // for included tax always disable line items (related to paypal amount rounding problem)
        $this->_api->setIsLineItemsEnabled($this->_config->getValue(PaypalConfig::TRANSFER_CART_LINE_ITEMS));

        // add shipping options if needed and line items are available
        $cartItems = $cart->getAllItems();
        if ($this->_config->getValue(PaypalConfig::TRANSFER_CART_LINE_ITEMS)
            && $this->_config->getValue(PaypalConfig::TRANSFER_SHIPPING_OPTIONS)
            && !empty($cartItems)
        ) {
            if (!$this->_quote->getIsVirtual()) {
                $options = $this->_prepareShippingOptions($address, true);
                if ($options) {
                    $this->_api->setShippingOptionsCallbackUrl(
                        $this->_coreUrl->getUrl(
                            '*/*/shippingOptionsCallback',
                            ['quote_id' => $this->_quote->getId()]
                        )
                    )->setShippingOptions($options);
                }
            }
        }
    }

    /**
     * Place the order when customer returned from PayPal until this moment all quote data must be valid.
     *
     * @param string $token
     * @param string|null $shippingMethodCode
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function place($token, $shippingMethodCode = null)
    {
        if ($shippingMethodCode) {
            $this->updateShippingMethod($shippingMethodCode);
        }

        if ($this->getCheckoutMethod() == \Magento\Checkout\Model\Type\Onepage::METHOD_GUEST) {
            $this->prepareGuestQuote();
        }

        $this->ignoreAddressValidation();
        $this->_quote->collectTotals();
        $order = $this->quoteManagement->submit($this->_quote);

        if (!$order) {
            return;
        }

        // commence redirecting to finish payment, if paypal requires it
        if ($order->getPayment()->getAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_REDIRECT)) {
            $this->_redirectUrl = $this->_config->getExpressCheckoutCompleteUrl($token);
        }

        if ($order->getState == '') $order->setState('complete')->save();

        switch ($order->getState()) {
            // even after placement paypal can disallow to authorize/capture, but will wait until bank transfers money
            case \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT:
                // TODO
                break;
                // regular placement, when everything is ok
            case \Magento\Sales\Model\Order::STATE_PROCESSING:
            case \Magento\Sales\Model\Order::STATE_COMPLETE:
            case \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW:
                $this->orderSender->send($order);
                $this->_checkoutSession->start();
                break;
            default:
                break;
        }
        $this->_order = $order;
    }

    /**
     * Make sure addresses will be saved without validation errors
     *
     * @return void
     */
    private function ignoreAddressValidation()
    {
        $this->_quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$this->_quote->getIsVirtual()) {
            $this->_quote->getShippingAddress()->setShouldIgnoreValidation(true);
            if (!$this->_config->getValue('requireBillingAddress')
                && !$this->_quote->getBillingAddress()->getEmail()
            ) {
                $this->_quote->getBillingAddress()->setSameAsBilling(1);
            }
        }
    }
}
