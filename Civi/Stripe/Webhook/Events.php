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

namespace Civi\Stripe\Webhook;
use Brick\Money\Money;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use CRM_Stripe_ExtensionUtil as E;

class Events {

  use \CRM_Core_Payment_MJWIPNTrait;

  /**
   * @var \Civi\Stripe\Api
   */
  private $api;

  public function __construct(int $paymentProcessorID) {
    $this->setPaymentProcessor($paymentProcessorID);
    $this->api = new \Civi\Stripe\Api($this->_paymentProcessor);
  }

  /**
   * @param string $eventID
   *
   * @return void
   */
  public function setEventID(string $eventID): void {
    $this->eventID = $eventID;
  }

  /**
   * @param string $eventType
   *
   * @return void
   */
  public function setEventType(string $eventType): void {
    $this->eventType = $eventType;
  }

  /**
   * @param \Stripe\StripeObject|\PropertySpy $data
   *
   * @return void
   */
  public function setData($data): void {
    $this->data = $data;
  }

  /**
   * @return \stdClass
   */
  private function getResultObject() {
    $return = new \stdClass();
    $return->message = '';
    $return->ok = FALSE;
    $return->exception = NULL;
    return $return;
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
   * @param string $chargeID Optional, one of chargeID, invoiceID, subscriptionID must be specified
   * @param string $invoiceID
   * @param string $subscriptionID
   * @param string $paymentIntentID
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function findContribution(string $chargeID = '', string $invoiceID = '', string $subscriptionID = '', string $paymentIntentID = ''): array {
    $paymentParams = [
      'contribution_test' => $this->getPaymentProcessor()->getIsTestMode(),
    ];

    // A) One-off contribution
    if (!empty($chargeID)) {
      $paymentParams['trxn_id'] = $chargeID;
      $contributionApi3 = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
    }

    // A2) trxn_id = paymentIntentID if set by checkout.session.completed
    if (empty($contributionApi3['count'])) {
      if (!empty($paymentIntentID)) {
        $paymentParams['trxn_id'] = $paymentIntentID;
        $contributionApi3 = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
      }
    }

    // B2) Contribution linked to subscription and we have invoice_id
    // @todo there is a case where $contribution is not defined (i.e. if charge_id is empty)
    if (empty($contributionApi3['count'])) {
      unset($paymentParams['trxn_id']);
      if (!empty($invoiceID)) {
        $paymentParams['order_reference'] = $invoiceID;
        $contributionApi3 = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
      }
    }

    // B1) Contribution linked to subscription and we have subscription_id
    // @todo there is a case where $contribution is not defined (i.e. if charge_id, invoice_id are empty)
    if (empty($contributionApi3['count'])) {
      unset($paymentParams['trxn_id']);
      if (!empty($subscriptionID)) {
        $paymentParams['order_reference'] = $subscriptionID;
        $contributionApi3 = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
      }
    }

    // @todo there is a case where $contribution is not defined (i.e. if charge_id, invoice_id, subscription_id are empty)
    if (empty($contributionApi3['count'])) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->getPaymentProcessor()->getPaymentProcessorLabel() . 'No matching contributions for event ' . $this->getEventID();
        \Civi::log('stripe')->debug($message);
      }
      $result = [];
      \CRM_Mjwshared_Hook::webhookEventNotMatched('stripe', $this, 'contribution_not_found', $result);
      if (empty($result['contribution'])) {
        return [];
      }
      $contribution = $result['contribution'];
    }
    else {
      $contribution = $contributionApi3['values'][$contributionApi3['id']];
    }
    return $contribution ?? [];
  }

  /**
   * This allows us to end a subscription once:
   *   a) We've reached the end date / number of installments
   *   b) The recurring contribution is marked as completed
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function handleInstallmentsForSubscription(string $subscriptionID = '', int $contributionRecurID = NULL) {
    // Check that we have both contributionRecurID and subscriptionID
    if ((empty($contributionRecurID)) || (empty($subscriptionID))) {
      return;
    }

    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contributionRecurID)
      ->execute()
      ->first();

    // Do we have an end date?
    if (empty($contributionRecur['end_date'])) {
      return;
    }

    // There is no easy way of retrieving a count of all invoices for a subscription so we ignore the "installments"
    //   parameter for now and rely on checking end_date (which was calculated based on number of installments...)
    // if (empty($contributionRecur['installments'])) { return; }

    $stripeSubscription = $this->getPaymentProcessor()->stripeClient->subscriptions->retrieve($subscriptionID);
    // If we've passed the end date cancel the subscription
    if (($stripeSubscription->current_period_end >= strtotime($contributionRecur['end_date']))
      || ($contributionRecur['contribution_status_id']
        == \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Completed'))) {
      $this->getPaymentProcessor()->stripeClient->subscriptions->update($subscriptionID, ['cancel_at_period_end' => TRUE]);
      $this->updateRecurCompleted(['id' => $contributionRecurID]);
    }
  }

  /**
   * Get the recurring contribution from the Stripe subscription ID
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getRecurFromSubscriptionID($subscriptionID): array {
    if (empty($subscriptionID)) {
      return [];
    }

    // Get the recurring contribution record associated with the Stripe subscription.
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionID)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();
    if (empty($contributionRecur)) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->getPaymentProcessor()->getPaymentProcessorLabel() . ': ' . $this->getEventID() . ': Cannot find recurring contribution for subscription ID: ' . $subscriptionID;
        \Civi::log('stripe')->debug($message);
      }
      return [];
    }

    return $contributionRecur;
  }

  /**
   * Create the next contribution for a recurring contribution
   * This happens when Stripe generates a new invoice and notifies us (normally by invoice.finalized but
   * invoice.payment_succeeded sometimes arrives first).
   *
   * @param string $chargeID
   * @param string $invoiceID
   * @param array $contributionRecur
   *
   * @return int
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function createNextContributionForRecur(string $chargeID, string $invoiceID, array $contributionRecur): int {
    // We have a recurring contribution but no contribution so we'll repeattransaction
    // Stripe has generated a new invoice (next payment in a subscription) so we
    //   create a new contribution in CiviCRM
    $balanceTransactionDetails = $this->api->getDetailsFromBalanceTransaction($chargeID, $this->getData()->object);
    $repeatContributionParams = [
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_status_id' => \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
      'receive_date' => $this->api->getValueFromStripeObject('receive_date', 'String', $this->getData()->object),
      'order_reference' => $invoiceID,
      'trxn_id' => $chargeID,
      'total_amount' => $this->api->getValueFromStripeObject('amount', 'String', $this->getData()->object),
      // 'fee_amount' Added below via $balanceTransactionDetails
    ];
    foreach ($balanceTransactionDetails as $key => $value) {
      $repeatContributionParams[$key] = $value;
    }
    return $this->repeatContribution($repeatContributionParams);
    // Don't touch the contributionRecur as it's updated automatically by Contribution.repeattransaction
  }

  /**
   * Format the message that is returned from the event processor
   *
   * @param string $function
   * @param string $message
   * @param array $entityIDs
   *
   * @return string
   */
  private function formatResultMessage(string $function, string $message, array $entityIDs = []): string {
    $resultMessage = $function . ': ' . $message;
    if (!empty($entityIDs)) {
      $entityStrings = [];
      foreach ($entityIDs as $entityName => $entityID) {
        $entityStrings[] = $entityName . ':' . $entityID;
      }
      $resultMessage = $resultMessage . '. ' . implode(';', $entityStrings);
    }

    return $resultMessage;
  }

  /**
   * Webhook event: charge.succeeded / charge.captured
   * We process charge.succeeded per charge.captured
   *
   * @return \stdClass
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function doChargeSucceeded(): \stdClass {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object->object ?? '') !== 'charge') {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Invalid object type');
      return $return;
    }

    // For a recurring contribution we can process charge.succeeded once we receive the event with an invoice ID.
    // For a single contribution we can't process charge.succeeded because it only triggers BEFORE the charge is captured
    if (empty($this->api->getValueFromStripeObject('customer_id', 'String', $this->getData()->object))) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'not processing because no customer_id');
      $return->ok = TRUE;
      return $return;
    }

    $chargeID = $this->api->getValueFromStripeObject('charge_id', 'String', $this->getData()->object);
    if (!$chargeID) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Missing charge_id');
      return $return;
    }
    $paymentIntentID = $this->api->getValueFromStripeObject('payment_intent_id', 'String', $this->getData()->object);
    $invoiceID = $this->api->getValueFromStripeObject('invoice_id', 'String', $this->getData()->object);

    $contribution = $this->findContribution($chargeID, $invoiceID, '', $paymentIntentID);
    if (empty($contribution)) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'ignoring - contribution not found');
      $return->ok = TRUE;
      return $return;
    }

    // For a single contribution we have to use charge.captured because it has the customer_id.

    // We only process charge.captured for one-off contributions (see invoice.paid/invoice.payment_succeeded for recurring)
    if (!empty($contribution['contribution_recur_id'])) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'ignoring - contribution has recur');
      $return->ok = TRUE;
      return $return;
    }

    // We only process charge.captured for one-off contributions
    if (empty($this->api->getValueFromStripeObject('captured', 'Boolean', $this->getData()->object))) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'ignoring - charge not captured');
      $return->ok = TRUE;
      return $return;
    }

    $pendingContributionStatusID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $failedContributionStatusID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
    $completedContributionStatusID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    $statusesAllowedToComplete = [$pendingContributionStatusID, $failedContributionStatusID];

    // If contribution is in Pending or Failed state record payment and transition to Completed
    if (in_array($contribution['contribution_status_id'], $statusesAllowedToComplete)) {
      $balanceTransactionDetails = $this->api->getDetailsFromBalanceTransaction($chargeID, $this->getData()->object);
      $contributionParams = [
        'contribution_id' => $contribution['id'],
        'trxn_date' => $this->api->getValueFromStripeObject('receive_date', 'String', $this->getData()->object),
        'order_reference' => !empty($invoiceID) ? $invoiceID : $chargeID,
        'trxn_id' => $chargeID,
        'total_amount' => $this->api->getValueFromStripeObject('amount', 'Float', $this->getData()->object),
        // 'fee_amount' Added below via $balanceTransactionDetails
      ];
      foreach ($balanceTransactionDetails as $key => $value) {
        $contributionParams[$key] = $value;
      }

      $this->updateContributionCompleted($contributionParams);
    }
    elseif ($contribution['contribution_status_id'] === $completedContributionStatusID) {
      // By this point we should have a contribution and a completed payment
      $financialTrxn = \Civi\Api4\FinancialTrxn::get(FALSE)
        ->addSelect('*', 'custom.*')
        ->addWhere('trxn_id', '=', $chargeID)
        ->addWhere('is_payment', '=', TRUE)
        ->addWhere('status_id:name', '=', 'Completed')
        ->execute()
        ->first();
      $return->message = $this->formatResultMessage(__FUNCTION__, 'already completed. No additional payment details added', ['coid' => $contribution['id']]);
      if (empty($financialTrxn['Payment_details.available_on'])) {
        $balanceTransactionDetails = $this->api->getDetailsFromBalanceTransaction($chargeID, $this->getData()->object);
        foreach ($balanceTransactionDetails as $key => $value) {
          $paymentParams[$key] = $value;
        }

        $customFields = \Civi\Api4\CustomField::get(FALSE)
          ->addWhere('custom_group_id:name', '=', 'Payment_details')
          ->execute()
          ->indexBy('name');
        foreach ($customFields as $key => $value) {
          if (isset($paymentParams[$key])) {
            $customParams['custom_' . $value['id']] = $paymentParams[$key];
          }
        }
        if (!empty($customParams)) {
          $customParams['entity_id'] = $financialTrxn['id'];
          civicrm_api3('CustomValue', 'create', $customParams);
          $return->message = $this->formatResultMessage(__FUNCTION__, 'already completed. Added additional payment details', ['coid' => $contribution['id']]);
        }
      }
      $return->ok = TRUE;
      return $return;
    }

    $return->message = $this->formatResultMessage(__FUNCTION__, '', ['coid' => $contribution['id']]);
    $return->ok = TRUE;
    return $return;
  }

  /**
   * Webhook event: charge.refunded
   * Process the charge.refunded event from Stripe
   *
   * @return \stdClass
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function doChargeRefunded(): \stdClass {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object->object ?? '') !== 'charge') {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Invalid object type');
      return $return;
    }

    // Cancelling an uncaptured paymentIntent triggers charge.refunded but we don't want to process that
    if (empty($this->api->getValueFromStripeObject('captured', 'Boolean', $this->getData()->object))) {
      $return->ok = TRUE;
      return $return;
    }

    // Charge ID is required
    $chargeID = $this->api->getValueFromStripeObject('charge_id', 'String', $this->getData()->object);
    if (!$chargeID) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Missing charge_id');
      return $return;
    }

    // Invoice ID is optional
    $invoiceID = $this->api->getValueFromStripeObject('invoice_id', 'String', $this->getData()->object);

    // This gives us the refund date + reason code
    $refunds = $this->getPaymentProcessor()->stripeClient->refunds->all(['charge' => $chargeID, 'limit' => 1]);
    $refund = $refunds->data[0];

    // Stripe does not refund fees - see https://support.stripe.com/questions/understanding-fees-for-refunded-payments
    // This gives us the actual amount refunded
    $amountRefunded = $this->api->getValueFromStripeObject('amount_refunded', 'Float', $this->getData()->object);

    // Get the CiviCRM contribution that matches the Stripe metadata we have from the event
    $contribution = $this->findContribution($chargeID, $invoiceID);
    if (empty($contribution)) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Contribution not found');
      return $return;
    }

    if (isset($contribution['payments'])) {
      foreach ($contribution['payments'] as $payment) {
        if ($payment['trxn_id'] === $refund->id) {
          $return->message = $this->formatResultMessage(__FUNCTION__, 'Refund already recorded in CiviCRM', ['refund ID' => $refund->id]);
          $return->ok = TRUE;
          return $return;
        }
        if ($payment['trxn_id'] === $chargeID) {
          // This triggers the financial transactions/items to be updated correctly.
          $cancelledPaymentID = $payment['id'];
        }
      }
    }

    $refundParams = [
      'contribution_id' => $contribution['id'],
      'total_amount' => 0 - abs($amountRefunded),
      'trxn_date' => date('YmdHis', $refund->created),
      'trxn_result_code' => $refund->reason,
      'fee_amount' => 0,
      'trxn_id' => $refund->id,
      'order_reference' => $invoiceID,
    ];

    if (!empty($cancelledPaymentID)) {
      $refundParams['cancelled_payment_id'] = $cancelledPaymentID;
    }

    $lock = \Civi::lockManager()->acquire('data.contribute.contribution.' . $refundParams['contribution_id']);
    if (!$lock->isAcquired()) {
      \Civi::log('stripe')->error('Could not acquire lock to record refund for contribution: ' . $refundParams['contribution_id']);
    }
    $refundPayment = civicrm_api3('Payment', 'get', [
      'trxn_id' => $refundParams['trxn_id'],
      'total_amount' => $refundParams['total_amount'],
    ]);
    if (!empty($refundPayment['count'])) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Refund already recorded', ['coid' => $contribution['id']]);
      $return->ok = TRUE;
    }
    else {
      $this->updateContributionRefund($refundParams);
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Refund recorded', ['coid' => $contribution['id']]);
      $return->ok = TRUE;
    }
    $lock->release();
    return $return;
  }

  /**
   * Webhook event: charge.failed
   * One-time donation and per invoice payment
   *
   * @return \stdClass
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doChargeFailed(): \stdClass {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object->object ?? '') !== 'charge') {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Invalid object type');
      return $return;
    }

    // If we don't have a customer_id we can't do anything with it!
    // It's quite likely to be a fraudulent/spam so we ignore.
    if (empty($this->api->getValueFromStripeObject('customer_id', 'String', $this->getData()->object))) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'ignoring - no customer_id');
      $return->ok = TRUE;
      return $return;
    }

    // Charge ID is required
    $chargeID = $this->api->getValueFromStripeObject('charge_id', 'String', $this->getData()->object);
    if (!$chargeID) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Missing charge_id');
      return $return;
    }

    $paymentIntentID = $this->api->getValueFromStripeObject('payment_intent_id', 'String', $this->getData()->object);
    // Invoice ID is optional
    $invoiceID = $this->api->getValueFromStripeObject('invoice_id', 'String', $this->getData()->object);

    $contribution = $this->findContribution($chargeID, $invoiceID, '', $paymentIntentID);
    if (empty($contribution)) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Contribution not found');
      return $return;
    }

    $failedContributionParams = [
      'contribution_id' => $contribution['id'],
      'cancel_date' => $this->api->getValueFromStripeObject('receive_date', 'String', $this->getData()->object),
      'cancel_reason' => $this->api->getValueFromStripeObject('failure_message', 'String', $this->getData()->object),
    ];
    // Fallback from invoiceID to chargeID. We can't use ?? because invoiceID might be empty string ie. '' and not NULL
    $failedContributionParams['order_reference'] = empty($invoiceID) ? $chargeID : $invoiceID;
    $this->updateContributionFailed($failedContributionParams);

    $return->message = $this->formatResultMessage(__FUNCTION__, '', ['coid' => $contribution['id']]);
    $return->ok = TRUE;
    return $return;

  }

  /**
   * Webhook event: checkout.session.completed
   *
   * @return \stdClass
   */
  public function doCheckoutSessionCompleted(): \stdClass {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object->object ?? '') !== 'checkout.session') {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Invalid object type');
      return $return;
    }

    // Invoice ID is required
    $clientReferenceID = $this->api->getValueFromStripeObject('client_reference_id', 'String', $this->getData()->object);
    if (!$clientReferenceID) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Missing client_reference_id');
      return $return;
    }

    $contribution = Contribution::get(FALSE)
      ->addWhere('invoice_id', '=', $clientReferenceID)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();
    if (empty($contribution)) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'contribution not found for client_reference_id');
      return $return;
    }

    // For one-off we have a paymentintentID
    $paymentIntentID = $this->api->getValueFromStripeObject('payment_intent_id', 'String', $this->getData()->object);

    // For subscription we have invoice + subscription
    $invoiceID = $this->api->getValueFromStripeObject('invoice_id', 'String', $this->getData()->object);
    $subscriptionID = $this->api->getValueFromStripeObject('subscription_id', 'String', $this->getData()->object);

    if (!empty($invoiceID)) {
      $contributionTrxnID = $invoiceID;
    }
    elseif (!empty($paymentIntentID)) {
      $contributionTrxnID = $paymentIntentID;
    }
    else {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Missing invoiceID or paymentIntentID');
      return $return;
    }
    Contribution::update(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->addValue('trxn_id', $contributionTrxnID)
      ->execute();

    if (!empty($subscriptionID) && !empty($contribution['contribution_recur_id'])) {
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $contribution['contribution_recur_id'])
        ->addValue('processor_id', $subscriptionID)
        ->execute();
    }

    // charge.succeeded often arrives before checkout.session.completed and we have no way
    //   to match it to a contribution so it will be ignored.
    // Now we have processed checkout.session.completed see if we need to process
    //   charge.succeeded again.
    $chargeSucceededWebhook = \Civi\Api4\PaymentprocessorWebhook::get(FALSE)
      ->addSelect('id')
      ->addWhere('identifier', 'CONTAINS', $paymentIntentID)
      ->addWhere('trigger', '=', 'charge.succeeded')
      ->addWhere('status', '=', 'success')
      ->addOrderBy('created_date', 'DESC')
      ->execute()
      ->first();
    if (!empty($chargeSucceededWebhook)) {
      // Flag charge.succeeded for re-processing
      \Civi\Api4\PaymentprocessorWebhook::update(FALSE)
        ->addValue('status', 'new')
        ->addValue('processed_date', NULL)
        ->addWhere('id', '=', $chargeSucceededWebhook['id'])
        ->execute();
      $return->message = $this->formatResultMessage(__FUNCTION__, 'charge.succeeded flagged for re-process', ['coid' => $contribution['id']]);
    }
    else {
      $return->message = $this->formatResultMessage(__FUNCTION__, '', ['coid' => $contribution['id']]);
    }

    $return->ok = TRUE;
    return $return;
  }

  /**
   * Webhook event: invoice.paid / invoice.payment_succeeded
   * Invoice changed to paid. This is nearly identical to invoice.payment_succeeded
   *
   * The invoice.payment_successful type Event object is created and sent to any webhook endpoints configured
   *   to accept that type of Event when the PaymentIntent powering the payment of an Invoice is used successfully.
   * The invoice.paid type Event object is created and sent to any webhook endpoints configured to accept that
   *   type of Event when the Invoice object has its paid property modified to a "true" value
   *   (see https://stripe.com/docs/api/invoices/object#invoice_object-paid).
   *
   * Successful recurring payment. Either we are completing an existing contribution or it's the next one in a subscription
   *
   * @return \stdClass
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doInvoicePaid(): \stdClass {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object->object ?? '') !== 'invoice') {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Invalid object type');
      return $return;
    }

    // Invoice ID is required
    $invoiceID = $this->api->getValueFromStripeObject('invoice_id', 'String', $this->getData()->object);
    if (!$invoiceID) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Missing invoice_id');
      return $return;
    }

    $chargeID = $this->api->getValueFromStripeObject('charge_id', 'String', $this->getData()->object);
    $subscriptionID = $this->api->getValueFromStripeObject('subscription_id', 'String', $this->getData()->object);
    $contributionRecur = $this->getRecurFromSubscriptionID($subscriptionID);
    if (empty($contributionRecur)) {
      $return->message = $this->formatResultMessage(__FUNCTION__, E::ts('No contributionRecur record found in CiviCRM. Ignored'));
      $return->ok = TRUE;
      return $return;
    }

    // Acquire the lock to find/create contribution
    $lock = \Civi::lockManager()->acquire('data.contribute.contribution.' . $invoiceID);
    if (!$lock->isAcquired()) {
      \Civi::log('stripe')->error('Could not acquire lock to record ' . $this->getEventType() . ' for Stripe InvoiceID: ' . $invoiceID);
    }

    // We *normally/ideally* expect to be able to find the contribution,
    // since the logical order of events would be invoice.finalized first which
    // creates a contribution; then invoice.payment_succeeded/paid following, which would
    // find it.
    $contribution = $this->findContribution($chargeID, $invoiceID, $subscriptionID);
    if (empty($contribution)) {
      // We were unable to locate the Contribution; it could be the next one in a subscription.
      if (empty($contributionRecur['id'])) {
        // Hmmm. We could not find the contribution recur record either. Silently ignore this event(!)
        $return->ok = TRUE;
        $return->message = $this->formatResultMessage(__FUNCTION__, E::ts('No contribution or recur record found in CiviCRM. Ignored'));
        return $return;
      }
      else {
        // We have a recurring contribution but have not yet received invoice.finalized so we don't have the next contribution yet.
        // invoice.payment_succeeded sometimes comes before invoice.finalized so trigger the same behaviour here to create a new contribution

        $contributionID = $this->createNextContributionForRecur($chargeID, $invoiceID, $contributionRecur);
        // Now get the contribution we just created.
        $contribution = Contribution::get(FALSE)
          ->addWhere('id', '=', $contributionID)
          ->execute()
          ->first();
      }
    }
    // Release the lock to find/create contribution
    $lock->release();

    // Now acquire lock to record payment on the contribution
    $lock = \Civi::lockManager()->acquire('data.contribute.contribution.' . $contribution['id']);
    if (!$lock->isAcquired()) {
      \Civi::log('stripe')->error('Could not acquire lock to record ' . $this->getEventType() . ' for contribution: ' . $contribution['id']);
    }

    // By this point we should have a contribution
    if (civicrm_api3('Mjwpayment', 'get_payment', [
        'trxn_id' => $chargeID,
        'status_id' => 'Completed',
      ])['count'] > 0) {
      // Payment already recorded
      $return->ok = TRUE;
      $return->message = $this->formatResultMessage(__FUNCTION__, E::ts('Payment already recorded'), ['coid' => $contribution['id']]);
      $lock->release();
      return $return;
    }

    $pendingContributionStatusID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $failedContributionStatusID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
    $statusesAllowedToComplete = [$pendingContributionStatusID, $failedContributionStatusID];

    // If contribution is in Pending or Failed state record payment and transition to Completed
    if (in_array($contribution['contribution_status_id'], $statusesAllowedToComplete)) {
      $balanceTransactionDetails = $this->api->getDetailsFromBalanceTransaction($chargeID, $this->getData()->object);
      $contributionParams = [
        'contribution_id' => $contribution['id'],
        'trxn_date' => $this->api->getValueFromStripeObject('receive_date', 'String', $this->getData()->object),
        'order_reference' => $invoiceID,
        'trxn_id' => $chargeID,
        'total_amount' => $this->api->getValueFromStripeObject('amount', 'String', $this->getData()->object),
        // 'fee_amount' Added below via $balanceTransactionDetails
        'contribution_status_id' => $contribution['contribution_status_id'],
      ];
      foreach ($balanceTransactionDetails as $key => $value) {
        $contributionParams[$key] = $value;
      }

      $this->updateContributionCompleted($contributionParams);
      // Don't touch the contributionRecur as it's updated automatically by Contribution.completetransaction
    }
    $lock->release();

    $this->handleInstallmentsForSubscription($subscriptionID, $contributionRecur['id']);
    $return->message = $this->formatResultMessage(__FUNCTION__, '', ['coid' => $contribution['id']]);
    $return->ok = TRUE;
    return $return;
  }

  /**
   * Webhook event: invoice.finalized
   *
   * @return \stdClass
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function doInvoiceFinalized(): \stdClass {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object->object?? '') !== 'invoice') {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Invalid object type');
      return $return;
    }

    // Invoice ID is required
    $invoiceID = $this->api->getValueFromStripeObject('invoice_id', 'String', $this->getData()->object);
    if (!$invoiceID) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Missing invoice_id');
      return $return;
    }

    $chargeID = $this->api->getValueFromStripeObject('charge_id', 'String', $this->getData()->object);
    $subscriptionID = $this->api->getValueFromStripeObject('subscription_id', 'String', $this->getData()->object);
    $contributionRecur = $this->getRecurFromSubscriptionID($subscriptionID);
    if (empty($contributionRecur)) {
      $return->message = $this->formatResultMessage(__FUNCTION__, E::ts('No contributionRecur record found in CiviCRM. Ignored'));
      $return->ok = TRUE;
      return $return;
    }

    $contribution = $this->findContribution($chargeID, $invoiceID, $subscriptionID);

    // An invoice has been created and finalized (ready for payment)
    // This usually happens automatically through a Stripe subscription
    if (empty($contribution)) {
      // Unable to find a Contribution.
      $this->createNextContributionForRecur($chargeID, $invoiceID, $contributionRecur);
      $return->ok = TRUE;
      return $return;
    }

    // For a future recur start date we setup the initial contribution with the
    // Stripe subscriptionID because we didn't have an invoice.
    // Now we do we can map subscription_id to invoice_id so payment can be recorded
    // via subsequent IPN requests (eg. invoice.payment_succeeded)
    if ($contribution['trxn_id'] === $subscriptionID) {
      $this->updateContribution([
        'contribution_id' => $contribution['id'],
        'trxn_id' => $invoiceID,
      ]);
    }
    $return->message = $this->formatResultMessage(__FUNCTION__, '', ['coid' => $contribution['id']]);
    $return->ok = TRUE;
    return $return;
  }

  /**
   * Webhook event: invoice.payment_failed
   * Failed recurring payment. Either we are failing an existing contribution or it's the next one in a subscription
   *
   * @return \stdClass
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function doInvoicePaymentFailed(): \stdClass {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object->object?? '') !== 'invoice') {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Invalid object type');
      return $return;
    }

    // Invoice ID is required
    $invoiceID = $this->api->getValueFromStripeObject('invoice_id', 'String', $this->getData()->object);
    if (!$invoiceID) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Missing invoice_id');
      return $return;
    }

    $chargeID = $this->api->getValueFromStripeObject('charge_id', 'String', $this->getData()->object);

    // Get the CiviCRM contribution that matches the Stripe metadata we have from the event
    $contribution = $this->findContribution($chargeID, $invoiceID);
    if (empty($contribution)) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Contribution not found');
      return $return;
    }

    $pendingContributionStatusID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

    if ($contribution['contribution_status_id'] == $pendingContributionStatusID) {
      // If this contribution is Pending, set it to Failed.

      // To obtain the failure_message we need to look up the charge object
      $failureMessage = '';

      if ($chargeID) {
        $stripeCharge = $this->getPaymentProcessor()->stripeClient->charges->retrieve($chargeID);
        $failureMessage = $this->api->getValueFromStripeObject('failure_message', 'String', $stripeCharge);
        $failureMessage = is_string($failureMessage) ? $failureMessage : '';
      }

      $receiveDate = $this->api->getValueFromStripeObject('receive_date', 'String', $this->getData()->object);
      $params = [
        'contribution_id' => $contribution['id'],
        'order_reference' => $invoiceID,
        'cancel_date' => $receiveDate,
        'cancel_reason'   => $failureMessage,
      ];
      $this->updateContributionFailed($params);
    }
    $return->message = $this->formatResultMessage(__FUNCTION__, '', ['coid' => $contribution['id']]);
    $return->ok = TRUE;
    return $return;
  }

  /**
   * Subscription is cancelled.
   *
   * @return \stdClass
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doCustomerSubscriptionDeleted(): \stdClass {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object->object ?? '') !== 'subscription') {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Invalid object type');
      return $return;
    }

    $subscriptionID = $this->api->getValueFromStripeObject('subscription_id', 'String', $this->getData()->object);
    if (!$subscriptionID) {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Missing subscription_id');
      return $return;
    }

    $contributionRecur = $this->getRecurFromSubscriptionID($subscriptionID);
    if (empty($contributionRecur)) {
      // Subscription was not found in CiviCRM
      $result = [];
      \CRM_Mjwshared_Hook::webhookEventNotMatched('stripe', $this, 'subscription_not_found', $result);
      if (empty($result['contributionRecur'])) {
        $return->message = $this->formatResultMessage(__FUNCTION__, E::ts('No contributionRecur record found in CiviCRM. Ignored'));
        $return->ok = TRUE;
        return $return;
      }
      else {
        $contributionRecur = $result['contributionRecur'];
      }
    }

    // Cancel the recurring contribution
    $this->updateRecurCancelled(['id' => $contributionRecur['id'], 'cancel_date' => $this->api->getValueFromStripeObject('cancel_date', 'String', $this->getData()->object)]);

    $return->message = $this->formatResultMessage(__FUNCTION__, 'cancelled', ['crid' => $contributionRecur['id']]);
    $return->ok = TRUE;
    return $return;
  }

  /**
   * Subscription is updated. We don't currently do anything with this
   *
   * @return \stdClass
   */
  public function doCustomerSubscriptionUpdated(): \stdClass {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object->object ?? '') !== 'subscription') {
      $return->message = $this->formatResultMessage(__FUNCTION__, 'Invalid object type');
      return $return;
    }

    $subscriptionID = $this->api->getValueFromStripeObject('subscription_id', 'String', $this->getData()->object);

    $contributionRecur = $this->getRecurFromSubscriptionID($subscriptionID);
    if (empty($contributionRecur)) {
      // Subscription was not found in CiviCRM
      $result = [];
      \CRM_Mjwshared_Hook::webhookEventNotMatched('stripe', $this, 'subscription_not_found', $result);
      if (empty($result['contributionRecur'])) {
        $return->message = $this->formatResultMessage(__FUNCTION__, E::ts('No contributionRecur record found in CiviCRM. Ignored'));
        $return->ok = TRUE;
        return $return;
      }
      $contributionRecur = $result['contributionRecur'];
    }

    if (!isset($this->getData()->previous_attributes)) {
      // Nothing changed?!
      $return->message = $this->formatResultMessage(__FUNCTION__, E::ts('No changes. Ignored'));
      $return->ok = TRUE;
      return $return;
    }

    // First work out what changed. This is held in "previous_attributes" on webhook data
    $previousAttributes = $this->getData()->previous_attributes;
    // Simple check that we actually have some items data
    // Otherwise it could just be a metadata change which we are not interested in.
    $amountHasChanged = FALSE;
    if (!empty($previousAttributes->items->data)) {
      $amountHasChanged = TRUE;
    }

    if ($amountHasChanged) {
      $objectData = $this->getData()->object;
      $calculatedItems = $this->api->calculateItemsForSubscription($subscriptionID, $objectData);
    }

    // $calculatedItems now contains array of new prices by key [currency]_[frequency_unit]_[frequency_interval]
    // Eg. $calculatedItems[usd_month_1] = [
    //       'currency' => 'usd',
    //       'amount' => '2000', (amount is in pence)
    //     ];

    // Now check if recurring contribution matches frequency

    $contributionRecurKey = mb_strtolower($contributionRecur['currency']) . "_{$contributionRecur['frequency_unit']}_{$contributionRecur['frequency_interval']}";
    if (isset($calculatedItems[$contributionRecurKey])) {
      $calculatedItem = $calculatedItems[$contributionRecurKey];
      $templateContribution = \CRM_Contribute_BAO_ContributionRecur::getTemplateContribution($contributionRecur['id']);
      if (!Money::of($calculatedItem['amount'], mb_strtoupper($calculatedItem['currency']))
        ->isAmountAndCurrencyEqualTo(Money::of($templateContribution['total_amount'], $templateContribution['currency']))) {
        // Create a new template contribution to update the amount
        \Civi\Api4\ContributionRecur::updateAmountOnRecurMJW(FALSE)
          ->addWhere('id', '=', $contributionRecur['id'])
          ->addValue('amount', $calculatedItem['amount'])
          ->execute();
        $return->message = $this->formatResultMessage(__FUNCTION__, 'recur: ' . $contributionRecur['id'] . '; new amount: ' . $calculatedItem['amount'] . ' currency: ' . $calculatedItem['currency']);
      }
    }

    $return->ok = TRUE;
    return $return;
  }

}
