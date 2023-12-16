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
 * Stripe.Importcharges
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_importcharge_spec(&$spec) {
  $spec['ppid']['title'] = E::ts('Use the given Payment Processor ID');
  $spec['ppid']['type'] = CRM_Utils_Type::T_INT;
  $spec['ppid']['api.required'] = TRUE;
  $spec['contact_id']['title'] = E::ts('Contact ID');
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['contact_id']['api.required'] = TRUE;
  $spec['charge']['title'] = E::ts('Import a specific charge');
  $spec['charge']['type'] = CRM_Utils_Type::T_STRING;
  $spec['charge']['api.required'] = FALSE;
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
  $spec['contribution_id']['title'] = E::ts('Optionally, provide contribution ID of existing contribution you want to link to.');
  $spec['contribution_id']['type'] = CRM_Utils_Type::T_INT;

}

/**
 * Stripe.Importcharges API
 *
 * @param array $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 * @throws \Stripe\Exception\UnknownApiErrorException
 */
function civicrm_api3_stripe_importcharge($params) {
  // Get the payment processor and activate the Stripe API
  /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
  $paymentProcessor = \Civi\Payment\System::singleton()->getById($params['ppid']);

  // Retrieve the Stripe charge.
  $stripeCharge = $paymentProcessor->stripeClient->charges->retrieve($params['charge']);

  // Get the related invoice.
  $stripeInvoice = $paymentProcessor->stripeClient->invoices->retrieve($stripeCharge->invoice);
  if (!$stripeInvoice) {
    throw new \CiviCRM_API3_Exception(E::ts('The charge does not have an invoice, it cannot be imported.'));
  }

  // Determine source text.
  if (!empty(CRM_Stripe_Api::getObjectParam('description', $stripeInvoice))) {
    $sourceText = CRM_Stripe_Api::getObjectParam('description', $stripeInvoice);
  }
  elseif (!empty($params['contribution_source'])) {
    $sourceText = $params['contribution_source'];
  }
  else {
    $sourceText = 'Stripe: Manual import via API';
  }

  // Check for a subscription.
  $subscription = CRM_Stripe_Api::getObjectParam('subscription_id', $stripeInvoice);
  $contribution_recur_id = NULL;
  if ($subscription) {
    // Lookup the contribution_recur_id.
    $cr_results = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', '=', $subscription)
      ->addWhere('is_test', '=', $paymentProcessor->getIsTestMode())
      ->execute();
    $contribution_recur = $cr_results->first();
    if (!$contribution_recur) {
      throw new \CiviCRM_API3_Exception(E::ts('The charge has a subscription, but the subscription is not in CiviCRM. Please import the subscription and try again.'));
    }
    $contribution_recur_id = $contribution_recur['id'];
  }


  // Prepare to either create or update a contribution record in CiviCRM.
  $contributionParams = [];

  // We update these parameters regardless if it's a new contribution
  // or an existing contributions.
  $contributionParams['receive_date'] = CRM_Stripe_Api::getObjectParam('receive_date', $stripeInvoice);
  $contributionParams['total_amount'] = CRM_Stripe_Api::getObjectParam('total_amount', $stripeInvoice);

  // Check if a contribution already exists.
  $contribution_id = NULL;
  if (isset($params['contribution_id']) && $params['contribution_id']) {
    // From user input.
    $contribution_id = $params['contribution_id'];
  }
  else {
    // Check database.
    $c_results = \Civi\Api4\Contribution::get(FALSE)
      ->addWhere('trxn_id', 'LIKE', '%'. $params['charge'].'%')
      ->addWhere('is_test', '=', $paymentProcessor->getIsTestMode())
      ->execute();
    $contribution = $c_results->first();
    if ($contribution) {
      $contribution_id = $contribution['id'];
    }
  }

  // If it exists, we update by adding the id.
  if ($contribution_id) {
    $contributionParams['id'] = $contribution_id;
  }
  else {
    // We have to build all the parameters.
    $contributionParams['contact_id'] = $params['contact_id'];
    $contributionParams['total_amount'] = CRM_Stripe_Api::getObjectParam('amount', $stripeInvoice);
    $contributionParams['currency'] = CRM_Stripe_Api::getObjectParam('currency', $stripeInvoice);
    $contributionParams['receive_date'] = CRM_Stripe_Api::getObjectParam('receive_date', $stripeInvoice);
    $contributionParams['trxn_id'] = CRM_Stripe_Api::getObjectParam('charge_id', $stripeInvoice);
    $contributionParams['payment_instrument_id'] = !empty($params['payment_instrument_id']) ? $params['payment_instrument_id'] : 'Credit Card';
    $contributionParams['financial_type_id'] = !empty($params['financial_type_id']) ? $params['financial_type_id'] : 'Donation';
    $contributionParams['is_test'] = $paymentProcessor->getIsTestMode();
    $contributionParams['contribution_source'] = $sourceText;
    $contributionParams['contribution_status_id:name'] = 'Pending';
    if ($contribution_recur_id) {
      $contributionParams['contribution_recur_id'] = $contribution_recur_id;
    }
  }

  $contribution = \Civi\Api4\Contribution::create(FALSE)
    ->setValues($contributionParams)
    ->execute()
    ->first();

  if (CRM_Stripe_Api::getObjectParam('status', $stripeInvoice) === 'Completed') {
    $paymentParams = [
      'contribution_id' => $contribution['id'],
      'total_amount' => $contributionParams['total_amount'],
      'trxn_date' => $contributionParams['receive_date'],
      'payment_processor_id' => $params['ppid'],
      'is_send_contribution_notification' => FALSE,
      'trxn_id' => CRM_Stripe_Api::getObjectParam('charge_id', $stripeInvoice),
      'order_reference' => CRM_Stripe_Api::getObjectParam('invoice_id', $stripeInvoice),
      'payment_instrument_id' => $contributionParams['payment_instrument_id'],
    ];
    if (!empty(CRM_Stripe_Api::getObjectParam('balance_transaction', $stripeCharge))) {
      $stripeBalanceTransaction = $paymentProcessor->stripeClient->balanceTransactions->retrieve(
        CRM_Stripe_Api::getObjectParam('balance_transaction', $stripeCharge)
      );
      $paymentParams['fee_amount'] = $stripeBalanceTransaction->fee / 100;
    }
    civicrm_api3('Payment', 'create', $paymentParams);
  }
  $contribution = \Civi\Api4\Contribution::get(FALSE)
    ->addWhere('id', '=', $contribution['id'])
    ->execute()
    ->first();
  return civicrm_api3_create_success($contribution);
}
