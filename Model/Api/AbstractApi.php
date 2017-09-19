<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Yaoli\Paypals\Model\Api;

/**
 * Abstract class for Paypal API wrappers
 */

class AbstractApi extends \Magento\Paypal\Model\Api\AbstractApi
{
	/**
     * Prepare line items request
     *
     * Returns true if there were line items added
     *
     * @param array &$request
     * @param int $i
     * @return true|null
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _exportLineItems(array &$request, $i = 0)
    {
        if (!$this->_cart) {
            return;
        }

        // always add cart totals, even if line items are not requested
        if ($this->_lineItemTotalExportMap) {
            foreach ($this->_cart->getAmounts() as $key => $total) {
                if (isset($this->_lineItemTotalExportMap[$key])) {
                    // !empty($total)
                    $privateKey = $this->_lineItemTotalExportMap[$key];
                    $request[$privateKey] = $this->formatPrice($total);
                }
            }
        }

        // add cart line items
        $items = $this->_cart->getAllItems();
        if (empty($items) || !$this->getIsLineItemsEnabled()) {
            return;
        }
        $result = null;

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager  = $objectManager->get('Magento\Store\Model\StoreManagerInterface');
        $currency      = $storeManager->getStore()->getCurrentCurrency()->getCode();

        foreach ($items as $item) {
            foreach ($this->_lineItemExportItemsFormat as $publicKey => $privateFormat) {
                $result = true;
                $value = $item->getDataUsingMethod($publicKey);
                if (isset($this->_lineItemExportItemsFilters[$publicKey])) {
                    $callback = $this->_lineItemExportItemsFilters[$publicKey];
                    $value = call_user_func([$this, $callback], $value);
                }

                if (is_float($value)) 
                {
                	if ($currency == 'TWD')
                	{
                		$value = intval($value);
                	} else {
                		$value = $this->formatPrice($value);
                	}
                }

                $request[sprintf($privateFormat, $i)] = $value;
            }
            $i++;
        }
        return $result;
    }
}