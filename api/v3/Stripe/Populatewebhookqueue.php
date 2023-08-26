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

/**
 * Populate the CiviCRM civicrm_paymentprocessor_webhook with Stripe events.
 *
 * This api will take all stripe events known to Stripe that are of the type
 * invoice.payment_succeeded and add them * to the civicrm_paymentprocessor_webhook table.
 * It will not add an event that has already been added, so it can be run multiple times.
 * Once added, they will be automatically processed by the Job.process_paymentprocessor_webhooks api call.
 */

use CRM_Stripe_ExtensionUtil as E;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentprocessorWebhook;

/**
 * Stripe.Populatewebhookqueue API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_Populatewebhookqueue_spec(&$spec) {
  $spec['ppid'] = [
    'type' => CRM_Utils_Type::T_INT,
    'title' => ts('Payment Processor ID'),
    'description' => 'Foreign key to civicrm_payment_processor.id',
    'pseudoconstant' => [
      'table' => 'civicrm_payment_processor',
      'keyColumn' => 'id',
      'labelColumn' => 'title',
    ],
    'api.required' => FALSE,
  ];
  $spec['type']['title'] = E::ts('The event type - defaults to invoice.payment_succeeded.');
  $spec['type']['api.default'] = 'invoice.payment_succeeded';
  $spec['starting_after']['title'] = E::ts('Only return results after this Stripe event ID.');
}

/**
 * Stripe.Populatewebhookqueue API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_stripe_Populatewebhookqueue($params) {
  if (!$params['ppid']) {
    // By default, select the live stripe processor (we expect there to be only one).
    $paymentProcessors = PaymentProcessor::get(FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('payment_processor_type_id:name', '=', 'Stripe')
      ->execute();
    if ($paymentProcessors->rowCount !== 1) {
      throw new API_Exception("Expected one live Stripe payment processor, but found none or more than one. Please specify ppid=.", 2234);
    }
    else {
      $params['ppid'] = $paymentProcessors->first()['id'];
    }
  }

  $listEventsParams['limit'] = 100;
  $listEventsParams['ppid'] = $params['ppid'];
  $listEventsParams['type'] = $params['type'];
  if (!empty($params['starting_after'])) {
    $listEventsParams['starting_after'] = $params['starting_after'];
  }

  $items = [];
  $last_item = NULL;
  while(1) {
    if ($last_item) {
      $listEventsParams['starting_after'] = $last_item['id'];
    }
    $events = civicrm_api3('Stripe', 'Listevents', $listEventsParams)['values'];

    if (count($events) == 0) {
      // No more!
      break;
    }
    $items = array_merge($items, $events);
    $last_item = end($events);

    // Support the standard API3 limit clause
    if (isset($params['options']['limit']) && $params['options']['limit'] > 0 && count($items) >= $params['options']['limit']) {
      break;
    }
  }

  $results = [];
  $eventIDs = CRM_Utils_Array::collect('id', $items);

  $paymentprocessorWebhookEventIDs = PaymentprocessorWebhook::get(FALSE)
    ->addWhere('payment_processor_id', '=', $params['ppid'])
    ->addWhere('event_id', 'IN', $eventIDs)
    ->execute()->column('event_id');

  $missingEventIDs = array_diff($eventIDs, $paymentprocessorWebhookEventIDs);

  foreach($items as $item) {
    if (!in_array($item['id'], $missingEventIDs)) {
      continue;
    }

    $ipnClass = new CRM_Core_Payment_StripeIPN(\Civi\Payment\System::singleton()->getById($params['ppid']));
    $ipnClass->setEventID($item['id']);
    if (!$ipnClass->setEventType($item['type'])) {
      // We don't handle this event
      continue;
    }

    $ipnClass->setData($item['data']);
    $ipnClass->onReceiveWebhook(FALSE);

    $results[] = $item['id'];
  }
  return civicrm_api3_create_success($results);
}
