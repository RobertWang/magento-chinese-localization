<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Mage
 * @package     Mage_Paypal
 * @copyright   Copyright (c) 2009 Irubin Consulting Inc. DBA Varien (http://www.varien.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Abstract Express Checkout Controller
 */
abstract class Mage_Paypal_Controller_Express_Abstract extends Mage_Core_Controller_Front_Action
{
    /**
     * @var Mage_Paypal_Model_Express_Checkout
     */
    protected $_checkout = null;

    /**
     * @var Mage_Paypal_Model_Config
     */
    protected $_config = null;

    /**
     * Instantiate config
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_config = Mage::getModel($this->_configType, array($this->_configMethod));
    }

    /**
     * Start Express Checkout by requesting initial token and dispatching customer to PayPal
     */
    public function startAction()
    {
        try {
            $this->_initCheckout();
            $this->_checkout->prepareGiropayUrls(Mage::getUrl('checkout/onepage/success'),
                Mage::getUrl('paypal/express/cancel'), Mage::getUrl('checkout/onepage/success')
            );
            $token = $this->_checkout->start(Mage::getUrl('*/*/return'), Mage::getUrl('*/*/cancel'));
            if ($token && $url = $this->_checkout->getRedirectUrl()) {
                $this->_initToken($token);
                $this->getResponse()->setRedirect($url);
                return;
            }
        }
        catch (Mage_Core_Exception $e) {
            $this->_getCheckoutSession()->addError($e->getMessage());
        }
        catch (Exception $e) {
            $this->_getCheckoutSession()->addError($this->__('Unable to start Express Checkout.'));
            Mage::logException($e);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Cancel Express Checkout
     */
    public function cancelAction()
    {
        try {
            $this->_initToken(false);
            // if there is an order - cancel it
            if ($orderId = $this->_getCheckoutSession()->getLastOrderId()) {
                $order = Mage::getModel('sales/order')->load($orderId);
                if ($order->getId()) {
                    $order->cancel()->save();
                    $this->_getCheckoutSession()
                        ->unsLastQuoteId()
                        ->unsLastSuccessQuoteId()
                        ->unsLastOrderId()
                        ->unsLastRealOrderId()
                        ->addSuccess($this->__('Express Checkout and Order have been cancelled.'))
                    ;
                }
            } else {
                $this->_getCheckoutSession()->addSuccess($this->__('Express Checkout has been cancelled.'));
            }
        } catch (Mage_Core_Exception $e) {
            $this->_getCheckoutSession()->addError($e->getMessage());
        } catch (Exception $e) {
            $this->_getCheckoutSession()->addError($this->__('Unable to cancel Express Checkout.'));
            Mage::logException($e);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Return from PayPal and dispatch customer to order review page
     */
    public function returnAction()
    {
        try {
            $this->_initCheckout();
            $this->_checkout->returnFromPaypal($this->_initToken());
            $this->_redirect('*/*/review');
            return;
        }
        catch (Mage_Core_Exception $e) {
            Mage::getSingleton('checkout/session')->addError($e->getMessage());
        }
        catch (Exception $e) {
            Mage::getSingleton('checkout/session')->addError($this->__('Unable to process Express Checkout approval.'));
            Mage::logException($e);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Review order after returning from PayPal
     */
    public function reviewAction()
    {
        try {
            $this->_initCheckout();
            $this->_checkout->prepareOrderReview($this->_initToken());
            $this->loadLayout();
            $this->_initLayoutMessages('paypal/session');
            $this->getLayout()->getBlock('paypal.express.review')
                ->setQuote($this->_getQuote())
                ->setCanEditShippingAddress($this->_checkout->mayEditShippingAddress())
                ->getChild('details')->setQuote($this->_getQuote())
            ;
            $this->renderLayout();
            return;
        }
        catch (Mage_Core_Exception $e) {
            Mage::getSingleton('checkout/session')->addError($e->getMessage());
        }
        catch (Exception $e) {
            Mage::getSingleton('checkout/session')->addError($this->__('Unable to initialize Express Checkout review.'));
            Mage::logException($e);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Dispatch customer back to PayPal for editing payment information
     */
    public function editAction()
    {
        try {
            $this->getResponse()->setRedirect($this->_config->getExpressCheckoutEditUrl($this->_initToken()));
        }
        catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
            $this->_redirect('*/*/review');
        }
    }

    /**
     * Update shipping method (combined action for ajax and regular request)
     */
    public function saveShippingMethodAction()
    {
        try {
            $isAjax = $this->getRequest()->getParam('isAjax');
            $this->_initCheckout();
            $this->_checkout->updateShippingMethod($this->getRequest()->getParam('shipping_method'));
            if ($isAjax) {
                $this->loadLayout('paypal_express_review_details');
                $this->getResponse()->setBody($this->getLayout()->getBlock('root')
                    ->setQuote($this->_getQuote())
                    ->toHtml());
                return;
            }
        }
        catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        catch (Exception $e) {
            $this->_getSession()->addError($this->__('Unable to update shipping method.'));
            Mage::logException($e);
        }
        if ($isAjax) {
            $this->getResponse()->setBody('<script type="text/javascript">window.location.href = '
                . Mage::getUrl('*/*/review') . ';</script>');
        } else {
            $this->_redirect('*/*/review');
        }
    }

    /**
     * Submit the order
     */
    public function placeOrderAction()
    {
        try {
            $this->_initCheckout();
            $order = $this->_checkout->placeOrder($this->_initToken());
            // prepare session to success or cancellation page
            $quoteId = $this->_getQuote()->getId();
            $this->_getCheckoutSession()
                ->setLastQuoteId($quoteId)
                ->setLastSuccessQuoteId($quoteId)
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
            ;
            if ($url = $this->_checkout->getRedirectUrl()) {
                $this->getResponse()->setRedirect($url);
                return;
            }
            $this->_initToken(false); // no need in token anymore
            $this->_redirect('checkout/onepage/success');
            return;
        }
        catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        catch (Exception $e) {
            $this->_getSession()->addError($this->__('Unable to place the order.'));
            Mage::logException($e);
        }
        $this->_redirect('*/*/review');
    }

    /**
     * Instantiate quote and checkout
     * @throws Mage_Core_Exception
     */
    private function _initCheckout()
    {
        $quote = $this->_getQuote();
        if (!$quote->hasItems()) {
            $this->getResponse()->setHeader('HTTP/1.1','403 Forbidden');
            Mage::throwException(Mage::helper('paypal')->__('Unable to initialize Express Checkout.'));
        }
        $this->_checkout = Mage::getSingleton($this->_checkoutType, array(
            'config' => $this->_config,
            'quote'  => $quote,
        ));
    }

    /**
     * Search for proper checkout token in request or session or (un)set specified one
     * Combined getter/setter
     *
     * @param string $setToken
     * @return Mage_Paypal_ExpressController|string
     */
    protected function _initToken($setToken = null)
    {
        if (null !== $setToken) {
            if (false === $setToken) {
                if (!$this->_getSession()->getExpressCheckoutToken()) { // security measure for avoid unsetting token twice
                    Mage::throwException($this->__('PayPal Express Checkout Token does not exist.'));
                }
                $this->_getSession()->unsExpressCheckoutToken();
            } else {
                $this->_getSession()->setExpressCheckoutToken($setToken);
            }
            return $this;
        }
        if ($setToken = $this->getRequest()->getParam('token')) {
            if ($setToken !== $this->_getSession()->getExpressCheckoutToken()) {
                Mage::throwException($this->__('Wrong PayPal Express Checkout Token specified.'));
            }
        } else {
            $setToken = $this->_getSession()->getExpressCheckoutToken();
        }
        return $setToken;
    }

    /**
     * PayPal session instance getter
     *
     * @return Mage_PayPal_Model_Session
     */
    private function _getSession()
    {
        return Mage::getSingleton('paypal/session');
    }

    /**
     * Return checkout session object
     *
     * @return Mage_Checkout_Model_Session
     */
    private function _getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Return checkout quote object
     *
     * @return Mage_Sale_Model_Quote
     */
    private function _getQuote()
    {
        return $this->_getCheckoutSession()->getQuote();
    }
}
