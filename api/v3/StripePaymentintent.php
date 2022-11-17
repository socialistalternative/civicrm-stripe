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

use Civi\Firewall\Firewall;
use CRM_Stripe_ExtensionUtil as E;

/**
 * StripePaymentintent.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_stripe_paymentintent_create($params) {
  return _civicrm_api3_basic_create('CRM_Stripe_BAO_StripePaymentintent', $params, 'StripePaymentintent');
}

/**
 * @param array $params
 *
 * @return array
 * @throws \CRM_Core_Exception
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_stripe_paymentintent_createorupdate($params) {
  if (class_exists('\Civi\Firewall\Firewall')) {
    $firewall = new Firewall();
    if (!$firewall->checkIsCSRFTokenValid(CRM_Utils_Type::validate($params['csrfToken'], 'String'))) {
      _civicrm_api3_stripe_paymentintent_returnInvalid($firewall->getReasonDescription());
    }
  }
  foreach ($params as $key => $value) {
    if (substr($key, 0, 3) === 'api') {
      _civicrm_api3_stripe_paymentintent_returnInvalid('Invalid params');
    }
  }
  if (!empty($params['stripe_intent_id'])) {
    try {
      $params['id'] = civicrm_api3('StripePaymentintent', 'getvalue', ['stripe_intent_id' => $params['stripe_intent_id'], 'return' => 'id']);
    }
    catch (Exception $e) {
      // Do nothing, we will creating a new StripePaymentIntent record
    }
  }
  // We already checked permissions for createorupdate and now we're "trusted".
  $params['check_permissions'] = FALSE;
  return civicrm_api3('StripePaymentintent', 'create', $params);
}

/**
 * StripePaymentintent.delete API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_paymentintent_delete_spec(&$spec) {
  $spec['payment_processor_id.domain_id']['api.default'] = \CRM_Core_Config::domainID();
}

/**
 * StripePaymentintent.delete API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\API\Exception\UnauthorizedException
 */
function civicrm_api3_stripe_paymentintent_delete($params) {
  return _civicrm_api3_basic_delete('CRM_Stripe_BAO_StripePaymentintent', $params);
}

/**
 * StripePaymentintent.get API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_paymentintent_get_spec(&$spec) {
  $spec['payment_processor_id.domain_id']['api.default'] = \CRM_Core_Config::domainID();
}

/**
 * StripePaymentintent.get API
 *
 * @param array $params
 *
 * @return array API result descriptor
 */
function civicrm_api3_stripe_paymentintent_get($params) {
  return _civicrm_api3_basic_get('CRM_Stripe_BAO_StripePaymentintent', $params, TRUE, 'StripePaymentintent');
}

/**
 * StripePaymentintent.process API specification
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 */
function _civicrm_api3_stripe_paymentintent_process_spec(&$spec) {
  $spec['payment_method_id']['title'] = E::ts('Payment Method ID');
  $spec['payment_method_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['payment_method_id']['api.default'] = NULL;
  $spec['payment_intent_id']['title'] = E::ts('Payment Intent ID');
  $spec['payment_intent_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['payment_intent_id']['api.default'] = NULL;
  $spec['amount']['title'] = E::ts('Payment amount');
  $spec['amount']['type'] = CRM_Utils_Type::T_STRING;
  $spec['amount']['api.default'] = NULL;
  $spec['capture']['title'] = E::ts('Whether we should try to capture the amount, not just confirm it');
  $spec['capture']['type'] = CRM_Utils_Type::T_BOOLEAN;
  $spec['capture']['api.default'] = FALSE;
  $spec['description']['title'] = E::ts('Describe the payment');
  $spec['description']['type'] = CRM_Utils_Type::T_STRING;
  $spec['description']['api.default'] = NULL;
  $spec['currency']['title'] = E::ts('Currency (eg. EUR)');
  $spec['currency']['type'] = CRM_Utils_Type::T_STRING;
  $spec['currency']['api.default'] = CRM_Core_Config::singleton()->defaultCurrency;
  $spec['payment_processor_id']['title'] = E::ts('The stripe payment processor id');
  $spec['payment_processor_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['payment_processor_id']['api.required'] = TRUE;
  $spec['extra_data']['title'] = E::ts('Extra Data');
  $spec['extra_data']['type'] = CRM_Utils_Type::T_STRING;
}

/**
 * StripePaymentintent.process API
 *
 * In the normal flow of a CiviContribute form, this will be called with a
 * payment_method_id (which is generated by Stripe via its javascript code),
 * in which case it will create a PaymentIntent using that and *attempt* to
 * 'confirm' it.
 *
 * This can also be called with a payment_intent_id instead, in which case it
 * will retrieve the PaymentIntent and attempt (again) to 'confirm' it. This
 * is useful to confirm funds after a user has completed SCA in their
 * browser.
 *
 * 'confirming' a PaymentIntent refers to the process by which the funds are
 * reserved in the cardholder's account, but not actually taken yet.
 *
 * Taking the funds ('capturing') should go through without problems once the
 * transaction has been confirmed - this is done later on in the process.
 *
 * Nb. confirmed funds are released and will become available to the
 * cardholder again if the PaymentIntent is cancelled or is not captured
 * within 1 week.
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 * @throws \Stripe\Exception\UnknownApiErrorException
 */
function civicrm_api3_stripe_paymentintent_process($params) {
  $authorizeEvent = new \Civi\Stripe\Event\AuthorizeEvent('StripePaymentintent', 'process', $params);
  $event = \Civi::dispatcher()->dispatch('civi.stripe.authorize', $authorizeEvent);
  if ($event->isAuthorized() === FALSE) {
    _civicrm_api3_stripe_paymentintent_returnInvalid(E::ts('Bad Request'));
  }

  foreach ($params as $key => $value) {
    if (substr($key, 0, 3) === 'api') {
      _civicrm_api3_stripe_paymentintent_returnInvalid('Invalid params');
    }
  }
  $paymentMethodID = CRM_Utils_Type::validate($params['payment_method_id'] ?? '', 'String');
  $paymentIntentID = CRM_Utils_Type::validate($params['payment_intent_id'] ?? '', 'String');
  $capture = CRM_Utils_Type::validate($params['capture'] ?? NULL, 'Boolean', FALSE);
  $amount = CRM_Utils_Type::validate($params['amount'], 'String');
  $setup = CRM_Utils_Type::validate($params['setup'] ?? NULL, 'Boolean', FALSE);
  // $capture is normally true if we have already created the intent and just need to get extra
  //   authentication from the user (eg. on the confirmation page). So we don't need the amount
  //   in this case.
  if (empty($amount) && !$capture && !$setup) {
    _civicrm_api3_stripe_paymentintent_returnInvalid();
  }

  $description = CRM_Utils_Type::validate($params['description'], 'String');
  $currency = CRM_Utils_Type::validate($params['currency'], 'String', CRM_Core_Config::singleton()->defaultCurrency);

  // Until 6.6.1 we were passing 'id' instead of the correct 'payment_processor_id' from js scripts. This retains
  //   compatibility with any 3rd-party scripts.
  if (isset($params['id']) && !isset($params['payment_processor_id'])) {
    $params['payment_processor_id'] = $params['id'];
  }
  $paymentProcessorID = CRM_Utils_Type::validate((int)$params['payment_processor_id'], 'Positive');

  !empty($paymentProcessorID) ?: _civicrm_api3_stripe_paymentintent_returnInvalid();

  /** @var CRM_Core_Payment_Stripe $paymentProcessor */
  $paymentProcessor = \Civi\Payment\System::singleton()->getById($paymentProcessorID);
  ($paymentProcessor->getPaymentProcessor()['class_name'] === 'Payment_Stripe') ?: _civicrm_api3_stripe_paymentintent_returnInvalid();

  $stripePaymentIntent = new CRM_Stripe_PaymentIntent($paymentProcessor);
  $stripePaymentIntent->setDescription($description);
  $stripePaymentIntent->setReferrer($_SERVER['HTTP_REFERER'] ?? '');
  $stripePaymentIntent->setExtraData($params['extra_data'] ?? '');
  if ($setup) {
    $params = [
      // Optional paymentMethodID
      'paymentMethodID' => $paymentMethodID ?? NULL,
      // 'customer => 'cus_xx',
    ];
    $processIntentResult = $stripePaymentIntent->processSetupIntent($params);
    if ($processIntentResult->ok) {
      return civicrm_api3_create_success($processIntentResult->data);
    }
    else {
      return civicrm_api3_create_error($processIntentResult->message);
    }
  }
  else {
    $params = [
      'paymentIntentID' => $paymentIntentID ?? NULL,
      'paymentMethodID' => $paymentMethodID ?? NULL,
      'capture' => $capture,
      'amount' => $amount,
      'currency' => $currency,
    ];
    $processIntentResult = $stripePaymentIntent->processPaymentIntent($params);
    if ($processIntentResult->ok) {
      return civicrm_api3_create_success($processIntentResult->data);
    }
    else {
      return civicrm_api3_create_error($processIntentResult->message);
    }
  }
}

/**
 * Passed parameters were invalid
 */
function _civicrm_api3_stripe_paymentintent_returnInvalid($message = '') {
  if (empty($message)) {
    $message = E::ts('Bad Request');
  }
  header("HTTP/1.1 400 {$message}");
  exit(1);
}
