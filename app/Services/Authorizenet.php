<?php

namespace App\Services;

use net\authorize\api\contract\v1 as AnetAPI;

/**
 * Handles Authorize.net payment gateway integration and configuration.
 */
class Authorizenet
{
    /**
     * @var string The Authorize.net API Login ID
     */
    private string $apiLoginId;

    /**
     * @var string The Authorize.net Transaction Key
     */
    private string $transactionKey;

    /**
     * @var string The environment mode (live/test)
     */
    private string $is_live_mode;

    /**
     * Authorizenet constructor.
     *
     * Initializes the Authorize.net service with provided credentials.
     *
     * @param string $apiLoginId The Authorize.net API Login ID
     * @param string $transactionKey The Authorize.net Transaction Key
     * @param string $isLiveMode The environment mode ('1' for live, '0' for test)
     * @throws \Exception If credentials are invalid
     */
    public function __construct(string $apiLoginId, string $transactionKey, string $isLiveMode)
    {
        $this->apiLoginId = $apiLoginId;
        $this->transactionKey = $transactionKey;
        $this->is_live_mode = $isLiveMode;

        // Validate that required fields are present
        if (empty($this->apiLoginId) || empty($this->transactionKey)) {
            throw new \Exception('Missing required credentials.');
        }
    }

    /**
     * Get the API Login ID.
     *
     * @return string The Authorize.net API Login ID
     */
    public function getApiLoginId(): string
    {
        return $this->apiLoginId;
    }

    /**
     * Get the Transaction Key.
     *
     * @return string The Authorize.net Transaction Key
     */
    public function getTransactionKey(): string
    {
        return $this->transactionKey;
    }

    /**
     * Get the merchant authentication object for Authorize.net API calls.
     *
     * @return AnetAPI\MerchantAuthenticationType The configured merchant authentication object
     */
    public function getMerchantAuthentication(): AnetAPI\MerchantAuthenticationType
    {
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($this->getApiLoginId());
        $merchantAuthentication->setTransactionKey($this->getTransactionKey());
        return $merchantAuthentication;
    }

    /**
     * Check if the gateway is in live mode.
     *
     * @return string The live mode status ('1' for live, '0' for test)
     */
    public function isLive(): string
    {
        return $this->is_live_mode;
    }

    /**
     * Generate a unique reference ID.
     *
     * @return string A unique reference string based on timestamp
     */
    public function getRef(): string
    {
        return 'ref' . time();
    }
}
