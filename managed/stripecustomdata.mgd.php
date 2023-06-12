<?php

/**
 * The record will be automatically inserted, updated, or deleted from the
 * database as appropriate. For more details, see "hook_civicrm_managed" at:
 * https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
 */

use \CRM_Stripe_ExtensionUtil as E;

// Group is added by mjwshared
$customGroup = \Civi\Api4\CustomGroup::get(FALSE)
  ->addWhere('name', '=', 'Payment_details')
  ->execute()
  ->first();
if (empty($customGroup)) {
  return [];
}
else {
  return [
    [
      'name' => 'CustomGroup_Payment_details_CustomField_available_on',
      'entity' => 'CustomField',
      'cleanup' => 'never',
      'update' => 'unmodified',
      'params' => [
        'version' => 4,
        'values' => [
          'custom_group_id.name' => 'Payment_details',
          'name' => 'available_on',
          'label' => E::ts('Available On'),
          'data_type' => 'Date',
          'html_type' => 'Select Date',
          'default_value' => NULL,
          'is_required' => FALSE,
          'is_searchable' => TRUE,
          'is_search_range' => FALSE,
          'help_pre' => E::ts('If there is a delay between your payment provider receiving the payment and it arriving in your bank account this is the date that it should be available in your account.'),
          'help_post' => NULL,
          'attributes' => NULL,
          'is_active' => TRUE,
          'is_view' => TRUE,
          'options_per_line' => NULL,
          'text_length' => 255,
          'start_date_years' => NULL,
          'end_date_years' => NULL,
          'date_format' => 'yy-mm-dd',
          'time_format' => 2,
          'note_columns' => 60,
          'note_rows' => 4,
          'column_name' => 'available_on',
          'option_group_id' => NULL,
          'serialize' => 0,
          'filter' => NULL,
          'in_selector' => FALSE,
          'fk_entity' => NULL,
        ],
        'match' => [
          'name',
        ],
      ],
    ],
    [
      'name' => 'CustomGroup_Payment_details_CustomField_exchange_rate',
      'entity' => 'CustomField',
      'cleanup' => 'never',
      'update' => 'unmodified',
      'params' => [
        'version' => 4,
        'values' => [
          'custom_group_id.name' => 'Payment_details',
          'name' => 'exchange_rate',
          'label' => E::ts('Exchange Rate'),
          'data_type' => 'Float',
          'html_type' => 'Text',
          'default_value' => NULL,
          'is_required' => FALSE,
          'is_searchable' => TRUE,
          'is_search_range' => FALSE,
          'help_pre' => NULL,
          'help_post' => NULL,
          'attributes' => NULL,
          'is_active' => TRUE,
          'is_view' => FALSE,
          'options_per_line' => NULL,
          'text_length' => 255,
          'start_date_years' => NULL,
          'end_date_years' => NULL,
          'date_format' => NULL,
          'time_format' => NULL,
          'note_columns' => 60,
          'note_rows' => 4,
          'column_name' => 'exchange_rate',
          'option_group_id' => NULL,
          'serialize' => 0,
          'filter' => NULL,
          'in_selector' => FALSE,
          'fk_entity' => NULL,
        ],
        'match' => [
          'name',
        ],
      ],
    ],
    [
      'name' => 'CustomGroup_Payment_details_CustomField_payout_amount',
      'entity' => 'CustomField',
      'cleanup' => 'never',
      'update' => 'unmodified',
      'params' => [
        'version' => 4,
        'values' => [
          'custom_group_id.name' => 'Payment_details',
          'name' => 'payout_amount',
          'label' => 'Payout Amount',
          'data_type' => 'Money',
          'html_type' => 'Text',
          'default_value' => NULL,
          'is_required' => FALSE,
          'is_searchable' => TRUE,
          'is_search_range' => FALSE,
          'help_pre' => NULL,
          'help_post' => NULL,
          'attributes' => NULL,
          'javascript' => NULL,
          'is_active' => TRUE,
          'is_view' => FALSE,
          'options_per_line' => NULL,
          'text_length' => 255,
          'start_date_years' => NULL,
          'end_date_years' => NULL,
          'date_format' => NULL,
          'time_format' => NULL,
          'note_columns' => 60,
          'note_rows' => 4,
          'column_name' => 'payout_amount',
          'option_group_id' => NULL,
          'serialize' => 0,
          'filter' => NULL,
          'in_selector' => FALSE,
        ],
        'match' => [
          'name',
        ],
      ],
    ],
    [
      'name' => 'CustomGroup_Payment_details_CustomField_payout_currency',
      'entity' => 'CustomField',
      'cleanup' => 'never',
      'update' => 'unmodified',
      'params' => [
        'version' => 4,
        'values' => [
          'custom_group_id.name' => 'Payment_details',
          'name' => 'payout_currency',
          'label' => 'Payout Currency',
          'data_type' => 'String',
          'html_type' => 'Text',
          'default_value' => NULL,
          'is_required' => FALSE,
          'is_searchable' => FALSE,
          'is_search_range' => FALSE,
          'help_pre' => NULL,
          'help_post' => NULL,
          'attributes' => NULL,
          'javascript' => NULL,
          'is_active' => TRUE,
          'is_view' => FALSE,
          'options_per_line' => NULL,
          'text_length' => 255,
          'start_date_years' => NULL,
          'end_date_years' => NULL,
          'date_format' => NULL,
          'time_format' => NULL,
          'note_columns' => 60,
          'note_rows' => 4,
          'column_name' => 'payout_currency',
          'option_group_id' => NULL,
          'serialize' => 0,
          'filter' => NULL,
          'in_selector' => FALSE,
        ],
        'match' => [
          'name',
        ],
      ],
    ],
  ];
}
