<?php

namespace Kato\SplitOrder\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

class CartItemsUpdateObserver extends AbstractCartActionObserver implements ObserverInterface
{

    /**
     * Fired by checkout_cart_update_items_after event
     *
     * @param Observer $observer
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /**
         * @var \Magento\Checkout\Model\Cart $cart
         */
        $cart = $observer->getEvent()->getCart();
        $this->_processQuoteItemsChange($cart->getQuote()->getItems());
        return $this;
    }
}
