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
 * This api sets up a Stripe Payment Processor with test credentials.
 *
 * This api should only be used for testing purposes.
 */

/**
 * Stripe.Setuptest API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_Setuptest_spec(&$spec) {
  // Note: these test credentials belong to PTP and are contributed to
  // tests can be automated. If you are setting up your own testing
  // infrastructure, please use your own keys.
  $spec['sk']['api.default'] = 'sk_test_TlGdeoi8e1EOPC3nvcJ4q5UZ';
  $spec['pk']['api.default'] = 'pk_test_k2hELLGpBLsOJr6jZ2z9RaYh';
}

/**
 * Stripe.Setuptest API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \CiviCRM_API3_Exception
 *
 * @deprecated
 */
function civicrm_api3_stripe_Setuptest($params) {
  $params = [
    'name' => 'Stripe',
    'domain_id' => CRM_Core_Config::domainID(),
    'payment_processor_type_id' => 'Stripe',
    'title' => 'Stripe',
    'is_active' => 1,
    'is_default' => 0,
    'is_test' => 1,
    'is_recur' => 1,
    'user_name' => $params['pk'],
    'password' => $params['sk'],
    'url_site' => 'https://api.stripe.com/v1',
    'url_recur' => 'https://api.stripe.com/v1',
    'class_name' => 'Payment_Stripe',
    'billing_mode' => 1
  ];
  // First see if it already exists.
  $result = civicrm_api3('PaymentProcessor', 'get', $params);
  if ($result['count'] != 1) {
    // Nope, create it.
    $result = civicrm_api3('PaymentProcessor', 'create', $params);
  }
  return civicrm_api3_create_success($result['values']);
}
