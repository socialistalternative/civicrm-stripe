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

return [
  'stripe_oneoffreceipt' => [
    'name' => 'stripe_oneoffreceipt',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 1,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Allow Stripe to send a receipt for one-off payments?'),
    'description' => E::ts('Sets the "email_receipt" parameter on a Stripe Charge so that Stripe can send an email receipt.'),
    'html_attributes' => [],
    'settings_pages' => [
      'stripe' => [
        'weight' => 10,
      ]
    ],
  ],
  'stripe_nobillingaddress' => [
    'name' => 'stripe_nobillingaddress',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 1,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Disable billing address fields'),
    'description' => E::ts('Disable the fixed billing address fields block. Historically this was required by CiviCRM but since Stripe 6.x the stripe element collects everything it requires to make payment.'),
    'html_attributes' => [],
    'settings_pages' => [
      'stripe' => [
        'weight' => 20,
      ]
    ],
  ],
  'stripe_statementdescriptor' => [
    'name' => 'stripe_statementdescriptor',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Statement Descriptor'),
    'description' => E::ts('The text that will be shown on the customer bank/card statement.
If this is empty it will be generated by CiviCRM using the information available (Contact/ContributionID + event/contribution title).
<br/>If you want to use a fixed descriptor specify one here - make sure you comply with the <a href="%1" target="_blank">Statement descriptor requirements</a>.
<br/>Max length 22 characters.',
      [
        1 => 'https://stripe.com/docs/statement-descriptors',
      ]),
    'html_attributes' => [
      'size' => 30,
      'maxlength' => 22,
    ],
    'settings_pages' => [
      'stripe' => [
        'weight' => 25,
      ]
    ],
  ],
  'stripe_statementdescriptorsuffix' => [
    'name' => 'stripe_statementdescriptorsuffix',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Statement descriptor Suffix (Cards only)'),
    'description' => E::ts('For credit cards you can specify a static <a href="%2" target="_blank">"prefix"</a> in the Stripe account dashboard.
If this is empty the "suffix" will be generated by CiviCRM using the information available (Contact/ContributionID + event/contribution title).
<br/>If you want to use a fixed descriptor specify the suffix here - make sure you comply with the <a href="%1" target="_blank">Statement descriptor requirements</a>.
<br/>Max length 12 characters',
      [
        1 => 'https://stripe.com/docs/statement-descriptors',
        2 => 'https://stripe.com/docs/statement-descriptors#static',
      ]),
    'html_attributes' => [
      'size' => 20,
      'maxlength' => 12,
    ],
    'settings_pages' => [
      'stripe' => [
        'weight' => 26,
      ]
    ],
  ],
  'stripe_enable_public_future_recur_start' => [
    'name' => 'stripe_enable_public_future_recur_start',
    'type' => 'Array',
    'html_type' => 'select',
    'default' => [],
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Enable public selection of future recurring start dates for intervals'),
    'description' => E::ts('Allow public selection of start date for a recurring contribution for intervals'),
    'html_attributes' => [
      'multiple' => TRUE,
      'class' => 'crm-select2',
    ],
    'pseudoconstant' => [
      'optionGroupName' => 'recur_frequency_units',
      'keyColumn' => 'name',
      'optionEditPath' => 'civicrm/admin/options/recur_frequency_units',
    ],
    'settings_pages' => [
      'stripe' => [
        'weight' => 40,
      ]
    ],
  ],
  'stripe_future_recur_start_days' => [
    'name' => 'stripe_future_recur_start_days',
    'type' => 'Array',
    'html_type' => 'select',
    'html_attributes' => [
      'size' => 29,
      'multiple' => TRUE
    ],
    'pseudoconstant' => ['callback' => 'CRM_Stripe_Recur::getRecurStartDays'],
    'default' => [0],
    'title' => E::ts('Restrict allowable days of the month for Recurring Contributions'),
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Restrict allowable days of the month'),
    'settings_pages' => [
      'stripe' => [
        'weight' => 41,
      ],
    ],
  ],
  'stripe_webhook_processing_limit' => [
    'name' => 'stripe_webhook_processing_limit',
    'type' => 'Integer',
    'html_type' => 'text',
    'default' => 50,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Maximum number of webhooks to process simultaneously.'),
    'description' => E::ts('Default 50. This helps prevents webhooks from Stripe failing if a large number are triggered at the same time by delaying processing of any over this limit.'),
    'html_attributes' => [
      'size' => 10,
      'maxlength' => 3,
    ],
    'settings_pages' => [
      'stripe' => [
        'weight' => 60,
      ]
    ],
  ],
  'stripe_ipndebug' => [
    'name' => 'stripe_ipndebug',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Enable Stripe IPN (Webhook) debugging?'),
    'description' => E::ts('Enables debugging to CiviCRM log for IPN / webhook issues.'),
    'html_attributes' => [],
    'settings_pages' => [
      'stripe' => [
        'weight' => 100,
      ]
    ],
  ],
  'stripe_moto' => [
    'name' => 'stripe_moto',
    'type' => 'Array',
    'html_type' => 'Select',
    'default' => [],
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Enable Mail Order Telephone Order (MOTO) transactions'),
    'description' => E::ts('If enabled a checkbox will appear below the card details which, if checked, will submit the payment as MOTO and will not require (SCA/3DSecure) customer challenges.
Do NOT enable unless you\'ve enabled this feature on your Stripe account - see <a href="%1">Stripe MOTO payments</a>.
You also need to give the permission "CiviCRM Stripe: Process MOTO transactions" to any users that should be allowed to do this - do NOT give to unprivileged users!', [1 => 'https://support.stripe.com/questions/mail-order-telephone-order-moto-transactions-when-to-categorize-transactions-as-moto']),
    'html_attributes' => [
      'multiple' => TRUE,
      'class' => 'crm-select2',
    ],
    'options' => [
      'backend' => E::ts('Backoffice forms'),
      'frontend' => E::ts('Frontend forms (contribution/event pages)'),
    ],
    'settings_pages' => [
      'stripe' => [
        'weight' => 110,
      ]
    ],
  ],

  'stripe_minamount' => [
    'name' => 'stripe_minamount',
    'type' => 'Integer',
    'html_type' => 'text',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Minimum amount that Stripe is allowed to process.'),
    'description' => E::ts('Default 0. This can help reduce the impact of card testing attacks as they tend to use low amounts eg. $1. If you know that your will never take payments under eg. $10 you can set that here and the request will fail for anything below that amount.'),
    'html_attributes' => [
      'size' => 10,
      'maxlength' => 3,
    ],
    'settings_pages' => [
      'stripe' => [
        'weight' => 120,
      ]
    ],
  ],
  'stripe_checkout_supported_payment_methods' => [
    'name' => 'stripe_checkout_supported_payment_methods',
    'type' => 'Array',
    'html_type' => 'select',
    'html_attributes' => [
      'multiple' => TRUE,
      'class' => 'crm-select2',
    ],
    'required' => TRUE,
    'pseudoconstant' => ['callback' => 'CRM_Stripe_Api::getListOfSupportedPaymentMethodsCheckout'],
    'default' => ['card'],
    'title' => E::ts('Supported payment methods for Stripe Checkout'),
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Link and Google/Apple Pay are controlled via your Stripe Dashboard. Payment method will only appear if all criteria are met (eg. for SEPA currency must be EUR)'),
    'settings_pages' => [
      'stripe' => [
        'weight' => 30,
      ],
    ],
  ],
  'stripe_cardelement_disablelink' => [
    'name' => 'stripe_cardelement_disablelink',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Disable Stripe "Link" in Card element'),
    'description' => E::ts('Stripe might add a "Link button" (https://stripe.com/payments/link) which can be controlled via the dashboard. This setting allows you to force it to disabled.'),
    'html_attributes' => [],
    'settings_pages' => [
      'stripe' => [
        'weight' => 10,
      ]
    ],
  ],

];
