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
use Civi\Api4\Contribution;
use CRM_Stripe_ExtensionUtil as E;

class Events {

  use \CRM_Core_Payment_MJWIPNTrait;

  /**
   * @var \CRM_Core_Payment_Stripe Payment processor
   */
  private $paymentProcessor;

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
   * @param string $name The key of the required value
   * @param string $dataType The datatype of the required value (eg. String)
   *
   * @return int|mixed|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  private function getValueFromStripeObject(string $name, string $dataType) {
    $value = \CRM_Stripe_Api::getObjectParam($name, $this->getData()->object);
    $value = \CRM_Utils_Type::validate($value, $dataType, FALSE);
    return $value;
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
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function findContribution(string $chargeID = '', string $invoiceID = '', string $subscriptionID = ''): array {
    $paymentParams = [
      'contribution_test' => $this->getPaymentProcessor()->getIsTestMode(),
    ];

    // A) One-off contribution
    if (!empty($chargeID)) {
      $paymentParams['trxn_id'] = $chargeID;
      $contributionApi3 = civicrm_api3('Mjwpayment', 'get_contribution', $paymentParams);
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
        \Civi::log()->debug($message);
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
   * @param string $chargeID
   *
   * @return float
   * @throws \CRM_Core_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  private function getFeeFromCharge(string $chargeID) {
    if (($this->getData()->object['object'] !== 'charge') && (!empty($chargeID))) {
      $charge = $this->getPaymentProcessor()->stripeClient->charges->retrieve($chargeID);
      $balanceTransactionID = \CRM_Stripe_Api::getObjectParam('balance_transaction', $charge);
    }
    else {
      $balanceTransactionID = $this->getValueFromStripeObject('balance_transaction', 'String');
    }
    return $this->getPaymentProcessor()->getFeeFromBalanceTransaction($balanceTransactionID, $this->getValueFromStripeObject('currency', 'String'));
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

    $contributionRecur = \Civi\Api4\ContributionRecur::get(FALSE)
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
    $contributionRecur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionID)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();
    if (empty($contributionRecur)) {
      if ((bool)\Civi::settings()->get('stripe_ipndebug')) {
        $message = $this->getPaymentProcessor()->getPaymentProcessorLabel() . ': ' . $this->getEventID() . ': Cannot find recurring contribution for subscription ID: ' . $subscriptionID;
        \Civi::log()->debug($message);
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
    $repeatContributionParams = [
      'contribution_recur_id' => $contributionRecur['id'],
      'contribution_status_id' => \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
      'receive_date' => $this->getValueFromStripeObject('receive_date', 'String'),
      'order_reference' => $invoiceID,
      'trxn_id' => $chargeID,
      'total_amount' => $this->getValueFromStripeObject('amount', 'String'),
      'fee_amount' => $this->getFeeFromCharge($chargeID),
    ];
    return $this->repeatContribution($repeatContributionParams);
    // Don't touch the contributionRecur as it's updated automatically by Contribution.repeattransaction
  }

  /**
   * Process the charge.refunded event from Stripe
   *
   * @return \stdClass
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function doChargeRefunded() {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object['object'] ?? '') !== 'charge') {
      $return->message = 'doChargeRefunded Invalid object type';
      return $return;
    }

    // Cancelling an uncaptured paymentIntent triggers charge.refunded but we don't want to process that
    if (empty(\CRM_Stripe_Api::getObjectParam('captured', $this->getData()->object))) {
      $return->ok = TRUE;
      return $return;
    }

    // Charge ID is required
    $chargeID = $this->getValueFromStripeObject('charge_id', 'String');
    if (!$chargeID) {
      $return->message = 'doChargeRefunded Missing charge_id';
      return $return;
    }

    // Invoice ID is optional
    $invoiceID = $this->getValueFromStripeObject('invoice_id', 'String');

    // This gives us the refund date + reason code
    $refunds = $this->getPaymentProcessor()->stripeClient->refunds->all(['charge' => $chargeID, 'limit' => 1]);
    $refund = $refunds->data[0];

    // Stripe does not refund fees - see https://support.stripe.com/questions/understanding-fees-for-refunded-payments
    // This gives us the actual amount refunded
    $amountRefunded = \CRM_Stripe_Api::getObjectParam('amount_refunded', $this->getData()->object);

    // Get the CiviCRM contribution that matches the Stripe metadata we have from the event
    $contribution = $this->findContribution($chargeID, $invoiceID);
    if (empty($contribution)) {
      $return->message = 'doChargeRefunded Contribution not found';
      return $return;
    }

    if (isset($contribution['payments'])) {
      foreach ($contribution['payments'] as $payment) {
        if ($payment['trxn_id'] === $refund->id) {
          $return->message = 'Refund ' . $refund->id . ' already recorded in CiviCRM';
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
      \Civi::log()->error('Could not acquire lock to record refund for contribution: ' . $refundParams['contribution_id']);
    }
    $refundPayment = civicrm_api3('Payment', 'get', [
      'trxn_id' => $refundParams['trxn_id'],
      'total_amount' => $refundParams['total_amount'],
    ]);
    if (!empty($refundPayment['count'])) {
      $return->message = 'OK - refund already recorded';
      $return->ok = TRUE;
    }
    else {
      $this->updateContributionRefund($refundParams);
      $return->message = 'OK - refund recorded';
      $return->ok = TRUE;
    }
    $lock->release();
    return $return;
  }

  /**
   * Webhook event: checkout.session.completed
   *
   * @return void
   */
  public function doCheckoutSessionCompleted() {
    $return = $this->getResultObject();

    $session = $this->getData()->object;
    $subscriptionID = $this->getValueFromStripeObject('subscription', 'String');
    $subscriptionID = $session->subscription;

        # Find the subscription or save it to your database.
        # invoice.paid may have fired before this so there
        # could already be a subscription.
        find_or_create_subscription($subscription_id);
        http_response_code(200);
        return;
  }

  /**
   * Webhook event: invoice.paid
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
  public function doInvoicePaid() {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object['object'] ?? '') !== 'invoice') {
      $return->message = __FUNCTION__ . ' Invalid object type';
      return $return;
    }

    // Invoice ID is required
    $invoiceID = $this->getValueFromStripeObject('invoice_id', 'String');
    if (!$invoiceID) {
      $return->message = __FUNCTION__ . ' Missing invoice_id';
      return $return;
    }

    $chargeID = $this->getValueFromStripeObject('charge_id', 'String');
    $subscriptionID = $this->getValueFromStripeObject('subscription_id', 'String');
    $contributionRecur = $this->getRecurFromSubscriptionID($subscriptionID);
    if (empty($contributionRecur)) {
      $return->message = __FUNCTION__ . ': ' . E::ts('No contributionRecur record found in CiviCRM. Ignored.');
      $return->ok = TRUE;
      return $return;
    }

    // Acquire the lock to find/create contribution
    $lock = \Civi::lockManager()->acquire('data.contribute.contribution.' . $invoiceID);
    if (!$lock->isAcquired()) {
      \Civi::log()->error('Could not acquire lock to record ' . $this->getEventType() . ' for Stripe InvoiceID: ' . $invoiceID);
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
        $return->message = __FUNCTION__ . ': ' . E::ts('No contribution or recur record found in CiviCRM. Ignored.');
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
      \Civi::log()->error('Could not acquire lock to record ' . $this->getEventType() . ' for contribution: ' . $contribution['id']);
    }

    // By this point we should have a contribution
    if (civicrm_api3('Mjwpayment', 'get_payment', [
        'trxn_id' => $chargeID,
        'status_id' => 'Completed',
      ])['count'] > 0) {
      // Payment already recorded
      $return->ok = TRUE;
      $return->message = E::ts('Payment already recorded');
      return $return;
    }

    $pendingContributionStatusID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $failedContributionStatusID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
    $statusesAllowedToComplete = [$pendingContributionStatusID, $failedContributionStatusID];

    // If contribution is in Pending or Failed state record payment and transition to Completed
    if (in_array($contribution['contribution_status_id'], $statusesAllowedToComplete)) {
      $contributionParams = [
        'contribution_id' => $contribution['id'],
        'trxn_date' => $this->getValueFromStripeObject('receive_date', 'String'),
        'order_reference' => $invoiceID,
        'trxn_id' => $chargeID,
        'total_amount' => $this->getValueFromStripeObject('amount', 'String'),
        'fee_amount' => $this->getFeeFromCharge($chargeID),
        'contribution_status_id' => $contribution['contribution_status_id'],
      ];
      $this->updateContributionCompleted($contributionParams);
      // Don't touch the contributionRecur as it's updated automatically by Contribution.completetransaction
    }
    $lock->release();

    $this->handleInstallmentsForSubscription($subscriptionID, $contributionRecur['id']);
    $return->ok = TRUE;
    return $return;
  }

  /**
   * invoice.finalized
   *
   * @return \stdClass
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function doInvoiceFinalized() {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object['object'] ?? '') !== 'invoice') {
      $return->message = __FUNCTION__ . ' Invalid object type';
      return $return;
    }

    // Invoice ID is required
    $invoiceID = $this->getValueFromStripeObject('invoice_id', 'String');
    if (!$invoiceID) {
      $return->message = __FUNCTION__ . ' Missing invoice_id';
      return $return;
    }

    $chargeID = $this->getValueFromStripeObject('charge_id', 'String');
    $subscriptionID = $this->getValueFromStripeObject('subscription_id', 'String');
    $contributionRecur = $this->getRecurFromSubscriptionID($subscriptionID);
    if (empty($contributionRecur)) {
      $return->message = __FUNCTION__ . ': ' . E::ts('No contributionRecur record found in CiviCRM. Ignored.');
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
  public function doInvoicePaymentFailed() {
    $return = $this->getResultObject();

    // Check we have the right data object for this event
    if (($this->getData()->object['object'] ?? '') !== 'invoice') {
      $return->message = __FUNCTION__ . ' Invalid object type';
      return $return;
    }

    // Invoice ID is required
    $invoiceID = $this->getValueFromStripeObject('invoice_id', 'String');
    if (!$invoiceID) {
      $return->message = __FUNCTION__ . ' Missing invoice_id';
      return $return;
    }

    $chargeID = $this->getValueFromStripeObject('charge_id', 'String');

    // Get the CiviCRM contribution that matches the Stripe metadata we have from the event
    $contribution = $this->findContribution($chargeID, $invoiceID);
    if (empty($contribution)) {
      $return->message = __FUNCTION__ . ' Contribution not found';
      return $return;
    }

    $pendingContributionStatusID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

    if ($contribution['contribution_status_id'] == $pendingContributionStatusID) {
      // If this contribution is Pending, set it to Failed.

      // To obtain the failure_message we need to look up the charge object
      $failureMessage = '';

      if ($chargeID) {
        $stripeCharge = $this->getPaymentProcessor()->stripeClient->charges->retrieve($chargeID);
        $failureMessage = \CRM_Stripe_Api::getObjectParam('failure_message', $stripeCharge);
        $failureMessage = is_string($failureMessage) ? $failureMessage : '';
      }

      $receiveDate = $this->getValueFromStripeObject('receive_date', 'String');
      $params = [
        'contribution_id' => $contribution['id'],
        'order_reference' => $invoiceID,
        'cancel_date' => $receiveDate,
        'cancel_reason'   => $failureMessage,
      ];
      $this->updateContributionFailed($params);
    }
    $return->ok = TRUE;
    return $return;
  }

}
