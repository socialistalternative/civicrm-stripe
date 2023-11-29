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

use CRM_Stripe_ExtensionUtil as E;

/**
 * API to import a stripe subscription, create a customer, recur, contribution and optionally link to membership
 * You run it once for each subscription and it creates/updates a recurring contribution in civicrm (and optionally links it to a membership).
 *
 * @param array $params
 *
 * @return array
 * @throws \API_Exception
 * @throws \CRM_Core_Exception
 * @throws \CiviCRM_API3_Exception
 * @throws \Stripe\Exception\ApiErrorException
 */
function civicrm_api3_stripe_importsubscription($params) {
  civicrm_api3_verify_mandatory($params, NULL, ['subscription', 'contact_id', 'ppid']);

  $params['payment_instrument_id'] = $params['payment_instrument_id'] ?? 1; // Credit Card
  $params['financial_type_id'] = $params['financial_type_id'] ?? 1; // Donation

  /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
  $paymentProcessor = \Civi\Payment\System::singleton()->getById($params['ppid']);

  // Now re-retrieve the data from Stripe to ensure it's legit.
  $stripeSubscription = $paymentProcessor->stripeClient->subscriptions->retrieve($params['subscription']);

  // Create the stripe customer in CiviCRM if it doesn't exist already.
  $customerParams = [
    'customer_id' => CRM_Stripe_Api::getObjectParam('customer_id', $stripeSubscription),
    'processor_id' => (int) $params['ppid'],
    'contact_id' => $params['contact_id']
  ];

  $custresult = civicrm_api3('StripeCustomer', 'get', $customerParams);
  if ($custresult['count'] == 0) {
    // Create the customer.
    $customerParams['contact_id'] = $params['contact_id'];
    civicrm_api3('StripeCustomer', 'create', $customerParams);
    $custresult = civicrm_api3('StripeCustomer', 'get', $customerParams);
  }
  $customer = array_pop($custresult['values']);
  if ($customer['contact_id'] != $params['contact_id']) {
    throw new API_Exception(E::ts('There is a mismatch between the contact id for the customer indicated by the subscription (%1) and the contact id provided via the API params (%2).', [ 1 => $customer['contact_id'], 2 => $params['contact_id']]));
  }

  // Create the recur record in CiviCRM if it doesn't exist.
  $contributionRecur = \Civi\Api4\ContributionRecur::get(FALSE)
    ->addWhere('processor_id', '=', $params['subscription'])
    ->execute()
    ->first();

  if (empty($contributionRecur)) {
    $contributionRecurParams = [
      'contact_id' => $params['contact_id'],
      'amount' => CRM_Stripe_Api::getObjectParam('amount', $stripeSubscription),
      'currency' => CRM_Stripe_Api::getObjectParam('currency', $stripeSubscription),
      'frequency_unit' => CRM_Stripe_Api::getObjectParam('frequency_unit', $stripeSubscription),
      'frequency_interval' => CRM_Stripe_Api::getObjectParam('frequency_interval', $stripeSubscription),
      'start_date' => CRM_Stripe_Api::getObjectParam('plan_start', $stripeSubscription),
      'processor_id' => $params['subscription'],
      'cycle_day' => CRM_Stripe_Api::getObjectParam('cycle_day', $stripeSubscription),
      'auto_renew' => 1,
      'payment_processor_id' => $params['ppid'],
      'payment_instrument_id' => $params['payment_instrument_id'],
      'financial_type_id' => $params['financial_type_id'],
      'is_email_receipt' => !empty($params['is_email_receipt']) ? 1 : 0,
      'is_test' => $paymentProcessor->getIsTestMode(),
      'contribution_source' => !empty($params['contribution_source']) ? $params['contribution_source'] : '',
    ];
    if (isset($params['recur_id']) && $params['recur_id']) {
      $contributionRecurParams['id'] = $params['recur_id'];
    }
    $contributionRecur = \Civi\Api4\ContributionRecur::create(FALSE)
      ->setValues($contributionRecurParams)
      ->execute()
      ->first();
  }
  // Get the invoices for the subscription
  $invoiceParams = [
    'customer' => CRM_Stripe_Api::getObjectParam('customer_id', $stripeSubscription),
    'limit' => 100,
  ];
  $stripeInvoices = $paymentProcessor->stripeClient->invoices->all($invoiceParams);
  if ($stripeInvoices->count()) {
    // We have one or more invoices to import (as contributions)
    foreach ($stripeInvoices->data as $stripeInvoice) {
      if (CRM_Stripe_Api::getObjectParam('subscription_id', $stripeInvoice) === $params['subscription']) {
        $charge = CRM_Stripe_Api::getObjectParam('charge_id', $stripeInvoice);
        if (empty($charge)) {
          continue;
        }
        $exists_params = [
          'contribution_test' => $paymentProcessor->getIsTestMode(),
          'trxn_id' => $charge
        ];
        $contribution = civicrm_api3('Mjwpayment', 'get_contribution', $exists_params);
        if ($contribution['count'] == 0) {
          // It has not been imported, so import it now.
          $charge_params = [
            'charge' => $charge,
            'financial_type_id' => $params['financial_type_id'],
            'payment_instrument_id' => $params['payment_instrument_id'],
            'ppid' => $params['ppid'],
            'contact_id' => $params['contact_id'],
            'contribution_source' => ($params['contribution_source'] ?? ''),
          ];
          $contribution = civicrm_api3('Stripe', 'Importcharge', $charge_params);

          // Link to membership record
          // By default we'll match the latest active membership, unless membership_id is passed in.
          if (!empty($params['membership_id'])) {
            $membershipParams = [
              'id' => $params['membership_id'],
              'contribution_recur_id' => $contributionRecur['id'],
            ];
            $membership = civicrm_api3('Membership', 'create', $membershipParams);
          }
          elseif (!empty($params['membership_auto'])) {
            $membershipParams = [
              'contact_id' => $params['contact_id'],
              'options' => ['limit' => 1, 'sort' => "id DESC"],
              'contribution_recur_id' => ['IS NULL' => 1],
              'is_test' => $paymentProcessor->getIsTestMode(),
              'active_only' => 1,
            ];
            $membership = civicrm_api3('Membership', 'get', $membershipParams);
            if (!empty($membership['id'])) {
              $membershipParams = [
                'id' => $membership['id'],
                'contribution_recur_id' => $contributionRecur['id'],
              ];
              civicrm_api3('Membership', 'create', $membershipParams);
            }
          }
        }
      }
    }
  }
  else {
    // We have no invoices to import. This will be for one of the following reasons:
    //   - Stripe subscription is in a free "trial" period.
    //   - Stripe subscription has not yet reached the start date.
    // In this case we have to create a template contribution (see https://lab.civicrm.org/dev/financial/-/issues/6)
    //   because CiviCRM currently expects at least 1 contribution per ContributionRecur.
    $contribution = \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contribution_recur_id', $contributionRecur['id'])
      ->addValue('contact_id', $contributionRecur['contact_id'])
      ->addValue('financial_type_id', $contributionRecur['financial_type_id'])
      ->addValue('payment_instrument_id', $contributionRecur['payment_instrument_id'])
      ->addValue('source', $params['contribution_source'] ?? '')
      ->addValue('total_amount', $contributionRecur['amount'])
      ->addValue('currency', $contributionRecur['currency'])
      ->addValue('is_test', $paymentProcessor->getIsTestMode())
      ->addValue('is_template', TRUE)
      ->addValue('contribution_status_id:name', 'Template')
      ->execute()
      ->first();
  }

  $results = [
    'subscription' => $params['subscription'],
    'customer' => CRM_Stripe_Api::getObjectParam('customer_id', $stripeSubscription),
    'recur_id' => $contributionRecur['id'],
    'contribution_id' => !empty($contribution['id'])? $contribution['id'] : NULL,
    'membership_id' => !empty($membership['id']) ? $membership['id'] : NULL,
  ];

  return civicrm_api3_create_success($results, $params, 'StripeSubscription', 'import');
}

/**
 * @param array $spec
 */
function _civicrm_api3_stripe_importsubscription_spec(&$spec) {
  $spec['subscription']['title'] = E::ts('Stripe Subscription ID');
  $spec['subscription']['type'] = CRM_Utils_Type::T_STRING;
  $spec['subscription']['api.required'] = TRUE;
  $spec['contact_id']['title'] = E::ts('Contact ID');
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['contact_id']['api.required'] = TRUE;
  $spec['ppid']['title'] = E::ts('Payment Processor ID');
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;

  $spec['recur_id']['title'] = E::ts('Contribution Recur ID');
  $spec['recur_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['contribution_id']['title'] = E::ts('Contribution ID');
  $spec['contribution_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['membership_id']['title'] = E::ts('Membership ID');
  $spec['membership_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['membership_auto']['title'] = E::ts('Link to existing membership automatically');
  $spec['membership_auto']['type'] = CRM_Utils_Type::T_BOOLEAN;
  $spec['membership_auto']['api.default'] = TRUE;
  $spec['financial_type_id'] = [
    'title' => 'Financial Type ID',
    'name' => 'financial_type_id',
    'type' => CRM_Utils_Type::T_INT,
    'pseudoconstant' => [
      'table' => 'civicrm_financial_type',
      'keyColumn' => 'id',
      'labelColumn' => 'name',
    ],
  ];
  $spec['payment_instrument_id']['api.aliases'] = ['payment_instrument'];
  $spec['contribution_source'] = [
    'title' => 'Contribution Source (optional description for contribution)',
    'type' => CRM_Utils_Type::T_STRING,
  ];
}
