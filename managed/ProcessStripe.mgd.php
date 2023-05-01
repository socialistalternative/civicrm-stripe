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

return [
  0 =>
    [
      'name' => 'ProcessStripe',
      'entity' => 'Job',
      'update' => 'never',
      'params' =>
        [
          'version' => 3,
          'name' => 'Stripe: Cleanup',
          'description' => 'Cleanup Stripe PaymentIntents',
          'run_frequency' => 'Hourly',
          'api_entity' => 'Job',
          'api_action' => 'process_stripe',
          'parameters' => 'delete_old=-3 month
cancel_incomplete=-1 hour',
        ],
    ],
];
