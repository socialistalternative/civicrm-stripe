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
 * This api provides a list of events generated by Stripe
 *
 * See the Stripe event reference for a full explanation of the options.
 * https://stripe.com/docs/api#events
 */

use CRM_Stripe_ExtensionUtil as E;

/**
 * Stripe.ListEvents API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_ListEvents_spec(&$spec) {
  $spec['ppid']['title'] = E::ts('Use the given Payment Processor ID');
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;
  $spec['type']['title'] = E::ts('Limit to the given Stripe events type, defaults to invoice.payment_succeeded.');
  $spec['type']['api.default'] = 'invoice.payment_succeeded';
  $spec['limit']['title'] = E::ts('Limit number of results returned (100 is max, 25 default)');
  $spec['starting_after']['title'] = E::ts('Only return results after this Stripe event ID.');
  $spec['output']['api.default'] = 'brief';
  $spec['output']['title'] = E::ts('How to format the output, brief or raw. Defaults to brief.');
  $spec['source']['title'] = E::ts('List events via the Stripe API (default: stripe) or via the CiviCRM System Log (systemlog).');
  $spec['filter_processed']['title'] = E::ts('If set to 1, filter out all transactions that have been processed already.');
  $spec['filter_processed']['type'] = CRM_Utils_Type::T_INT;
  $spec['subscription']['title'] = E::ts('Pass a Stripe subscription id to filter results to charges to that subscription id.');
}

/**
 * Stripe.VerifyEventType
 *
 * @param string $eventType
 *
 * @return boolean True if valid type, false otherwise.
 */
function civicrm_api3_stripe_VerifyEventType($eventType) {
  return in_array($eventType, [
      'account.external_account.created',
      'account.external_account.deleted',
      'account.external_account.updated',
      'application_fee.created',
      'application_fee.refunded',
      'application_fee.refund.updated',
      'balance.available',
      'bitcoin.receiver.created',
      'bitcoin.receiver.filled',
      'bitcoin.receiver.updated',
      'bitcoin.receiver.transaction.created',
      'charge.captured',
      'charge.failed',
      'charge.pending',
      'charge.refunded',
      'charge.succeeded',
      'charge.updated',
      'charge.dispute.closed',
      'charge.dispute.created',
      'charge.dispute.funds_reinstated',
      'charge.dispute.funds_withdrawn',
      'charge.dispute.updated',
      'charge.refund.updated',
      'coupon.created',
      'coupon.deleted',
      'coupon.updated',
      'customer.created',
      'customer.deleted',
      'customer.updated',
      'customer.discount.created',
      'customer.discount.deleted',
      'customer.discount.updated',
      'customer.source.created',
      'customer.source.deleted',
      'customer.source.updated',
      'customer.subscription.created',
      'customer.subscription.deleted',
      'customer.subscription.trial_will_end',
      'customer.subscription.updated',
      'invoice.created',
      'invoice.payment_failed',
      'invoice.payment_succeeded',
      'invoice.upcoming',
      'invoice.updated',
      'invoiceitem.created',
      'invoiceitem.deleted',
      'invoiceitem.updated',
      'order.created',
      'order.payment_failed',
      'order.payment_succeeded',
      'order.updated',
      'order_return.created',
      'payout.canceled',
      'payout.created',
      'payout.failed',
      'payout.paid',
      'payout.updated',
      'plan.created',
      'plan.deleted',
      'plan.updated',
      'product.created',
      'product.deleted',
      'product.updated',
      'recipient.created',
      'recipient.deleted',
      'recipient.updated',
      'review.closed',
      'review.opened',
      'sku.created',
      'sku.deleted',
      'sku.updated',
      'source.canceled',
      'source.chargeable',
      'source.failed',
      'source.transaction.created',
      'transfer.created',
      'transfer.reversed',
      'transfer.updated',
      'ping',
    ]
  );
}

/**
 * Process parameters to determine ppid.
 *
 * @param array $params
 *
 * @return array
 * @throws \API_Exception
 */
function civicrm_api3_stripe_ProcessParams($params) {
  $type = NULL;
  $created = NULL;
  $limit = 25;
  $starting_after = NULL;
  $source = 'stripe';
  $filter_processed = 0;
  $subscription = NULL;

  if (array_key_exists('created', $params) ) {
    $created = $params['created'];
  }
  if (array_key_exists('limit', $params) ) {
    $limit = $params['limit'];
  }
  if (array_key_exists('starting_after', $params) ) {
    $starting_after = $params['starting_after'];
  }

  // Check to see if we should filter by type.
  // If empty we get all events
  if (!empty($params['type'])) {
    // Validate - since we will be appending this to an URL.
    if (!civicrm_api3_stripe_VerifyEventType($params['type'])) {
      throw new API_Exception("Unrecognized Event Type.", 1236);
    }
    else {
      $type = $params['type'];
    }
  }

  // Created can only be passed in as an array
  if (array_key_exists('created', $params)) {
    $created = $params['created'];
    if (!is_array($created)) {
      throw new API_Exception("Created can only be passed in programatically as an array", 1237);
    }
  }

  if (array_key_exists('source', $params)) {
    $allowed = [ 'stripe', 'systemlog' ];
    if (!in_array($params['source'], $allowed)) {
      throw new API_Exception(E::ts('Source can only be set to %1 or %2.', [ 1 => 'stripe', 2 => 'systemlog' ]), 1238);
    }
    $source = $params['source'];
  }

  if (array_key_exists('filter_processed', $params)) {
    $allowed = [ 0, 1 ];
    if (!in_array($params['filter_processed'], $allowed)) {
      throw new API_Exception(E::ts('Filter processed can only be set to 0 or 1.'), 1239);
    }
    $filter_processed = $params['filter_processed'];
  }

  if (array_key_exists('subscription', $params)) {
    if (!preg_match('/^sub_/', $params['subscription'])) {
      throw new API_Exception(E::ts('Subscription should start with sub_.'), 1240);
    }

    if (array_key_exists('source', $params)) {
      throw new API_Exception(E::ts('Subscription and source are incompatible. Please choose one or the other.'), 1241);
    }
    else {
      $source = NULL;
    }

    $subscription = $params['subscription'];
  }
  return [
    'type' => $type,
    'created' => $created,
    'limit' => $limit,
    'starting_after' => $starting_after,
    'source' => $source,
    'filter_processed' => $filter_processed,
    'subscription' => $subscription,
  ];
}

/**
 * Stripe.ListEvents API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 * @throws \Stripe\Exception\UnknownApiErrorException
 */
function civicrm_api3_stripe_Listevents($params) {
  $parsed = civicrm_api3_stripe_ProcessParams($params);
  $type = $parsed['type'];
  $created = $parsed['created'];
  $limit = $parsed['limit'];
  $starting_after = $parsed['starting_after'];
  $source = $parsed['source'];
  $filter_processed = $parsed['filter_processed'];
  $subscription = $parsed['subscription'];

  // $data_list will contain all the values we will return to the user.
  $data_list = [ 'data' => [] ];

  // Search by a single Stripe subscription id.
  if ($subscription) {
    // If we are searching by subscription, we ignore source because we will
    // search both the system log and we will query Stripe to get a complete
    // list.
    $sql = 'SELECT id, context FROM civicrm_system_log WHERE message = %0 AND
      context LIKE %1 AND (context LIKE "%invoice.payment_failed%" OR context LIKE
      "%invoice.payment_succeeded%") ORDER BY timestamp DESC';

    $sql_params = [
      0 => [ 'payment_notification processor_id=' . $params['ppid'], 'String'  ],
      1 => [ '%' . $subscription . '%', 'String' ],
    ];

    $dao = CRM_Core_DAO::executeQuery($sql, $sql_params);
    $seen_charges = [];
    while($dao->fetch()) {
      $data = json_decode($dao->context);
      if (in_array($data->data->object->charge, $seen_charges)) {
        // We might get more then one event for a single charge if the first attempt fails. We
        // don't need to list them all.
        continue;
      }
      $seen_charges[] = $data->data->object->charge;
      $data->system_log_id = $dao->id;

      // Add this charge to the list to return. Index by timestamp so we can
      // sort them chronologically later.
      $index = $data->created;
      $data_list['data'][$index] = $data;
    }

    // Now query stripe directly to see if there are any that system log didn't record.
    $processor = \Civi\Payment\System::singleton()->getById($params['ppid']);
    $invoices = $processor->stripeClient->invoices->all(['subscription' => $subscription]);
    $seen_invoices = [];
    foreach($invoices['data'] as $invoice) {
      if ($invoice->charge) {
        // We get a lot of repeats - for example, one item for the creation of the subscription
        // and another for the initial charge. We only want one record per charge.
        if (!in_array($invoice->charge, $seen_charges)) {
          // If we already have this charge from system log, we don't need it again.
          if (!in_array($invoice->charge, $seen_invoices)) {
            // This means a charge was made that was not included in the system log.
            $data_list['data'][$invoice->created] = $invoice;
            $seen_invoices[]  = $invoice->charge;
          }
        }
      }
    }
    // Since we're combining data from the system log and from stripe, it may be
    // out of chronological order.
    asort($data_list);
  }

  // Query the last month of Stripe events.
  elseif ($source == 'stripe') {
    // Here we need to get a singleton xxx
    $processor = \Civi\Payment\System::singleton()->getById($params['ppid']);
    $args = [];
    if ($type) {
      $args['type'] = $type;
    }
    if ($created) {
      $args['created'] = $created;
    }
    // 100 is the max we can request.
    $args['limit'] = 100;
    if ($starting_after) {
      $args['starting_after'] = $starting_after;
    }
    // Returns an array of \Stripe\Event objects
    $data_list = $processor->stripeClient->events->all($args);
  }

  // Query the system log.
  else {
    $sql = 'SELECT id, context FROM civicrm_system_log WHERE message = %0 AND context LIKE %1 ORDER BY timestamp DESC';
    $sql_params = [
      0 => [ 'payment_notification processor_id=' . $params['ppid'], 'String'  ],
      1 => [ '%' . $type . '%', 'String' ],
    ];

    $dao = CRM_Core_DAO::executeQuery($sql, $sql_params);
    $seen_charges = [];
    while($dao->fetch()) {
      $data = json_decode($dao->context);
      $charge = $data->data->object->charge;
      if ($charge && in_array($charge, $seen_charges)) {
        continue;
      }
      $seen_charges[] = $charge;
      $data->system_log_id = $dao->id;
      $data_list['data'][] = $data;
    }
  }

  if ($params['output'] != 'brief') {
    $out = $data_list;
    // Only return the requested number of results.
    $out['data'] = array_slice($data_list['data'], 0, $limit);
  }
  else {
    $out = [];
    $count = 0;
    foreach($data_list['data'] as $data) {
      if ($count >= $limit) {
        break;
      }
      $item = [];

      if (isset($data->system_log_id)) {
        $item['system_log_id'] = $data->system_log_id;
      }
      $item['id'] = $data->id;
      $item['created'] = date('Y-m-d H:i:s', $data->created);
      $item['livemode'] = $data->livemode;
      $item['pending_webhooks'] = $data->pending_webhooks;
      $item['type'] = $data->type;

      $invoice = NULL;
      $charge = NULL;
      $customer = NULL;
      $subscription = NULL;
      $total = NULL;
      if (preg_match('/invoice\.payment_/', $data->type)) {
        $invoice = $data->data->object->id;
        $charge = $data->data->object->charge;
        $customer = $data->data->object->customer;
        $subscription = $data->data->object->subscription;
        $total = $data->data->object->total;
      }
      elseif($data->object == 'invoice') {
        $invoice = $data->id;
        $charge = $data->charge;
        $customer = $data->customer;
        $subscription = $data->subscription;
        $total = $data->total;
      }

      $item['invoice'] = $invoice;
      $item['charge'] = $charge;
      $item['customer'] = $customer;
      $item['subscription'] = $subscription;
      $item['total'] = $total;

      // We will populate several additional fields based on whether any
      // of this data has been entered into CiviCRM.
      $item['contact_id'] = NULL;
      $item['contribution_recur_id'] = NULL;
      $item['contribution_recur_status_id'] = NULL;
      $item['contribution_id'] = NULL;
      $item['contribution_status_id'] = NULL;
      $item['processed'] = 'no';
      $item['data'] = json_encode($data);

      if ($customer) {
        // Check if the customer is in the stripe customer table.
        $results = civicrm_api3('StripeCustomer', 'get', [ 'customer_id' => $customer]);
        if ($results['count'] == 1) {
          $value = array_pop($results['values']);
          $item['contact_id'] = $value['contact_id'];
        }
      }

      if ($subscription) {
        // Check if recurring contribution can be found.
        $results = civicrm_api3('ContributionRecur', 'get', ['processor_id' => $subscription]);
        if ($results['count'] > 0) {
          $item['contribution_recur_id'] = $results['id'];
          $contribution_recur = array_pop($results['values']);
          $status_id = $contribution_recur['contribution_status_id'];
          $item['contribution_recur_status_id'] = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $status_id);
        }
      }

      if ($charge && $invoice) {
        // Check if charge is in the contributions table.
        $contribution = NULL;
        $results = \Civi\Api4\Contribution::get()
          ->addClause('OR', [ 'trxn_id', 'LIKE', '%' . $item['charge'] . '%' ], [ 'trxn_id', 'LIKE', '%' . $item['invoice'] . '%' ])
          ->setCheckPermissions(FALSE)
          ->execute();
        if ($results->rowCount > 0) {
          $contribution = $results->first();
        }
        if ($contribution) {
          $item['contribution_id'] = $contribution['id'];
          $status_id = $contribution['contribution_status_id'];
          $item['contribution_status_id'] = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $status_id);
          if ($contribution['contribution_status_id'] == 1) {
            if ($filter_processed == 1) {
              // Woops. All this for nothing. We will filter this one out.
              continue;
            }
            $item['processed'] = 'yes';
          }
        }
      }
      $count++;
      $out[] = $item;
    }
  }
  return civicrm_api3_create_success($out);
}
