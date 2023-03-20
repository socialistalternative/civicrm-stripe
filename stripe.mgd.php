<?php

/**
 * The record will be automatically inserted, updated, or deleted from the
 * database as appropriate. For more details, see "hook_civicrm_managed" at:
 * https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
 */
return [
  [
    'name' => 'Stripe',
    'entity' => 'PaymentProcessorType',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Stripe',
        'title' => 'Stripe',
        'description' => 'Stripe Payment Processor',
        'is_active' => TRUE,
        'is_default' => FALSE,
        'user_name_label' => 'Publishable key',
        'password_label' => 'Secret Key',
        'signature_label' => 'Webhook Secret',
        'subject_label' => NULL,
        'class_name' => 'Payment_Stripe',
        'url_site_default' => 'http://unused.com',
        'url_api_default' => NULL,
        'url_recur_default' => NULL,
        'url_button_default' => NULL,
        'url_site_test_default' => 'http://unused.com',
        'url_api_test_default' => NULL,
        'url_recur_test_default' => NULL,
        'url_button_test_default' => NULL,
        'billing_mode' => 1,
        'is_recur' => TRUE,
        'payment_type' => 1,
        'payment_instrument_id:name' => 'Credit Card',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  1 => [
    'name' => 'StripeCheckout',
    'entity' => 'PaymentProcessorType',
    'params' => [
      'version' => 3,
      'name' => 'StripeCheckout',
      'title' => 'Stripe Checkout',
      'description' => 'Stripe Checkout Payment Processor',
      'class_name' => 'Payment_StripeCheckout',
      'user_name_label' => 'Publishable key',
      'password_label' => 'Secret Key',
      'signature_label' => 'Webhook Secret',
      'url_site_default' => 'http://unused.com',
      'url_site_test_default' => 'http://unused.com',
      'billing_mode' => 1,
      'payment_type' => 1,
      'is_recur' => 1,
    ],
    'match' => [
      'name',
    ],
  ],

];
