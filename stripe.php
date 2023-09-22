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

require_once 'stripe.civix.php';
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}

use CRM_Stripe_ExtensionUtil as E;

/**
 * Implementation of hook_civicrm_config().
 */
function stripe_civicrm_config(&$config) {
  _stripe_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install().
 */
function stripe_civicrm_install() {
  _stripe_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable().
 */
function stripe_civicrm_enable() {
  _stripe_civix_civicrm_enable();
}

/**
 * Add stripe.js to forms, to generate stripe token
 * hook_civicrm_alterContent is not called for all forms (eg. CRM_Contribute_Form_Contribution on backend)
 *
 * @param string $formName
 * @param \CRM_Core_Form $form
 *
 * @throws \CRM_Core_Exception
 */
function stripe_civicrm_buildForm($formName, &$form) {
  // Don't load stripe js on ajax forms
  if (CRM_Utils_Request::retrieveValue('snippet', 'String') === 'json') {
    return;
  }

  switch ($formName) {
    case 'CRM_Admin_Form_PaymentProcessor':
      $paymentProcessor = $form->getVar('_paymentProcessorDAO');
      if ($paymentProcessor && $paymentProcessor->class_name === 'Payment_Stripe') {
        // Hide configuration fields that we don't use
        foreach (['accept_credit_cards', 'url_site', 'url_recur', 'test_url_site', 'test_url_recur'] as $element) {
          if ($form->elementExists($element)) {
            $form->removeElement($element);
          }
        }
      }
      break;
  }
}

/**
 * Implements hook_civicrm_check().
 *
 * @throws \CiviCRM_API3_Exception
 */
function stripe_civicrm_check(&$messages) {
  $checks = new CRM_Stripe_Check($messages);
  $messages = $checks->checkRequirements();
}

/**
 * Implements hook_civicrm_navigationMenu().
 */
function stripe_civicrm_navigationMenu(&$menu) {
  _stripe_civix_insert_navigation_menu($menu, 'Administer/CiviContribute', [
    'label' => E::ts('Stripe Settings'),
    'name' => 'stripe_settings',
    'url' => 'civicrm/admin/setting/stripe',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _stripe_civix_navigationMenu($menu);
}

/**
 * Implements hook_civicrm_alterLogTables().
 *
 * Exclude tables from logging tables since they hold mostly temp data.
 */
function stripe_civicrm_alterLogTables(&$logTableSpec) {
  unset($logTableSpec['civicrm_stripe_paymentintent']);
}

/**
 * Implements hook_civicrm_permission().
 *
 * @see CRM_Utils_Hook::permission()
 */
function stripe_civicrm_permission(&$permissions) {
  if (\Civi::settings()->get('stripe_moto')) {
    $permissions['allow stripe moto payments'] = E::ts('CiviCRM Stripe: Process MOTO transactions');
  }
}

/*
 * Implements hook_civicrm_post().
 */
function stripe_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  switch ($objectName) {
    case 'Contact':
    case 'Individual':
      switch ($op) {
        case 'merge':
          try {
            CRM_Stripe_BAO_StripeCustomer::updateMetadataForContact($objectId);
          }
          catch (Exception $e) {
            \Civi::log(E::SHORT_NAME)->error('Stripe Contact Merge failed: ' . $e->getMessage());
          }
          break;

        case 'edit':
          register_shutdown_function('stripe_civicrm_shutdown_updatestripecustomer', $objectId);
      }
      break;

    case 'Email':
      if (in_array($op, ['create', 'edit'])) {
        if ($objectRef->N == 0) {
          // Object may not be loaded; may not have contact_id available yet.
          $objectRef->find(TRUE);
        }
        if ($objectRef->contact_id) {
          register_shutdown_function('stripe_civicrm_shutdown_updatestripecustomer', $objectRef->contact_id);
        }
      }
  }
}

/**
 * Update the Stripe Customers for a contact (metadata)
 *
 * @param int $contactID
 *
 * @return void
 */
function stripe_civicrm_shutdown_updatestripecustomer(int $contactID) {
  if (isset(\Civi::$statics['stripe_civicrm_shutdown_updatestripecustomer'][$contactID])) {
    // Don't run the update more than once
    return;
  }
  \Civi::$statics['stripe_civicrm_shutdown_updatestripecustomer'][$contactID] = TRUE;

  try {
    // Does the contact have a Stripe customer record?
    $stripeCustomers = \Civi\Api4\StripeCustomer::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->execute();
    // Update the contact details at Stripe for each customer associated with this contact
    foreach ($stripeCustomers as $stripeCustomer) {
      \Civi\Api4\StripeCustomer::updateStripe(FALSE)
        ->setCustomerID($stripeCustomer['customer_id'])
        ->execute();
    }
  }
  catch (Exception $e) {
    \Civi::log(E::SHORT_NAME)->error('Stripe Contact update failed: ' . $e->getMessage());
  }

}
