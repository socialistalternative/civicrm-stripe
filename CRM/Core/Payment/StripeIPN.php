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
      $event = $this->_paymentProcessor->stripeClient->events->retrieve($this->eventID);
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
      $data->object = $this->_paymentProcessor->stripeClient->charges->retrieve(
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
    return "{$this->charge_id}:{$this->invoice_id}:{$this->subscription_id}";
  }

  /**
   * When CiviCRM receives a Stripe webhook call this method (via handlePaymentNotification()).
   * This checks the webhook and either queues or triggers processing (depending on existing webhooks in queue)
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Stripe\Exception\UnknownApiErrorException
   */
  public function onReceiveWebhook(): bool {
    if (!in_array($this->eventType, CRM_Stripe_Webhook::getDefaultEnabledEvents())) {
      // We don't handle this event, return 200 OK so Stripe does not retry.
      return TRUE;
    }

    // Now re-retrieve the data from Stripe to ensure it's legit.
    // Special case if this is the test webhook
    if (substr($this->getEventID(), -15, 15) === '_00000000000000') {
      $test = (boolean) $this->_paymentProcessor->getPaymentProcessor()['is_test'] ? '(Test)' : '(Live)';
      $name = $this->_paymentProcessor->getPaymentProcessor()['name'];
      echo "Test webhook from Stripe ({$this->getEventID()}) received successfully by CiviCRM: {$name} {$test}.";
      exit();
    }

    // Check, decode, validate webhook data and extract some parameters to the class
    $this->setInputParameters();

    $uniqueIdentifier = $this->getWebhookUniqueIdentifier();

    // Get all received webhooks with matching identifier which have not been processed
    // This returns all webhooks that match the uniqueIdentifier above and have not been processed.
    // For example this would match both invoice.finalized and invoice.payment_succeeded events which must be
    // processed sequentially and not simultaneously.
    $paymentProcessorWebhooks = PaymentprocessorWebhook::get(FALSE)
      ->addWhere('payment_processor_id', '=', $this->_paymentProcessor->getID())
      ->addWhere('identifier', '=', $uniqueIdentifier)
      ->addWhere('processed_date', 'IS NULL')
      ->execute();

    // Set default to "process immediately". This will get changed to FALSE if we already
    //   have a pending webhook in the queue or the webhook is flagged for delayed processing.
    $processWebhook = TRUE;

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
      ->addValue('payment_processor_id', $this->_paymentProcessor->getID())
      ->addValue('trigger', $this->getEventType())
      ->addValue('identifier', $uniqueIdentifier)
      ->addValue('event_id', $this->getEventID())
      ->addValue('data', $this->getData())
      ->execute()
      ->first();

    // Check the number of webhooks to be processed does not exceed connection-limit
    $toBeProcessedWebhook = PaymentprocessorWebhook::get(FALSE)
        ->addWhere('payment_processor_id', '=', $this->_paymentProcessor->getID())
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
   * @return bool
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function processWebhookEvent() :StdClass {
    $return = (object) ['message' => '', 'ok' => FALSE, 'exception' => NULL];
    try {
      $this->setInputParameters();
      switch ($this->eventType) {
        case 'charge.refunded':
          $return->ok = TRUE;
          $return->message = $this->doChargeRefunded();
          break;

        default:
          $return->ok = $this->processEventType();
      }

    }
    catch (Exception $e) {
      if ($this->exceptionOnFailure) {
        // Re-throw a modified exception. (Special case for phpunit testing).
        $return->message = get_class($e) . ": " . $e->getMessage();
        throw new PaymentProcessorException($return->message, $e->getCode(), $e);
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

  /**
   * Process the received event in CiviCRM
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  private function processEventType() {
    $pendingContributionStatusID = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $failedContributionStatusID = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
    $statusesAllowedToComplete = [$pendingContributionStatusID, $failedContributionStatusID];

    // NOTE: If you add an event here make sure you add it to the webhook or it will never be received!
    switch($this->eventType) {
      case 'invoice.finalized':
        // An invoice has been created and finalized (ready for payment)
        // This usually happens automatically through a Stripe subscription
        if (!$this->setInfo()) {
          // Unable to find a Contribution.
          if (!$this->contribution_recur_id) {
            // We don't have a matching contribution or a recurring contribution - this was probably created outside of CiviCRM
            // @todo In the future we may want to match the customer->contactID and create a contribution to match.
            return TRUE;
          }
          else {
            $this->createNextContributionForRecur();
          }
          return TRUE;
        }
        // For a future recur start date we setup the initial contribution with the
        // Stripe subscriptionID because we didn't have an invoice.
        // Now we do we can map subscription_id to invoice_id so payment can be recorded
        // via subsequent IPN requests (eg. invoice.payment_succeeded)
        if ($this->contribution['trxn_id'] === $this->subscription_id) {
          $this->updateContribution([
            'contribution_id' => $this->contribution['id'],
            'trxn_id' => $this->invoice_id,
          ]);
        }
        break;

      case 'invoice.payment_succeeded':
        // Successful recurring payment. Either we are completing an existing contribution or it's the next one in a subscription
        //
        // We *normally/ideally* expect to be able to find the contribution via setInfo(),
        // since the logical order of events would be invoice.finalized first which
        // creates a contribution; then invoice.payment_succeeded following, which would
        // find it.
        if (!$this->setInfo()) {
          // We were unable to locate the Contribution; it could be the next one in a subscription.
          if (!$this->contribution_recur_id) {
            // Hmmm. We could not find the contribution recur record either. Silently ignore this event(!)
            return TRUE;
          }
          else {
            // We have a recurring contribution but have not yet received invoice.finalized so we don't have the next contribution yet.
            // invoice.payment_succeeded sometimes comes before invoice.finalized so trigger the same behaviour here to create a new contribution
            $this->createNextContributionForRecur();
            // Now get the contribution we just created.
            $this->getContribution();
          }
        }
        if (civicrm_api3('Mjwpayment', 'get_payment', [
            'trxn_id' => $this->charge_id,
            'status_id' => 'Completed',
          ])['count'] > 0) {
          // Payment already recorded
          return TRUE;
        }

        // If contribution is in Pending or Failed state record payment and transition to Completed
        if (in_array($this->contribution['contribution_status_id'], $statusesAllowedToComplete)) {
          $params = [
            'contribution_id' => $this->contribution['id'],
            'trxn_date' => $this->receive_date,
            'order_reference' => $this->invoice_id,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
            'contribution_status_id' => $this->contribution['contribution_status_id'],
          ];
          $this->updateContributionCompleted($params);
          // Don't touch the contributionRecur as it's updated automatically by Contribution.completetransaction
        }
        $this->handleInstallmentsForSubscription();
        return TRUE;

      case 'invoice.payment_failed':
        // Failed recurring payment. Either we are failing an existing contribution or it's the next one in a subscription
        if (!$this->setInfo()) {
          return TRUE;
        }

        if ($this->contribution['contribution_status_id'] == $pendingContributionStatusID) {
          // If this contribution is Pending, set it to Failed.

          // To obtain the failure_message we need to look up the charge object
          $failureMessage = '';
          if ($this->charge_id) {
            $stripeCharge = $this->_paymentProcessor->stripeClient->charges->retrieve($this->charge_id);
            $failureMessage = CRM_Stripe_Api::getObjectParam('failure_message', $stripeCharge);
            $failureMessage = is_string($failureMessage) ? $failureMessage : '';
          }

          $params = [
            'contribution_id' => $this->contribution['id'],
            'order_reference' => $this->invoice_id,
            'cancel_date' => $this->receive_date,
            'cancel_reason'   => $failureMessage,
          ];
          $this->updateContributionFailed($params);
        }
        return TRUE;

      // One-time donation and per invoice payment.
      case 'charge.failed':
        // If we don't have a customer_id we can't do anything with it!
        // It's quite likely to be a fraudulent/spam so we ignore.
        if (empty(CRM_Stripe_Api::getObjectParam('customer_id', $this->getData()->object))) {
          return TRUE;
        }

        if (!$this->setInfo()) {
          // We could not find this contribution.
          return TRUE;
        }
        $params = [
          'contribution_id' => $this->contribution['id'],
          'order_reference' => $this->invoice_id ?? $this->charge_id,
          'cancel_date' => $this->receive_date,
          'cancel_reason' => $this->retrieve('failure_message', 'String'),
        ];
        $this->updateContributionFailed($params);
        return TRUE;

      // case 'charge.refunded': Handled via doChargeRefunded();

      case 'charge.succeeded':
        // For a recurring contribution we can process charge.succeeded once we receive the event with an invoice ID.
        // For a single contribution we can't process charge.succeeded because it only triggers BEFORE the charge is captured
        if (empty(CRM_Stripe_Api::getObjectParam('customer_id', $this->getData()->object))) {
          return TRUE;
        }

      // Deliberately missing break here because we process charge.succeeded per charge.captured
      case 'charge.captured':
        // For a single contribution we have to use charge.captured because it has the customer_id.
        if (!$this->setInfo()) {
          return TRUE;
        }

        // We only process charge.captured for one-off contributions (see invoice.paid/invoice.payment_succeeded for recurring)
        if (!empty($this->contribution['contribution_recur_id'])) {
          return TRUE;
        }

        // We only process charge.captured for one-off contributions
        if (empty(CRM_Stripe_Api::getObjectParam('captured', $this->getData()->object))) {
          return TRUE;
        }

        // If contribution is in Pending or Failed state record payment and transition to Completed
        if (in_array($this->contribution['contribution_status_id'], $statusesAllowedToComplete)) {
          $params = [
            'contribution_id' => $this->contribution['id'],
            'trxn_date' => $this->receive_date,
            'order_reference' => $this->invoice_id ?? $this->charge_id,
            'trxn_id' => $this->charge_id,
            'total_amount' => $this->amount,
            'fee_amount' => $this->fee,
            'contribution_status_id' => $this->contribution['contribution_status_id'],
          ];
          $this->updateContributionCompleted($params);
        }
        return TRUE;

      case 'customer.subscription.updated':
        // Subscription is updated. This used to be "implemented" but didn't work
        return TRUE;

      case 'customer.subscription.deleted':
        // Subscription is cancelled
        if (!$this->getSubscriptionDetails()) {
          // Subscription was not found in CiviCRM
          CRM_Mjwshared_Hook::webhookEventNotMatched('stripe', $this, 'subscription_not_found');
          return TRUE;
        }
        // Cancel the recurring contribution
        $this->updateRecurCancelled(['id' => $this->contribution_recur_id, 'cancel_date' => $this->retrieve('cancel_date', 'String', FALSE)]);
        return TRUE;
    }

    // Unhandled event
    return TRUE;
  }

  /**
   * Process the charge.refunded event from Stripe
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  private function doChargeRefunded() {
    // Cancelling an uncaptured paymentIntent triggers charge.refunded but we don't want to process that
    if (empty(CRM_Stripe_Api::getObjectParam('captured', $this->getData()->object))) {
      return TRUE;
    }

    // This gives us the refund date + reason code
    $refunds = $this->_paymentProcessor->stripeClient->refunds->all(['charge' => $this->charge_id, 'limit' => 1]);
    $refund = $refunds->data[0];

    // Stripe does not refund fees - see https://support.stripe.com/questions/understanding-fees-for-refunded-payments
    // This gets the fee refunded
    // $this->fee = $this->getPaymentProcessor()->getFeeFromBalanceTransaction($refund->balance_transaction, $this->retrieve('currency', 'String', FALSE));
    // This gives us the actual amount refunded
    $amountRefunded = CRM_Stripe_Api::getObjectParam('amount_refunded', $this->getData()->object);

    // Get the CiviCRM contribution that matches the Stripe metadata we have from the event
    $this->getContribution();

    if (isset($this->contribution['payments'])) {
      foreach ($this->contribution['payments'] as $payment) {
        if ($payment['trxn_id'] === $refund->id) {
          return 'Refund ' . $refund->id . ' already recorded in CiviCRM';
        }
        if ($payment['trxn_id'] === $this->charge_id) {
          // This triggers the financial transactions/items to be updated correctly.
          $cancelledPaymentID = $payment['id'];
        }
      }
    }

    $refundParams = [
      'contribution_id' => $this->contribution['id'],
      'total_amount' => 0 - abs($amountRefunded),
      'trxn_date' => date('YmdHis', $refund->created),
      'trxn_result_code' => $refund->reason,
      'fee_amount' => 0,
      'trxn_id' => $refund->id,
      'order_reference' => $this->invoice_id ?? NULL,
    ];

    if (!empty($cancelledPaymentID)) {
      $refundParams['cancelled_payment_id'] = $cancelledPaymentID;
    }

    $lock = Civi::lockManager()->acquire('data.contribute.contribution.' . $refundParams['contribution_id']);
    if (!$lock->isAcquired()) {
      \Civi::log()->error('Could not acquire lock to record refund for contribution: ' . $refundParams['contribution_id']);
    }
    $refundPayment = civicrm_api3('Payment', 'get', [
      'trxn_id' => $refundParams['trxn_id'],
      'total_amount' => $refundParams['total_amount'],
    ]);
    if (!empty($refundPayment['count'])) {
      $message = 'OK - refund already recorded';
    }
    else {
      $this->updateContributionRefund($refundParams);
      $message = 'OK - refund recorded';
    }
    $lock->release();
    return $message;
  }

  /**
   * Create the next contribution for a recurring contribution
   * This happens when Stripe generates a new invoice and notifies us (normally by invoice.finalized but
   * invoice.payment_succeeded sometimes arrives first).
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function createNextContributionForRecur() {
    // We have a recurring contribution but no contribution so we'll repeattransaction
    // Stripe has generated a new invoice (next payment in a subscription) so we
    //   create a new contribution in CiviCRM
    $params = [
      'contribution_recur_id' => $this->contribution_recur_id,
      'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
      'receive_date' => $this->receive_date,
      'order_reference' => $this->invoice_id,
      'trxn_id' => $this->charge_id,
      'total_amount' => $this->amount,
      'fee_amount' => $this->fee,
    ];
    $this->repeatContribution($params);
    // Don't touch the contributionRecur as it's updated automatically by Contribution.repeattransaction
  }

  /**
   * Gather and set info as class properties.
   *
   * Given the data passed to us via the Stripe Event, try to determine
   * as much as we can about this event and set that information as
   * properties to be used later.
   *
   * @return bool TRUE if we were able to find a contribution (via getContribution)
   * @throws \CRM_Core_Exception
   */
  public function setInfo() {
    if (!$this->getCustomer()) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->_paymentProcessor->getPaymentProcessorLabel() . ': ' . $this->getEventID() . ': Missing customer_id';
        Civi::log()->debug($message);
      }
  //    return FALSE;
    }

    $this->receive_date = $this->retrieve('receive_date', 'String', FALSE);
    $this->amount = $this->retrieve('amount', 'String', FALSE);

    if (($this->getData()->object->object !== 'charge') && (!empty($this->charge_id))) {
      $charge = $this->_paymentProcessor->stripeClient->charges->retrieve($this->charge_id);
      $balanceTransactionID = CRM_Stripe_Api::getObjectParam('balance_transaction', $charge);
    }
    else {
      $balanceTransactionID = CRM_Stripe_Api::getObjectParam('balance_transaction', $this->getData()->object);
    }
    $this->fee = $this->getPaymentProcessor()->getFeeFromBalanceTransaction($balanceTransactionID, $this->retrieve('currency', 'String', FALSE));

    // Get the CiviCRM recurring contribution that matches the Stripe subscription (if we have one).
    $this->getSubscriptionDetails();
    // Get the CiviCRM contribution that matches the Stripe metadata we have from the event
    return $this->getContribution();
  }

  /**
   * Get the recurring contribution from the Stripe event parameters (subscription_id)
   *   and set subscription_id, contribution_recur_id vars.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function getSubscriptionDetails() {
    if (!$this->subscription_id) {
      return FALSE;
    }

    // Get the recurring contribution record associated with the Stripe subscription.
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $this->subscription_id)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();
    if (empty($contributionRecur)) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->_paymentProcessor->getPaymentProcessorLabel() . ': ' . $this->getEventID() . ': Cannot find recurring contribution for subscription ID: ' . $this->subscription_id;
        Civi::log()->debug($message);
      }
      return FALSE;
    }
    $this->contribution_recur_id = $contributionRecur['id'];
    $this->contributionRecur = $contributionRecur;
    return TRUE;
  }

  /**
   * A) A one-off contribution will have trxn_id == stripe.charge_id
   * B) A contribution linked to a recur (stripe subscription):
   *   1. May have the trxn_id == stripe.subscription_id if the invoice was not generated at the time the contribution
   * was created
   *     (Eg. the recur was setup with a future recurring start date).
   *     This will be updated to trxn_id == stripe.invoice_id when a suitable IPN is received
   *     @todo: Which IPN events will update this?
   *   2. May have the trxn_id == stripe.invoice_id if the invoice was generated at the time the contribution was
   *   created OR the contribution has been updated by the IPN when the invoice was generated.
   *
   * @return bool
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  private function getContribution() {
    $paymentParams = [
      'contribution_test' => $this->_paymentProcessor->getIsTestMode(),
    ];

    // A) One-off contribution
    if (!empty($this->charge_id)) {
      $paymentParams['trxn_id'] = $this->charge_id;
      $contribution = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
    }

    // B2) Contribution linked to subscription and we have invoice_id
    // @todo there is a case where $contribution is not defined (i.e. if charge_id is empty)
    if (!$contribution['count']) {
      unset($paymentParams['trxn_id']);
      if (!empty($this->invoice_id)) {
        $paymentParams['order_reference'] = $this->invoice_id;
        $contribution = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
      }
    }

    // B1) Contribution linked to subscription and we have subscription_id
    // @todo there is a case where $contribution is not defined (i.e. if charge_id, invoice_id are empty)
    if (!$contribution['count']) {
      unset($paymentParams['trxn_id']);
      if (!empty($this->subscription_id)) {
        $paymentParams['order_reference'] = $this->subscription_id;
        $contribution = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
      }
    }

    // @todo there is a case where $contribution is not defined (i.e. if charge_id, invoice_id, subscription_id are empty)
    if (!$contribution['count']) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->_paymentProcessor->getPaymentProcessorLabel() . 'No matching contributions for event ' . $this->getEventID();
        Civi::log()->debug($message);
      }
      $result = [];
      CRM_Mjwshared_Hook::webhookEventNotMatched('stripe', $this, 'contribution_not_found', $result);
      if (empty($result['contribution'])) {
        return FALSE;
      }
      $this->contribution = $result['contribution'];
    }
    else {
      $this->contribution = $contribution['values'][$contribution['id']];
    }

    return TRUE;
  }

  /**
   * Get the Stripe customer details and match to the StripeCustomer record in CiviCRM
   * This gives us $this->contactID
   *
   * @return bool
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  private function getCustomer() {
    $this->customer_id = CRM_Stripe_Api::getObjectParam('customer_id', $this->getData()->object);
    if (empty($this->customer_id)) {
      $this->exception('Missing customer_id!');
    }
    try {
      $customer = civicrm_api3('StripeCustomer', 'getsingle', [
        'id' => $this->customer_id,
      ]);
      $this->contactID = $customer['contact_id'];
      if ($this->_paymentProcessor->getID() !== (int) $customer['processor_id']) {
        $this->exception("Customer ({$this->customer_id}) and payment processor ID don't match (expected: {$customer['processor_id']}, actual: {$this->_paymentProcessor->getID()})");
      }
    }
    catch (Exception $e) {
      // Customer not found in CiviCRM
      if ((bool)\Civi::settings()->get('stripe_ipndebug') && !$this->contribution) {
        $message = $this->_paymentProcessor->getPaymentProcessorLabel() . 'Stripe Customer not found in CiviCRM for event ' . $this->getEventID();
        Civi::log()->debug($message);
      }
      CRM_Mjwshared_Hook::webhookEventNotMatched('stripe', $this, 'customer_not_found');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * This allows us to end a subscription once:
   *   a) We've reached the end date / number of installments
   *   b) The recurring contribution is marked as completed
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function handleInstallmentsForSubscription() {
    if ((!$this->contribution_recur_id) || (!$this->subscription_id)) {
      return;
    }

    $contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $this->contribution_recur_id,
    ]);

    if (empty($contributionRecur['end_date'])) {
      return;
    }

    // There is no easy way of retrieving a count of all invoices for a subscription so we ignore the "installments"
    //   parameter for now and rely on checking end_date (which was calculated based on number of installments...)
    // if (empty($contributionRecur['installments'])) { return; }

    $stripeSubscription = $this->_paymentProcessor->stripeClient->subscriptions->retrieve($this->subscription_id);
    // If we've passed the end date cancel the subscription
    if (($stripeSubscription->current_period_end >= strtotime($contributionRecur['end_date']))
      || ($contributionRecur['contribution_status_id']
        == CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Completed'))) {
      $this->_paymentProcessor->stripeClient->subscriptions->update($this->subscription_id, ['cancel_at_period_end' => TRUE]);
      $this->updateRecurCompleted(['id' => $this->contribution_recur_id]);
    }
  }

}
