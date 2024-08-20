<?php

namespace Kato\SplitOrder\Observer;

use Kato\SplitOrder\Observer\AbstractCartActionObserver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CartRemoveObserver extends AbstractCartActionObserver implements ObserverInterface
{

    /**
     * Fired by sales_quote_remove_item event
     *
     * @param Observer $observer
     * @return $this
     */
    public function execute(Observer $observer)
    {
        $quoteItem = $observer->getEvent()->getQuoteItem();
        $this->_processQuoteItemsChange([$quoteItem]);
        return $this;
    }
}
