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

/**
 * Collection of upgrade steps.
 * DO NOT USE a naming scheme other than upgrade_N, where N is an integer.
 * Naming scheme upgrade_X_Y_Z is offically wrong!
 * https://chat.civicrm.org/civicrm/pl/usx3pfjzjbrhzpewuggu1e6ftw
 */
class CRM_Stripe_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Add is_live column to civicrm_stripe_plans and civicrm_stripe_customers tables.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1_9_003() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    // Add is_live column to civicrm_stripe_plans and civicrm_stripe_customers tables.
    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_customers' AND COLUMN_NAME = 'is_live'";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$dbName, 'String']]);
    $live_column_exists = $dao->N == 0 ? FALSE : TRUE;
    if (!$live_column_exists) {
      $this->ctx->log->info('Applying civicrm_stripe update 1903.  Adding is_live to civicrm_stripe_plans and civicrm_stripe_customers tables.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_customers ADD COLUMN `is_live` tinyint(4) NOT NULL COMMENT "Whether this is a live or test transaction"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_plans ADD COLUMN `is_live` tinyint(4) NOT NULL COMMENT "Whether this is a live or test transaction"');
    }
    else {
      $this->ctx->log->info('Skipped civicrm_stripe update 1903.  Column is_live already present on civicrm_stripe_plans table.');
    }
    return TRUE;
  }

  /**
   * Add processor_id column to civicrm_stripe_customers table.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5001() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_customers' AND COLUMN_NAME = 'processor_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$dbName, 'String']]);
    if ($dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5001.  Column processor_id already present on our customers, plans and subscriptions tables.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5001.  Adding processor_id to the civicrm_stripe_customers, civicrm_stripe_plans and civicrm_stripe_subscriptions tables.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_customers ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_plans ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions ADD COLUMN `processor_id` int(10) DEFAULT NULL COMMENT "ID from civicrm_payment_processor"');
    }
    return TRUE;
  }


  /**
   * Populate processor_id column in civicrm_stripe_customers, civicrm_stripe_plans and civicrm_stripe_subscriptions tables.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5002() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $null_count = CRM_Core_DAO::executeQuery('SELECT COUNT(*) FROM civicrm_stripe_customers where processor_id IS NULL') +
      CRM_Core_DAO::executeQuery('SELECT COUNT(*) FROM civicrm_stripe_plans where processor_id IS NULL') +
      CRM_Core_DAO::executeQuery('SELECT COUNT(*) FROM civicrm_stripe_subscriptions where processor_id IS NULL');
    if ($null_count == 0) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5002.  No nulls found in column processor_id in our tables.');
      return TRUE;
    }
    else {
      try {
        // Set processor ID if there's only one.
        $processorCount = civicrm_api3('PaymentProcessorType', 'get', [
          'name' => "Stripe",
          'api.PaymentProcessor.get' => ['is_test' => 0],
        ]);
        foreach ($processorCount['values'] as $processorType) {
          if (!empty($processorType['api.PaymentProcessor.get']['id'])) {
            $stripe_live = $processorType['api.PaymentProcessor.get']['id'];
            $stripe_test = $stripe_live + 1;
            $p = [
              1 => [$stripe_live, 'Integer'],
              2 => [$stripe_test, 'Integer'],
            ];
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_customers` SET processor_id = %1 where processor_id IS NULL and is_live = 1', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_customers` SET processor_id = %2 where processor_id IS NULL and is_live = 0', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_plans` SET processor_id = %1 where processor_id IS NULL and is_live = 1', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_plans` SET processor_id = %2 where processor_id IS NULL and is_live = 0', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_subscriptions` SET processor_id = %1 where processor_id IS NULL and is_live = 1', $p);
            CRM_Core_DAO::executeQuery('UPDATE `civicrm_stripe_subscriptions` SET processor_id = %2 where processor_id IS NULL and is_live = 0', $p);
          }
        }
      } catch (CiviCRM_API3_Exception $e) {
        Civi::log()->debug("Cannot find a PaymentProcessorType named Stripe.");
        return;
      }
    }
    return TRUE;
  }


  /**
   * Add subscription_id column to civicrm_stripe_subscriptions table.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5003() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_subscriptions' AND COLUMN_NAME = 'subscription_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$dbName, 'String']]);

    if ($dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5003.  Column  subscription_id already present in civicrm_stripe_subscriptions table.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5003.  Adding subscription_id to civicrm_stripe_subscriptions.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions ADD COLUMN `subscription_id` varchar(255) DEFAULT NULL COMMENT "Subscription ID from Stripe" FIRST');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions` ADD UNIQUE KEY(`subscription_id`)');

    }
    return TRUE;
  }

  /**
   * Populates the subscription_id column in table civicrm_stripe_subscriptions.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5004() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $null_count = CRM_Core_DAO::executeQuery('SELECT COUNT(*) FROM civicrm_stripe_subscriptions where subscription_id IS NULL');
    if ($null_count == 0) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5004.  No nulls found in column subscription_id in our civicrm_stripe_subscriptions table.');
    }
    else {
      $customer_infos = CRM_Core_DAO::executeQuery("SELECT customer_id,processor_id
      FROM `civicrm_stripe_subscriptions`;");
      while ($customer_infos->fetch()) {
        $processor_id = $customer_infos->processor_id;
        $customer_id = $customer_infos->customer_id;
        try {
          /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
          $paymentProcessor = \Civi\Payment\System::singleton()->getById($processor_id);

          $subscription = $paymentProcessor->stripeClient->subscriptions->all([
            'customer' => $customer_id,
            'limit' => 1,
          ]);
        } catch (Exception $e) {
          // Don't quit here.  A missing customer in Stipe is OK.  They don't exist, so they can't have a subscription.
          Civi::log()->debug('Cannot find Stripe API key: ' . $e->getMessage());
        }
        if (!empty($subscription['data'][0]['id'])) {
          $query_params = [
            1 => [$subscription['data'][0]['id'], 'String'],
            2 => [$customer_id, 'String'],
          ];
          CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET subscription_id = %1 where customer_id = %2;', $query_params);
          unset($subscription);
        }
      }
    }
    return TRUE;
  }

  /**
   * Add contribution_recur_id column to civicrm_stripe_subscriptions table.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5005() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_subscriptions' AND COLUMN_NAME = 'contribution_recur_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$dbName, 'String']]);

    if ($dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5005.  Column contribution_recur_id already present in civicrm_stripe_subscriptions table.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5005.  Adding contribution_recur_id to civicrm_stripe_subscriptions table.');
      CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_subscriptions
       ADD COLUMN `contribution_recur_id` int(10) UNSIGNED DEFAULT NULL
       COMMENT "FK ID from civicrm_contribution_recur" AFTER `customer_id`');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions` ADD INDEX(`contribution_recur_id`);');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions` ADD CONSTRAINT `FK_civicrm_stripe_contribution_recur_id` FOREIGN KEY (`contribution_recur_id`) REFERENCES `civicrm_contribution_recur`(`id`) ON DELETE SET NULL ON UPDATE RESTRICT;');
    }
    return TRUE;
  }

  /**
   *  Method 1 for populating the contribution_recur_id column in the civicrm_stripe_subscriptions table.
   *  ( A simple approach if that works if there have never been any susbcription edits in the Stripe UI. )
   *
   * @return TRUE on success
   * @throws Exception
   */

  public function upgrade_5006() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $subscriptions = CRM_Core_DAO::executeQuery("SELECT invoice_id,is_live
      FROM `civicrm_stripe_subscriptions`;");
    while ($subscriptions->fetch()) {
      $test_mode = (int) !$subscriptions->is_live;
      try {
        // Fetch the recurring contribution Id.
        $recur_id = civicrm_api3('Contribution', 'getvalue', [
          'sequential' => 1,
          'return' => "contribution_recur_id",
          'invoice_id' => $subscriptions->invoice_id,
          'contribution_test' => $test_mode,
        ]);
      } catch (CiviCRM_API3_Exception $e) {
        // Don't quit here. If we can't find the recurring ID for a single customer, make a note in the error log and carry on.
        Civi::log()->debug('Recurring contribution search: ' . $e->getMessage());
      }
      if (!empty($recur_id)) {
        $p = [
          1 => [$recur_id, 'Integer'],
          2 => [$subscriptions->invoice_id, 'String'],
        ];
        CRM_Core_DAO::executeQuery('UPDATE civicrm_stripe_subscriptions SET contribution_recur_id = %1 WHERE invoice_id = %2;', $p);
      }
    }
    return TRUE;
  }

  /**
   * Add change default NOT NULL to NULL in vestigial invoice_id column in civicrm_stripe_subscriptions table if needed. (issue #192)
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5008() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = 'civicrm_stripe_subscriptions' AND COLUMN_NAME = 'invoice_id'";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$dbName, 'String']]);

    if (!$dao->N) {
      $this->ctx->log->info('Skipped civicrm_stripe update 5008. Column not present.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5008. Altering invoice_id to be default NULL.');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_subscriptions`
        MODIFY COLUMN `invoice_id` varchar(255) NULL default ""
        COMMENT "Safe to remove this column if the update retrieving subscription IDs completed satisfactorily."');
    }
    return TRUE;
  }

  /**
   * Add remove unique from email and add to customer in civicrm_stripe_customers tables. (issue #191)
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_5009() {
    $config = CRM_Core_Config::singleton();
    $dbName = DB::connect($config->dsn)->_db;

    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = %1
      AND TABLE_NAME = 'civicrm_stripe_customers'
      AND COLUMN_NAME = 'id'
      AND COLUMN_KEY = 'UNI'";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$dbName, 'String']]);
    if ($dao->N) {
      $this->ctx->log->info('id is already unique in civicrm_stripe_customers table, no need for civicrm_stripe update 5009.');
    }
    else {
      $this->ctx->log->info('Applying civicrm_stripe update 5009.  Setting unique key from email to id on civicrm_stripe_plans table.');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_customers` DROP INDEX email');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_customers` ADD UNIQUE (id)');
    }
    return TRUE;
  }

  public function upgrade_5010() {
    $this->ctx->log->info('Applying Stripe update 5010.  Adding contact_id to civicrm_stripe_customers.');
    if (!CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_stripe_customers', 'contact_id', FALSE)) {
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_customers`
       ADD COLUMN `contact_id` int(10) UNSIGNED DEFAULT NULL COMMENT "FK ID from civicrm_contact"');
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_customers`
       ADD CONSTRAINT `FK_civicrm_stripe_customers_contact_id` FOREIGN KEY (`contact_id`) REFERENCES `civicrm_contact` (`id`) ON DELETE CASCADE;');
    }

    $this->ctx->log->info('Applying Stripe update 5010. Getting Contact IDs for civicrm_stripe_customers.');
    civicrm_api3('StripeCustomer', 'updatecontactids', []);

    return TRUE;
  }

  public function upgrade_5020() {
    $this->ctx->log->info('Applying Stripe update 5020.  Migrate civicrm_stripe_subscriptions data to recurring contributions.');
    civicrm_api3('StripeSubscription', 'updatetransactionids', []);

    return TRUE;
  }

  public function upgrade_5021() {
    $this->ctx->log->info('Applying Stripe update 5021.  Copy trxn_id to processor_id so we can cancel recurring contributions.');
    civicrm_api3('StripeSubscription', 'copytrxnidtoprocessorid', []);

    return TRUE;
  }

  public function upgrade_5022() {
    $this->ctx->log->info('Applying Stripe update 5021.  Remove is_live NOT NULL constraint as we don\'t use this parameter any more');
    if (CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_stripe_customers', 'is_live', FALSE)) {
      CRM_Core_DAO::executeQuery('ALTER TABLE `civicrm_stripe_customers`
        MODIFY COLUMN `is_live` tinyint(4) COMMENT "Whether this is a live or test transaction"');
    }

    return TRUE;
  }

  public function upgrade_5023() {
    $this->ctx->log->info('Applying Stripe update 5023.  Swap over public/secret key settings');
    $stripeProcessors = civicrm_api3('PaymentProcessor', 'get', [
      'payment_processor_type_id' => "Stripe",
    ]);
    foreach ($stripeProcessors['values'] as $processor) {
      if ((substr($processor['user_name'], 0, 3) === 'sk_')
        && (substr($processor['password'], 0, 3) === 'pk_')) {
        // Need to switch over parameters
        $createParams = [
          'id' => $processor['id'],
          'user_name' => $processor['password'],
          'password' => $processor['user_name'],
        ];
        civicrm_api3('PaymentProcessor', 'create', $createParams);
      }
    }
    CRM_Utils_System::flushCache();
    return TRUE;
  }

  public function upgrade_5024() {
    $this->ctx->log->info('Applying Stripe update 5024. Add the civicrm_stripe_paymentintent database table');
    CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, E::path('/sql/paymentintent_install.sql'));
    CRM_Utils_System::flushCache();
    return TRUE;
  }

  public function upgrade_5025() {
    $this->ctx->log->info('Applying Stripe update 5025. Add referrer column to civicrm_stripe_paymentintent database table');
    if (!CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_stripe_paymentintent', 'referrer', FALSE)) {
      CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_stripe_paymentintent`
        ADD COLUMN `referrer` varchar(255) NULL   COMMENT 'HTTP referrer of this paymentIntent'");
    }
    return TRUE;
  }

  public function upgrade_5026() {
    $this->ctx->log->info('Change paymentintent_id column to stripe_intent_id in civicrm_stripe_paymentintent database table');
    if (CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_stripe_paymentintent', 'paymentintent_id')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_stripe_paymentintent
        CHANGE paymentintent_id stripe_intent_id varchar(255) COMMENT 'The Stripe PaymentIntent/SetupIntent/PaymentMethod ID'");
    }
    if (CRM_Core_BAO_SchemaHandler::checkIfIndexExists('civicrm_stripe_paymentintent', 'UI_paymentintent_id')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_stripe_paymentintent
        DROP INDEX UI_paymentintent_id, ADD INDEX UI_stripe_intent_id (stripe_intent_id)");
    }
    if (!CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_stripe_paymentintent', 'extra_data')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_stripe_paymentintent
        ADD COLUMN `extra_data` varchar(255) NULL   COMMENT 'Extra data collected to help with diagnostics (such as email, name)'");
    }
    return TRUE;
  }

  public function upgrade_6801() {
    $this->ctx->log->info('In civicrm_stripe_paymentintent database table, change extra_data from varchar to text');
    CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_paymentintent MODIFY extra_data TEXT');
    return TRUE;
  }

  public function upgrade_6802() {
    $this->ctx->log->info('Drop unused \'email\' and \'is_live\' columns from civicrm_stripe_customers database table');
    if (CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_stripe_customers', 'email')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_stripe_customers DROP COLUMN email");
    }
    if (CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_stripe_customers', 'is_live')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_stripe_customers DROP COLUMN is_live");
    }
    return TRUE;
  }

  public function upgrade_6803() {
    $this->ctx->log->info('In civicrm_stripe_customers database table, rename id to customer_id, add new id column');
    if (!CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_stripe_customers', 'customer_id')) {
      // ALTER TABLE ... RENAME COLUMN only in MySQL8+
      CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_stripe_customers CHANGE COLUMN id customer_id varchar(255) COMMENT 'Stripe Customer ID'");
      if (CRM_Core_BAO_SchemaHandler::checkIfIndexExists('civicrm_stripe_customers', 'id')) {
        CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_stripe_customers DROP INDEX id');
        CRM_Core_DAO::executeQuery('CREATE INDEX customer_id ON civicrm_stripe_customers (customer_id)');
      }
      CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_stripe_customers ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique ID' FIRST");
    }
    return TRUE;
  }

  public function upgrade_6900() {
    $this->ctx->log->info('Add currency to civicrm_stripe_customers because the customer can only have one currency for subscriptions');
    if (!CRM_Core_BAO_SchemaHandler::checkIfFieldExists('civicrm_stripe_customers', 'currency')) {
      CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_stripe_customers ADD COLUMN currency varchar(3) DEFAULT NULL COMMENT '3 character string, value from Stripe customer.'");
    }
    return TRUE;
  }

}
