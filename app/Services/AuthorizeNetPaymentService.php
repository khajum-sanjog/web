<?php

namespace App\Services;

use Illuminate\Http\Response;
use App\Models\PaymentAttempt;
use App\Services\Authorizenet;
use App\Constant\CommonConstant;
use Illuminate\Support\Facades\Log;
use PHPUnit\TextUI\Configuration\Constant;
use net\authorize\api\contract\v1\OrderType;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\contract\v1\PaymentType;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1\UserFieldType;
use net\authorize\api\contract\v1\CreditCardType;
use net\authorize\api\contract\v1\OpaqueDataType;
use net\authorize\api\contract\v1\CustomerAddressType;
use net\authorize\api\contract\v1\TransactionRequestType;
use net\authorize\api\contract\v1\TransactionResponseType;
use net\authorize\api\contract\v1\CreateTransactionRequest;
use net\authorize\api\contract\v1\CreateTransactionResponse;
use net\authorize\api\contract\v1\MerchantAuthenticationType;
use net\authorize\api\controller\CreateTransactionController;

/**
 * Service class for processing payments through Authorize.net gateway.
 */
class AuthorizeNetPaymentService
{
    private $authorizenet;
    private $merchantAuthentication;

    public function __construct(Authorizenet $authorizenet)
    {
        $this->authorizenet = $authorizenet;
        $this->merchantAuthentication = $this->authorizenet->getMerchantAuthentication();
    }

    /**
     * Processes a payment transaction through Authorize.net.
     */
    public function processPayment(array $paymentData): array
    {
        $paymentAttempt = PaymentAttempt::create([
            'user_id' => $paymentData['user_id'],
            'store_id' => $paymentData['store_id'],
            'temp_order_number' => $paymentData['temp_order_number'],
            'member_email' => $paymentData['member_email'] ?? null,
            'member_name' => $paymentData['member_name'] ?? null,
            'gateway' => $paymentData['gateway'],
            'amount' => $paymentData['amount'],
            'status' => CommonConstant::STATUS_ATTEMPT,
            'comment' => $paymentData['comment'] ?? 'Payment initiated, awaiting webhook confirmation',
            'charge_id' => null,
            'payment_handle_comment' => 'Payment attempt',
        ]);

        // only decode the nonce if it is not empty and is a valid Base64 string and if the descriptor is 'COMMON.ACCEPT.INAPP.PAYMENT'
        if (!empty($paymentData['payment_method_id'])) {
            $decodedNonce = base64_decode($paymentData['payment_method_id'], true); // Strict mode for valid Base64
            if ($decodedNonce === false) {
                \Log::error('Failed to decode nonce', [
                    'encoded_nonce' => $paymentData['payment_method_id'],
                    'user_id' => $paymentData['user_id'],
                    'temp_order_number' => $paymentData['temp_order_number']
                ]);
                throw new \Exception('Invalid payment nonce format', Response::HTTP_BAD_REQUEST);
            }
            $paymentData['payment_method_id'] = $decodedNonce;
        }

        // Set payment method (nonce)
        $paymentOne = new PaymentType();

        if (!empty($paymentData['payment_method_id'])) {
            $opaqueData = new OpaqueDataType();
            $opaqueData->setDataDescriptor($paymentData['descriptor']);
            $opaqueData->setDataValue($paymentData['payment_method_id']);
            $paymentOne->setOpaqueData($opaqueData);
        }

        $transactionRequest = new TransactionRequestType();
        $transactionRequest->setTransactionType("authCaptureTransaction");
        $transactionRequest->setAmount($paymentData['amount']);

        // Set the customer's identifying information
        $customerData = new AnetAPI\CustomerDataType();
        $customerData->setType("individual");
        $customerData->setId($paymentData['user_id']);
        $transactionRequest->setCustomer($customerData);

        // Add metadata
        $invoiceNumber = "ATT-{$paymentData['user_id']}-{$paymentAttempt->id}-{$paymentData['temp_order_number']}-{$paymentData['store_id']}";

        $transactionRequest->setOrder((new OrderType())->setInvoiceNumber($invoiceNumber)->setDescription($paymentData['description'] ?? 'Payment for order'));
        $transactionRequest->setPayment($paymentOne);

        $transactionRequest->setBillTo((new CustomerAddressType())
            ->setFirstName($paymentData['member_name'])
            ->setAddress($paymentData['address'] ?? '')
            ->setCity($paymentData['city'] ?? '')
            ->setState($paymentData['state'] ?? '')
            ->setZip($paymentData['zip'] ?? '')
            ->setCountry($paymentData['country'] ?? 'USA'));

        $response = $this->executeTransaction($transactionRequest);
        // Update payment attempt with transaction response immediately
        $this->updatePaymentAttempt($paymentAttempt, $response );
        $paymentResponse = $this->processTransactionResponse($paymentAttempt, $response, $paymentData, CommonConstant::STATUS_PAID);
        // Handle successful transaction (empty response from processTransactionResponse)
        if (empty($paymentResponse)) {
            // Return minimal response for successful transaction
            $paymentResponse = [
                'success' => true,
                'transaction_id' => $response->getTransactionResponse()->getTransId(),
                'charge_id' => $response->getTransactionResponse()->getAuthCode(),
                'status' => CommonConstant::STATUS_ATTEMPT,
                'message' => 'Payment initiated, awaiting webhook confirmation',
                'card_last_4_digit' => $response->getTransactionResponse()->getAccountNumber() ?? 'N/A',
                'card_expire_date' => 'N/A',
            ];
        }
        // $paymentAttempt->update($this->buildPaymentAttemptUpdate($paymentResponse));
        return $paymentResponse;
    }

    // /**
    //  * Processes a refund for a previous payment transaction through Authorize.net.
    //  */
    // public function refundTransaction(array $paymentData): array
    // {
    //     $temp_order_number = rand(100, 10000);
    //     $originalPayment = $this->validateOriginalPayment($paymentData);

    //     // Use requested amount if provided, otherwise default to full original amount
    //     $refundAmount = isset($paymentData['amount']) ? abs((float)$paymentData['amount']) : abs((float)$originalPayment->amount);
    //     $refundAttempt = PaymentAttempt::create([
    //               'user_id' => $originalPayment->user_id,
    //               'store_id' => $originalPayment->store_id,
    //               'temp_order_number' => $temp_order_number,
    //               'member_email' => $originalPayment->member_email,
    //               'member_name' => $originalPayment->member_name,
    //               'gateway' => 'authorize.net',
    //               'amount' => -$refundAmount, // Negative to indicate refund
    //               'status' => CommonConstant::STATUS_ATTEMPT,
    //               'comment' => 'Refund transaction initiated, awaiting webhook confirmation',
    //               'transaction_id' => $paymentData['transaction_id'],
    //               'refund_void_transaction_id' => null, // New field for refund transId
    //               'charge_id' => $originalPayment->charge_id,
    //               'payment_handle_comment' => 'Refund attempt',
    //           ]);

    //     // Retrieve original transaction details
    //     $transactionDetail = $this->getAuthorizeNetTransactionDetail($paymentData['transaction_id']);
    //     $paymentInfo = $transactionDetail->getPayment();

    //     // Prepare payment method
    //     $paymentType = new PaymentType();

    //     // Handle different payment methods
    //     if ($paymentInfo->getCreditCard()) {
    //         // Credit card transactions
    //         $creditCard = $paymentInfo->getCreditCard();
    //         $cardNumber = $creditCard->getCardNumber(); // Returns masked number (e.g., XXXX1111)
    //         $expDate = $creditCard->getExpirationDate(); // Format: "MMYYYY"

    //         $paymentType->setCreditCard(
    //             (new CreditCardType())
    //                 ->setCardNumber($cardNumber)
    //                 ->setExpirationDate($expDate)
    //         );
    //     } elseif ($paymentInfo->getOpaqueData()) {
    //         // Digital wallet transactions (Google Pay/Apple Pay)
    //         $opaqueData = $paymentInfo->getOpaqueData();
    //         $paymentType->setOpaqueData(
    //             (new OpaqueDataType())
    //                 ->setDataDescriptor($opaqueData->getDataDescriptor())
    //                 ->setDataValue($opaqueData->getDataValue())
    //         );
    //     } else {
    //         throw new \Exception("Unsupported payment method for refund");
    //     }
    //     $transactionRequest = new TransactionRequestType();
    //     $transactionRequest->setTransactionType('refundTransaction');
    //     $transactionRequest->setAmount($refundAmount);

    //     $transactionRequest->setPayment($paymentType);
    //     $transactionRequest->setRefTransId($paymentData['transaction_id']);
    //     // Add metadata
    //     $invoiceNumber = "ATT-{$paymentData['user_id']}-{$refundAttempt->id}-{$temp_order_number}";
    //     $transactionRequest->setOrder((new OrderType())->setInvoiceNumber($invoiceNumber));

    //     $response = $this->executeTransaction($transactionRequest);
    //     // Update payment attempt with transaction response immediately
    //     $this->updatePaymentAttempt($refundAttempt, $response, true);
    //     $refundResponse = $this->processTransactionResponse($refundAttempt, $response, $paymentData, CommonConstant::STATUS_REFUND);

    //     if (empty($refundResponse)) {
    //         // Return minimal response for successful transaction
    //         $refundResponse = [
    //             'success' => true,
    //             'transaction_id' => $response->getTransactionResponse()->getTransId(),
    //             'charge_id' => $response->getTransactionResponse()->getAuthCode(),
    //             'status' => CommonConstant::STATUS_ATTEMPT,
    //             'message' => 'Refund initiated, awaiting webhook confirmation',
    //             'card_last_4_digit' => $response->getTransactionResponse()->getAccountNumber() ?? 'N/A',
    //             'card_expire_date' => 'N/A',
    //         ];
    //     }

    //     unset($refundResponse['charge_id'], $refundResponse['message'], $refundResponse['card_expire_date'], $refundResponse['card_last_4_digit']);
    //     return $refundResponse;
    // }

    // /**
    //  * Voids an unsettled payment transaction through Authorize.net.
    //  */
    // public function voidTransaction(array $paymentData): array
    // {
    //     $temp_order_number = rand(100, 10000);
    //     $originalPayment = $this->validateOriginalPayment($paymentData, true);

    //     $voidAttempt = PaymentAttempt::create([
    //        'user_id' => $originalPayment->user_id,
    //        'store_id' => $originalPayment->store_id,
    //        'temp_order_number' => $temp_order_number,
    //        'member_email' => $originalPayment->member_email,
    //        'member_name' => $originalPayment->member_name,
    //        'gateway' => 'authorize.net',
    //        'amount' => -abs($originalPayment->amount),
    //        'status' => CommonConstant::STATUS_ATTEMPT,
    //        'comment' => 'Void transaction initiated, awaiting webhook confirmation',
    //        'transaction_id' => $paymentData['transaction_id'],
    //        'refund_void_transaction_id' => null, // New field for void transId
    //        'charge_id' => $originalPayment->charge_id,
    //        'payment_handle_comment' => 'Void attempt',

    //     ]);

    //     // check if the transaction is already settled
    //     $transactionDetail = $this->getAuthorizeNetTransactionDetail($paymentData['transaction_id']);
            // if(!$transactionDetail){
            //     throw new \Exception("Transaction not found or invalid: {$paymentData['transaction_id']}, make sure transaction id belongs to this user", Response::HTTP_NOT_FOUND);
            // }
    //     $transactionStatus = $transactionDetail->getTransactionStatus();

    //     if (in_array($transactionStatus, ['settledSuccessfully', 'refundSettledSuccessfully'])) {
    //         // If the transaction is already settled, we cannot void it
    //         return $this->buildAndLogErrorResponse(
    //             "Transaction with ID {$paymentData['transaction_id']} is already settled. Cannot void, try refund instead.",
    //             $paymentData,
    //             $voidAttempt
    //         );
    //     }

    //     $transactionRequest = new TransactionRequestType();
    //     $transactionRequest->setTransactionType('voidTransaction');
    //     $transactionRequest->setRefTransId($paymentData['transaction_id']);
    //     // Add metadata
    //     $invoiceNumber = "ATT-{$paymentData['user_id']}-{$voidAttempt->id}-{$temp_order_number}";
    //     $transactionRequest->setOrder((new OrderType())->setInvoiceNumber($invoiceNumber));

    //     $response = $this->executeTransaction($transactionRequest);
    //     // Update payment attempt with transaction response immediately
    //     $this->updatePaymentAttempt($voidAttempt, $response, true);

    //     $voidResponse = $this->processTransactionResponse($voidAttempt, $response, $paymentData, CommonConstant::STATUS_VOID);

    //     if (empty($voidResponse)) {
    //         // Return minimal response for successful void transaction
    //         $voidResponse = [
    //             'success' => true,
    //             'transaction_id' => $response->getTransactionResponse()->getTransId(),
    //             'charge_id' => $response->getTransactionResponse()->getAuthCode(),
    //             'status' => CommonConstant::STATUS_ATTEMPT,
    //             'message' => 'Void initiated, awaiting webhook confirmation',
    //             'card_last_4_digit' => $response->getTransactionResponse()->getAccountNumber() ?? 'N/A',
    //             'card_expire_date' => 'N/A',
    //         ];
    //     }
    //     unset($voidResponse['charge_id'], $voidResponse['message'], $voidResponse['card_expire_date'], $voidResponse['card_last_4_digit'], $voidResponse['status']);
    //     return $voidResponse;
    // }

    /**
     * Executes a transaction request to Authorize.net.
     */
    private function executeTransaction(TransactionRequestType $transactionRequest): ?CreateTransactionResponse
    {
        try {
            $request = new CreateTransactionRequest();
            $request->setMerchantAuthentication($this->merchantAuthentication);
            $request->setRefId($this->authorizenet->getRef());
            $request->setTransactionRequest($transactionRequest);

            $controller = new CreateTransactionController($request);
            $response = $controller->executeWithApiResponse(
                $this->authorizenet->isLive() === '0' ? ANetEnvironment::SANDBOX : ANetEnvironment::PRODUCTION,
            );
            return $response;
        } catch (\Exception $e) {
            Log::error('Authorize.net transaction failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Processes the transaction response and returns a standardized response array.
     *
     * @param AnetAPI\CreateTransactionResponse|null $response
     * @param array $paymentData
     * @param string $successStatus
     * @return array
     */

    private function processTransactionResponse(PaymentAttempt $paymentAttempt, ?CreateTransactionResponse $response, array $paymentData, string $successStatus): array
    {
        // Step 1: Check if response is null or invalid
        if (!$response instanceof CreateTransactionResponse) {
            \Log::error('Null or invalid response from Authorize.net', [
                'user_id' => $paymentData['user_id'],
                'temp_order_number' => isset($paymentData['temp_order_number']) ? $paymentData['temp_order_number'] : null
            ]);
            return $this->buildErrorResponse(
                $paymentAttempt,
                'Failed to connect to Authorize.net. Please check your credentials or try again later.',
                $paymentData,
                'E00001',
                CommonConstant::STATUS_ERROR
            );
        }

        $messages = $response->getMessages();
        $tResponse = $response->getTransactionResponse();

        // Step 2: Check for successful response and return early
        if ($messages && $messages->getResultCode() === 'Ok' && $tResponse && $tResponse->getResponseCode() == '1') {
            \Log::info('Successful Authorize.net transaction, now awaiting webhook confirmation for status update', [
                'user_id' => $paymentData['user_id'],
                'temp_order_number' => isset($paymentData['temp_order_number']) ? $paymentData['temp_order_number'] : null,
                'trans_id' => $tResponse->getTransId()
            ]);
            return []; // Return empty array to skip further processing
        }

        // Step 3: Handle API-level errors from Messages
        if ($messages && $tResponse && $tResponse->getResponseCode() != '1') {
            $messageArray = $messages->getMessage();
            $resultCode = $messages->getResultCode();

            if (!empty($messageArray)) {
                $message = $messageArray[0];
                $errorCode = $message->getCode();
                $errorText = $message->getText();

                // Customize error messages for specific API-level error codes
                switch ($errorCode) {
                    case 'E00007':
                        $errorText = 'Invalid Authorize.net credentials provided.';
                        break;
                    default:
                        break;
                }

                \Log::error('Authorize.net API error', [
                    'error_code' => $errorCode,
                    'error_text' => $errorText,
                    'user_id' => $paymentData['user_id'],
                    'temp_order_number' => isset($paymentData['temp_order_number']) ? $paymentData['temp_order_number'] : null

                ]);
                // return $this->buildErrorResponse(
                //     $errorText,
                //     $paymentData,
                //     $errorCode,
                //     CommonConstant::STATUS_ERROR
                // );
                return $this->handleErrorResponse($paymentAttempt, $tResponse, $paymentData);
            }
        }

        // Step 4: Handle transaction-level errors
        if ($tResponse) {
            return $this->handleErrorResponse($paymentAttempt, $tResponse, $paymentData);
        }

        // Step 5: Rare case: No messages and no TransactionResponse
        \Log::error('Unexpected response state from Authorize.net', [
            'user_id' => $paymentData['user_id'],
            'temp_order_number' => isset($paymentData['temp_order_number']) ? $paymentData['temp_order_number'] : null

        ]);
        return $this->buildErrorResponse(
            $paymentAttempt,
            'Unexpected response from Authorize.net.',
            $paymentData,
            'E00001',
            CommonConstant::STATUS_ERROR
        );
    }

    /**
     * Handles an error transaction response.
     */
    private function handleErrorResponse(PaymentAttempt $paymentAttempt, TransactionResponseType $tresponse, array $paymentData): array
    {
        $errors = $tresponse->getErrors();
        if (!$errors || count($errors) === 0) {
            return $this->buildErrorResponse($paymentAttempt, 'Unknown error in transaction response', $paymentData);
        }

        $error = $errors[0];
        $errorCode = $error->getErrorCode();
        $errorMessage = $error->getErrorText();
        $status = CommonConstant::STATUS_ERROR;

        // Special handling for specific error codes
        if ($errorCode === '54') { // Refund-specific: Transaction not settled
            $errorMessage .= ' Transaction may not be settled yet. Consider voiding instead.';
            $status = CommonConstant::STATUS_ERROR;
        } elseif ($errorCode === '16') { // void-specific: Transaction already settled.
            $errorMessage = ' Unable to void. Transaction has already been settled.';
            $status = CommonConstant::STATUS_ERROR;
        }

        Log::error("Transaction failed", [
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'transaction_id' => $paymentData['transaction_id'] ?? null,
        ]);

        return $this->buildErrorResponse($paymentAttempt, $errorMessage, $paymentData, $errorCode, $status);
    }

    /**
     * Builds a generic error response.
     */

    private function buildErrorResponse(?PaymentAttempt $paymentAttempt, string $message, ?array $paymentData, ?string $errorCode = null, ?int $status = commonConstant::STATUS_ERROR): array
    {
        if ($paymentAttempt) {
            $paymentAttempt->update([
                'status' => $status,
                'comment' => $message,
                'payment_handle_comment' => 'Payment processing failed'
            ]);
        }
        return [
            'success' => false,
            'transaction_id' => $paymentData['transaction_id'] ?? null,
            'status' => $status,
            'error_message' => $message ?? null,
            'error_code' => $errorCode ?? null,
        ];
    }

    // /**
    //  * Validates the original payment and checks for void/refund status.
    //  *
    //  * @param array<string, mixed> $paymentData Payment data including transaction_id and user_id
    //  * @param bool $isVoid Whether this is a void transaction validation (default: false)
    //  * @return PaymentAttempt The validated original payment
    //  * @throws \Exception If validation fails
    //  */
    // private function validateOriginalPayment(array $paymentData, bool $isVoid = false): PaymentAttempt
    // {
    //     // Fetch original payment
    //     $originalPayment = PaymentAttempt::where([
    //         ['transaction_id', $paymentData['transaction_id']],
    //         ['user_id', $paymentData['user_id']],
    //     ])->first();

    //     if (!$originalPayment) {
    //         throw new \Exception("Store payment not found for transaction ID: {$paymentData['transaction_id']}", Response::HTTP_NOT_FOUND);
    //     }

    //     // Check for existing void attempts
    //     $voidExists = PaymentAttempt::where([
    //         ['transaction_id', $paymentData['transaction_id']],
    //         ['status', CommonConstant::STATUS_VOID],
    //         ['gateway', 'authorize.net'],
    //     ])->exists();

    //     if ($voidExists) {
    //         $message = $isVoid
    //             ? "This transaction has already been voided."
    //             : "Unable to process refund since the payment with transaction ID {$originalPayment->transaction_id} has already been voided.";
    //         throw new \Exception($message, Response::HTTP_BAD_REQUEST);
    //     }

    //     // Additional refund-specific check
    //     if (!$isVoid && $originalPayment->status === CommonConstant::STATUS_REFUND) {
    //         throw new \Exception("Payment with transaction ID {$originalPayment->transaction_id} has already been refunded.", Response::HTTP_BAD_REQUEST);
    //     }

    //     return $originalPayment;
    // }

    /**
     * Retrieves transaction details for a given transaction ID from Authorize.net.
     *
     * This method fetches the payment attempt record from the database and queries
     * Authorize.net for transaction details using the provided transaction ID. If the
     * transaction is not found in the database, an exception is thrown. If the API call
     * fails or returns an error, an array with error details is returned.
     *
     * @param array<string, mixed> $paymentData An associative array containing payment data.
     *                                          Must include 'transaction_id' (string) and 'user_id' (int|string).
     * @return array<string, mixed> An array containing transaction details on success or error details on failure.
     *                              - On success: ['success' => true, 'transaction_details' => TransactionType]
     *                              - On failure: ['success' => false, 'error_message' => string, 'error_code' => ?string]
     * @throws \Exception If no payment attempt is found for the given transaction ID and user ID in the database.
     */
    public function getTransactionDetails(array $paymentData): ?array
    {
        $isLive = filter_var($paymentData['isLive'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        // Fetch original payment
        $originalPayment = PaymentAttempt::where(
            'user_id',
            $paymentData['user_id']
        )->where(function ($query) use ($paymentData) {
            $query->where('transaction_id', $paymentData['transaction_id'])
                ->orWhere('refund_void_transaction_id', $paymentData['transaction_id']);
        })->select(['store_id','temp_order_number','member_email','member_name','gateway','amount','card_last_4_digit','card_expire_date','transaction_id','charge_id','status','comment','payment_handle_comment'])->first();

        if (!$originalPayment) {
            throw new \Exception("Store payment not found for transaction ID: {$paymentData['transaction_id']}", Response::HTTP_NOT_FOUND);
        }

        if (!$isLive) {
            return [
                'success' => true,
                'transaction_details' => $originalPayment
            ];
        }

        // Validate payment data
        $request = new \net\authorize\api\contract\v1\GetTransactionDetailsRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setTransId($paymentData['transaction_id']);

        $controller = new \net\authorize\api\controller\GetTransactionDetailsController($request);
        $response = $controller->executeWithApiResponse(
            $this->authorizenet->isLive() === '0' ? ANetEnvironment::SANDBOX : ANetEnvironment::PRODUCTION
        );

        if ($response === null) {
            return $this->buildErrorResponse(
                null,
                'Failed to connect to Authorize.net. Please check your credentials or try again later.',
                $paymentData,
                'E00003',
                CommonConstant::STATUS_ERROR
            );
        }

        if ($response->getMessages()->getResultCode() === 'Ok') {
            $transactionDetails = array_merge($originalPayment->toArray(), [
              'live_details' => $response->getTransaction()
            ]);
            return [
                'success' => true,
                'transaction_details' => $transactionDetails,
            ];
        } else {
            $messages = $response->getMessages();
            if ($messages && $messages->getMessage() && count($messages->getMessage()) > 0) {
                return $this->buildErrorResponse(
                    null,
                    $messages->getMessage()[0]->getText(),
                    $paymentData,
                    $messages->getMessage()[0]->getCode(),
                    CommonConstant::STATUS_ERROR
                );
            }
            return $this->buildErrorResponse(
                null,
                'Unknown error retrieving transaction details.',
                $paymentData,
                null,
                CommonConstant::STATUS_ERROR
            );
        }
    }

    // /**
    //  * Builds an error response array and updates the PaymentAttempt record in the database.
    //  *
    //  * @param string $message Error message to return.
    //  * @param array|null $paymentData Payment data array.
    //  * @param string|null $errorCode Optional error code.
    //  * @param int|null $status Optional status code.
    //  * @return array The error response array.
    //  */
    // private function buildAndLogErrorResponse(string $message, ?array $paymentData, PaymentAttempt $voidRefundAttempt): array
    // {
    //     // Update PaymentAttempt in DB if transaction_id and user_id are present
    //     if ($paymentData && !empty($paymentData['transaction_id']) && !empty($paymentData['user_id'])) {
    //         $voidRefundAttempt->update([
    //             'status' => CommonConstant::STATUS_ERROR,
    //             'comment' => $message,
    //             'refund_void_transaction_id' => null,
    //             'charge_id' => null,
    //             'payment_handle_comment' => 'Payment processing failed'
    //         ]);
    //     }

    //     return [
    //         'success' => false,
    //         'transaction_id' => $paymentData['transaction_id'] ?? null,
    //         'charge_id' => $paymentData['charge_id'] ?? null,
    //         'error_message' => $message,
    //         'status' => CommonConstant::STATUS_ERROR, // CommonConstant::STATUS_ERROR
    //     ];
    // }

    protected function getAuthorizeNetTransactionDetail(string $transactionId)
    {
        $request = new \net\authorize\api\contract\v1\GetTransactionDetailsRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setTransId($transactionId);

        $controller = new \net\authorize\api\controller\GetTransactionDetailsController($request);
        return $controller->executeWithApiResponse(
            $this->authorizenet->isLive() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        )->getTransaction();
    }

    protected function updatePaymentAttempt(PaymentAttempt $paymentAttempt, $response, $isRefundOrVoid = false){
        $transactionIdField = $isRefundOrVoid ? 'refund_void_transaction_id' : 'transaction_id';
    
        $paymentAttempt->update([
            $transactionIdField => $response->getTransactionResponse()->getTransId() ?? null,
            'charge_id' => $response->getTransactionResponse()->getAuthCode() ?? null,
        ]);
    }


    /**
     * Processes a refund for a previous payment transaction through Authorize.net.
     */
    public function refundTransactionV2(array $paymentData): array
    {
        $temp_order_number = rand(100, 10000);
        $originalPayment = $this->validateOriginalPaymentV2($paymentData);
        \Log::info('Original payment data', [
            'charge_id' => $originalPayment->charge_id,
            'transaction_id' => $originalPayment->transaction_id,
            'is_external' => isset($originalPayment->is_external) ? $originalPayment->is_external : false,
        ]);

        // Use requested amount if provided, otherwise default to full original amount
        $refundAmount = isset($paymentData['amount']) ? abs((float)$paymentData['amount']) : abs((float)$originalPayment->amount);
        $refundAttempt = PaymentAttempt::create([
              'user_id' => $originalPayment->user_id,
              'store_id' => $originalPayment->store_id ?? $paymentData['store_id'],
              'temp_order_number' => $temp_order_number,
              'member_email' => $originalPayment->member_email ?? $paymentData['member_email'],
              'member_name' => $originalPayment->member_name ?? $paymentData['member_name'],
              'gateway' => 'Authorize.net',
              'amount' => -$refundAmount, // Negative to indicate refund
              'status' => CommonConstant::STATUS_ATTEMPT,
              'comment' => isset($originalPayment->is_external) ? 'External refund transaction initiated, awaiting webhook confirmation' : 'Refund transaction initiated, awaiting webhook confirmation',
              'transaction_id' => $paymentData['transaction_id'],
              'refund_void_transaction_id' => null, // New field for refund transId
              'charge_id' => $originalPayment->charge_id,
              'payment_handle_comment' => 'Refund attempt',
          ]);

        // Retrieve original transaction details
        $transactionDetail = $this->getAuthorizeNetTransactionDetail($originalPayment->transaction_id);
        $paymentInfo = $transactionDetail->getPayment();

        // Prepare payment method
        $paymentType = new PaymentType();

        // Handle different payment methods
        if ($paymentInfo->getCreditCard()) {
            // Credit card transactions
            $creditCard = $paymentInfo->getCreditCard();
            $cardNumber = $creditCard->getCardNumber(); // Returns masked number (e.g., XXXX1111)
            $expDate = $creditCard->getExpirationDate(); // Format: "MMYYYY"

            $paymentType->setCreditCard(
                (new CreditCardType())
                    ->setCardNumber($cardNumber)
                    ->setExpirationDate($expDate)
            );
        } elseif ($paymentInfo->getOpaqueData()) {
            // Digital wallet transactions (Google Pay/Apple Pay)
            $opaqueData = $paymentInfo->getOpaqueData();
            $paymentType->setOpaqueData(
                (new OpaqueDataType())
                    ->setDataDescriptor($opaqueData->getDataDescriptor())
                    ->setDataValue($opaqueData->getDataValue())
            );
        } else {
            throw new \Exception("Unsupported payment method for refund");
        }
        $transactionRequest = new TransactionRequestType();
        $transactionRequest->setTransactionType('refundTransaction');
        $transactionRequest->setAmount($refundAmount);

        $transactionRequest->setPayment($paymentType);
        $transactionRequest->setRefTransId($paymentData['transaction_id']);
        // Add metadata
        $invoiceNumber = "ATT-{$paymentData['user_id']}-{$refundAttempt->id}-{$temp_order_number}";
        $transactionRequest->setOrder((new OrderType())->setInvoiceNumber($invoiceNumber));

        $response = $this->executeTransaction($transactionRequest);

        // Update payment attempt with transaction response immediately
        $this->updatePaymentAttempt($refundAttempt, $response, true);
        $refundResponse = $this->processTransactionResponse($refundAttempt, $response, $paymentData, CommonConstant::STATUS_REFUND);

        if (empty($refundResponse)) {
            // Calculate remaining refundable amount
            $totalRefunded = PaymentAttempt::where('transaction_id', $paymentData['transaction_id'])
                ->where('status', CommonConstant::STATUS_REFUND)
                ->sum('amount');
            $remainingRefundable = abs($originalPayment->amount) + $totalRefunded; // totalRefunded is negative
            
            // Return consistent response structure matching Stripe format
            $refundResponse = [
                'success' => true,
                'refund_id' => $response->getTransactionResponse()->getTransId(),
                'charge_id' => $originalPayment->charge_id,
                'transaction_status' => '2', // Refund status
                'note' => $response->getTransactionResponse()->getAuthCode(),
                'gateway' => "AUTHORIZE.NET",
                'status' => 'succeeded',
                'refunded_amount' => $refundAmount,
                'remaining_refundable' => round($remainingRefundable - $refundAmount, 2),
            ];
        }

        return $refundResponse;
    }

    /**
     * Validates the original payment and checks for void/refund status.
     *
     * @param array<string, mixed> $paymentData Payment data including transaction_id and user_id
     * @param bool $isVoid Whether this is a void transaction validation (default: false)
     * @return PaymentAttempt|null The validated original payment or null for external transactions
     * @throws \Exception If validation fails
     */
    private function validateOriginalPaymentV2(array $paymentData, bool $isVoid = false)
    {
        // Validate required parameters
        if (empty($paymentData['transaction_id']) || empty($paymentData['user_id'])) {
            throw new \Exception('Transaction ID and User ID are required for payment validation.', Response::HTTP_BAD_REQUEST);
        }

        // First, try to fetch from our local DB (new transactions)
        $originalPayment = PaymentAttempt::where([
            ['transaction_id', $paymentData['transaction_id']],
            ['user_id', $paymentData['user_id']],
            ['status', CommonConstant::STATUS_PAID],
        ])->first();

        if ($originalPayment) {
            // Found in our DB - perform status checks
            $this->validatePaymentStatusV2($originalPayment, $isVoid);
            return $originalPayment;
        }

        // Not in our DB - handle as external transaction
        \Log::info('Transaction not found in local DB, checking as external transaction', [
            'transaction_id' => $paymentData['transaction_id'],
            'user_id' => $paymentData['user_id'],
            'operation' => $isVoid ? 'void' : 'refund'
        ]);

        return $this->handleExternalTransaction($paymentData, $isVoid);
    }

    /**
     * Handles external transactions that don't exist in our local database.
     * Creates a temporary PaymentAttempt-like object from Authorize.Net transaction details.
     *
     * @param array $paymentData Payment data
     * @param bool $isVoid Whether this is a void operation
     * @return object External transaction object with PaymentAttempt-like properties
     * @throws \Exception If transaction is invalid or cannot be processed
     */
    private function handleExternalTransaction(array $paymentData, bool $isVoid = false)
    {
        try {
            // Query Authorize.Net for transaction details
            $transactionDetail = $this->getAuthorizeNetTransactionDetail($paymentData['transaction_id']);
            if(!$transactionDetail){
                throw new \Exception("Transaction not found or invalid: {$paymentData['transaction_id']}, make sure transaction id belongs to this user", Response::HTTP_NOT_FOUND);
            }
            $transactionStatus = $transactionDetail->getTransactionStatus();
            
            // Define valid statuses for each operation
            $validStatusesForVoid = ['authorizedPendingCapture', 'capturedPendingSettlement'];
            $validStatusesForRefund = ['settledSuccessfully', 'capturedPendingSettlement'];
            
            $validStatuses = $isVoid ? $validStatusesForVoid : $validStatusesForRefund;
            $operation = $isVoid ? 'void' : 'refund';
            
            if (!in_array($transactionStatus, $validStatuses)) {
                throw new \Exception("Transaction cannot be {$operation}ed. Transaction status is already {$transactionStatus}", Response::HTTP_BAD_REQUEST);
            }

            // Create a PaymentAttempt-like object for external transaction
            $externalPayment = (object) [
                'user_id' => $paymentData['user_id'],
                'store_id' => $paymentData['store_id'] ?? null,
                'transaction_id' => $paymentData['transaction_id'],
                'charge_id' => $transactionDetail->getAuthCode(),
                'amount' => $transactionDetail->getSettleAmount(),
                'member_email' => $paymentData['member_email'] ?? null,
                'member_name' => $paymentData['member_name'] ?? null,
                'gateway' => 'Authorize.net',
                'status' => CommonConstant::STATUS_PAID,
                'is_external' => true // Flag to identify external transactions
            ];
            
            \Log::info("Processing {$operation} for external transaction", [
                'transaction_id' => $paymentData['transaction_id'],
                'amount' => $externalPayment->amount,
                'status' => $transactionStatus,
                'operation' => $operation,
                'charge_id' => $externalPayment->charge_id,
            ]);
            
            return $externalPayment;
            
        } catch (\Exception $e) {
            \Log::error('Failed to process external transaction', [
                'transaction_id' => $paymentData['transaction_id'],
                'error' => $e->getMessage()
            ]);
            throw new \Exception("External transaction not found or invalid: {$paymentData['transaction_id']}. Error: {$e->getMessage()}", Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Validate the status of an existing payment attempt (optimized version)
     */
    private function validatePaymentStatusV2(PaymentAttempt $originalPayment, bool $isVoid = false): void
    {
        // Single query to check for existing void/refund attempts
        $existingStatuses = PaymentAttempt::where([
            ['transaction_id', $originalPayment->transaction_id],
            ['gateway', 'Authorize.net'],
        ])->whereIn('status', [CommonConstant::STATUS_VOID, CommonConstant::STATUS_REFUND])
        ->pluck('status')->unique()->toArray();

        // Check for void attempts (applies to both void and refund operations)
        if (in_array(CommonConstant::STATUS_VOID, $existingStatuses)) {
            $message = $isVoid
                ? "This transaction has already been voided."
                : "Unable to process refund since the payment with transaction ID {$originalPayment->transaction_id} has already been voided.";
            throw new \Exception($message, Response::HTTP_BAD_REQUEST);
        }

        if ($isVoid) {
            // Void-specific validations: Can only void PAID payments
            if ($originalPayment->status !== CommonConstant::STATUS_PAID) {
                throw new \Exception("Transaction cannot be voided. Only paid transactions can be voided. Current status: {$originalPayment->status}");
            }
        } else {
            // Refund-specific validations: Check if fully refunded
            $totalRefunded = PaymentAttempt::where([
                ['transaction_id', $originalPayment->transaction_id],
                ['gateway', 'Authorize.net'],
                ['status', CommonConstant::STATUS_REFUND]
            ])->sum('amount');

            $totalRefundedAbs = abs($totalRefunded);
            
            if ($totalRefundedAbs >= $originalPayment->amount) {
                throw new \Exception("Payment with transaction ID {$originalPayment->transaction_id} has already been fully refunded.", Response::HTTP_BAD_REQUEST);
            }

            // Additional status validation for refunds - only block if original payment status is refund
            if ($originalPayment->status === CommonConstant::STATUS_REFUND) {
                throw new \Exception("Payment with transaction ID {$originalPayment->transaction_id} has already been refunded.", Response::HTTP_BAD_REQUEST);
            }
        }
    }

    /**
     * Processes a void for a previous payment transaction through Authorize.net.
     */
    public function voidTransactionV2(array $paymentData): array
    {
        $temp_order_number = rand(100, 10000);
        $originalPayment = $this->validateOriginalPaymentV2($paymentData, true);
        \Log::info('Original payment data for void', [
            'charge_id' => $originalPayment->charge_id,
            'transaction_id' => $originalPayment->transaction_id,
            'is_external' => isset($originalPayment->is_external) ? $originalPayment->is_external : false,
        ]);

        // Use requested amount if provided, otherwise default to full original amount
        $voidAmount = isset($paymentData['amount']) ? abs((float)$paymentData['amount']) : abs((float)$originalPayment->amount);
        $voidAttempt = PaymentAttempt::create([
              'user_id' => $originalPayment->user_id,
              'store_id' => $originalPayment->store_id ?? $paymentData['store_id'],
              'temp_order_number' => $temp_order_number,
              'member_email' => $originalPayment->member_email ?? $paymentData['member_email'],
              'member_name' => $originalPayment->member_name ?? $paymentData['member_name'],
              'gateway' => 'Authorize.net',
              'amount' => -$voidAmount, // Negative to indicate void
              'status' => CommonConstant::STATUS_ATTEMPT,
              'comment' => isset($originalPayment->is_external) ? 'Legacy void transaction initiated, awaiting webhook confirmation' : 'Void transaction initiated, awaiting webhook confirmation',
              'transaction_id' => $paymentData['transaction_id'],
              'refund_void_transaction_id' => null, // New field for void transId
              'charge_id' => $originalPayment->charge_id,
              'payment_handle_comment' => 'Void attempt',
          ]);

        // Check if the transaction is already settled
        $transactionDetail = $this->getAuthorizeNetTransactionDetail($originalPayment->transaction_id);
        if(!$transactionDetail){
            throw new \Exception("Transaction not found or invalid: {$originalPayment->transaction_id}, make sure transaction id belongs to this user", Response::HTTP_NOT_FOUND);
        }
        $transactionStatus = $transactionDetail->getTransactionStatus();

        if (in_array($transactionStatus, ['settledSuccessfully', 'refundSettledSuccessfully'])) {
            // If the transaction is already settled, we cannot void it
            return $this->buildErrorResponse(
                $voidAttempt,
                "Transaction with ID {$paymentData['transaction_id']} is already settled. Cannot void, try refund instead.",
                $paymentData,
                'E00027',
                CommonConstant::STATUS_ERROR
            );
        }

        $transactionRequest = new TransactionRequestType();
        $transactionRequest->setTransactionType('voidTransaction');
        $transactionRequest->setRefTransId($paymentData['transaction_id']);
        
        // Add metadata
        $invoiceNumber = "ATT-{$paymentData['user_id']}-{$voidAttempt->id}-{$temp_order_number}";
        $transactionRequest->setOrder((new OrderType())->setInvoiceNumber($invoiceNumber));

        $response = $this->executeTransaction($transactionRequest);
        
        // Update payment attempt with transaction response immediately
        $this->updatePaymentAttempt($voidAttempt, $response, true);
        $voidResponse = $this->processTransactionResponse($voidAttempt, $response, $paymentData, CommonConstant::STATUS_VOID);

        if (empty($voidResponse)) {
            // Return consistent response structure matching Stripe format
            $voidResponse = [
                'success' => true,
                'refund_id' => $response->getTransactionResponse()->getTransId(),
                'charge_id' => $originalPayment->charge_id,
                'transaction_status' => '1', // Void status
                'note' => $response->getTransactionResponse()->getAuthCode(),
                'gateway' => "AUTHORIZE.NET",
                'status' => 'succeeded',
                'refunded_amount' => abs($originalPayment->amount),
            ];
        }

        return $voidResponse;
    }
}
