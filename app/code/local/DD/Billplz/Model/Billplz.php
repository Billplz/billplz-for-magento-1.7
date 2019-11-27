<?php

class DD_Billplz_Model_Billplz
{
    const API_BASE_URL = 'https://www.billplz.com/api/v3/';
    const STAGING_API_BASE_URL = 'https://www.billplz-sandbox.com/api/v3/';

    /**
     * @var bool
     */
    protected $_testMode = false;

    /**
     * @var DD_Billplz_Helper_Data
     */
    protected $_helper;

    /**
     * @var DD_Billplz_Model_Payment
     */
    protected $_payment;

    /**
     * DD_Billplz_Model_Api constructor.
     */
    public function __construct()
    {
        $this->_helper = Mage::helper('billplz');
        $this->_payment = Mage::getModel('billplz/payment');
        $this->_testMode = (bool) Mage::getStoreConfig('payment/billplz/test_mode');
    }

    /**
     * Create bill collection
     *
     * @param string $title
     *
     * @return bool|object
     */
    public function createCollection($title)
    {
        try {

            $client = $this->getClient("collections");

            $response = $client->setParameterPost('title', $title)->request(Zend_Http_Client::POST);

            return json_decode($response->getBody());

        } catch (Exception $e) {

            return false;

        }
    }

    /**
     * @param array $payload
     *
     * @return bool|object
     */
    public function createBill(array $payload)
    {
        try {

            $client = $this->getClient("bills");

            Mage::log("Amount: {$payload['amount']}", LOG_DEBUG, 'billplz.log');

            $response = $client->setParameterPost([
                'collection_id' => $payload['collection_id'],
                'email' => $payload['email'],
                'name' => $payload['name'],
                'amount' => $payload['amount'] * 100,
                'description' => "Bill for #{$payload['order_id']}",
                'collection_id' => $this->_payment->getCollectionId(),
                'callback_url' => $this->_helper->getCallbackUrl(),
                'redirect_url' => $this->_helper->getRedirectUrl(),
                'deliver' => false,
            ])->request(Zend_Http_Client::POST);

            return json_decode($response->getBody());

        } catch (Exception $e) {

            return false;

        }
    }

    /**
     * @param $id
     *
     * @return bool|object
     */
    public function getBill($id)
    {
        try {
            $response = $this->getClient("bills/{$id}")->request(Zend_Http_Client::GET);

            return json_decode($response->getBody());

        } catch (Exception $e) {

            return false;

        }
    }

    /**
     * @param $endpoint
     *
     * @return string
     */
    protected function getRequestUri($endpoint)
    {
        $baseUri = $this->_testMode ? self::STAGING_API_BASE_URL : self::API_BASE_URL;

        return $baseUri . $endpoint;
    }

    /**
     * @return Zend_Http_Client
     * @throws Zend_Http_Client_Exception
     */
    public function getClient($endpoint = null)
    {
        $uri = $endpoint ? $this->getRequestUri($endpoint) : null;

        $client = new Zend_Http_Client($uri, [
            'maxredirects' => 0,
            'timeout' => 10,
        ]);

        $client->setAuth($this->_payment->getSecretKey());

        return $client;
    }

    public function getXSignature()
    {
        return $this->_payment->getXSignature();
    }
}
