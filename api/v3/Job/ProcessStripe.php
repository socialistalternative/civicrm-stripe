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

use Civi\Api4\StripePaymentintent;

/**
 * This job performs various housekeeping actions related to the Stripe payment processor
 * NOT domain-specific. On multidomain only run on one domain.
 *
 * @param array $params
 *
 * @return array
 *   API result array.
 * @throws CiviCRM_API3_Exception
 */
function civicrm_api3_job_process_stripe($params) {
  // Note: "canceled" is the status from Stripe, we used to record "cancelled" so we check for both
  $results = [
    'deleted' => 0,
    'canceled' => 0,
  ];

  if ($params['delete_old'] !== 0 && !empty($params['delete_old'])) {
    // Delete all locally recorded paymentIntents that are older than 3 months

    $oldPaymentintents = StripePaymentintent::delete(FALSE)
      ->addWhere('status', 'IN', ['succeeded', 'cancelled', 'canceled', 'failed'])
      ->addWhere('created_date', '<', '-3 month')
      ->execute();
    $results['deleted'] = $oldPaymentintents->count();
  }

  if ($params['cancel_incomplete'] !== 0 && !empty($params['cancel_incomplete'])) {
    // Cancel incomplete paymentIntents after 1 hour
    $incompletePaymentintents = StripePaymentintent::get(FALSE)
      ->addWhere('status', 'NOT IN', ['succeeded', 'cancelled', 'canceled', 'failed'])
      ->addWhere('created_date', '<', $params['cancel_incomplete'])
      ->addWhere('stripe_intent_id', 'IS NOT EMPTY');
    if (!empty($params['stripe_paymentintent_id'])) {
      $incompletePaymentintents->addWhere('id', '=', $params['stripe_paymentintent_id']);
    }
    $incompletePaymentintents = $incompletePaymentintents->execute();

    $cancelledIDs = [];
    foreach ($incompletePaymentintents as $incompletePaymentintent) {
      try {
        /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
        $paymentProcessor = Civi\Payment\System::singleton()->getById($incompletePaymentintent['payment_processor_id']);
        $intent = $paymentProcessor->stripeClient->paymentIntents->retrieve($incompletePaymentintent['stripe_intent_id']);
        // Check intent was not already cancelled - this could happen eg. at Stripe before we process it
        if ($intent->status !== 'canceled') {
          $intent->cancel(['cancellation_reason' => 'abandoned']);
        }
        $cancelledIDs[] = $incompletePaymentintent['id'];
      } catch (Exception $e) {
        if ($e instanceof \Stripe\Exception\InvalidRequestException) {
          // 404 resource_missing (paymentIntent not found at Stripe)
          // 400 bad_request (paymentIntent is already in canceled state?)
          if (in_array($e->getHttpStatus(), [400, 404])) {
            $cancelledIDs[] = $incompletePaymentintent['id'];
          }
        }
        \Civi::log('stripe')->error("Stripe.process_stripe: Unable to cancel paymentIntentID: {$incompletePaymentintent['id']}: " . $e->getMessage());
      }
    }
    if (!empty($cancelledIDs)) {
      StripePaymentintent::update(FALSE)
        ->addValue('status', 'canceled')
        ->addWhere('id', 'IN', $cancelledIDs)
        ->execute();
    }
    $results['canceled'] = count($cancelledIDs);
  }

  return civicrm_api3_create_success($results, $params);
}

/**
 * Action Payment.
 *
 * @param array $params
 */
function _civicrm_api3_job_process_stripe_spec(&$params) {
  $params['delete_old']['api.default'] = '-3 month';
  $params['delete_old']['title'] = 'Delete old records after (default: -3 month)';
  $params['delete_old']['description'] = 'Delete old records from database. Specify 0 to disable. Default is "-3 month"';
  $params['cancel_incomplete']['api.default'] = '-1 hour';
  $params['cancel_incomplete']['title'] = 'Cancel incomplete records after (default: -1hour)';
  $params['cancel_incomplete']['description'] = 'Cancel incomplete paymentIntents in your stripe account. Specify 0 to disable. Default is "-1hour"';
  $params['stripe_paymentintent_id']['title'] = 'ID of record from civicrm_stripe_paymentintent table';
  $params['stripe_paymentintent_id']['description'] = 'If specified, only this record will be processed';
  $params['stripe_paymentintent_id']['type'] = CRM_Utils_Type::T_INT;

}
