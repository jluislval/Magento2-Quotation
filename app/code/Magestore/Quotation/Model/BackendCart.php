<?php
/**
 * Copyright © Magestore, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

// @codingStandardsIgnoreFile

namespace Magestore\Quotation\Model;

use Magento\Framework\App\ObjectManager;

/**
 * Class BackendCart
 * @package Magestore\Quotation\Model
 */
class BackendCart extends \Magento\Sales\Model\AdminOrder\Create
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    protected $_quote_request;

    /**
     * @var \Magestore\Quotation\Model\GeneralSession
     */
    protected $_general_session;

    /**
     * @var \Magento\Quote\Model\CustomerManagement
     */
    protected $customerManagement;

    /**
     * @var \Magento\Quote\Model\CustomerManagement
     */
    protected $extendedQuoteManagement;

    /**
     * @return \Magestore\Quotation\Model\BackendSession
     */
    public function getSession()
    {
        if( !$this->_session ||
            !($this->_session instanceof \Magestore\Quotation\Model\BackendSession)
        ) {
            $this->_session = ObjectManager::getInstance()->get(\Magestore\Quotation\Model\BackendSession::class);
        }
        return $this->_session;
    }

    /**
     * @return \Magestore\Quotation\Model\GeneralSession
     */
    public function getGeneralSession()
    {
        if( !$this->_general_session ||
            !($this->_general_session instanceof \Magestore\Quotation\Model\GeneralSession)
        ) {
            $this->_general_session = ObjectManager::getInstance()->get(\Magestore\Quotation\Model\GeneralSession::class);
        }
        return $this->_general_session;
    }

    /**
     * @return \Magento\Quote\Model\CustomerManagement
     */
    public function getCustomerManagement(){
        if( !$this->customerManagement ||
            !($this->customerManagement instanceof \Magento\Quote\Model\CustomerManagement)
        ) {
            $this->customerManagement = ObjectManager::getInstance()->get(\Magento\Quote\Model\CustomerManagement::class);
        }
        return $this->customerManagement;
    }

    /**
     * @return \Magestore\Quotation\Model\Quote\QuoteManagement
     */
    public function getExtendedQuoteManagement(){
        if( !$this->extendedQuoteManagement ||
            !($this->extendedQuoteManagement instanceof \Magestore\Quotation\Model\Quote\QuoteManagement)
        ) {
            $this->extendedQuoteManagement = ObjectManager::getInstance()->get(\Magestore\Quotation\Model\Quote\QuoteManagement::class);
        }
        return $this->extendedQuoteManagement;
    }

    /**
     * Retrieve quote object model
     *
     * @return \Magento\Quote\Model\Quote
     */
    public function getQuote()
    {
        if (!$this->_quote_request) {
            $this->_quote_request = $this->getSession()->getQuote();
        }

        return $this->_quote_request;
    }

    /**
     * Set quote object
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return $this
     */
    public function setQuote(\Magento\Quote\Model\Quote $quote)
    {
        $this->_quote_request = $quote;
        return $this;
    }


    /**
     * Update quantity of order quote items
     *
     * @param array $items
     * @return $this
     * @throws \Exception|\Magento\Framework\Exception\LocalizedException
     */
    public function updateQuoteItems($items)
    {
        if (!is_array($items)) {
            return $this;
        }
        parent::updateQuoteItems($items);
        try {
            foreach ($items as $itemId => $info) {
                if (!empty($info['configured'])) {
                    $item = $this->getQuote()->updateItem($itemId, $this->objectFactory->create($info));
                } else {
                    $item = $this->getQuote()->getItemById($itemId);
                    if (!$item) {
                        continue;
                    }
                }
                if ($item && empty($info['custom_price'])) {
                    $item->setOriginalCustomPrice(null);
                    $item->setCustomPrice(null);
                }
                if($item && isset($info['admin_discount_percentage'])){
                    $percentage = intval($info['admin_discount_percentage']);
                    $percentage = min(100, $percentage);
                    $percentage = max(0, $percentage);
                    $item->setAdminDiscountPercentage($percentage);
                }
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->recollectCart();
            throw $e;
        } catch (\Exception $e) {
            $this->_logger->critical($e);
        }
        $this->recollectCart();

        return $this;
    }

    /**
     * @param array $data
     * @return $this|\Magento\Sales\Model\AdminOrder\Create
     */
    public function importPostData($data)
    {
        parent::importPostData($data);
        if($this->getShippingMethod() && $this->getShippingMethod() == 'admin_shipping_standard') {
            if (isset($data['admin_shipping_amount'])) {
                $shippingPrice = $this->_parseAmount($data['admin_shipping_amount']);
                $this->getQuote()->setAdminShippingAmount($shippingPrice);
            }
            if (isset($data['admin_shipping_description'])) {
                $this->getQuote()->setAdminShippingDescription($data['admin_shipping_description']);
            }
            $this->collectShippingRates();
        }

        return $this;
    }

    /**
     * @param $amount
     * @return float|int|null
     */
    public function _parseAmount($amount){
        if($amount === ""){
            return null;
        }
        $amount = floatval($amount);
        $amount = $amount > 0 ? $amount : 0;
        return $amount;
    }

    /**
     * @return $this
     */
    public function checkAndCreateCustomerAccount($quote){
        $quote = ($quote)?$quote:$this->getQuote();
        if (!$quote->getCustomerIsGuest()) {
            if (!$quote->getCustomerId()) {
                $newCustomerGroupId = $quote->getCustomerGroupId();
                $newCustomerEmail = $quote->getCustomerEmail();
                $email = $this->getData('account/email');
                if(!$email && $newCustomerEmail){
                    $this->addData([
                        'account' => [
                            'email'  => $newCustomerEmail
                        ]
                    ]);
                    $this->setData('account/email', $newCustomerEmail);
                }
                $this->_prepareCustomer();
                $customer = $quote->getCustomer();
                $customer->setGroupId($newCustomerGroupId);
            }
            $this->getExtendedQuoteManagement()->updateCustomerAddress($quote);
        }
        return $this;
    }

    /**
     * @param $email
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function validateNewCustomerEmail($email){
        if ($email) {
            $quote = $this->getQuote();
            $storeId = $quote->getStoreId();
            try{
                $customer = $this->customerRepository->get($email);
                if($customer && $customer->getId()){
                    $websiteId = $customer->getWebsiteId();
                    if ($this->accountManagement->isCustomerInStore($websiteId, $storeId)) {
                        throw new \Magento\Framework\Exception\LocalizedException(__('A customer with the same email "%1" already exists in an associated website.', $email));
                    }
                }
            }catch (\Magento\Framework\Exception\NoSuchEntityException $e){
                return true;
            }
        }
        return true;
    }

    /**
     * @param $data
     * @return $this
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\State\InputMismatchException
     */
    public function updateCustomerData($data){
        $quote = $this->getQuote();
        if(!empty($data) && $quote->getCustomerId()){
            $customer = $this->customerRepository->getById($quote->getCustomerId());
            if($customer && $customer->getId()){
                foreach ($data as $index => $value) {
                    $customer->setData($index, $value);
                }
                $this->customerRepository->save($customer);
            }
        }
        return $this;
    }
}
