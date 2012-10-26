<?php

class Loewenstark_Paypal_Model_Standard
extends Mage_Paypal_Model_Standard
{

    /**
     * Return form field array
     *
     * @return array
     */
    public function getStandardCheckoutFormFields()
    {
        $orderIncrementId = $this->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
        /* @var $api Mage_Paypal_Model_Api_Standard */
        $api = Mage::getModel('paypal/api_standard')->setConfigObject($this->getConfig());
        $api->setOrderId($orderIncrementId)
            ->setCurrencyCode($this->getCurrencyCode($order))
            //->setPaymentAction()
            ->setOrder($order)
            ->setNotifyUrl(Mage::getUrl('paypal/ipn/'))
            ->setReturnUrl(Mage::getUrl('paypal/standard/success'))
            ->setCancelUrl(Mage::getUrl('paypal/standard/cancel'));

        // export address
        $isOrderVirtual = $order->getIsVirtual();
        $address = $isOrderVirtual ? $order->getBillingAddress() : $order->getShippingAddress();
        if ($isOrderVirtual) {
            $api->setNoShipping(true);
        } elseif ($address->validate()) {
            $api->setAddress($address);
        }

        // add cart totals and line items
        $api->setPaypalCart(Mage::getModel('paypal/cart', array($order)))
            ->setIsLineItemsEnabled($this->_config->lineItemsEnabled)
        ;
        $api->setCartSummary($this->_getAggregatedCartSummary());
        $api->setLocale($api->getLocaleCode());
        $result = $api->getStandardCheckoutRequest();
        return $result;
    }
    
    /**
     * get Order Currency Code
     *
     * @return ISO Currency Code
     */
    protected function getCurrencyCode(Mage_Sales_Model_Order $order)
    {
        $obj = new Varien_Object();
        $obj->setCurrencyCode($order->getOrderCurrencyCode());
        Mage::dispatchEvent('loe_paypal_currency_code', array('obj' => $obj, "order" => $order, "standard" => $this));
        return $obj->getCurrencyCode();
    }
}