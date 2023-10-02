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

use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentprocessorWebhook;
use Civi\Payment\Exception\PaymentProcessorException;

/**
 * Class CRM_Core_Payment_StripeIPN
 */
class CRM_Core_Payment_StripeIPN {

  use CRM_Core_Payment_MJWIPNTrait;

  /**
   * @var \CRM_Core_Payment_Stripe Payment processor
   */
  protected $_paymentProcessor;

  /**
   * The data provided by the IPN
   * Redeclared here to tighten up the var type
   *
   * @var \Stripe\StripeObject
   */
  protected $data;

  /**
   * The CiviCRM contact ID that maps to the Stripe customer
   *
   * @var int
   */
  protected $contactID = NULL;

  // Properties of the event.

  /**
   * @var string The Stripe Subscription ID
   */
  protected $subscription_id = NULL;

  /**
   * @var string The Stripe Customer ID
   */
  protected $customer_id = NULL;

  /**
   * @var string The Stripe PaymentIntent ID
   */
  protected $payment_intent_id = NULL;

  /**
   * @var string The Stripe Charge ID
   */
  protected $charge_id = NULL;

  /**
   * @var string The stripe Invoice ID (mapped to trxn_id on a contribution for recurring contributions)
   */
  protected $invoice_id = NULL;

  /**
   * @var string The date/time the charge was made
   */
  protected $receive_date = NULL;

  /**
   * @var float The amount paid
   */
  protected $amount = 0.0;

  /**
   * @var float The fee charged by Stripe
   */
  protected $fee = 0.0;

  /**
   * @var array The current contribution (linked to Stripe charge(single)/invoice(subscription)
   */
  protected $contribution = NULL;

  /**
   * @var bool
   */
  protected $setInputParametersHasRun = FALSE;

  /**
   * Normally if any exception is thrown in processing a webhook it is
   * caught and a simple error logged.
   *
   * In a test environment it is often helpful for it to throw the exception instead.
   *
   * @var bool.
   */
  public $exceptionOnFailure = FALSE;

  /**
   * Redeclared here to tighten up the var type
   * Can't define return because unit tests return PropertySpy
   * Should be \Stripe\StripeObject|PropertySpy with PHP8.0+
   *
   * @return \Stripe\StripeObject
   */
  public function getData() {
    return $this->data;
  }

  public function __construct(?CRM_Core_Payment_Stripe $paymentObject = NULL) {
    if ($paymentObject !== NULL && !($paymentObject instanceof CRM_Core_Payment_Stripe)) {
      // This would be a coding error.
      throw new Exception(__CLASS__ . " constructor requires CRM_Core_Payment_Stripe object (or NULL for legacy use).");
    }
    $this->_paymentProcessor = $paymentObject;
  }

  /**
   * Returns TRUE if we handle this event type, FALSE otherwise
   * @param string $eventType
   *
   * @return bool
   */
  public function setEventType($eventType) {
    $this->eventType = $eventType;
    if (!in_array($this->eventType, CRM_Stripe_Webhook::getDefaultEnabledEvents())) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Set and initialise the paymentProcessor object
   * @param int $paymentProcessorID
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function setPaymentProcessor($paymentProcessorID) {
    try {
      $this->_paymentProcessor = \Civi\Payment\System::singleton()->getById($paymentProcessorID);
    }
    catch (Exception $e) {
      $this->exception('Failed to get payment processor');
    }
  }

  /**
   * @return string|null
   */
  public function getStripeCustomerID() {
    return $this->customer_id;
  }

  /**
   * @return string|null
   */
  public function getStripeSubscriptionID() {
    return $this->subscription_id;
  }

  /**
   * @return string|null
   */
  public function getStripeInvoiceID() {
    return $this->invoice_id;
  }

  /**
   * @return string|null
   */
  public function getStripeChargeID() {
    return $this->charge_id;
  }

  /**
   * Check, decode, validate webhook data and extract some parameters to the class.
   */
  public function setInputParameters() {
    if ($this->setInputParametersHasRun) {
      return;
    }

    // If we don't have a webhook signing secret we need to retrieve the event again
    // to make sure that it is "real" and was not faked.
    if ($this->getVerifyData()) {
      /** @var \Stripe\Event $event */
      $event = $this->getPaymentProcessor()->stripeClient->events->retrieve($this->eventID);
      $this->setData($event->data);
    }

    // If we have a webhook signing secret data was already set on the class.
    $data = $this->getData();
    if (!is_object($data)) {
      $this->exception('Invalid input data (not an object)');
    }

    // When we receive a charge.X webhook event and it has an invoice ID we expand the invoice object
    //   so that we have the subscription ID.
    //   We'll receive both invoice.payment_succeeded/failed and charge.succeeded/failed at the same time
    //   and we need to make sure we don't process them at the same time or we can get deadlocks/race conditions
    //   that cause processing to fail.
    if (($data->object instanceof \Stripe\Charge) && !empty($data->object->invoice)) {
      $data->object = $this->getPaymentProcessor()->stripeClient->charges->retrieve(
        $this->getData()->object->id,
        ['expand' => ['invoice']]
      );
      $this->setData($data);
      $this->subscription_id = CRM_Stripe_Api::getObjectParam('subscription_id', $this->getData()->object->invoice);
      $this->invoice_id = CRM_Stripe_Api::getObjectParam('invoice_id', $this->getData()->object->invoice);
    }
    else {
      $this->subscription_id = $this->retrieve('subscription_id', 'String', FALSE);
      $this->invoice_id = $this->retrieve('invoice_id', 'String', FALSE);
    }

    $this->charge_id = $this->retrieve('charge_id', 'String', FALSE);
    $this->payment_intent_id = $this->retrieve('payment_intent_id', 'String', FALSE);
    $this->customer_id = $this->retrieve('customer_id', 'String', FALSE);

    $this->setInputParametersHasRun = TRUE;
  }

  /**
   * Get a parameter from the Stripe data object
   *
   * @param string $name
   * @param string $type
   * @param bool $abort
   *
   * @return int|mixed|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function retrieve($name, $type, $abort = TRUE) {
    $value = CRM_Stripe_Api::getObjectParam($name, $this->getData()->object);
    $value = CRM_Utils_Type::validate($value, $type, FALSE);
    if ($abort && $value === NULL) {
      echo "Failure: Missing or invalid parameter " . CRM_Utils_Type::escape($name, 'String');
      $this->exception("Missing or invalid parameter {$name}");
    }
    return $value;
  }

  /**
   * Get a unique identifier string based on webhook data.
   *
   * @return string
   */
  private function getWebhookUniqueIdentifier() {
    return "{$this->payment_intent_id}:{$this->charge_id}:{$this->invoice_id}:{$this->subscription_id}";
  }

  /**
   * When CiviCRM receives a Stripe webhook call this method (via handlePaymentNotification()).
   * This checks the webhook and either queues or triggers processing (depending on existing webhooks in queue)
   *
   * Set default to "process immediately". This will get changed to FALSE if we already
   *   have a pending webhook in the queue or the webhook is flagged for delayed processing.
   * @param bool $processWebhook
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Stripe\Exception\UnknownApiErrorException
   */
  public function onReceiveWebhook($processWebhook = TRUE): bool {
    if (!in_array($this->eventType, CRM_Stripe_Webhook::getDefaultEnabledEvents())) {
      // We don't handle this event, return 200 OK so Stripe does not retry.
      return TRUE;
    }

    $test = $this->getPaymentProcessor()->getPaymentProcessor()['is_test'] ? '(Test)' : '(Live)';
    $name = $this->getPaymentProcessor()->getPaymentProcessor()['name'];
    // Special case if this is the test webhook
    if (substr($this->getEventID(), -15, 15) === '_00000000000000') {
      echo "Test webhook from Stripe ({$this->getEventID()}) received successfully by CiviCRM: {$name} {$test}.";
      exit();
    }

    // Check, decode, validate webhook data and extract some parameters to the class
    $this->setInputParameters();

    // If we have both Stripe (elements) and Stripe Checkout setup it is quite likely that
    //   we have two payment processors with the same private/public key and we'll receive "duplicate" webhooks.
    // So if we have a Stripe Customer ID with the event check that it matches our payment processor ID as recorded
    //   in the civicrm_stripe_customers table.
    if (!empty($this->getStripeCustomerID())) {
      $stripeCustomers = \Civi\Api4\StripeCustomer::get(FALSE)
        ->addWhere('customer_id', '=', $this->getStripeCustomerID())
        ->execute();
      $eventForThisPaymentProcessor = FALSE;
      foreach ($stripeCustomers as $stripeCustomer) {
        if ($stripeCustomer['processor_id'] === $this->getPaymentProcessor()->getID()) {
          // We have a customer in the database for this processor - continue processing
          $eventForThisPaymentProcessor = TRUE;
          break;
        }
      }
      if (!$eventForThisPaymentProcessor) {
        echo "Event ({$this->getEventID()}) is not for this payment processor - ignoring. CiviCRM: {$name} {$test}.";
        exit();
      }
    }

    // Get a "unique" identifier for this webhook that allows us to match "duplicate/similar" webhooks.
    $uniqueIdentifier = $this->getWebhookUniqueIdentifier();

    // Get all received webhooks with matching identifier which have not been processed
    // This returns all webhooks that match the uniqueIdentifier above and have not been processed.
    // For example this would match both invoice.finalized and invoice.payment_succeeded events which must be
    // processed sequentially and not simultaneously.
    $paymentProcessorWebhooks = PaymentprocessorWebhook::get(FALSE)
      ->addWhere('payment_processor_id', '=', $this->getPaymentProcessor()->getID())
      ->addWhere('identifier', '=', $uniqueIdentifier)
      ->addWhere('processed_date', 'IS NULL')
      ->execute();

    if (empty($paymentProcessorWebhooks->rowCount)) {
      // We have not received this webhook before.
      // Some webhooks we always add to the queue and do not process immediately (eg. invoice.finalized)
      if (in_array($this->eventType, CRM_Stripe_Webhook::getDelayProcessingEvents())) {
        // Never process the webhook immediately.
        $processWebhook = FALSE;
      }
    }
    else {
      // We already have one or more webhooks with matching identifier
      foreach ($paymentProcessorWebhooks as $paymentProcessorWebhook) {
        // Does the eventType match our webhook?
        if ($paymentProcessorWebhook['trigger'] === $this->eventType) {
          // We have already recorded a webhook with a matching event type and it is awaiting processing.
          // Exit
          return TRUE;
        }
        if (!in_array($paymentProcessorWebhook['trigger'], CRM_Stripe_Webhook::getDelayProcessingEvents())) {
          // There is a webhook that is already in the queue not flagged for delayed processing.
          //   So we cannot process the current webhook immediately and must add it to the queue instead.
          $processWebhook = FALSE;
        }
      }
      // We have recorded another webhook with matching identifier but different eventType.
      // There is already a recorded webhook with matching identifier that has not yet been processed.
      // So we will record this webhook but will not process now (it will be processed later by the scheduled job).
    }

    $newWebhookEvent = PaymentprocessorWebhook::create(FALSE)
      ->addValue('payment_processor_id', $this->getPaymentProcessor()->getID())
      ->addValue('trigger', $this->getEventType())
      ->addValue('identifier', $uniqueIdentifier)
      ->addValue('event_id', $this->getEventID())
      ->addValue('data', $this->getData())
      ->execute()
      ->first();

    // Check the number of webhooks to be processed does not exceed connection-limit
    $toBeProcessedWebhook = PaymentprocessorWebhook::get(FALSE)
        ->addWhere('payment_processor_id', '=', $this->getPaymentProcessor()->getID())
        ->addWhere('processed_date', 'IS NULL')
        ->execute();

    // Limit on webhooks that will be processed immediately. Otherwise we delay execution.
    $webhookProcessingLimit = (int)\Civi::settings()->get('stripe_webhook_processing_limit');
    if (!$processWebhook || ($toBeProcessedWebhook->rowCount > $webhookProcessingLimit)) {
        return TRUE;
    }

    return $this->processQueuedWebhookEvent($newWebhookEvent);
  }

  /**
   * Process a single queued event and update it.
   *
   * @param array $webhookEvent
   *
   * @return bool TRUE on success.
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function processQueuedWebhookEvent(array $webhookEvent) :bool {
    $this->setEventID($webhookEvent['event_id']);
    if (!$this->setEventType($webhookEvent['trigger'])) {
      // We don't handle this event
      return FALSE;
    };
    // @todo consider storing webhook data when received.
    $this->setVerifyData(TRUE);
    $this->setExceptionMode(FALSE);
    if (isset($emailReceipt)) {
      $this->setSendEmailReceipt($emailReceipt);
    }

    $processingResult = $this->processWebhookEvent();
    // Update the stored webhook event.
    PaymentprocessorWebhook::update(FALSE)
      ->addWhere('id', '=', $webhookEvent['id'])
      ->addValue('status', $processingResult->ok ? 'success' : 'error')
      ->addValue('message', preg_replace('/^(.{250}).*/su', '$1 ...', $processingResult->message))
      ->addValue('processed_date', 'now')
      ->execute();

    return $processingResult->ok;
  }

  /**
   * Process the given webhook
   *
   * @return stdClass
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function processWebhookEvent() :stdClass {
    $return = (object) ['message' => '', 'ok' => FALSE, 'exception' => NULL];

    try {
      $this->setInputParameters();

      $webhookEventProcessor = new \Civi\Stripe\Webhook\Events($this->getPaymentProcessor()->getID());
      $webhookEventProcessor->setEventType($this->getEventType());
      $webhookEventProcessor->setEventID($this->getEventID());
      $webhookEventProcessor->setData($this->getData());

      switch ($this->eventType) {
        case 'checkout.session.completed':
          $return = $webhookEventProcessor->doCheckoutSessionCompleted();
          break;

        case 'charge.succeeded':
        case 'charge.captured':
          $return = $webhookEventProcessor->doChargeSucceeded();
          break;

        case 'charge.refunded':
          $return = $webhookEventProcessor->doChargeRefunded();
          break;

        case 'charge.failed':
          $return = $webhookEventProcessor->doChargeFailed();
          break;

        case 'invoice.payment_failed':
          $return = $webhookEventProcessor->doInvoicePaymentFailed();
          break;

        case 'invoice.finalized':
          $return = $webhookEventProcessor->doInvoiceFinalized();
          break;

        case 'invoice.paid':
        case 'invoice.payment_succeeded':
          $return = $webhookEventProcessor->doInvoicePaid();
          break;

        case 'customer.subscription.updated':
          $return = $webhookEventProcessor->doCustomerSubscriptionUpdated();
          break;

        case 'customer.subscription.deleted':
          $return = $webhookEventProcessor->doCustomerSubscriptionDeleted();
          break;

        default:
          $return->message = $this->eventType . ' - not implemented';
          $return->ok = TRUE;
      }

    }
    catch (Exception $e) {
      if ($this->exceptionOnFailure) {
        // Re-throw a modified exception. (Special case for phpunit testing).
        $return->message = get_class($e) . ": " . $e->getMessage();
        throw new PaymentProcessorException($return->message, $e->getCode());
      }
      else {
        // Normal use.
        $return->ok = FALSE;
        if (($e instanceof \Stripe\Exception\InvalidRequestException) && ($e->getHttpStatus() === 404)) {
          /** @var \Stripe\Exception\InvalidRequestException $e */
          // Probably "is no longer available because it's aged out of our retention policy"
          // We don't need a backtrace
          $return->message = $e->getMessage();
        }
        else {
          $return->message = $e->getMessage() . "\n" . $e->getTraceAsString();
        }
        $return->exception = $e;
        \Civi::log()->error("StripeIPN: processWebhookEvent failed. EventID: {$this->eventID} : " . $return->message);
      }
    }

    $this->setEventID('');
    return $return;
  }

}
