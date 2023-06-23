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
 * @file
 *
 * The purpose of these tests is to test this extension's code. We are not
 * focussed on testing that the StripeAPI behaves as it should, and therefore
 * we mock the Stripe API. This approach enables us to focus on our code,
 * removes external factors like network connectivity, and enables tests to
 * run quickly.
 *
 * Gotchas for developers new to phpunit's mock objects
 *
 * - once you have created a mock and called method('x') you cannot call
 *   method('x') again; you'll need to make a new mock.
 * - $this->any() refers to an argument for a with() matcher.
 * - $this->anything() refers to a method for a method() matcher.
 *
 */

/**
 * Tests simple recurring contribution with IPN.
 *
 * @group headless
 */
require_once('BaseTest.php');

class CRM_Stripe_IpnTest extends CRM_Stripe_BaseTest {

  protected $contributionRecurID;

  /**
   * Test creating a one-off contribution and
   * update it after creation.
   */
  public function testNewOneOffChargeSucceeded() {
    $this->mockOneOffPaymentSetup();
    $success = $this->simulateEvent([
      'type'             => 'charge.succeeded',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'ch_mock',
          'object'       => 'charge',
          'customer'     => 'cus_mock',
          'charge'       => 'ch_mock',
          'created'      => time(),
          'amount'       => $this->total*100,
          'status'       => 'succeeded',
          "captured"     => FALSE,
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // charge is not yet captured so contribution should remain pending
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'ch_mock',
    ]);
  }

  /**
   * Test completing a one-off contribution with trxn_id = paymentIntentID
   * For Stripe checkout we find the contribution using contribution.invoice_id=checkout.client_reference_id
   * Then we set the contribution.trxn_id=checkout.payment_intent_id (we don't have charge_id yet)
   * So when charge.succeeded comes in we need to match on payment_intent_id.
   *
   */
  public function testNewOneOffStripeCheckout() {
    $this->setOrCreateStripeCheckoutPaymentProcessor();
    $this->getMocksForOneOffPayment();
    $contribution = $this->setupPendingContribution(['invoice_id' => md5(uniqid(mt_rand(), TRUE))]);

    // Simulate payment
    $this->assertInstanceOf('CRM_Core_Payment_StripeCheckout', $this->paymentObject);

    //
    // Check the Contribution
    // ...should be pending
    // ...its transaction ID should be our Charge ID.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => '',
      'invoice_id'             => $contribution['invoice_id']
    ]);

    // Set the new contribution to have trxn_id=pi_mock
    $success = $this->simulateEvent([
      'type'             => 'checkout.session.completed',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'cs_mock',
          'object'       => 'checkout.session',
          'customer'     => 'cus_mock',
          'payment_intent' => 'pi_mock',
          'client_reference_id' => $contribution['invoice_id'],
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'pi_mock',
    ]);

    $success = $this->simulateEvent([
      'type'             => 'charge.succeeded',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'ch_mock',
          'object'       => 'charge',
          'customer'     => 'cus_mock',
          'payment_intent' => 'pi_mock',
          'balance_transaction' => 'txn_mock',
          'created'      => time(),
          'amount'       => $this->total*100,
          'currency'     => 'usd',
          'status'       => 'succeeded',
          "captured"     => TRUE,
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Ensure Contribution status is updated to complete and that we now have both invoice ID and charge ID as the transaction ID.
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'pi_mock,ch_mock',
      'fee_amount'             => 11.90
    ]);
  }

  /**
   * charge.succeeded and checkout.session.completed arrive at the same time.
   * If charge.succeeded arrives first we can't match the contribution so we re-trigger it
   * once checkout.session.completed has processed.
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testNewOneOffStripeCheckoutOutOfOrder() {
    $this->setOrCreateStripeCheckoutPaymentProcessor();
    $this->getMocksForOneOffPayment();
    $contribution = $this->setupPendingContribution(['invoice_id' => md5(uniqid(mt_rand(), TRUE))]);

    // Simulate payment
    $this->assertInstanceOf('CRM_Core_Payment_StripeCheckout', $this->paymentObject);

    //
    // Check the Contribution
    // ...should be pending
    // ...its transaction ID should be our Charge ID.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => '',
      'invoice_id'             => $contribution['invoice_id']
    ]);

    // This will be ignored as it comes in before checkout.session.completed
    $success = $this->simulateEvent([
      'type'             => 'charge.succeeded',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'ch_mock',
          'object'       => 'charge',
          'customer'     => 'cus_mock',
          'payment_intent' => 'pi_mock',
          'balance_transaction' => 'trx_mock',
          'created'      => time(),
          'amount'       => $this->total*100,
          'currency'     => 'usd',
          'status'       => 'succeeded',
          'captured'     => TRUE,
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Ensure Contribution status is updated to complete and that we now have both invoice ID and charge ID as the transaction ID.
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => '',
    ]);

    // Create dummy webhook record
    \Civi\Api4\PaymentprocessorWebhook::create(FALSE)
      ->addValue('payment_processor_id', $this->paymentProcessorID)
      ->addValue('event_id', 'ev_mock')
      ->addValue('trigger', 'charge.succeeded')
      ->addValue('status', 'success')
      ->addValue('identifier', 'pi_mock::')
      ->addValue('data', '')
      ->addValue('message', 'already processed')
      ->execute();

    // Set the new contribution to have trxn_id=pi_mock
    $success = $this->simulateEvent([
      'type'             => 'checkout.session.completed',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'cs_mock',
          'object'       => 'checkout.session',
          'customer'     => 'cus_mock',
          'payment_intent' => 'pi_mock',
          'client_reference_id' => $contribution['invoice_id'],
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'pi_mock',
    ]);

    $chargeSucceededWebhook = \Civi\Api4\PaymentprocessorWebhook::get(FALSE)
      ->addWhere('identifier', 'CONTAINS', 'pi_mock')
      ->addWhere('trigger', '=', 'charge.succeeded')
      ->addWhere('status', '=', 'new')
      ->addWhere('processed_date', 'IS EMPTY')
      ->execute()
      ->first();
    $this->assertNotEmpty($chargeSucceededWebhook, 'charge.succeeded should queued for processing but is not');

    // Now trigger charge.succeeded again
    $success = $this->simulateEvent([
      'type'             => 'charge.succeeded',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'ch_mock',
          'object'       => 'charge',
          'customer'     => 'cus_mock',
          'payment_intent' => 'pi_mock',
          'balance_transaction' => 'txn_mock',
          'created'      => time(),
          'amount'       => $this->total*100,
          'currency'     => 'usd',
          'status'       => 'succeeded',
          'captured'     => TRUE,
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Ensure Contribution status is updated to complete and that we now have both invoice ID and charge ID as the transaction ID.
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'pi_mock,ch_mock',
      'fee_amount'             => 11.90
    ]);
  }

  /**
   * Test creating a one-off contribution and
   * update it after creation.
   */
  public function testNewOneOffChargeCaptured() {
    $this->mockOneOffPaymentSetup();
    $success = $this->simulateEvent([
      'type'             => 'charge.captured',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'ch_mock',
          'object'       => 'charge',
          'customer'     => 'cus_mock',
          'charge'       => 'ch_mock',
          'balance_transaction' => 'txn_mock',
          'created'      => time(),
          'amount'       => $this->total*100,
          'currency'     => 'usd',
          'status'       => 'succeeded',
          'captured'     => TRUE,
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Ensure Contribution status is updated to complete and that we now have both invoice ID and charge ID as the transaction ID.
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'ch_mock',
      'fee_amount'             => 11.90
    ]);

    // Check we set some values on the FinancialTrxn (payment)
    $this->checkFinancialTrxn([
      'Payment_details.available_on' => '2023-06-10 21:05:05',
      'fee_amount' => 11.90,
      'total_amount' => $this->total,
      'order_reference' => 'ch_mock',
      'trxn_id' => 'ch_mock'
    ],
      $this->contributionID
    );
  }

  /**
   * Test creating a one-off contribution and
   * update it after creation.
   */
  public function testNewOneOffChargeRefundedFull() {
    $doPaymentResult = $this->mockOneOffPaymentSetup();

    if ($doPaymentResult['payment_status'] === 'Completed') {
      civicrm_api3('Payment', 'create', [
        'trxn_id' => $doPaymentResult['trxn_id'],
        'total_amount' => $this->total,
        'fee_amount' => $doPaymentResult['fee_amount'],
        'order_reference' => $doPaymentResult['order_reference'],
        'contribution_id' => $this->contributionID,
      ]);
    }

    $success = $this->simulateEvent([
      'type'             => 'charge.refunded',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'              => 'ch_mock',
          'object'          => 'charge',
          'customer'        => 'cus_mock',
          'charge'          => 'ch_mock',
          'created'         => time(),
          'amount_refunded' => $this->total*100,
          'status'          => 'succeeded',
          "captured"        => TRUE,
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Ensure Contribution status is updated to complete and that we now have both invoice ID and charge ID as the transaction ID.
    $this->checkContrib([
      'contribution_status_id' => 'Refunded',
      'trxn_id'                => 'ch_mock,re_mock',
    ]);
  }

  /**
   * Unlike charge succeeded, charge failed is processed.
   */
  public function testNewOneOffStripeCheckoutChargeFailed() {
    $this->setOrCreateStripeCheckoutPaymentProcessor();
    $this->getMocksForOneOffPayment();
    $contribution = $this->setupPendingContribution(['invoice_id' => md5(uniqid(mt_rand(), TRUE))]);

    // Simulate payment
    $this->assertInstanceOf('CRM_Core_Payment_StripeCheckout', $this->paymentObject);

    //
    // Check the Contribution
    // ...should be pending
    // ...its transaction ID should be our Charge ID.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => '',
      'invoice_id'             => $contribution['invoice_id']
    ]);

    // Set the new contribution to have trxn_id=pi_mock
    $success = $this->simulateEvent([
      'type'             => 'checkout.session.completed',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'cs_mock',
          'object'       => 'checkout.session',
          'customer'     => 'cus_mock',
          'payment_intent' => 'pi_mock',
          'client_reference_id' => $contribution['invoice_id'],
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'pi_mock',
    ]);

    $success = $this->simulateEvent([
      'type'             => 'charge.failed',
      'id'               => 'evt_mock',
      'object'           => 'event',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'                  => 'ch_mock',
          'object'              => 'charge',
          'amount'              => $this->total*100,
          'amount_captured'     => $this->total*100,
          'captured'            => TRUE,
          'balance_transaction' => 'txn_mock',
          'customer'            => 'cus_mock',
          'payment_intent'      => 'pi_mock',
          'created'             => time(),
          'failure_message'     => 'Mocked failure',
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    //
    // Ensure Contribution is marked Failed, with the reason, and that the
    // ContributionRecur is not changed from Pending.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Failed',
      'trxn_id'                => 'ch_mock',
      'cancel_reason'          => 'Mocked failure',
    ]);
  }

  /**
   * Test creating a recurring contribution and
   * update it after creation. @todo The membership should also be updated.
   */
  public function testNewRecurringInvoicePaymentSucceeded() {
    $this->mockRecurringPaymentSetup();
    $success = $this->simulateEvent([
      'type'             => 'invoice.payment_succeeded',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'in_mock',
          'object'       => 'invoice',
          'subscription' => 'sub_mock',
          'customer'     => 'cus_mock',
          'charge'       => 'ch_mock',
          'created'      => time(),
          'amount_due'   => $this->total*100,
          'currency'     => 'usd',
          'status'      => 'paid',
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Ensure Contribution status is updated to complete and that we now have both invoice ID and charge ID as the transaction ID.
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'ch_mock',
      'fee_amount'             => 11.90
    ]);
    $this->checkContribRecur(['contribution_status_id' => 'In Progress']);
  }

  /**
   * Test creating a recurring contribution and
   * update it after creation. @todo The membership should also be updated.
   */
  public function testNewRecurringInvoicePaid() {
    $this->mockRecurringPaymentSetup();
    $success = $this->simulateEvent([
      'type'             => 'invoice.paid',
      'id'               => 'evt_mock',
      'object'           => 'event', // ?
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'in_mock',
          'object'       => 'invoice',
          'subscription' => 'sub_mock',
          'customer'     => 'cus_mock',
          'charge'       => 'ch_mock',
          'created'      => time(),
          'amount_due'   => $this->total*100,
          'currency'     => 'usd',
          'status'       => 'paid',
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Ensure Contribution status is updated to complete and that we now have both invoice ID and charge ID as the transaction ID.
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'ch_mock',
      'fee_amount'             => 11.90
    ]);
    $this->checkContribRecur(['contribution_status_id' => 'In Progress']);
  }

  /**
   * Test creating a recurring contribution and
   * the handling of charge.succeeded
   *
   * This should be a no-op event; charge.succeeded events are only processed for
   * one-offs, though it does fire for recurrings, hence the test.
   */
  public function testNewRecurringChargeSucceededAreIgnored() {

    $this->mockRecurringPaymentSetup();
    $success = $this->simulateEvent([
      'type'             => 'charge.succeeded',
      'id'               => 'evt_mock',
      'object'           => 'event',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'                  => 'ch_mock',
          'object'              => 'charge',
          'amount'              => $this->total*100,
          'amount_captured'     => $this->total*100,
          'captured'            => TRUE,
          'balance_transaction' => 'txn_mock',
          'invoice'             => 'in_mock',
          'customer'            => 'cus_mock',
          'created'             => time(),
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    //
    // Ensure Contribution and recur records remain as-was.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'ch_mock',
    ]);
    $this->checkContribRecur([ 'contribution_status_id' => 'Pending' ]);
  }

  /**
   * Unlike charge succeeded, charge failed is processed.
   */
  public function testNewRecurringChargeFailed() {
    $this->mockRecurringPaymentSetup();

    $success = $this->simulateEvent([
      'type'             => 'charge.failed',
      'id'               => 'evt_mock',
      'object'           => 'event',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'                  => 'ch_mock',
          'object'              => 'charge',
          'amount'              => $this->total*100,
          'amount_captured'     => $this->total*100,
          'captured'            => TRUE,
          'balance_transaction' => 'txn_mock',
          'invoice'             => 'in_mock',
          'customer'            => 'cus_mock',
          'created'             => time(),
          'failure_message'     => 'Mocked failure',
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    //
    // Ensure Contribution is marked Failed, with the reason, and that the
    // ContributionRecur is not changed from Pending.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Failed',
      'trxn_id'                => 'in_mock',
      'cancel_reason'          => 'Mocked failure',
    ]);
    $this->checkContribRecur(['contribution_status_id' => 'Pending']);
  }

  /**
   *
   * @see https://stripe.com/docs/billing/invoices/overview#invoice-status-transition-endpoints-and-webhooks
   */
  public function testNewRecurringInvoicePaymentFailed() {

    $this->mockRecurringPaymentSetup();

    $success = $this->simulateEvent([
      'id'               => 'evt_mock',
      'object'           => 'event',
      'type'             => 'invoice.payment_failed',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'                  => 'in_mock',
          'object'              => 'invoice',
          'charge'              => 'ch_mock',
          'amount_due'          => $this->total*100,
          'amount_paid'         => 0,
          'currency'            => 'usd',
          'customer'            => 'cus_mock',
          'created'             => time(),
          'status'              => 'uncollectible'
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    //
    // Ensure Contribution is marked Failed, with the reason, and that the
    // ContributionRecur is not changed from Pending.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Failed',
      'trxn_id'                => 'in_mock',
    ]);
    $this->checkContribRecur([ 'contribution_status_id' => 'Pending' ]);
  }
  /**
   * What about the next payments in a recurring?
   *
   * - Repeats initial testNewRecurringInvoicePaymentSucceeded, then
   *
   * - Creates invoice.finalized event: this should create a Pending
   *   Contribution with its trxn_id set to the new invoice ID.
   *
   * - Creates invoice.payment_succeeded: which should complete the Contribution
   *   and should update its trxn_id, appending the new charge ID
   *
   */
  public function testRecurringInvoiceFinalizedChronological() {

    // Initial payment comes in...
    $this->testNewRecurringInvoicePaymentSucceeded();

    list ($mockCharge1, $mockCharge2, $mockInvoice2, $balanceTransaction2) = $this->getMocksForRecurringInvoiceFinalized();
    $success = $this->simulateEvent([
      'type'             => 'invoice.finalized',
      'id'               => 'evt_mock_2',
      'object'           => 'event',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => $mockInvoice2
      ],
    ], TRUE);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Recur should still be In Progress.
    $this->checkContribRecur([ 'contribution_status_id' => 'In Progress' ]);

    // We should have a new contribution.
    $contributions = $this->getContributionsAndAssertCount(2);
    $contrib2 = $contributions[1];
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'in_mock_2',
    ], (int) $contrib2['id']);

    // Now the charge succeeds on this invoice.
    $this->simulateEvent([
      'type'             => 'invoice.payment_succeeded',
      'id'               => 'evt_mock_3',
      'object'           => 'event',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'in_mock_2',
          'object'       => 'invoice',
          'subscription' => 'sub_mock',
          'customer'     => 'cus_mock',
          'charge'       => 'ch_mock_2',
          'created'      => time(),
          'amount_due'   => $this->total*100,
          'currency'     => 'usd',
          'status'       => 'paid',
        ]
      ],
    ], TRUE);
    // Check the contribution was updated.
    $this->checkContrib([
        'contribution_status_id' => 'Completed',
        'trxn_id'                => 'in_mock_2,ch_mock_2',
        'fee_amount'             => 11.90
      ],
      (int) $contrib2['id']
    );

    // Check we set some values on the FinancialTrxn (payment)
    $this->checkFinancialTrxn([
        'Payment_details.available_on' => '2023-06-10 21:05:05',
        'fee_amount' => 11.90,
        'total_amount' => $this->total,
        'order_reference' => 'in_mock_2',
        'trxn_id' => 'ch_mock_2'
      ],
      (int) $contrib2['id']
    );
  }
  /**
   * It's possible that the payment_succeeded event comes in before finalized.
   *
   * - Repeats initial testNewRecurringInvoicePaymentSucceeded, then
   *
   * - Creates invoice.payment_succeeded: which should complete the Contribution
   *   and should update its trxn_id, appending the new charge ID
   *
   * - Creates invoice.finalized event: this should basically not do anything.
   *
   *
   */
  public function testRecurringInvoiceFinalizedNotChronological() {

    // Initial payment comes in...
    $this->testNewRecurringInvoicePaymentSucceeded();
    list ($mockCharge1, $mockCharge2, $mockInvoice2, $balanceTransaction2) = $this->getMocksForRecurringInvoiceFinalized();

    // Simulate payment_succeeded before we have had a invoice finalized.
    $success = $this->simulateEvent([
      'type'             => 'invoice.payment_succeeded',
      'id'               => 'evt_mock_2',
      'object'           => 'event',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'           => 'in_mock_2',
          'object'       => 'invoice',
          'subscription' => 'sub_mock',
          'customer'     => 'cus_mock',
          'charge'       => 'ch_mock_2',
          'created'      => time(),
          'amount_due'   => $this->total*100,
          'currency'     => 'usd',
          'status'       => 'paid',
        ]
      ],
    ], TRUE);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // We should have a new, Completed contribution.
    $contributions = $this->getContributionsAndAssertCount(2);
    $contrib2 = $contributions[1];
    // Check the new contribution
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'in_mock_2,ch_mock_2',
      'fee_amount'             => 11.90
    ], $contrib2);

    // Now trigger invoice.finalized. We expect that it does nothing?
    $success = $this->simulateEvent([
      'type'             => 'invoice.finalized',
      'id'               => 'evt_mock_3',
      'object'           => 'event',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => $mockInvoice2
      ],
    ], TRUE);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Recur should still be In Progress.
    $this->checkContribRecur([ 'contribution_status_id' => 'In Progress' ]);

    // We should still have just 2 contribs.
    $contributions = $this->getContributionsAndAssertCount(2);

    // Our 2nd contribution should still be Completed and have the same trxn_id
    $contrib2 = $contributions[1];
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'in_mock_2,ch_mock_2',
      'fee_amount'             => 11.90
    ], $contrib2);

  }
  /**
   * Tests situation when the initial recurring payment came in OK,
   * but the next one fails.
   *
   * 1. Repeats basic test on first successful payment to get the database set up.
   *
   * 2. Simulates invoice.finalized. This is the normal case and creates a Pending Contribution.
   *
   * 3. Simulates invoice.payment_failed: this time the Contribution created in
   *    (2) should be updated to Failed.
   *
   */
  public function testRecurringInvoicePaymentFailedThenSucceeds() {

    // Initial payment comes in...
    $this->testNewRecurringInvoicePaymentSucceeded();

    $createdTimestamp = time();
    //
    // Now test if we get invoice.finalized first.
    //
    // To do this we'll need a new invoice and a new charge.
    // and pending balance transaction
    $mockInvoice2 = new PropertySpy('invoice2', [
      'id'           => 'in_mock_2',
      'object'       => 'invoice',
      'amount_due'   => $this->total*100,
      'currency'     => 'usd',
      'charge'       => 'ch_mock_2',
      'subscription' => 'sub_mock',
      'customer'     => 'cus_mock',
      'created'      => time(),
    ]);
    $mockCharge2 = new PropertySpy('charge2', [
      'id'                  => 'ch_mock_2',
      'object'              => 'charge',
      'balance_transaction' => 'txn_mock_2',
      'amount'              => $this->total*100,
      'subscription' => 'sub_mock',
      'customer'     => 'cus_mock',
      'created'      => $createdTimestamp,
      'failure_message' => 'payment failed',
    ]);
    $balanceTransaction2 = new PropertySpy('balance_transaction2', [
      'id'            => 'txn_mock_2',
      'object'        => 'balance_transaction',
      'amount'        => $this->total * 100,
      'created'       => time(),
      'currency'      => 'usd',
      'exchange_rate' => NULL,
      'fee'           => 1190, /* means $11.90 */
      'status'        => 'pending',
      'type'          => 'charge',
    ]);
    $this->paymentObject->stripeClient->balanceTransactions = $this->createMock('Stripe\\Service\\BalanceTransactionService');
    $this->paymentObject->stripeClient->balanceTransactions
      ->method('retrieve')
      ->with($this->equalTo('txn_mock_2'))
      ->willReturn($balanceTransaction2);

    $this->paymentObject->stripeClient->charges = $this->createMock('Stripe\\Service\\ChargeService');
    $this->paymentObject->stripeClient->charges
      ->method('retrieve')
      ->will($this->returnValueMapOrDie([
        ['ch_mock_2', NULL, NULL, $mockCharge2],
      ]));


    //
    // Simulate invoice.finalized
    //
    $success = $this->simulateEvent([
      'type'             => 'invoice.finalized',
      'id'               => 'evt_mock_3',
      'object'           => 'event',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => $mockInvoice2
      ],
    ], TRUE);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Recur should still be In Progress.
    $this->checkContribRecur([ 'contribution_status_id' => 'In Progress' ]);

    // We should have 2 contribs.
    // The 2nd one should be Pending.
    $contributions = $this->getContributionsAndAssertCount(2);
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id' => 'in_mock_2',
    ], $contributions[1]);

    //
    // Now simulate a failed invoice again. (normal flow for a failed invoice)
    //
    $success = $this->simulateEvent([
      'id'               => 'evt_mock_4',
      'object'           => 'event',
      'type'             => 'invoice.payment_failed',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'                  => 'in_mock_2',
          'object'              => 'invoice',
          'charge'              => 'ch_mock_2',
          'subscription'        => 'sub_mock',
          'amount_due'          => $this->total*100,
          'amount_paid'         => 0,
          'currency'            => 'usd',
          'customer'            => 'cus_mock',
          'created'             => $createdTimestamp,
          'status'              => 'uncollectible'
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // The 2nd contribution should be Failed
    $contributions = $this->getContributionsAndAssertCount(2);
    $this->checkContrib([
      'contribution_status_id' => 'Failed',
      'trxn_id' => 'in_mock_2',
      'cancel_reason' => 'payment failed',
      'cancel_date' => date('Y-m-d H:i:s', $createdTimestamp),
    ], $contributions[1]);

    //
    // Now simulate an invoice.payment_succeeded - e.g. a 2nd charge attempt worked.
    //
    $mockCharge3 = new PropertySpy('charge3', [
      'id'                  => 'ch_mock_3',
      'object'              => 'charge',
      'balance_transaction' => 'txn_mock_3',
      'amount'              => $this->total*100,
      'subscription'        => 'sub_mock',
      'customer'            => 'cus_mock',
      'created'             => time(),
    ]);
    $balanceTransaction2 = new PropertySpy('balance_transaction3', [
      'id'            => 'txn_mock_3',
      'object'        => 'balance_transaction',
      'amount'        => $this->total * 100,
      'created'       => time(),
      'currency'      => 'usd',
      'exchange_rate' => NULL,
      'fee'           => 1190, /* means $11.90 */
      'status'        => 'available',
      'type'          => 'charge',
    ]);
    $this->paymentObject->stripeClient->balanceTransactions = $this->createMock('Stripe\\Service\\BalanceTransactionService');
    $this->paymentObject->stripeClient->balanceTransactions
      ->method('retrieve')
      ->with($this->equalTo('txn_mock_3'))
      ->willReturn($balanceTransaction2);

    $this->paymentObject->stripeClient->charges = $this->createMock('Stripe\\Service\\ChargeService');
    $this->paymentObject->stripeClient->charges
      ->method('retrieve')
      ->will($this->returnValueMapOrDie([
        ['ch_mock_3', NULL, NULL, $mockCharge3],
      ]));

    $success = $this->simulateEvent([
      'id'               => 'evt_mock_5',
      'object'           => 'event',
      'type'             => 'invoice.payment_succeeded',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => [
          'id'          => 'in_mock_2', // still same invoice
          'object'      => 'invoice',
          'charge'      => 'ch_mock_3', // different charge
          'subscription' => 'sub_mock',
          'amount_due'  => $this->total*100,
          'amount_paid' => 0,
          'currency'    => 'usd',
          'customer'    => 'cus_mock',
          'created'     => time(),
          'status'      => 'paid',
        ]
      ],
    ]);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // The 2nd contribution should now be Completed and have the invoice and the successful charge as its trxn_id
    $contributions = $this->getContributionsAndAssertCount(2);
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id' => 'in_mock_2,ch_mock_3',
      'fee_amount' => 11.90
    ], $contributions[1]);
  }

  /**
   * Tests situation when the initial recurring payment came in OK,
   * but the subscription is then deleted.
   */
  public function testRecurringDeletedAfterInitialSuccess() {
    // Initial payment comes in...
    $this->testNewRecurringInvoicePaymentSucceeded();

    $cancelTimestamp = time();
    $mockSubscription = new PropertySpy('subscription', [
      'id'                 => 'sub_mock',
      'object'             => 'subscription',
      'current_period_end' => time()+60*60*24,
      'status'             => 'cancelled',
      // Note US? spelling of cancelled
      'canceled_at'        => $cancelTimestamp,
      'plan' => [
        'amount' => $this->total,
        'currency' => 'usd',
      ],
    ]);
    $stripeClient = $this->paymentObject->stripeClient;
    $stripeClient->subscriptions = $this->createMock('Stripe\\Service\\SubscriptionService');
    $stripeClient->subscriptions
      ->method('retrieve')
      ->will($this->returnValueMapOrDie([
        ['sub_mock', NULL, NULL, $mockSubscription],
      ]));
    //
    // Now test if we get customer.subscription.deleted .
    //
    $success = $this->simulateEvent([
      'type'             => 'customer.subscription.deleted',
      'id'               => 'evt_mock_2',
      'object'           => 'event',
      'livemode'         => FALSE,
      'pending_webhooks' => 0,
      'request'          => [ 'id' => NULL ],
      'data'             => [
        'object' => $mockSubscription
      ],
    ], TRUE);
    $this->assertEquals(TRUE, $success, 'IPN did not return OK');

    // Recur should be Cancelled.
    $this->checkContribRecur( [
      'contribution_status_id' => 'Cancelled',
      'cancel_date'            => date('Y-m-d H:i:s', $cancelTimestamp),
    ]);

    // We should still have 1 contrib which should be unaffected.
    $this->getContributionsAndAssertCount(1);
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'ch_mock',
      'fee_amount'             => 11.90
    ]);
  }

  /**
   * Retrieve the event with a matching subscription id
   *
   * This method is/was intended for use with the live Stripe API, however
   * now we're using mocks we don't need it.
   */
  public function getEvent($type) {
    // If the type has subscription in it, then the id is the subscription id
    if (preg_match('/\.subscription\./', $type)) {
      $property = 'id';
    }
    else {
      // Otherwise, we'll find the subscription id in the subscription property.
      $property = 'subscription';
    }
    // Gather all events since this class was instantiated.
    $params['created'] = ['gte' => $this->created_ts];
    //$params['type'] = $type;
    $params['ppid'] = $this->paymentProcessorID;
    $params['output'] = 'raw';

    // Now try to retrieve this transaction.
    $transactions = civicrm_api3('Stripe', 'listevents', $params);
    foreach($transactions['values']['data'] as $transaction) {
      $_ = $transaction->data;
      $_ = $_->object;
      if ($transaction->data->object->$property == $this->processorID) {
        return $transaction;
      }
    }
    return NULL;
  }

  /**
   * Run the webhook/ipn
   *
   * @return bool whether it was successful (nb. FALSE might be valid where we
   * want stripe to resend something again later)
   */
  public function ipn($event, $verifyRequest = TRUE, $exceptionOnFailure=FALSE) {
    $ipnClass = new CRM_Core_Payment_StripeIPN($this->paymentObject);

    if ($exceptionOnFailure) {
      // We don’t' expect failure, so ensure exceptions are not caught.
      $ipnClass->exceptionOnFailure = $exceptionOnFailure;
    }
    $ipnClass->setEventID($event->id);
    if (!$ipnClass->setEventType($event->type)) {
      // We don't handle this event
      return FALSE;
    };
    $ipnClass->setVerifyData($verifyRequest);
    if (!$verifyRequest) {
      $ipnClass->setData($event->data);
    }
    $ipnClass->setExceptionMode(FALSE);

    return $ipnClass->processWebhookEvent()->ok;
  }

  /**
   * Create recurring contribition
   */
  public function setupRecurringContribution($params = []) {
    $contributionRecur = civicrm_api3('contribution_recur', 'create', array_merge([
      'financial_type_id' => $this->financialTypeID,
      'payment_instrument_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'payment_instrument_id', 'Credit Card'),
      'contact_id' => $this->contactID,
      'amount' => $this->total,
      'sequential' => 1,
      'installments' => $this->contributionRecur['installments'],
      'frequency_unit' => $this->contributionRecur['frequency_unit'],
      'frequency_interval' => $this->contributionRecur['frequency_interval'],
      'contribution_status_id' => 2,
      'payment_processor_id' => $this->paymentProcessorID,
      'is_test' => 1,
      'api.contribution.create' => [
        'total_amount' => $this->total,
        'financial_type_id' => $this->financialTypeID,
        'contribution_status_id' => 'Pending',
        'contact_id' => $this->contactID,
        'payment_processor_id' => $this->paymentProcessorID,
        'is_test' => 1,
      ],
    ], $params));
    $this->assertEquals(0, $contributionRecur['is_error']);
    $this->contributionRecurID = $contributionRecur['id'];
    $this->contributionID = $contributionRecur['values']['0']['api.contribution.create']['id'];
  }

  /**
   * Returns an array of arrays of contributions.
   */
  protected function getContributionsAndAssertCount(int $expectedCount):array {
    $contributions = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $this->contributionRecurID,
      'is_test'               => 1,
      'options'               => ['sort' => 'id'],
      'sequential'            => 1,
    ]);
    $this->assertEquals($expectedCount, $contributions['count']);
    return $contributions['values'];
  }

  /**
   * DRY code
   */
  protected function getMocksForRecurringInvoiceFinalized() :array {
    $common = [
      'subscription' => 'sub_mock',
      'customer'     => 'cus_mock',
      'created'      => time(),
    ];
    $mockCharge1 = new PropertySpy('charge1', $common + [
        'id'                  => 'ch_mock',
        'object'              => 'charge',
        'balance_transaction' => 'txn_mock',
        'amount'              => $this->total*100,
      ]);
    $mockCharge2 = new PropertySpy('charge2', $common + [
        'id'                  => 'ch_mock_2',
        'object'              => 'charge',
        'balance_transaction' => 'txn_mock_2',
        'amount'              => $this->total*100,
      ]);
    $mockInvoice2 = new PropertySpy('invoice2', $common + [
        'id'           => 'in_mock_2',
        'object'       => 'invoice',
        'amount_due'   => $this->total*100,
        'currency'     => 'usd',
        'charge'       => 'ch_mock_2',
      ]);
    $balanceTransaction2 = new PropertySpy('balance_transaction2', [
      'id'            => 'txn_mock_2',
      'object'        => 'balance_transaction',
      'amount'        => $this->total * 100,
      'created'       => time(),
      'currency'      => 'usd',
      'exchange_rate' => NULL,
      'fee'           => 1190, /* means $11.90 */
      'status'        => 'available',
      'type'          => 'charge',
      'available_on'  => '1686427505' // 2023-06-10 21:05:05
    ]);

    $this->paymentObject->stripeClient->balanceTransactions = $this->createMock('Stripe\\Service\\BalanceTransactionService');
    $this->paymentObject->stripeClient->balanceTransactions
      ->method('retrieve')
      ->with($this->equalTo('txn_mock_2'))
      ->willReturn($balanceTransaction2);

    $this->paymentObject->stripeClient->charges = $this->createMock('Stripe\\Service\\ChargeService');
    $this->paymentObject->stripeClient->charges
      ->method('retrieve')
      ->will($this->returnValueMapOrDie([
        ['ch_mock', NULL, NULL, $mockCharge1],
        ['ch_mock_2', NULL, NULL, $mockCharge2],
      ]));

    return [$mockCharge1, $mockCharge2, $mockInvoice2, $balanceTransaction2];
  }

  protected function getMocksForOneOffPayment() {
    PropertySpy::$buffer = 'none';
    // Set this to 'print' or 'log' maybe more helpful in debugging but for
    // generally running tests 'exception' suits as we don't expect any output.
    PropertySpy::$outputMode = 'exception';

    // Create a mock stripe client.
    $stripeClient = $this->createMock('Stripe\\StripeClient');
    // Update our CRM_Core_Payment_Stripe object and ensure any others
    // instantiated separately will also use it.
    $this->paymentObject->setMockStripeClient($stripeClient);

    // Mock the payment methods service.
    $mockPaymentMethod = $this->createMock('Stripe\\PaymentMethod');
    $mockPaymentMethod->method('__get')
      ->will($this->returnValueMap([
        [ 'id', 'pm_mock']
      ]));
    $stripeClient->paymentMethods = $this->createMock('Stripe\\Service\\PaymentMethodService');
    $stripeClient->paymentMethods
      ->method('create')
      ->willReturn($mockPaymentMethod);
    $stripeClient->paymentMethods
      ->method('retrieve')
      ->with($this->equalTo('pm_mock'))
      ->willReturn($mockPaymentMethod);

    // Mock the Customers service
    $stripeClient->customers = $this->createMock('Stripe\\Service\\CustomerService');
    $stripeClient->customers
      ->method('create')
      ->willReturn(
        new PropertySpy('customers.create', ['id' => 'cus_mock'])
      );
    $stripeClient->customers
      ->method('retrieve')
      ->with($this->equalTo('cus_mock'))
      ->willReturn(
        new PropertySpy('customers.retrieve', ['id' => 'cus_mock'])
      );

    // Need a mock intent with id and status
    $mockCharge = $this->createMock('Stripe\\Charge');
    $mockCharge
      ->method('__get')
      ->will($this->returnValueMap([
        ['id', 'ch_mock'],
        ['captured', TRUE],
        ['status', 'succeeded'],
        ['balance_transaction', 'txn_mock'],
      ]));

    $mockChargesCollection = new \Stripe\Collection();
    $mockChargesCollection->data = [$mockCharge];

    $mockCharge = new PropertySpy('Charge', [
      'id' => 'ch_mock',
      'object' => 'charge',
      'captured' => TRUE,
      'status' => 'succeeded',
      'balance_transaction' => 'txn_mock',
      'amount' => $this->total * 100,
    ]);
    $stripeClient->charges = $this->createMock('Stripe\\Service\\ChargeService');
    $stripeClient->charges
      ->method('retrieve')
      ->with($this->equalTo('ch_mock'))
      ->willReturn($mockCharge);

    $mockPaymentIntent = new PropertySpy('PaymentIntent', [
      'id' => 'pi_mock',
      'status' => 'succeeded',
      'latest_charge' => 'ch_mock'
    ]);

    $stripeClient->paymentIntents = $this->createMock('Stripe\\Service\\PaymentIntentService');
    $stripeClient->paymentIntents
      ->method('retrieve')
      ->with($this->equalTo('pi_mock'))
      ->willReturn($mockPaymentIntent);

    $mockPaymentIntentWithAmount = new PropertySpy('PaymentIntent', [
      'id' => 'pi_mock',
      'status' => 'succeeded',
      'latest_charge' => 'ch_mock',
      'amount' => '40000',
    ]);
    $stripeClient->paymentIntents
      ->method('update')
      ->with($this->equalTo('pi_mock'))
      ->willReturn($mockPaymentIntentWithAmount);

    $stripeClient->balanceTransactions = $this->createMock('Stripe\\Service\\BalanceTransactionService');
    $stripeClient->balanceTransactions
      ->method('retrieve')
      ->with($this->equalTo('txn_mock'))
      ->willReturn(new PropertySpy('balanceTransaction', [
        'id' => 'txn_mock',
        'fee' => 1190, /* means $11.90 */
        'currency' => 'usd',
        'exchange_rate' => NULL,
        'object' => 'balance_transaction',
        'available_on'  => '1686427505' // 2023-06-10 21:05:05
      ]));

    $mockRefund = new PropertySpy('Refund', [
      'amount_refunded' => $this->total*100,
      'charge_id' => 'ch_mock', //xxx
      'created' => time(),
      'currency' => 'usd',
      'id' => 're_mock',
      'object' => 'refund',
    ]);
    $stripeClient->refunds = $this->createMock('Stripe\\Service\\RefundService');
    $stripeClient->refunds
      ->method('all')
      ->willReturn(new PropertySpy('refunds.all', [ 'data' => [ $mockRefund ] ]));
  }

  /**
   * DRY code. Sets up the database as it would be after a recurring contrib
   * has been set up with Stripe.
   *
   * Results in a pending ContributionRecur and a pending Contribution record.
   *
   * The following mock Stripe IDs strings are used:
   *
   * - pm_mock   PaymentMethod
   * - pi_mock   PaymentIntent
   * - cus_mock  Customer
   * - ch_mock   Charge
   * - txn_mock  Balance transaction
   * - sub_mock  Subscription
   *
   * @return array The result from doPayment()
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  protected function mockOneOffPaymentSetup(): array {
    $this->getMocksForOneOffPayment();

    $this->setupPendingContribution();
    // Submit the payment.
    $payment_extra_params = [
      'contributionID'      => $this->contributionID,
      'paymentIntentID'     => 'pi_mock',
    ];

    // Simulate payment
    $this->assertInstanceOf('CRM_Core_Payment_Stripe', $this->paymentObject);
    $doPaymentResult = $this->doPaymentStripe($payment_extra_params);

    //
    // Check the Contribution
    // ...should be pending
    // ...its transaction ID should be our Charge ID.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'ch_mock',
    ]);

    return $doPaymentResult;
  }

  /**
   * @return void
   */
  protected function getMocksForRecurringPayment() {
    PropertySpy::$buffer = 'none';
    // Set this to 'print' or 'log' maybe more helpful in debugging but for
    // generally running tests 'exception' suits as we don't expect any output.
    PropertySpy::$outputMode = 'exception';

    // Create a mock stripe client.
    $stripeClient = $this->createMock('Stripe\\StripeClient');
    // Update our CRM_Core_Payment_Stripe object and ensure any others
    // instantiated separately will also use it.
    $this->paymentObject->setMockStripeClient($stripeClient);

    // Mock the payment methods service.
    $mockPaymentMethod = $this->createMock('Stripe\\PaymentMethod');
    $mockPaymentMethod->method('__get')
      ->will($this->returnValueMap([
        [ 'id', 'pm_mock']
      ]));
    $stripeClient->paymentMethods = $this->createMock('Stripe\\Service\\PaymentMethodService');
    $stripeClient->paymentMethods
      ->method('create')
      ->willReturn($mockPaymentMethod);
    $stripeClient->paymentMethods
      ->expects($this->atLeastOnce())
      ->method('retrieve')
      ->with($this->equalTo('pm_mock'))
      ->willReturn($mockPaymentMethod);

    // Mock the Customers service
    $stripeClient->customers = $this->createMock('Stripe\\Service\\CustomerService');
    $stripeClient->customers
      ->method('create')
      ->willReturn(
        new PropertySpy('customers.create', ['id' => 'cus_mock'])
      );
    $stripeClient->customers
      ->method('retrieve')
      ->with($this->equalTo('cus_mock'))
      ->willReturn(
        new PropertySpy('customers.retrieve', ['id' => 'cus_mock'])
      );

    $mockPlan = $this->createMock('Stripe\\Plan');
    $mockPlan
      ->method('__get')
      ->will($this->returnValueMap([
        ['id', 'every-1-month-' . ($this->total * 100) . '-usd-test']
      ]));
    $stripeClient->plans = $this->createMock('Stripe\\Service\\PlanService');
    $stripeClient->plans
      ->method('retrieve')
      ->willReturn($mockPlan);

    // Need a mock intent with id and status
    $mockCharge = $this->createMock('Stripe\\Charge');
    $mockCharge
      ->method('__get')
      ->will($this->returnValueMap([
        ['id', 'ch_mock'],
        ['captured', TRUE],
        ['currency', 'usd'],
        ['status', 'succeeded'],
        ['balance_transaction', 'txn_mock'],
      ]));

    $mockChargesCollection = new \Stripe\Collection();
    $mockChargesCollection->data = [$mockCharge];

    $mockCharge = new PropertySpy('Charge', [
      'id' => 'ch_mock',
      'object' => 'charge',
      'captured' => TRUE,
      'currency' => 'usd',
      'status' => 'succeeded',
      'balance_transaction' => 'txn_mock',
      'invoice' => 'in_mock'
    ]);
    $stripeClient->charges = $this->createMock('Stripe\\Service\\ChargeService');
    $stripeClient->charges
      ->method('retrieve')
      ->with($this->equalTo('ch_mock'))
      ->willReturn($mockCharge);

    $mockPaymentIntent = new PropertySpy('PaymentIntent', [
      'id' => 'pi_mock',
      'status' => 'succeeded',
      'latest_charge' => 'ch_mock'
    ]);

    $stripeClient->paymentIntents = $this->createMock('Stripe\\Service\\PaymentIntentService');
    $stripeClient->paymentIntents
      ->method('retrieve')
      ->with($this->equalTo('pi_mock'))
      ->willReturn($mockPaymentIntent);

    $mockPaymentIntentWithAmount = new PropertySpy('PaymentIntent', [
      'id' => 'pi_mock',
      'status' => 'succeeded',
      'latest_charge' => 'ch_mock',
      'amount' => '40000',
    ]);
    $stripeClient->paymentIntents
      ->method('update')
      ->with($this->equalTo('pi_mock'))
      ->willReturn($mockPaymentIntentWithAmount);

    $mockSubscription = new PropertySpy('subscription.create', [
      'id' => 'sub_mock',
      'object' => 'subscription',
      'current_period_end' => time()+60*60*24,
      'pending_setup_intent' => '',
      'latest_invoice' => [
        'id' => 'in_mock',
        'payment_intent' => $mockPaymentIntent,
      ],
    ]);
    $stripeClient->subscriptions = $this->createMock('Stripe\\Service\\SubscriptionService');
    $stripeClient->subscriptions
      ->method('create')
      ->willReturn($mockSubscription);
    $stripeClient->subscriptions
      ->method('retrieve')
      ->with($this->equalTo('sub_mock'))
      ->willReturn($mockSubscription);

    $stripeClient->balanceTransactions = $this->createMock('Stripe\\Service\\BalanceTransactionService');
    $stripeClient->balanceTransactions
      ->method('retrieve')
      ->with($this->equalTo('txn_mock'))
      ->willReturn(new PropertySpy('balanceTransaction', [
        'id' => 'txn_mock',
        'fee' => 1190, /* means $11.90 */
        'currency' => 'usd',
        'exchange_rate' => NULL,
        'object' => 'balance_transaction',
      ]));

    // $stripeClient->paymentIntents = $this->createMock('Stripe\\Service\\PaymentIntentService');
    // todo change the status from requires_capture to ?
    //$stripeClient->paymentIntents ->method('update') ->willReturn();

    $mockInvoice = new PropertySpy('Invoice', [
      'amount_due' => $this->total*100,
      'charge_id' => 'ch_mock', //xxx
      'created' => time(),
      'currency' => 'usd',
      'customer' => 'cus_mock',
      'id' => 'in_mock',
      'object' => 'invoice',
      'subscription' => 'sub_mock',
    ]);
    $stripeClient->invoices = $this->createMock('Stripe\\Service\\InvoiceService');
    $stripeClient->invoices
      ->expects($this->never())
      ->method($this->anything());
  }

  /**
   * DRY code. Sets up the database as it would be after a recurring contrib
   * has been set up with Stripe.
   *
   * Results in a pending ContributionRecur and a pending Contribution record.
   *
   * The following mock Stripe IDs strings are used:
   *
   * - pm_mock   PaymentMethod
   * - pi_mock   PaymentIntent
   * - cus_mock  Customer
   * - ch_mock   Charge
   * - txn_mock  Balance transaction
   * - sub_mock  Subscription
   */
  protected function mockRecurringPaymentSetup() {
    $this->getMocksForRecurringPayment();

    // Setup a recurring contribution for $this->total per month.
    $this->setupRecurringContribution();

    // Submit the payment.
    $payment_extra_params = [
      'is_recur'            => 1,
      'contributionRecurID' => $this->contributionRecurID,
      'contributionID'      => $this->contributionID,
      'frequency_unit'      => $this->contributionRecur['frequency_unit'],
      'frequency_interval'  => $this->contributionRecur['frequency_interval'],
      'installments'        => $this->contributionRecur['installments'],
    ];

    // Simulate payment
    $this->assertInstanceOf('CRM_Core_Payment_Stripe', $this->paymentObject);
    $this->doPaymentStripe($payment_extra_params);

    //
    // Check the Contribution
    // ...should be pending
    // ...its transaction ID should be our Invoice ID.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'ch_mock',
    ]);

    //
    // Check the ContributionRecur
    //
    // The subscription ID should be in both processor_id and trxn_id fields
    // We expect it to be pending
    $this->checkContribRecur([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'sub_mock',
      'processor_id'           => 'sub_mock',
    ]);
  }

  /**
   *
   */
  protected function returnValueMapOrDie($map): ValueMapOrDie {
    return new ValueMapOrDie($map);
  }

  /**
   * Simulate an event being sent from Stripe and processed by our IPN code.
   *
   * @var array|Stripe\Event|PropertySpy|mock $eventData
   * @var bool $exceptionOnFailure
   *
   * @return bool result from ipn()
   */
  protected function simulateEvent($eventData, $exceptionOnFailure=TRUE) {
    // Mock Event service.
    $stripeClient = $this->paymentObject->stripeClient;
    $stripeClient->events = $this->createMock('Stripe\\Service\\EventService');

    $mockEvent = PropertySpy::fromMixed('simulate ' . $eventData['type'], $eventData);
    $stripeClient->events
      ->method('all')
      ->willReturn(new PropertySpy('events.all', [ 'data' => [ $mockEvent ] ]));
    $stripeClient->events
      ->expects($this->atLeastOnce())
      ->method('retrieve')
      ->with($this->equalTo($eventData['id']))
      ->willReturn(new PropertySpy('events.retrieve', $mockEvent));

    // Fetch the event
    // Previously used the following - but see docblock of getEvent()
    // $event = $this->getEvent($eventData['type']);
    // $this->assertNotEmpty($event, "Failed to fetch event type $eventData[type]");

    // Process it with the IPN/webhook
    return $this->ipn($mockEvent, TRUE, $exceptionOnFailure);
  }

}
