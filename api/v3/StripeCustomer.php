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
 * Stripe Customer API
 *
 */

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_Stripe_ExtensionUtil as E;

/**
 * StripeCustomer.Get API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_customer_get_spec(&$spec) {
  $spec['customer_id']['title'] = E::ts('Stripe Customer ID');
  $spec['customer_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['contact_id']['title'] = E::ts('CiviCRM Contact ID');
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['processor_id']['title'] = E::ts('Payment Processor ID');
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * StripeCustomer.Get API
 *  This api will get a customer from the civicrm_stripe_customers table
 *
 * @param array $params
 *
 * @return array
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_stripe_customer_get($params) {
  return _civicrm_api3_basic_get(_civicrm_api3_get_BAO(__FUNCTION__), $params, TRUE, 'StripeCustomer');
}

/**
 * StripeCustomer.delete API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_customer_delete_spec(&$spec) {
  $spec['customer_id']['title'] = E::ts('Stripe Customer ID');
  $spec['customer_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['contact_id']['title'] = E::ts('CiviCRM Contact ID');
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['processor_id']['title'] = E::ts('Payment Processor ID');
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['processor_id']['api.required'] = TRUE;
}

/**
 * StripeCustomer.delete API
 *  This api will delete a stripe customer from CiviCRM
 *
 * @param array $params
 *
 * @return array
 * @throws \CRM_Core_Exception
 * @throws \Civi\Payment\Exception\PaymentProcessorException
 */
function civicrm_api3_stripe_customer_delete($params) {
  CRM_Stripe_Customer::delete($params);
  return civicrm_api3_create_success([]);
}

/**
 * StripeCustomer.create API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_customer_create_spec(&$spec) {
  $spec['customer_id']['title'] = E::ts('Stripe Customer ID');
  $spec['customer_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['customer_id']['api.required'] = TRUE;
  $spec['contact_id']['title'] = E::ts('CiviCRM Contact ID');
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['contact_id']['api.required'] = TRUE;
  $spec['processor_id']['title'] = E::ts('Payment Processor ID');
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['processor_id']['api.required'] = TRUE;
}

/**
 * StripeCustomer.create API
 *  This api will add a stripe customer to CiviCRM
 *
 * @param array $params
 *
 * @return array
 * @throws \CRM_Core_Exception
 * @throws \Civi\Payment\Exception\PaymentProcessorException
 */
function civicrm_api3_stripe_customer_create($params) {
  CRM_Stripe_Customer::add($params);
  return civicrm_api3_create_success([]);
}

/**
 * @param array $spec
 */
function _civicrm_api3_stripe_customer_updatestripemetadata_spec(&$spec) {
  $spec['customer_id']['title'] = E::ts('Stripe Customer ID');
  $spec['customer_id']['description'] = E::ts('If set only this customer will be updated, otherwise we try and update ALL customers');
  $spec['customer_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['customer_id']['api.required'] = FALSE;
  $spec['dryrun']['api.required'] = TRUE;
  $spec['dryrun']['type'] = CRM_Utils_Type::T_BOOLEAN;
  $spec['processor_id']['api.required'] = FALSE;
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * This allows us to update the metadata held by stripe about our CiviCRM payments
 * Older versions of stripe extension did not set anything useful in stripe except email
 * Now we set a description including the name + metadata holding contact id.
 *
 * @param $params
 *
 * @return array
 * @throws \CRM_Core_Exception
 * @throws \Civi\Payment\Exception\PaymentProcessorException
 */
function civicrm_api3_stripe_customer_updatestripemetadata($params) {
  if (!isset($params['dryrun'])) {
    throw new CRM_Core_Exception('Missing required parameter dryrun');
  }
  $customers = \Civi\Api4\StripeCustomer::get(FALSE);
  if (isset($params['options']['limit'])) {
    $customers = $customers->setLimit($params['options']['limit']);
  }
  if (isset($params['options']['offset'])) {
    $customers = $customers->setOffset($params['options']['offset']);
  }

  if ($params['customer_id']) {
    $customers = $customers->addWhere('customer_id', '=', $params['customer_id']);
  }
  else {
    // We're doing an update on all stripe customers
    if (!isset($params['processor_id'])) {
      throw new CRM_Core_Exception('Missing required parameters processor_id when using without a customer id');
    }
    else {
      $customers = $customers->addWhere('processor_id', '=', $params['processor_id']);
    }
  }

  $customers = $customers->execute();

  foreach ($customers as $customer) {
    if (!$customer['contact_id']) {
      throw new CRM_Core_Exception('Could not find contact ID for stripe customer: ' . $customer['customer_id']);
    }

    /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
    $paymentProcessor = \Civi\Payment\System::singleton()->getById($customer['processor_id']);

    // Get the stripe customer from stripe
    try {
      $paymentProcessor->stripeClient->customers->retrieve($customer['customer_id']);
    }
    catch (Exception $e) {
      $err = $paymentProcessor->parseStripeException('retrieve_customer', $e);
      throw new PaymentProcessorException('Failed to retrieve Stripe Customer: ' . $err['code']);
    }

    $stripeCustomerParams = CRM_Stripe_BAO_StripeCustomer::getStripeCustomerMetadata($customer['contact_id'], $customer['invoice_settings'] ?? []);

    // Update the stripe customer object at stripe
    if (!$params['dryrun']) {
      $paymentProcessor->stripeClient->customers->update($customer['customer_id'], $stripeCustomerParams);
      $results[] = $stripeCustomerParams;
    }
    else {
      $results[] = $stripeCustomerParams;
    }
  }
  return civicrm_api3_create_success($results, $params);
}
