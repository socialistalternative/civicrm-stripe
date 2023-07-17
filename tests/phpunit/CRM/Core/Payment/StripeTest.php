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
 * This test class deals with testing the main methods of CRM_Core_Payment_Stripe
 *
 * @group headless
 */
require_once(__DIR__ . '/../../Stripe/BaseTest.php');

class CRM_Core_Payment_Stripe_Test extends CRM_Stripe_BaseTest {

  protected $contributionRecurID;

  /**
   * This test is primarily to test the fix for
   * https://lab.civicrm.org/extensions/stripe/-/issues/440
   */
  public function testNewRecurringNewPlan() {
    $this->getMocksForRecurringPayment(FALSE);
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
   * @return void
   */
  protected function getMocksForRecurringPayment($incPlan = TRUE) {
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

    if ($incPlan) {
      // Normally we assume the plan exists:
      $stripeClient->plans->method('retrieve')->willReturn($mockPlan);
    }
    else {
      // For testing what happens when the plan does not exist.
      $planNotFoundException = \Stripe\Exception\InvalidRequestException::factory(
          'mock message unused untested'); // , null, null, null, null,
          // 'resource_missing');
      $planNotFoundException->setError((object) ['code' => 'resource_missing']);
      $stripeClient->plans->method('retrieve')->willThrowException($planNotFoundException);
      $stripeClient->plans->method('create')->willReturn($mockPlan);

      // For these cases, there will also be a call to stripeClient->products->create.
      // But that is a static method (even though it's called on an object);
      // we cannot mock static methods. Bad bad.
      // $stripeClient->products = $this->createMock(\Stripe\Product::class);
      // $stripeClient->products->method('create')->willReturn((object) ['id' => 'mock_product_id']);
      $stripeClient->products = new mockProducts();
    }

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
}

/**
 * This cheeky little class is here because the 3rd party Stripe
 * code includes static methods which are untestable.
 */
class mockProducts {
  public static function create() {
    return (object) ['id' => 'mock_product_id'];
  }
}

