<?php

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Extension;
use Civi\Api4\StripeCustomer;
use CRM_Stripe_ExtensionUtil as E;

class CRM_Stripe_BAO_StripeCustomer extends CRM_Stripe_DAO_StripeCustomer {

  /**
   * @param int $contactID
   * @param array $invoiceSettings
   * @param string|null $description
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function getStripeCustomerMetadata(int $contactID, array $invoiceSettings = [], ?string $description = NULL) {
    $contact = Contact::get(FALSE)
      ->addSelect('display_name', 'email_primary.email', 'email_billing.email')
      ->addWhere('id', '=', $contactID)
      ->execute()
      ->first();

    if (version_compare(\CRM_Utils_System::version(), '5.53.0', '<')) {
      // @todo: Remove when we drop support for CiviCRM < 5.53
      // APIv4 - Read & write contact primary and billing locations as implicit joins
      // https://github.com/civicrm/civicrm-core/pull/23972 was added in 5.53
      $emailResult = Email::get(FALSE)
        ->addWhere('contact_id', '=', $contactID)
        ->addOrderBy('is_primary', 'DESC')
        ->addOrderBy('is_billing', 'DESC')
        ->execute()
        ->first();
      if (!empty($emailResult['email'])) {
        $contact['email_primary.email'] = $emailResult['email'];
      }
    }

    $extVersion = Extension::get(FALSE)
      ->addWhere('file', '=', E::SHORT_NAME)
      ->execute()
      ->first()['version'];

    $stripeCustomerParams = [
      'name' => $contact['display_name'],
      // Stripe does not include the Customer Name when exporting payments, just the customer
      // description, so we stick the name in the description.
      'description' => $description ?? $contact['display_name'] . ' (CiviCRM)',
      'metadata' => [
        'CiviCRM Contact ID' => $contactID,
        'CiviCRM URL' => CRM_Utils_System::url('civicrm/contact/view', "reset=1&cid={$contactID}", TRUE, NULL, FALSE, FALSE, TRUE),
        'CiviCRM Version' => CRM_Utils_System::version() . ' ' . $extVersion,
      ],
    ];
    $email = $contact['email_primary.email'] ?? $contact['email_billing.email'] ?? NULL;
    if ($email) {
      $stripeCustomerParams['email'] = $email;
    }

    // This is used for new subscriptions/invoices as the default payment method
    if (!empty($invoiceSettings)) {
      $stripeCustomerParams['invoice_settings'] = $invoiceSettings;
    }
    return $stripeCustomerParams;
  }

  /**
   * @param array $params
   * @param \CRM_Core_Payment_Stripe $stripe
   * @param string $stripeCustomerID
   *
   * @return \Stripe\Customer
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function updateMetadata(array $params, \CRM_Core_Payment_Stripe $stripe, string $stripeCustomerID) {
    $requiredParams = ['contact_id'];
    foreach ($requiredParams as $required) {
      if (empty($params[$required])) {
        throw new \Civi\Payment\Exception\PaymentProcessorException('Stripe Customer (updateMetadata): Missing required parameter: ' . $required);
      }
    }

    $stripeCustomerParams = CRM_Stripe_BAO_StripeCustomer::getStripeCustomerMetadata($params['contact_id'], $params['invoice_settings'] ?? [], $params['description'] ?? NULL);

    try {
      $stripeCustomer = $stripe->stripeClient->customers->update($stripeCustomerID, $stripeCustomerParams);
    }
    catch (Exception $e) {
      $err = $stripe->parseStripeException('create_customer', $e);
      \Civi::log('stripe')->error('Failed to create Stripe Customer: ' . $err['message'] . '; ' . print_r($err, TRUE));
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to update Stripe Customer: ' . $err['code']);
    }
    return $stripeCustomer;
  }

  /**
   * Update the metadata at Stripe for a given contactid
   *
   * @param int $contactID
   *
   * @return void
   */
  public static function updateMetadataForContact(int $contactID): void {
    $customers = StripeCustomer::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->execute();

    // Could be multiple customer_id's and/or stripe processors
    foreach ($customers as $customer) {
      /** @var CRM_Core_Payment_Stripe $stripe */
      \Civi\Api4\StripeCustomer::updateStripe(FALSE)
        ->setPaymentProcessorID($customer['processor_id'])
        ->setContactID($contactID)
        ->setCustomerID($customer['customer_id'])
        ->execute()
        ->first();
      $stripe = \Civi\Payment\System::singleton()->getById($customer['processor_id']);
      CRM_Stripe_BAO_StripeCustomer::updateMetadata(
        ['contact_id' => $contactID, 'processor_id' => $customer['processor_id']],
        $stripe,
        $customer['customer_id']
      );
    }
  }

}
