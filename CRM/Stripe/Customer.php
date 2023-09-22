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

use Civi\Api4\Contact;
use Civi\Api4\StripeCustomer;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_Stripe_ExtensionUtil as E;

/**
 * Class CRM_Stripe_Customer
 */
class CRM_Stripe_Customer {

  /**
   * Find an existing Stripe customer in the CiviCRM database
   *
   * @param $params
   *
   * @return null|string
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function find($params) {
    $requiredParams = ['processor_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new PaymentProcessorException('Stripe Customer (find): Missing required parameter: ' . $required);
      }
    }
    if (empty($params['contact_id'])) {
      throw new PaymentProcessorException('Stripe Customer (find): contact_id is required');
    }

    $result = StripeCustomer::get(FALSE)
      ->addWhere('contact_id', '=', $params['contact_id'])
      ->addWhere('processor_id', '=', $params['processor_id'])
      ->addClause('OR', ['currency', 'IS EMPTY'], ['currency', '=', $params['currency']])
      ->addSelect('customer_id')
      ->execute();

    return $result->count() ? $result->first()['customer_id'] : NULL;
  }

  /**
   * Find the details (contact_id, processor_id) for an existing Stripe customer in the CiviCRM database
   *
   * @param string $stripeCustomerId
   *
   * @return array|null
   */
  public static function getParamsForCustomerId($stripeCustomerId) {
    $result = StripeCustomer::get(FALSE)
      ->addWhere('customer_id', '=', $stripeCustomerId)
      ->addSelect('contact_id', 'processor_id')
      ->execute()
      ->first();

    // Not sure whether this return for no match is needed, but that's what was being returned previously
    return $result ? $result : ['contact_id' => NULL, 'processor_id' => NULL];
  }

  /**
   * Find all the Stripe customers in the CiviCRM database for a given processorId
   *
   * @param string $processorId
   *
   * @return array|null
   */
  public static function getAll($processorId, $options = []) {
    return civicrm_api4('StripeCustomer', 'get', [
      'select' => ['customer_id'],
      'where' => [['processor_id', '=', $processorId]],
      'checkPermissions' => FALSE,
    ] + $options, ['customer_id']);
  }

  /**
   * @param array $params
   * @param \CRM_Core_Payment_Stripe $stripe
   *
   * @return \Stripe\Customer|\PropertySpy
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function create(array $params, \CRM_Core_Payment_Stripe $stripe) {
    $requiredParams = ['contact_id', 'processor_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new PaymentProcessorException('Stripe Customer (create): Missing required parameter: ' . $required);
      }
    }

    $stripeCustomerParams = CRM_Stripe_BAO_StripeCustomer::getStripeCustomerMetadata($params['contact_id'], $params['invoice_settings'] ?? []);

    try {
      $stripeCustomerObject = $stripe->stripeClient->customers->create($stripeCustomerParams);
    }
    catch (Exception $e) {
      $err = $stripe->parseStripeException('create_customer', $e);
      \Civi::log('stripe')->error('Failed to create Stripe Customer: ' . $err['message'] . '; ' . print_r($err, TRUE));
      throw new PaymentProcessorException('Failed to create Stripe Customer: ' . $err['code']);
    }

    // Store the relationship between CiviCRM's email address for the Contact & Stripe's Customer ID.
    StripeCustomer::create(FALSE)
      ->addValue('contact_id', $params['contact_id'])
      ->addValue('customer_id', $stripeCustomerObject->id)
      ->addValue('processor_id', $params['processor_id'])
      ->addValue('currency', $params['currency'])
      ->execute();

    return $stripeCustomerObject;
  }

  /**
   * Delete a Stripe customer from the CiviCRM database
   *
   * @param array $params
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function delete(array $params) {
    $requiredParams = ['processor_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new PaymentProcessorException('Stripe Customer (delete): Missing required parameter: ' . $required);
      }
    }
    if (empty($params['contact_id']) && empty($params['customer_id'])) {
      throw new PaymentProcessorException('Stripe Customer (delete): Missing required parameter: contact_id or customer_id');
    }

    $delete = StripeCustomer::delete()
      ->addWhere('processor_id', '=', $params['processor_id']);

    if (!empty($params['customer_id'])) {
      $delete = $delete->addWhere('customer_id', '=', $params['customer_id']);
    }
    else {
      $delete = $delete->addWhere('contact_id', '=', $params['contact_id']);
    }
    $delete->execute();
  }

}
