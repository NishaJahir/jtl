<?php 
/**
 * Novalnet payment plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Novalnet End User License Agreement
 *
 * DISCLAIMER
 *
 * If you wish to customize Novalnet payment extension for your needs,
 * please contact technic@novalnet.de for more information.
 *
 * @author      Novalnet AG
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 *
 * Script: NovalnetPaymentGateway.php
 *
*/

namespace Plugin\jtl_novalnet\paymentmethod;

use JTL\Shop;
use Plugin\jtl_novalnet\src\NovalnetPaymentHelper;
use JTL\Session\Frontend;
use JTL\Cart\CartHelper;
use JTL\Plugin\Payment\Method;
use JTL\Checkout\Bestellung;
use JTL\Checkout\Lieferadresse;
use JTL\Catalog\Product\Preise;
use JTL\Checkout\Rechnungsadresse;
use JTL\Customer\Customer;
use JTL\Plugin\Helper;
use JTL\Helpers\Request;
use JTL\Catalog\Currency;
use JTL\Helpers\Text;
use JTL\Alert\Alert;
use JTLShop\SemVer\Version;
use stdClass;

/**
 * Class NovalnetPaymentGateway
 * @package Plugin\jtl_novalnet\paymentmethod
 */
class NovalnetPaymentGateway
{
    /**
     * @var NovalnetPaymentHelper
     */
    public $novalnetPaymentHelper;
    
    /**
     * NovalnetPaymentGateway constructor.
     */
    public function __construct()
    {
       $this->novalnetPaymentHelper = new NovalnetPaymentHelper();        
    }
    
    /**
     * Checks the required payment activation configurations
     *
     * return bool
     */
    public function canPaymentMethodProcessed(): bool
    {
        return ($this->novalnetPaymentHelper->getConfigurationValues('novalnet_enable_payment_method') && ($this->novalnetPaymentHelper->getConfigurationValues('novalnet_public_key') != '' && $this->novalnetPaymentHelper->getConfigurationValues('novalnet_private_key') != '' && $this->novalnetPaymentHelper->getConfigurationValues('novalnet_tariffid') != ''));
    }
    
    /**
     * Build payment parameters to server
     *
     * @param  object|null $order
     * @return array
     */
    public function generatePaymentParams(?object $order = null): array
    {
        // Selected theme in the shop
        $themeName = ucfirst(Shop::getSettings([CONF_TEMPLATE])['template']['theme']['theme_default']);
        
        $paymentRequestData = [];
        
        // Extracting the customer 
        $customerDetails = Frontend::getCustomer();
        
        // Building the merchant Data
        $paymentRequestData['merchant'] = [
                                            'signature'    => $this->novalnetPaymentHelper->getConfigurationValues('novalnet_public_key'),
                                            'tariff'       => $this->novalnetPaymentHelper->getConfigurationValues('novalnet_tariffid'),
                                          ];
        
        // Building the customer Data
        $paymentRequestData['customer'] = [
                                            'first_name'   => !empty($customerDetails->cVorname) ? $customerDetails->cVorname : $customerDetails->cNachname,
                                            'last_name'    => !empty($customerDetails->cNachname) ? $customerDetails->cNachname : $customerDetails->cVorname,
                                            'gender'       => !empty($customerDetails->cAnrede) ? $customerDetails->cAnrede : 'u',
                                            'email'        => $customerDetails->cMail,
                                            'customer_no'  => !empty($customerDetails->kKunde) ? $customerDetails->kKunde : 'guest',
                                            'customer_ip'  => $this->novalnetPaymentHelper->getNnIpAddress('REMOTE_ADDR')
                                          ];
        
        if (!empty($customerDetails->cTel)) { // Check if telephone field is given
            $paymentRequestData['customer']['tel'] = $customerDetails->cTel;
        }
        
        if (!empty($customerDetails->cMobil)) { // Check if mobile field is given
            $paymentRequestData['customer']['mobile'] = $customerDetails->cMobil;
        }
        
        // Extracting the required billing and shipping details from the customer session object        
        $billingShippingDetails = $this->novalnetPaymentHelper->getRequiredBillingShippingDetails($customerDetails);
        
        $paymentRequestData['customer'] = array_merge($paymentRequestData['customer'], $billingShippingDetails);
        
        // If the billing and shipping are equal, we notify it too 
        if ($paymentRequestData['customer']['billing'] == $paymentRequestData['customer']['shipping']) {
            $paymentRequestData['customer']['shipping']['same_as_billing'] = '1';
        }
        
        if (!empty($customerDetails->cFirma)) { // Check if company field is given in the billing address
            $paymentRequestData['customer']['billing']['company'] = $customerDetails->cFirma;
        }
        
        if (!empty($_SESSION['Lieferadresse']->cFirma)) { // Check if company field is given in the shipping address
            $paymentRequestData['customer']['shipping']['company'] = $_SESSION['Lieferadresse']->cFirma;
        }
        
        if (!empty($customerDetails->cBundesland)) { // Check if state field is given in the billing address
            $paymentRequestData['customer']['billing']['state'] = $customerDetails->cBundesland;
        }
        
        if (!empty($_SESSION['Lieferadresse']->cBundesland)) { // Check if state field is given in the shipping address
            $paymentRequestData['customer']['shipping']['state'] = $_SESSION['Lieferadresse']->cBundesland;
        }
        
        // Building the transaction Data
        $paymentRequestData['transaction'] = [
                                               'amount'    => $this->novalnetPaymentHelper->getOrderAmount(),
                                               'currency'  => Frontend::getCurrency()->getCode(),
                                               'system_name'   => 'jtlshop',
                                               'system_version' => Version::parse(APPLICATION_VERSION)->getOriginalVersion() . '-NN13.0.0-NNTjtlshop_'.$themeName,
                                               'system_url' => Shop::getURL(),
                                               'system_ip'  => $this->novalnetPaymentHelper->getNnIpAddress('SERVER_ADDR')
                                             ];
        
        // If the order generation is done before the payment completion, we get the order number in the initial call itself
        if (isset($_SESSION['Zahlungsart']->nWaehrendBestellung) && $_SESSION['Zahlungsart']->nWaehrendBestellung == 0) {
            $paymentRequestData['transaction']['order_no'] = $order->cBestellNr;
        } 
        // Send the order language
        $paymentRequestData['custom']['lang'] = (!empty($_SESSION['cISOSprache']) && $_SESSION['cISOSprache'] == 'ger') ? 'DE' : 'EN';  
        // Unset the billing and shipping house number if it is empty
        if (empty($paymentRequestData['customer']['billing']['house_no'])) {
            unset($paymentRequestData['customer']['billing']['house_no']);
        }
        if (empty($paymentRequestData['customer']['shipping']['house_no'])) {
            unset($paymentRequestData['customer']['shipping']['house_no']);
        }
        // Unset the shipping address if the billing and shipping address are equal
        if (!empty($paymentRequestData['customer']['shipping']['same_as_billing'])) {
            unset($paymentRequestData['customer']['shipping']);
            $paymentRequestData['customer']['shipping']['same_as_billing'] = 1;
        }         
        
        return $paymentRequestData;
    }
    
    /**
     * Returns with error message on failure cases 
     *
     * @param  object  $order
     * @param  array   $paymentResponse
     * @param  string  $paymentName 
     * @param  string  $explicitErrorMessage 
     * @return none
     */
    public function redirectOnError(object $order, array $paymentResponse, string $paymentName, string $explicitErrorMessage = ''): void
    {
        // Set the error message from the payment response
        $errorMsg = (!empty($paymentResponse['result']['status_text']) ? $paymentResponse['result']['status_text'] : $paymentResponse['status_text']);
        
        // If the order has been created already and if the order has to be closed 
        if ($_SESSION['Zahlungsart']->nWaehrendBestellung == 0) {
            
            // Building the transaction comments for the failure case 
            $transactionComments = $this->getTransactionInformation($order, $paymentResponse, $paymentName);
            
            // Setting up the cancellation status in the database for the order 
            $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung', $order->kBestellung, ['cStatus' => \BESTELLUNG_STATUS_STORNO, 'cAbgeholt' => 'Y', 'cKommentar' => $transactionComments . \PHP_EOL . $errorMsg]);
            
            $jtlPaymentmethod = Method::create($order->Zahlungsart->cModulId);
            
            // Triggers cancellation mail template
            $jtlPaymentmethod->sendMail($order->kBestellung, \MAILTEMPLATE_BESTELLUNG_STORNO);
            
            // logs the details into novalnet db for failure
            $this->insertOrderDetailsIntoNnDb($order, $paymentResponse, $paymentName);
            
            // Clear the shop and novalnet session
            Frontend::getInstance()->cleanUp(); // unset the shop session
            unset($_SESSION['novalnet']); // unset novalnet session
            
            // Redirecting to the order page in the account section 
            \header('Location:' . $order->BestellstatusURL);
            exit;
        }
        
        // If the order has to be continued, we display the error in the payment page and the payment process is continued 
        $errorMessageToDisplay = !empty($explicitErrorMessage) ? $explicitErrorMessage : $errorMsg;
        
        // Setting up the error message in the shop variable 
        $alertHelper = Shop::Container()->getAlertService();        
        $alertHelper->addAlert(Alert::TYPE_ERROR, $errorMessageToDisplay, 'display error on payment page', ['saveInSession' => true]);        
        
        // Redirecting to the checkout page 
        \header('Location:' . Shop::getURL() . '/Bestellvorgang?editVersandart=1');
        exit;
    }
    
    /**
     * Make CURL payment request to Novalnet server
     *
     * @param  array $paymentRequestData
     * @param  string $paymentUrl
     * @param  string $paymentAccessKey
     * @return array
     */
    public function performServerCall(array $paymentRequestData, string $paymentUrl, string $paymentAccessKey = ''): array
    {
        // Based on the request type, retrieving the payment request URL to make the API call
        $paymentUrl = $this->getApiRequestURL($paymentUrl);
        
        // Payment Access Key that can be found in the backend is an imporant information that needs to be sent in header for merchant validation 
        $paymentAccessKey = !empty($paymentAccessKey) ? $paymentAccessKey : $this->novalnetPaymentHelper->getConfigurationValues('novalnet_private_key');

        // Setting up the important information in the headers 
        $headers = [
                     'Content-Type:application/json',
                     'charset:utf-8',
                     'X-NN-Access-Key:'. base64_encode($paymentAccessKey),
                   ];
        
        // Initialization of the cURL 
        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_URL, $paymentUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($paymentRequestData));
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        // Execute cURL
        $paymentResponse = curl_exec($curl);

        // Handle cURL error
        if (curl_errno($curl)) {
           $logger   = Shop::Container()->getLogService();
           $logger->error('Request Error:' . curl_error($curl));
        }
        
        // Close cURL
        curl_close($curl);
        
        // Decoding the JSON string to array for further processing 
        return json_decode($paymentResponse, true);
    }
    
    /**
     * Get payment request URL's based on the request type 
     *
     * @param  string $apiType
     * @return string
     */
    public function getApiRequestURL(string $apiType): string
    { 
        // Novalnet's v2 interface base URL 
        $baseUrl = 'https://payport.novalnet.de/v2/';
        
        // Adding up the suffix based on the request type 
        $suffixUrl = strpos($apiType, '_') !== false ? str_replace('_', '/', $apiType) : $apiType;
        
        // Returning the payment URL for the API call 
        return $baseUrl . $suffixUrl;
    }
    
    /**
     * Validates the novalnet payment response
     *
     * @param  object    $order
     * @param  array     $paymentResponse
     * @param  string    $paymentName
     * @return none|bool
     */
    public function validatePaymentResponse(object $order, array $paymentResponse, string $paymentName): ?bool
    {
        // Building the failure transaction comments
        $transactionComments = $this->getTransactionInformation($order, $paymentResponse, $paymentName);
        
        // Routing if the result is a failure 
        if (!empty($paymentResponse['result']['status']) && $paymentResponse['result']['status'] != 'SUCCESS') {
            
            if ($_SESSION['Zahlungsart']->nWaehrendBestellung == 0) {
                // Logs the order details in Novalnet tables for failure
                $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung', $order->kBestellung, ['cKommentar' => $transactionComments]);
            }
            $this->redirectOnError($order, $paymentResponse, $paymentName);
            
        } else {
            if ($_SESSION['Zahlungsart']->nWaehrendBestellung == 1) {
                if (!empty($paymentResponse['transaction']['bank_details']) && ((in_array($paymentResponse['transaction']['status'], array('ON_HOLD', 'CONFIRMED'))) || in_array($paymentResponse['transaction']['payment_type'], array('INVOICE', 'PREPAYMENT')))) {
                    $transactionComments .= $this->getBankdetailsInformation($order, $paymentResponse);
                }
                if (!empty($paymentResponse['transaction']['payment_type'] == 'CASHPAYMENT')) {
                    $transactionComments .= $this->getStoreInformation($paymentResponse);
                }
            }
            
            $_SESSION['novalnet']['comments'] = $transactionComments;
            return true;
        }
    }
    
    /**
     * Process while handling handle_notification URL
     *
     * @param  object  $order
     * @param  string $paymentName
     * @param  string $sessionHash
     * @return none
     */
    public function handlePaymentCompletion(object $order, string $paymentName, string $sessionHash): void
    {        
        $paymentResponse = $_SESSION['novalnet']['payment_response'];
        if (!empty($paymentResponse['result']['status'])) {  
        
            // Success result handling 
            if ($paymentResponse['result']['status'] == 'SUCCESS') {
                
                // If the payment is already done and order created, we send update order email 
                if ($order->Zahlungsart->nWaehrendBestellung == 0) {
                    $jtlPaymentmethod = Method::create($order->Zahlungsart->cModulId);
                    
                    // Triggers order update mail template
                    $jtlPaymentmethod->sendMail($order->kBestellung, \MAILTEMPLATE_BESTELLUNG_AKTUALISIERT);
                
                } else {
                    $paymentRequestData = [];
                    $paymentRequestData['transaction'] = [
                                                            'tid' => $paymentResponse['transaction']['tid'],
                                                            'order_no' => $order->cBestellNr
                                                         ];
                    $transactionUpdateResponse = $this->performServerCall($paymentRequestData, 'transaction_update');
                    
                    if ((in_array($transactionUpdateResponse['transaction']['payment_type'], array('INSTALMENT_INVOICE', 'GUARANTEED_INVOICE')) && in_array($transactionUpdateResponse['transaction']['status'], array('ON_HOLD', 'CONFIRMED'))) ||  in_array($transactionUpdateResponse['transaction']['payment_type'], array('INVOICE', 'PREPAYMENT'))) {
                        $transactionDetails = $this->getTransactionInformation($order, $transactionUpdateResponse, $paymentName);
                        
                        $transactionDetails .= $this->getBankdetailsInformation($order, $transactionUpdateResponse);
                        $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung',  $order->kBestellung, ['cKommentar' => $transactionDetails]);
                    }
 
                }
               
                // Inserting order details into Novalnet table 
                $this->insertOrderDetailsIntoNnDb($order, $paymentResponse, $paymentName);
                
                $updateWawi = 'Y';
                
                // Update the WAWI pickup status as 'Nein' for confirmed transaction
                if ($paymentResponse['transaction']['status'] == 'CONFIRMED' || (in_array($paymentResponse['transaction']['payment_type'], ['INVOICE', 'PREPAYMENT']) && $paymentResponse['transaction']['status'] == 'PENDING')) {
                    $updateWawi = 'N';
                }
                
                // Updates the value into the database                
                $this->novalnetPaymentHelper->performDbUpdateProcess('tbestellung', 'kBestellung',  $order->kBestellung, ['cAbgeholt' => $updateWawi]); 
                
                // Unset the entire novalnet session on order completion
                unset($_SESSION['novalnet']);
               
                \header('Location: ' . Shop::Container()->getLinkService()->getStaticRoute('bestellabschluss.php') .
                '?i=' . $sessionHash);
                exit;
            } else {
                $this->redirectOnError($order,  $paymentResponse, $paymentName);
            }
        } else {
            \header('Location:' . Shop::getURL() . '/Bestellvorgang?editVersandart=1');
            exit;
        }        
    }
    
    /**
     * Setting up the transaction details for storing in the order 
     *
     * @param  object $order
     * @param  array  $paymentResponse
     * @param  string $paymentName
     * @return string
     */
    public function getTransactionInformation(object $order, array $paymentResponse, string $paymentName): string
    {
        $transactionComments = '';

        if(!empty($_SESSION['cPost_arr']['kommentar'])) {
            $userComments = ($_SESSION['Zahlungsart']->nWaehrendBestellung != 0) ? $_SESSION['cPost_arr']['kommentar'] : '';
        }
        
        if(!empty($userComments)) {
            $transactionComments .= $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_customer_comment') . $userComments . \PHP_EOL;
        }
        
        $transactionComments .= $order->cZahlungsartName;
        
        // Set the Novalnet transaction id based on the response
        $novalnetTxTid = !empty($paymentResponse['transaction']['tid']) ? $paymentResponse['transaction']['tid'] : $paymentResponse['tid'];
        
        if(!empty($novalnetTxTid)) {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_transaction_tid') . $novalnetTxTid;
        }
        
        // Set the Novalnet transaction mode based on the response
        $novalnetTxMode = !empty($paymentResponse['transaction']['test_mode']) ? $paymentResponse['transaction']['test_mode'] : $novalnetTxMode;
        
        if(!empty($novalnetTxMode)) {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_test_order');
        }
        
        if (strpos($paymentName, 'guaranteed') !== false) {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_guarantee_text');
        }
        
        if (in_array($paymentName, ['guaranteed_invoice', 'instalment_invoice'])  && $paymentResponse['transaction']['status'] == 'PENDING') {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_guaranteed_invoice_pending_text');
        }
        
        if (in_array($paymentName, ['guaranteed_sepa', 'instalment_sepa']) && $paymentResponse['transaction']['status'] == 'PENDING') {
            $transactionComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_guaranteed_sepa_pending_text');
        }
        
        return $transactionComments;
    }
    
    /**
     * Setting up the Invoice bank details for storing in the order 
     *
     * @param  object      $order
     * @param  array       $paymentResponse
     * @param  string|null $lang
     * @return string
     */
    public function getBankdetailsInformation(object $order, array $paymentResponse, ?string $lang = null): string
    {
        $amount = ($paymentResponse['transaction']['payment_type'] == 'INSTALMENT_INVOICE') ? $paymentResponse['instalment']['cycle_amount'] : $paymentResponse['transaction']['amount'];
        
        if ($paymentResponse['transaction']['status'] != 'ON_HOLD') {
            $invoiceComments = \PHP_EOL . sprintf($this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payment_transfer_duedate_comments', $lang), number_format($amount / 100 , 2, ',', ''),  $order->Waehrung->htmlEntity, $paymentResponse['transaction']['due_date']);
        } else {
            $invoiceComments = \PHP_EOL . sprintf($this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payment_transfer_comment', $lang), number_format($amount / 100 , 2, ',', ''),  $order->Waehrung->htmlEntity);
        }   
     
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payments_holder', $lang) . $paymentResponse['transaction']['bank_details']['account_holder'];
        $invoiceComments .= \PHP_EOL . 'IBAN: ' . $paymentResponse['transaction']['bank_details']['iban'];
        $invoiceComments .= \PHP_EOL . 'BIC: ' . $paymentResponse['transaction']['bank_details']['bic'];
        $invoiceComments .= \PHP_EOL . 'BANK: ' . $paymentResponse['transaction']['bank_details']['bank_name'] . ' ' . $paymentResponse['transaction']['bank_details']['bank_place'];        
        
        // Adding the payment reference details 
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payments_single_reference_text', $lang);
        $firstPaymentReference = ($paymentResponse['transaction']['payment_type'] == 'INSTALMENT_INVOICE') ? 'jtl_novalnet_instalment_payment_reference' : 'jtl_novalnet_invoice_payments_first_reference';
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation($firstPaymentReference, $lang) . $paymentResponse['transaction']['tid'];
        if (!empty($paymentResponse['transaction']['invoice_ref']) && $paymentResponse['transaction']['payment_type'] != 'INSTALMENT_INVOICE') {
        $invoiceComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_invoice_payments_second_reference', $lang) . $paymentResponse['transaction']['invoice_ref'];
        }
        return $invoiceComments;
    }
    
    /**
     * Setting up the Cashpayment store details for storing in the order 
     *
     * @param  array $paymentResponse
     * @return string
     */
    public function getStoreInformation(array $paymentResponse): string
    {        
        $cashpaymentComments  = \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_cashpayment_expiry_date') . $paymentResponse['transaction']['due_date'] . \PHP_EOL;
        $cashpaymentComments .= \PHP_EOL . $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_cashpayment_nearest_store_details') . \PHP_EOL;
        
        // There would be a maximum of three nearest stores for the billing address
        $nearestStores = $paymentResponse['transaction']['nearest_stores'];
        
        // We loop in each of them to print those store details 
        for ($storePos = 1; $storePos <= count($nearestStores); $storePos++) {
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['store_name'];
            $cashpaymentComments .= \PHP_EOL . utf8_encode($nearestStores[$storePos]['street']);
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['city'];
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['zip'];
            $cashpaymentComments .= \PHP_EOL . $nearestStores[$storePos]['country_code'];
            $cashpaymentComments .= \PHP_EOL;
        }
        
        return $cashpaymentComments;
    }        
    
    /**
     * To insert the order details into Novalnet tables
     *
     * @param  object $order
     * @param  array  $paymentResponse
     * @param  string $paymentName
     * @return none
     */
    public function insertOrderDetailsIntoNnDb(object $order, array $paymentResponse, string $paymentName): void
    {
        $customerDetails = Frontend::getCustomer();
        
        $insertOrder = new stdClass();
        $insertOrder->cNnorderid         = $order->cBestellNr;
        $insertOrder->nNntid             = !empty($paymentResponse['transaction']['tid']) ? $paymentResponse['transaction']['tid'] : $paymentResponse['tid'];
        $insertOrder->cZahlungsmethode   = $paymentName;
        $insertOrder->cMail              = $customerDetails->cMail;
        $insertOrder->cStatuswert        = !empty($paymentResponse['transaction']['status']) ? $paymentResponse['transaction']['status'] : $paymentResponse['status'];
        $insertOrder->nBetrag            = !empty($paymentResponse['transaction']['amount']) ? $paymentResponse['transaction']['amount'] : (round($order->fGesamtsumme) * 100);
        $insertOrder->cAdditionalInfo    = !empty($paymentResponse['instalment']) ? json_encode($paymentResponse['instalment']) : '';
        $insertOrder->nCallbackAmount    = !in_array($paymentName, ['invoice', 'prepayment', 'instalment_invoice', 'cashpayment']) ? $paymentResponse['transaction']['amount'] : 0;
        
        Shop::Container()->getDB()->insert('xplugin_novalnet_transaction_details', $insertOrder);
    }
    
    /**
     * Complete the order
     *
     * @param  object $order
     * @param  string $paymentName
     * @param  string $sessionHash
     * @return none
     */
    public function completeOrder(object $order, string $paymentName, string $sessionHash): void
    {     
        $paymentResponse = $_SESSION['novalnet']['payment_response'];
        
        if ($paymentResponse) {
            // If the order is already complete, we do the appropriate action 
            if ($paymentResponse['result']['status'] == 'SUCCESS') {
                        
                // Unset the entire novalnet session on order completion
                unset($_SESSION['novalnet']);
            
                // Routing to the order page from my account for the order completion 
                \header('Location: ' . Shop::Container()->getLinkService()->getStaticRoute('bestellabschluss.php') . '?i=' . $sessionHash);
                exit;
            } else {
                // Returns with error message on error
                $this->redirectOnError($order, $paymentResponse, $paymentName); 
            }
        }
    }
    
    /**
     * Compare the checksum generated for redirection payments
     *
     * @param  object  $$order
     * @param  array  $paymentResponse
     * @param  string $paymentName
     * @return array
     */
    public function checksumValidateAndPerformTxnStatusCall(object $order, array $paymentResponse, string $paymentName): array
    {
        if ($paymentResponse['status'] && $paymentResponse['status'] == 'SUCCESS') {
            
            // Condition to check whether the payment is redirect
            if (!empty($paymentResponse['checksum']) && !empty($paymentResponse['tid']) && !empty($_SESSION[$paymentName]['novalnet_txn_secret'])) {
                                            
                $generatedChecksum = hash('sha256', $paymentResponse['tid'] . $_SESSION[$paymentName]['novalnet_txn_secret'] . $paymentResponse['status'] . strrev($this->novalnetPaymentHelper->getConfigurationValues('novalnet_private_key')));
                
                // If the checksum isn't matching, there could be a possible manipulation in the data received 
                if ($generatedChecksum !== $paymentResponse['checksum']) {                                  
                    $explicitErrorMessage = $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_checksum_error');
                    
                    // Redirects to the error page
                    $this->redirectOnError($order, $paymentResponse, $paymentName, $explicitErrorMessage); 
                }
            }
                                          
            $transactionDetailsRequest = [];
            $transactionDetailsRequest['transaction']['tid'] = $paymentResponse['tid'];
                
            return $this->performServerCall($transactionDetailsRequest, 'transaction_details');         
            
        } else {
            // Redirects to the error page
            $this->redirectOnError($order, $paymentResponse, $paymentName); 
        }                   
    } 
    
    /**
     * Retrieve the Instalment information from the database 
     *
     * @param  mixed       $orderNo
     * @param  string|null $lang
     * @return array|null
     */
    public function getInstalmentInfoFromDb(mixed $orderNo, ?string $lang = null): ?array
    {
        $transactionDetails = Shop::Container()->getDB()->query('SELECT nov.nNntid, nov.cStatuswert, nov.nBetrag, nov.cAdditionalInfo  FROM tbestellung ord JOIN xplugin_novalnet_transaction_details nov ON ord.cBestellNr = nov.cNnorderid WHERE cNnorderid = "' . $orderNo . '" and nov.cZahlungsmethode LIKE "instalment%"', 1);
        
        if (!empty($transactionDetails) && $transactionDetails->cStatuswert == 'CONFIRMED') {
            $insAdditionalInfo = json_decode($transactionDetails->cAdditionalInfo, true);

            $instalmentInfo = [];
            $totalInstalments = count($insAdditionalInfo['cycle_dates']);
            $insAdditionalInfo[1]['tid'] = $transactionDetails->nNntid;
            
            foreach($insAdditionalInfo['cycle_dates'] as $key => $instalmentCycleDate) {
                $instalmentCycle[$key] = $instalmentCycleDate;
            }
            $instalment_cycle_cancel = '';
            // Instalment Status
            if (isset($insAdditionalInfo['is_full_instalment_cancel'])) {
                $instalment_cycle_cancel = $insAdditionalInfo['is_full_instalment_cancel'];
            }
            for($instalment=1;$instalment<=$totalInstalments;$instalment++) {
                if($instalment != $totalInstalments) {
                $instalmentInfo['insDetails'][$instalment]['cycle_amount'] = number_format($insAdditionalInfo['cycle_amount'] / 100 , 2, ',', '') .' '. Frontend::getCurrency()->gethtmlEntity() ;
                } else {
                    $cycleAmount = ($transactionDetails->nBetrag - ($insAdditionalInfo['cycle_amount'] * ($instalment - 1)));
                    $instalmentInfo['insDetails'][$instalment]['cycle_amount'] = number_format($cycleAmount / 100 , 2, ',', '') .' '. Frontend::getCurrency()->gethtmlEntity();
                }
                $instalmentInfo['insDetails'][$instalment]['tid'] = !empty($insAdditionalInfo[$instalment]['tid']) ?  $insAdditionalInfo[$instalment]['tid'] : '-';
                $instalmentInfo['insDetails'][$instalment]['payment_status'] = ($instalment_cycle_cancel == 'all_cycles') ? Shop::Lang()->get('statusCancelled', 'order') : (($instalmentInfo['insDetails'][$instalment]['tid'] != '-') ? Shop::Lang()->get('statusPaid', 'order') : ($instalment_cycle_cancel == 'remaining_cycles' ? Shop::Lang()->get('statusCancelled', 'order') : Shop::Lang()->get('statusPending', 'order')));
                $instalmentInfo['insDetails'][$instalment]['future_instalment_date'] = date_format(date_create($instalmentCycle[$instalment]), 'F j, Y');
            }

            $instalmentInfo['lang'] = $this->novalnetPaymentHelper->getNnLanguageText(['jtl_novalnet_serial_no', 'jtl_novalnet_instalment_future_date', 'jtl_novalnet_instalment_information', 'jtl_novalnet_instalment_amount', 'jtl_novalnet_instalment_transaction_id'], $lang);
             $instalmentInfo['status'] = $transactionDetails->cStatuswert;
            
            return $instalmentInfo;
        }
        
        return null;
    }
    
    /**
     * Form the wallet order details
     *
     * @return string
     */
    public function getWalletOrderDetails(): string
    {
        // Get article details
        $cartDetails = Frontend::getCart();
        // Set the TAX amount
        $taxDetails = Frontend::getCart()->gibSteuerpositionen();
        $taxAmount = $vatName = $totalProductAmount = 0;
        if(!empty($taxDetails)) {
            foreach($taxDetails as $taxDetail) {
                $vatName = $taxDetail->cName;
                $taxAmount += round(($taxDetail->fBetrag * 100));
            }
        }
        // Load the line items
        $positionArr = (array) $cartDetails->PositionenArr;
        if (!empty($positionArr)) {
            foreach($positionArr as $positionDetails) {
                if (!empty($positionDetails->kArtikel)) {
                    $productName = !empty($positionDetails->Artikel->cName) ? html_entity_decode($positionDetails->Artikel->cName) : html_entity_decode($positionDetails->cName);
                    $productPrice = !empty($positionDetails->Artikel->Preise->fVKBrutto) ? (floatval($positionDetails->Artikel->Preise->fVKBrutto) * 100) : (floatval($positionDetails->fVK[0]) * 100);
                    $productQuantity = !empty($positionDetails->Artikel->nAnzahl) ? $positionDetails->Artikel->nAnzahl : $positionDetails->nAnzahl;
                    $totalProductAmount += round($productPrice * $productQuantity);
                    $articleDetails[] = array (
                        'label' => '(' . $productQuantity . ' X ' . $productPrice . ') ' . $productName,
                        'amount' => round($productPrice * $productQuantity),
                        'type' => 'LINE_ITEM'
                    );
                }
            }
            // Set the TAX information
            if (!empty($taxAmount)) {
                $articleDetails[] = array('label' => $vatName, 'amount' => floatval($taxAmount), 'type' => 'SUBTOTAL');
            }
            // Set the additional payment fee
            if (!empty($_SESSION['Zahlungsart']->fAufpreis)) {
                $articleDetails[] = array('label' => $this->novalnetPaymentHelper->plugin->getLocalization()->getTranslation('jtl_novalnet_payment_charge'), 'amount' => floatval($_SESSION['Zahlungsart']->fAufpreis) * 100, 'type' => 'SUBTOTAL');
            }
            // Set the shipping method
            if (!empty($_SESSION['Versandart']->cName)) {
                $shippingMethodAmount = ($_SESSION['Versandart']->eSteuer == 'netto') ? ((($taxDetails[0]->fUst/100) * $_SESSION['Versandart']->fEndpreis) + $_SESSION['Versandart']->fEndpreis) : ($_SESSION['Versandart']->fEndpreis);
                $articleDetails[] = array('label' => $_SESSION['Versandart']->cName, 'amount' => round($shippingMethodAmount * 100), 'type' => 'SUBTOTAL');
            }
            // Set the coupon information
            $availableCoupons = ['Kupon', 'VersandKupon', 'NeukundenKupon'];
            foreach($availableCoupons as $coupon) {
                if(!empty($_SESSION[$coupon])) {
                    $couponAmount = '-' . round(($_SESSION[$coupon]->fWert * 100));
                    if($_SESSION[$coupon]->cWertTyp == 'prozent') {
                            $couponAmount = '-' . round($totalProductAmount * ($_SESSION[$coupon]->fWert/100));
                    }
                    if($_SESSION[$coupon] == 'VersandKupon') {
                        $couponAmount = '-' . round($_SESSION['Versandart']->fEndpreis * 100);
                    }
                    $articleDetails[] = array('label' => $_SESSION[$coupon]->cName, 'amount' => (string) $couponAmount, 'type' => 'SUBTOTAL');
                }
            }
        }
        return json_encode($articleDetails);
    }
    
    /**
     * Get the mandatory paramters for the payments 
     *
     * @param  array $paymentRequestData
     * @return none
     */
    public function getMandatoryPaymentParameters(array &$paymentRequestData): void
    {
        // If the consumer has opted to pay with the saved account ot card data, we use the token relavant to that   
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['token'])) {
            // Selected token is the key to the stored payment data             
            $paymentRequestData['transaction']['payment_data']['token'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['token'];     
        } else {
            // If the consumer has opted to save the account or card data for future purchases, we notify the server
            if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['create_token'])) {
                $paymentRequestData['transaction']['create_token'] = 1;
            }
            // For Credit card payment
            if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['pan_hash'])) {
                // Setting up the alternative card data to the server for card processing
                $paymentRequestData['transaction']['payment_data'] = [
                                                                        'pan_hash'   => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['pan_hash'],
                                                                        'unique_id'  => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['unique_id']
                                                                     ];
                                                                     
                // If the enforced 3D option is enabled, we notify the server about the forced 3D handling 
                if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['do_redirect'])) {
                    $paymentRequestData['transaction']['payment_data']['enforce_3d'] = 1;
                }
            }
            // For Direct debit SEPA payment
            if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['iban'])) {
                // Setting up the account data to the server for SEPA processing
                $paymentRequestData['transaction']['payment_data'] = [
                                                                        'iban'       => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['iban']
                                                                     ];
                if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['bic'])) {
                    $paymentRequestData['transaction']['payment_data']['bic'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['bic'];
                }
            }
        }
        // Notify the server about period of instalment
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['cycle'])) {
            $paymentRequestData['instalment']['cycles'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['cycle'];
        }
        // Send the Birthday to server for the Guaranteed payments
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['birth_date'])) {
            $paymentRequestData['customer']['birth_date'] = $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['birth_date'];
        }
        // Send the wallet token to the server
        if(!empty($_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['wallet_token'])) {
            // Setting up the account data to the server for SEPA processing
            $paymentRequestData['transaction']['payment_data'] = [
                                                                    'wallet_token'       => $_SESSION['novalnet']['seamless_payment_form_response']['booking_details']['wallet_token']
                                                                 ];
        }
    }
    
    /**
     * Check if the payment is redirection
     *
     * @param  string $paymentType
     * @return bool
     */
    public function isRedirectPayment(string $paymentType): bool
    {
        if(in_array($paymentType, ['IDEAL', 'ONLINE_TRANSFER', 'GIROPAY', 'PRZELEWY24', 'EPS', 'PAYPAL', 'POSTFINANCE_CARD', 'POSTFINANCE', 'BANCONTACT', 'ONLINE_BANK_TRANSFER'])) {
            return true;
        }
        return false;
    }
}
