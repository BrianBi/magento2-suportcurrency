<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Yaoli\Paypals\Model\Payment;

/**
 * Provide methods for collecting cart items information of specific sales model entity
 */
class Cart extends \Magento\Payment\Model\Cart
{/**
     * Create item object from item data
     *
     * @param string $name
     * @param int $qty
     * @param float $amount
     * @param null|string $identifier
     * @return \Magento\Framework\DataObject
     */
    protected function _createItemFromData($name, $qty, $amount, $identifier = null)
    {
        if ($name = 'Discount')
        {
            $_data = array(
                    'name'   => $name,
                    'qty'    => $qty,
                    'amount' => (double)$this->getDiscount()
                );
        } else {
            $_data = array(
                    'name'   => $name,
                    'qty'    => $qty,
                    'amount' => (double)$this->CurrencyConverter($amount)
                );
        }
        //$item = new \Magento\Framework\DataObject(['name' => $name, 'qty' => $qty, 'amount' => (double)$amount]);
        $item = new \Magento\Framework\DataObject($_data);

        if ($identifier) {
            $item->setData('id', $identifier);
        }

        return $item;
    }

    /**
     * CurrencyConverter Method
     * @param $amount float
     * @return float
     */
    protected function CurrencyConverter($amount)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager  = $objectManager->get( 'Magento\Store\Model\StoreManagerInterface' );
        $amount = $storeManager->getStore()->convertPrice($amount);
        return round($amount,2);
    }
}
