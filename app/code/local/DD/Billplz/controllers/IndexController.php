<?php

class DD_Billplz_IndexController extends Mage_Core_Controller_Front_Action
{
    /**
     * Redirect user to bill payment form
     *
     * @throws Exception
     */
    public function redirectAction()
    {
        $paymentMethod = $this->getMethod();
        $billplz = $this->getBillplz();

        // retrieve order
        $order = $paymentMethod->getCheckout()->getLastRealOrder();

        // create billplz bill before redirect to billplz
        $bill = $billplz->createBill([
            'order_id' => $order->getIncrementId(),
            'name' => $order->getBillingAddress()->getName(),
            'email' => $order->getBillingAddress()->getEmail(),
            'amount' => $order->getBaseGrandTotal(),
            'description' => "Bill for order #{$order->getIncrementId()}",
        ]);

        if ($bill) {
            // save Billplz bill id
            $payment = $order->getPayment();
            $payment->setAdditionalInformation('bill_id', $bill->id);
            $payment->save();

            $order->setData('billplz_bill_id', $bill->id);
            $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, "Collection ID: {$bill->collection_id}; Bill: {$bill->id}; Status: Pending Payment; Bill URL: {$bill->url}");
            $order->save();

            Mage::log("Redirecting user to Billplz for bill: {$bill->id}", LOG_DEBUG, 'billplz.log');

            $this->_redirectUrl($bill->url);
        } else {
            Mage::log("Failed to create bill. " . print_r($bill, true), LOG_DEBUG, 'billplz.log');
            $this->norouteAction();
        }
    }

    /**
     * Receive callback from Billplz
     */
    public function callbackAction()
    {
        if ($this->getRequest()->getMethod() != 'POST') {
            $this->norouteAction();
        }

        /** @var DD_Billplz_Model_Billplz $billplz */
        $billplz = $this->getBillplz();

        $params = $this->getRequest()->getPost();
        $source_string = $this->xSignatureSourceString($params);
        $xsignature_key = $billplz->getXSignature();
        $xsignature = $params['x_signature'];
        $equal = hash_equals(hash_hmac('sha256', $source_string, $xsignature_key), $xsignature);

        if (!$equal) {
            http_response_code(403);
            exit;
        }

        $bill_id = $params['id'];

        Mage::log("Received callback for bill: {$bill_id}", LOG_DEBUG, 'billplz.log');

        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($bill_id, 'billplz_bill_id');
        // If bill is paid and order status is pending payment, create invoice for order
        if ($params['paid'] == 'true' && $order->getState() == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            $this->_createInvoice($order, $bill_id);
        }

    }

    /**
     * Redirect after payment made
     */
    public function completeAction()
    {
        $params = $_GET;

        /** @var DD_Billplz_Model_Billplz $billplz */
        $billplz = $this->getBillplz();

        $source_string = $this->xSignatureSourceString($params);
        $xsignature_key = $billplz->getXSignature();
        $xsignature = $params['billplz']['x_signature'];
        $equal = hash_equals(hash_hmac('sha256', $source_string, $xsignature_key), $xsignature);

        if (!$equal) {
            http_response_code(403);
            exit('Check X Signature Key');
        }

        $bill_id = $params['billplz']['id'];

        Mage::log("Complete: {$bill_id}", LOG_DEBUG, 'billplz.log');

        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($bill_id, 'billplz_bill_id');
        if ($params['billplz']['paid'] == 'true') {
            if ($order->getState() == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                $this->_createInvoice($order, $bill_id);
            }

            $this->_redirect('checkout/onepage/success');
        } else {
            $this->_redirect('checkout/onepage/failure');
        }

    }

    /**
     * @return DD_Billplz_Model_Payment
     */
    private function getMethod()
    {
        return Mage::getModel('billplz/payment');
    }

    /**
     * @return DD_Billplz_Model_Billplz
     */
    private function getBillplz()
    {
        return Mage::getModel('billplz/billplz');
    }

    private function _createInvoice(Mage_Sales_Model_Order $order, $bill_id)
    {
        if (!$order->canInvoice()) {
            return;
        }

        /** @var Mage_Sales_Model_Order_Invoice $invoice */
        $invoice = $order->prepareInvoice();
        $invoice->register()->capture();
        Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();

        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, "Order invoiced; Bill ID: {$bill_id}", true);
        $order->save();
    }

    private function xSignatureSourceString($data, $prefix = '')
    {
        uksort($data, function ($a, $b) {
            $a_len = strlen($a);
            $b_len = strlen($b);
            $result = strncasecmp($a, $b, min($a_len, $b_len));
            if ($result === 0) {
                $result = $b_len - $a_len;
            }
            return $result;
        });
        $processed = [];
        foreach ($data as $key => $value) {
            if ($key === 'x_signature') {
                continue;
            }

            if (is_array($value)) {
                $processed[] = $this->xSignatureSourceString($value, $key);
            } else {
                $processed[] = $prefix . $key . $value;
            }
        }
        return implode('|', $processed);
    }
}
