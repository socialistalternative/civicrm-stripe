<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use Civi\Payment\PropertyBag;
use CRM_Stripe_ExtensionUtil as E;

/**
 * Manage the civicrm_stripe_paymentintent database table which records all created paymentintents
 * Class CRM_Stripe_PaymentIntent
 */
class CRM_Stripe_PaymentIntent {

  /**
   * @var CRM_Core_Payment_Stripe
   */
  protected $paymentProcessor;

  /**
   * @var string
   */
  protected $description = '';

  /**
   * @var string
   */
  protected $referrer = '';

  /**
   * @var string
   */
  protected $extraData = '';

  /**
   * @param \CRM_Core_Payment_Stripe $paymentProcessor
   */
  public function __construct(\CRM_Core_Payment_Stripe $paymentProcessor) {
    $this->paymentProcessor = $paymentProcessor;
  }

  /**
   * @param string $description
   *
   * @return void
   */
  public function setDescription($description) {
    $this->description = $description;
  }

  /**
   * @param string $referrer
   *
   * @return void
   */
  public function setReferrer($referrer) {
    $this->referrer = $referrer;
  }

  /**
   * @param string $extraData
   *
   * @return void
   */
  public function setExtraData(string $extraData) {
    $this->extraData = $extraData;
  }

  /**
   * Add a paymentIntent to the database
   *
   * @param $params
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function add($params) {
    $requiredParams = ['id', 'payment_processor_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe PaymentIntent (add): Missing required parameter: ' . $required);
      }
    }

    $count = 0;
    foreach ($params as $key => $value) {
      switch ($key) {
        case 'id':
        case 'description':
        case 'status':
        case 'identifier':
          $queryParams[] = [$value, 'String'];
          break;

        case 'payment_processor_id':
          $queryParams[] = [$value, 'Integer'];
          break;

        case 'contribution_id':
          if (empty($value)) {
            continue 2;
          }
          $queryParams[] = [$value, 'Integer'];
          break;

      }
      $keys[] = $key;
      $update[] = "{$key} = '{$value}'";
      $values[] = "%{$count}";
      $count++;
    }

    $query = "INSERT INTO civicrm_stripe_paymentintent
          (" . implode(',', $keys) . ") VALUES (" . implode(',', $values) . ")";
    $query .= " ON DUPLICATE KEY UPDATE " . implode(',', $update);
    CRM_Core_DAO::executeQuery($query, $queryParams);
  }

  /**
   * @param array $params
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function create($params) {
    self::add($params);
  }

  /**
   * Delete a Stripe paymentintent from the CiviCRM database
   *
   * @param array $params
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function delete($params) {
    $requiredParams = ['id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe PaymentIntent (delete): Missing required parameter: ' . $required);
      }
    }

    $queryParams = [
      1 => [$params['id'], 'String'],
    ];
    $sql = "DELETE FROM civicrm_stripe_paymentintent WHERE id = %1";
    CRM_Core_DAO::executeQuery($sql, $queryParams);
  }

  /**
   * @param array $params
   * @param \CRM_Core_Payment_Stripe $stripe
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   *
   * @deprecated not used anywhere?
   */
  public static function stripeCancel($params, $stripe) {
    $requiredParams = ['id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe PaymentIntent (getFromStripe): Missing required parameter: ' . $required);
      }
    }

    $intent = $stripe->stripeClient->paymentIntents->retrieve($params['id']);
    $intent->cancel();
  }

  /**
   * @param array $params
   * @param \CRM_Core_Payment_Stripe $stripe
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   *
   * @deprecated not used anywhere?
   */
  public static function stripeGet($params, $stripe) {
    $requiredParams = ['id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe PaymentIntent (getFromStripe): Missing required parameter: ' . $required);
      }
    }

    $intent = $stripe->stripeClient->paymentIntents->retrieve($params['id']);
    $params['status'] = $intent->status;
    self::add($params);
  }

  /**
   * Get an existing Stripe paymentIntent from the CiviCRM database
   *
   * @param $params
   *
   * @return array
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function get($params) {
    $requiredParams = ['id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe PaymentIntent (get): Missing required parameter: ' . $required);
      }
    }
    if (empty($params['contact_id'])) {
      throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe PaymentIntent (get): contact_id is required');
    }
    $queryParams = [
      1 => [$params['id'], 'String'],
    ];

    $dao = CRM_Core_DAO::executeQuery("SELECT *
      FROM civicrm_stripe_paymentintent
      WHERE id = %1", $queryParams);

    return $dao->toArray();
  }

  /**
   * @param $params
   *
   * @return object
   */
  public function processSetupIntent(array $params) {
    /*
    $params = [
      // Optional paymentMethodID
      'paymentMethodID' => 'pm_xx',
      'customer => 'cus_xx',
    ];
    */
    $resultObject = (object) ['ok' => FALSE, 'message' => '', 'data' => []];

    $intentParams['confirm'] = TRUE;
    if (!empty($this->description)) {
      $intentParams['description'] = $this->description;
    }
    $intentParams['payment_method_types'] = ['card'];
    if (!empty($params['paymentMethodID'])) {
      $intentParams['payment_method'] = $params['paymentMethodID'];
    }
    if (!empty($params['customer'])) {
      $intentParams['customer'] = $params['customer'];
    }
    $intentParams['usage'] = 'off_session';

    // Get the client IP address
    $ipAddress = (class_exists('\Civi\Firewall\Firewall')) ? (new \Civi\Firewall\Firewall())->getIPAddress() : $ipAddress = CRM_Utils_System::ipAddress();

    try {
      $intent = $this->paymentProcessor->stripeClient->setupIntents->create($intentParams);

    } catch (Exception $e) {
      // Save the "error" in the paymentIntent table in case investigation is required.
      $stripePaymentintentParams = [
        'payment_processor_id' => $this->paymentProcessor->getID(),
        'status' => 'failed',
        'description' => "{$e->getRequestId()};{$e->getMessage()};{$this->description}",
        'referrer' => $this->referrer,
      ];
      $extraData = (!empty($this->extraData)) ? explode(';', $this->extraData) : [];
      $extraData[] = $ipAddress;
      $extraData[] = $e->getMessage();
      $stripePaymentintentParams['extra_data'] = implode(';', $extraData);

      CRM_Stripe_BAO_StripePaymentintent::create($stripePaymentintentParams);
      $resultObject->ok = FALSE;
      $resultObject->message = $e->getMessage();
      return $resultObject;
    }

    // Save the generated setupIntent in the CiviCRM database for later tracking
    $stripePaymentintentParams = [
      'stripe_intent_id' => $intent->id,
      'payment_processor_id' => $this->paymentProcessor->getID(),
      'status' => $intent->status,
      'description' => $this->description,
      'referrer' => $this->referrer,
    ];

    $extraData = (!empty($this->extraData)) ? explode(';', $this->extraData) : [];
    $extraData[] = $ipAddress;
    $stripePaymentintentParams['extra_data'] = implode(';', $extraData);

    CRM_Stripe_BAO_StripePaymentintent::create($stripePaymentintentParams);

    switch ($intent->status) {
      case 'requires_payment_method':
      case 'requires_confirmation':
      case 'requires_action':
      case 'processing':
      case 'canceled':
      case 'succeeded':
        $resultObject->ok = TRUE;
        $resultObject->data = [
          'status' => $intent->status,
          'next_action' => $intent->next_action,
          'client_secret' => $intent->client_secret,
        ];
        break;
    }

    // Invalid status
    if (isset($intent->last_setup_error)) {
      if (isset($intent->last_payment_error->message)) {
        $message = E::ts('Payment failed: %1', [1 => $intent->last_payment_error->message]);
      }
      else {
        $message = E::ts('Payment failed.');
      }
      $resultObject->ok = FALSE;
      $resultObject->message = $message;
    }

    return $resultObject;
  }

  /**
   * Handle the processing of a Stripe "intent" from an endpoint eg. API3/API4.
   * This function does not implement any "security" checks - it is expected that
   * the calling code will do necessary security/permissions checks.
   * WARNING: This function is NOT supported outside of Stripe extension and may change without notice.
   *
   * @param array $params
   *
   * @return object
   * @throws \CRM_Core_Exception
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function processIntent(array $params) {
    // Params that may or may not be set by calling code:
    // 'currency' should really be set but we'll default if not set.
    $currency = \CRM_Utils_Type::validate($params['currency'], 'String', \CRM_Core_Config::singleton()->defaultCurrency);
    // If a payment using MOTO (mail order telephone order) was requested.
    // This parameter has security implications and great care should be taken when setting it to TRUE.
    $params['moto'] = $params['moto'] ?? FALSE;

    if ($this->paymentProcessor->getPaymentProcessor()['class_name'] !== 'Payment_Stripe') {
      \Civi::log('stripe')->error(__CLASS__ . " payment processor {$params['paymentProcessorID']} is not Stripe");
      return (object) ['ok' => FALSE, 'message' => 'Payment processor is not Stripe', 'data' => []];
    }

    if ($params['setup']) {
      $processSetupIntentParams = [
        'paymentMethodID' => $params['paymentMethodID'],
      ];
      $processIntentResult = $this->processSetupIntent($processSetupIntentParams);
      return $processIntentResult;
    }
    else {
      $processPaymentIntentParams = [
        'paymentIntentID' => $params['intentID'],
        'paymentMethodID' => $params['paymentMethodID'],
        'capture' => FALSE,
        'amount' => $params['amount'],
        'currency' => $currency,
        'payment_method_options' => $params['payment_method_options'] ?? [],
      ];
      if (!empty($params['moto'])) {
        $processPaymentIntentParams['moto'] = TRUE;
      }

      $processIntentResult = $this->processPaymentIntent($processPaymentIntentParams);
      return $processIntentResult;
    }
  }

  /**
   * @param array $params
   *
   * @return object
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function processPaymentIntent(array $params) {
    /*
    $params = [
      // Either paymentIntentID or paymentMethodID must be set
      'paymentIntentID' => 'pi_xx',
      'paymentMethodID' => 'pm_xx',
      'customer' => 'cus_xx', // required if paymentMethodID is set
      'capture' => TRUE/FALSE,
      'amount' => '12.05',
      'currency' => 'USD',
    ];
    */
    $resultObject = (object) ['ok' => FALSE, 'message' => '', 'data' => []];

    if (class_exists('\Civi\Firewall\Event\FraudEvent')) {
      if (!empty($this->extraData)) {
        // The firewall will block IP addresses when it detects fraud.
        // This additionally checks if the same details are being used on a different IP address.
        $ipAddress = \Civi\Firewall\Firewall::getIPAddress();

        // Where a payment is declined as likely fraud, log it as a more serious exception
        $numberOfFailedAttempts = \Civi\Api4\StripePaymentintent::get(FALSE)
          ->selectRowCount()
          ->addWhere('extra_data', '=', $this->extraData)
          ->addWhere('status', '=', 'failed')
          ->addWhere('created_date', '>', '-2 hours')
          ->execute()
          ->count();
        if ($numberOfFailedAttempts > 5) {
          \Civi\Firewall\Event\FraudEvent::trigger($ipAddress, CRM_Utils_String::ellipsify('StripeProcessPaymentIntent: ' . $this->extraData, 255));
        }
      }
    }

    $intentParams = [];
    $intentParams['confirm'] = TRUE;

    if (!empty($params['paymentIntentID'])) {
      try {
        // We already have a PaymentIntent, retrieve and attempt to confirm.
        $intent = $this->paymentProcessor->stripeClient->paymentIntents->retrieve($params['paymentIntentID']);
        if ($intent->status === 'requires_confirmation') {
          $intent->confirm();
        }
      }
      catch (Exception $e) {
        \Civi::log()->debug(get_class($e) . $e->getMessage());
      }
    }
    else {
      // We don't yet have a PaymentIntent, create one using the
      // Payment Method ID and attempt to confirm it too.
      try {
        if (!empty($params['moto'])) {
          $intentParams['payment_method_options']['card']['moto'] = TRUE;
        }
        $intentParams['amount'] = $this->paymentProcessor->getAmountFormattedForStripeAPI(PropertyBag::cast(['amount' => $params['amount'], 'currency' => $params['currency']]));
        $intentParams['currency'] = $params['currency'];
        // authorize the amount but don't take from card yet
        $intentParams['capture_method'] = 'manual';
        // Setup the card to be saved and used later
        $intentParams['setup_future_usage'] = 'off_session';
        if (isset($params['paymentMethodID'])) {
          $intentParams['payment_method'] = $params['paymentMethodID'];
        }
        if (isset($params['customer'])) {
          $intentParams['customer'] = $params['customer'];
        }

        $intentParams['automatic_payment_methods']['enabled'] = TRUE;
        $intentParams['automatic_payment_methods']['allow_redirects'] = 'never';

        $intent = $this->paymentProcessor->stripeClient->paymentIntents->create($intentParams);
      }
      catch (Exception $e) {
        $parsedError = $this->paymentProcessor->parseStripeException('process_paymentintent', $e);
        // Save the "error" in the paymentIntent table in case investigation is required.
        $stripePaymentintentParams = [
          'payment_processor_id' => $this->paymentProcessor->getID(),
          'status' => 'failed',
          'description' => "{$e->getRequestId()};{$e->getMessage()};{$this->description}",
          'referrer' => $this->referrer,
        ];

        // Get the client IP address
        $ipAddress = (class_exists('\Civi\Firewall\Firewall')) ? (new \Civi\Firewall\Firewall())->getIPAddress() : $ipAddress = CRM_Utils_System::ipAddress();

        $extraData = (!empty($this->extraData)) ? explode(';', $this->extraData) : [];
        $extraData[] = $ipAddress;
        $extraData[] = $e->getMessage();
        $stripePaymentintentParams['extra_data'] = implode(';', $extraData);

        CRM_Stripe_BAO_StripePaymentintent::create($stripePaymentintentParams);

        if ($e instanceof \Stripe\Exception\CardException) {

          $fraud = FALSE;

          if (method_exists('\Civi\Firewall\Firewall', 'getIPAddress')) {
            $ipAddress = \Civi\Firewall\Firewall::getIPAddress();
          }
          else {
            $ipAddress = \CRM_Utils_System::ipAddress();
          }

          // Where a payment is declined as likely fraud, log it as a more serious exception
          if (class_exists('\Civi\Firewall\Event\FraudEvent')) {

            // Fraud response from issuer
            if ($e->getDeclineCode() === 'fraudulent') {
              $fraud = TRUE;
            }

            // Look for fraud detected by Stripe Radar
            else {
              $jsonBody = $e->getJsonBody();
              if (!empty($jsonBody['error']['payment_intent']['charges']['data'])) {
                foreach ($jsonBody['error']['payment_intent']['charges']['data'] as $charge) {
                  if ($charge['outcome']['type'] === 'blocked') {
                    $fraud = TRUE;
                    break;
                  }
                }
              }
            }

            if ($fraud) {
              \Civi\Firewall\Event\FraudEvent::trigger($ipAddress, 'CRM_Stripe_PaymentIntent::processPaymentIntent');
            }

          }

          // Multiple declined card attempts is an indicator of card testing
          if (!$fraud && class_exists('\Civi\Firewall\Event\DeclinedCardEvent')) {
            \Civi\Firewall\Event\DeclinedCardEvent::trigger($ipAddress, 'CRM_Stripe_PaymentIntent::processPaymentIntent');
          }

          // Returned message should not indicate whether fraud was detected
          $message = $parsedError['message'];
        }
        elseif ($e instanceof \Stripe\Exception\InvalidRequestException) {
          $message = $parsedError['message'];
        }
        $resultObject->ok = FALSE;
        $resultObject->message = $message ?? 'Unknown error';
        return $resultObject;
      }
    }

    // Save the generated paymentIntent in the CiviCRM database for later tracking
    $stripePaymentintentParams = [
      'stripe_intent_id' => $intent->id,
      'payment_processor_id' => $this->paymentProcessor->getID(),
      'status' => $intent->status,
      'description' => $this->description,
      'referrer' => $this->referrer,
    ];
    if (!empty($this->extraData)) {
      $stripePaymentintentParams['extra_data'] = $this->extraData;
    }
    CRM_Stripe_BAO_StripePaymentintent::create($stripePaymentintentParams);

    $resultObject->data = [
      'requires_payment_method' => false,
      'requires_action' => false,
      'success' => false,
      'paymentIntent' => null,
    ];
    // generatePaymentResponse()
    if ($intent->status === 'requires_action' &&
      $intent->next_action->type === 'use_stripe_sdk') {
      // Tell the client to handle the action
      $resultObject->ok = TRUE;
      $resultObject->data['requires_action'] = true;
      $resultObject->data['paymentIntentClientSecret'] = $intent->client_secret;
      $resultObject->data['paymentIntent'] = ['id' => $intent->id];
    }
    elseif (($intent->status === 'requires_capture') || ($intent->status === 'requires_confirmation')) {
      // paymentIntent = requires_capture / requires_confirmation
      // The payment intent has been confirmed, we just need to capture the payment
      // Handle post-payment fulfillment
      $resultObject->ok = TRUE;
      $resultObject->data['success'] = true;
      $resultObject->data['paymentIntent'] = ['id' => $intent->id];
    }
    elseif ($intent->status === 'succeeded') {
      $resultObject->ok = TRUE;
      $resultObject->data['success'] = true;
      $resultObject->data['paymentIntent'] = ['id' => $intent->id];
    }
    elseif ($intent->status === 'requires_payment_method') {
      $resultObject->ok = TRUE;
      $resultObject->data['requires_payment_method'] = true;
      $resultObject->data['paymentIntentClientSecret'] = $intent->client_secret;
    }
    else {
      // Invalid status
      if (isset($intent->last_payment_error->message)) {
        $message = E::ts('Payment failed: %1', [1 => $intent->last_payment_error->message]);
      }
      else {
        $message = E::ts('Payment failed.');
      }
      $resultObject->ok = FALSE;
      $resultObject->message = $message;
    }
    return $resultObject;
  }

}
