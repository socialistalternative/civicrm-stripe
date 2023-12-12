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

namespace Civi\Stripe;
use CRM_Stripe_ExtensionUtil as E;

class Api {

  use \CRM_Core_Payment_MJWIPNTrait;

  public function __construct($paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * @param string $name The key of the required value
   * @param string $dataType The datatype of the required value (eg. String)
   * @param \Stripe\StripeObject|\PropertySpy $stripeObject
   *
   * @return int|mixed|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function getValueFromStripeObject(string $name, string $dataType, $stripeObject) {
    $value = \CRM_Stripe_Api::getObjectParam($name, $stripeObject);
    $value = \CRM_Utils_Type::validate($value, $dataType, FALSE);
    return $value;
  }

  /**
   * @param string $chargeID
   * @param \Stripe\StripeObject $stripeObject
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function getDetailsFromBalanceTransaction(string $chargeID, $stripeObject = NULL): array {
    if ($stripeObject && ($stripeObject->object !== 'charge') && (!empty($chargeID))) {
      $charge = $this->getPaymentProcessor()->stripeClient->charges->retrieve($chargeID);
      $balanceTransactionID = $this->getValueFromStripeObject('balance_transaction', 'String', $charge);
    }
    else {
      $balanceTransactionID = $this->getValueFromStripeObject('balance_transaction', 'String', $stripeObject);
    }
    try {
      $balanceTransaction = $this->getPaymentProcessor()->stripeClient->balanceTransactions->retrieve($balanceTransactionID);
    }
    catch (\Exception $e) {
      throw new \Civi\Payment\Exception\PaymentProcessorException("Error retrieving balanceTransaction {$balanceTransactionID}. " . $e->getMessage());
    }
    if (!empty($balanceTransactionID)) {
      $fee = $this->getPaymentProcessor()
        ->getFeeFromBalanceTransaction($balanceTransaction, $this->getValueFromStripeObject('currency', 'String', $stripeObject));
      return [
        'fee_amount' => $fee,
        'available_on' => \CRM_Stripe_Api::formatDate($balanceTransaction->available_on),
        'exchange_rate' => $balanceTransaction->exchange_rate,
        'charge_amount' => $this->getValueFromStripeObject('amount', 'Float', $stripeObject),
        'charge_currency' => $this->getValueFromStripeObject('currency', 'String', $stripeObject),
        'payout_amount' => $balanceTransaction->amount / 100,
        'payout_currency' => \CRM_Stripe_Api::formatCurrency($balanceTransaction->currency),
      ];
    }
    else {
      return [
        'fee_amount' => 0.0
      ];
    }
  }

  /**
   * @param string $subscriptionID
   * @param array $itemsData
   *   Array of \Stripe\SubscriptionItem
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function calculateItemsForSubscription(string $subscriptionID, array $itemsData) {
    $calculatedItems = [];
    // Recalculate amount and update
    foreach ($itemsData as $item) {
      $subscriptionItem['subscriptionItemID'] = $this->getValueFromStripeObject('id', 'String', $item);
      $subscriptionItem['quantity'] = $this->getValueFromStripeObject('quantity', 'Int', $item);
      $subscriptionItem['unit_amount'] = $this->getValueFromStripeObject('unit_amount', 'Float', $item->price);

      $calculatedItem['currency'] = $this->getValueFromStripeObject('currency', 'String', $item->price);
      $calculatedItem['amount'] = $subscriptionItem['unit_amount'] * $subscriptionItem['quantity'];
      if ($this->getValueFromStripeObject('type', 'String', $item->price) === 'recurring') {
        $calculatedItem['frequency_unit'] = $this->getValueFromStripeObject('recurring_interval', 'String', $item->price);
        $calculatedItem['frequency_interval'] = $this->getValueFromStripeObject('recurring_interval_count', 'Int', $item->price);
      }

      if (empty($calculatedItem['frequency_unit'])) {
        \Civi::log('stripe')->warning("StripeIPN: {$subscriptionID} customer.subscription.updated:
            Non recurring subscription items are not supported");
      }
      else {
        $intervalKey = $calculatedItem['currency'] . '_' . $calculatedItem['frequency_unit'] . '_' . $calculatedItem['frequency_interval'];
        if (isset($calculatedItems[$intervalKey])) {
          // If we have more than one subscription item with the same currency and frequency add up the amounts and combine.
          $calculatedItem['amount'] += ($calculatedItems[$intervalKey]['amount'] ?? 0);
          $calculatedItem['subscriptionItem'] = $calculatedItems[$intervalKey]['subscriptionItem'];
        }
        $calculatedItem['subscriptionItem'][] = $subscriptionItem;
        $calculatedItems[$intervalKey] = $calculatedItem;
      }
    }
    return $calculatedItems;
  }

}
