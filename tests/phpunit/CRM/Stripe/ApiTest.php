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
 * Stripe (CiviCRM) API3 tests
 *
 * @group headless
 */
require_once('BaseTest.php');
class CRM_Stripe_ApiTest extends CRM_Stripe_BaseTest {

  protected $contributionRecurID;

  protected $contributionRecur = [
    'frequency_unit' => 'month',
    'frequency_interval' => 1,
    'installments' => 5,
  ];

  // This test is particularly dirty for some reason so we have to force a reset.
  public function setUpHeadless() {
    $force = FALSE;
    return \Civi\Test::headless()
      ->install('mjwshared')
      ->installMe(__DIR__)
      ->apply($force);
  }

  /**
   * Test importing a subscription which has one paid invoice
   * This also tests the Stripe.Importcharge API
   */
  public function testImportSubscription() {
    $this->mockStripeSubscription();

    $result = civicrm_api3('Stripe', 'importsubscription', [
      'subscription' => 'sub_mock',
      'contact_id' => $this->contactID,
      'ppid' => $this->paymentProcessorID
    ]);

    $this->contributionID = $result['values']['contribution_id'];
    $this->contributionRecurID = $result['values']['recur_id'];

    //
    // Check the Contribution
    // ...should be Completed
    // ...its transaction ID should be our Charge ID.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'ch_mock',
    ]);

    //
    // Check the ContributionRecur
    //
    // The subscription ID should be in both processor_id and trxn_id fields
    // We expect it to be 'In Progress' (because we have a Completed contribution).
    $this->checkContribRecur([
      'contribution_status_id' => 'In Progress',
      'trxn_id'                => 'sub_mock',
      'processor_id'           => 'sub_mock',
    ]);

    // Check the payment. It should have trxn_id=Stripe charge ID and order_reference=Stripe Invoice ID
    $this->checkPayment([
      // Completed
      'status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'),
      'trxn_id' => 'ch_mock',
      'order_reference' => 'in_mock',
    ]);
  }

  /**
   * Test importing a subscription which has no paid invoices
   * This can happen if start_date is in the future or subscription has a free trial period
   * In this case we create a template contribution
   * This also tests the Stripe.Importcharge API
   */
  public function testImportSubscriptionWithNoInvoice() {
    $this->mockStripeSubscription(['hasPaidInvoice' => FALSE]);

    $result = civicrm_api3('Stripe', 'importsubscription', [
      'subscription' => 'sub_mock',
      'contact_id' => $this->contactID,
      'ppid' => $this->paymentProcessorID
    ]);

    $this->contributionID = $result['values']['contribution_id'];
    $this->contributionRecurID = $result['values']['recur_id'];

    //
    // Check the Contribution
    // ...should be Completed
    // ...its transaction ID should be our Charge ID.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Template',
      'trxn_id'                => '',
      'is_template'            => TRUE
    ]);

    //
    // Check the ContributionRecur
    //
    // The subscription ID should be in both processor_id and trxn_id fields
    // We expect it to be 'In Progress' (because we have a Completed contribution).
    $this->checkContribRecur([
      'contribution_status_id' => 'Pending',
      'trxn_id'                => 'sub_mock',
      'processor_id'           => 'sub_mock',
    ]);
  }

  /**
   * DRY code. Sets up the Stripe objects needed to import a subscription
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
   * @return \PHPUnit\Framework\MockObject\MockObject
   */
  protected function mockStripeSubscription($subscriptionParams = []) {
    $subscriptionParams['hasPaidInvoice'] = $subscriptionParams['hasPaidInvoice'] ?? TRUE;

    PropertySpy::$buffer = 'none';
    // Set this to 'print' or 'log' maybe more helpful in debugging but for
    // generally running tests 'exception' suits as we don't expect any output.
    PropertySpy::$outputMode = 'exception';

    $this->assertInstanceOf('CRM_Core_Payment_Stripe', $this->paymentObject);

    // Create a mock stripe client.
    $stripeClient = $this->createMock('Stripe\\StripeClient');
    // Update our CRM_Core_Payment_Stripe object and ensure any others
    // instantiated separately will also use it.
    $this->paymentObject->setMockStripeClient($stripeClient);

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
    $stripeClient->customers
      ->method('update')
      ->with($this->equalTo('cus_mock'))
      ->willReturn(
        new PropertySpy('customers.update', ['id' => 'cus_mock'])
      );

    $mockPlan = $this->createMock('Stripe\\Plan');
    $mockPlan
      ->method('__get')
      ->will($this->returnValueMap([
        ['id', 'every-1-month-' . ($this->total * 100) . '-usd-test'],
        ['amount', $this->total*100],
        ['currency', 'usd'],
        ['interval_count', $this->contributionRecur['frequency_interval']],
        ['interval', $this->contributionRecur['frequency_unit']],
      ]));
    $stripeClient->plans = $this->createMock('Stripe\\Service\\PlanService');
    $stripeClient->plans
      ->method('retrieve')
      ->willReturn($mockPlan);

    $mockPrice = $this->createMock('Stripe\\Price');
    $mockPrice
      ->method('__get')
      ->will($this->returnValueMap([
        ['unit_amount', $this->total * 100],
      ]));

    $mockSubscriptionItem = $this->createMock('Stripe\\SubscriptionItem');
    $mockSubscriptionItem
      ->method('__get')
      ->will($this->returnValueMap([
        ['plan', $mockPlan],
        ['price', $mockPrice],
        ['quantity', 1],
      ]));

    $mockSubscriptionItem = Stripe\SubscriptionItem::constructFrom([
      'plan' => Stripe\Plan::constructFrom([
        'id' => 'every-1-month-' . ($this->total * 100) . '-usd-test',
        'amount' => $this->total*100,
        'currency' => 'usd',
        'interval_count' => $this->contributionRecur['frequency_interval'],
        'interval' => $this->contributionRecur['frequency_unit'],
      ]),
      'price' => Stripe\Price::constructFrom([
          'unit_amount' => $this->total * 100,
          'active' => TRUE,
        ]),
      'quantity' => 1,
    ]);

    $mockSubscriptionItemsCollection = new \Stripe\Collection();
    $mockSubscriptionItemsCollection->data = [$mockSubscriptionItem];

    $mockSubscriptionParams = [
      'id' => 'sub_mock',
      'object' => 'subscription',
      'customer' => 'cus_mock',
      'current_period_end' => time()+60*60*24,
      'pending_setup_intent' => '',
      'plan' => $mockPlan,
      'start_date' => time(),
      'currency' => 'usd',
      'items' => $mockSubscriptionItemsCollection,
    ];
    if ($subscriptionParams['hasPaidInvoice']) {
      // Need a mock intent with id and status
      $mockCharge = $this->createMock('Stripe\\Charge');
      $mockCharge
        ->method('__get')
        ->will($this->returnValueMap([
          ['id', 'ch_mock'],
          ['object', 'charge'],
          ['captured', TRUE],
          ['status', 'succeeded'],
          ['balance_transaction', 'txn_mock'],
          ['invoice', 'in_mock']
        ]));
      $mockChargesCollection = new \Stripe\Collection();
      $mockChargesCollection->data = [$mockCharge];

      $mockPaymentIntent = $this->createMock('Stripe\\PaymentIntent');
      $mockPaymentIntent
        ->method('__get')
        ->will($this->returnValueMap([
          ['id', 'pi_mock'],
          ['status', 'succeeded'],
          ['charges', $mockChargesCollection]
        ]));
      $mockSubscriptionParams['latest_invoice'] = [
        'id' => 'in_mock',
        'payment_intent' => $mockPaymentIntent,
      ];
    }
    $mockSubscription = new PropertySpy('subscription.create', $mockSubscriptionParams);
    $stripeClient->subscriptions = $this->createMock('Stripe\\Service\\SubscriptionService');
    $stripeClient->subscriptions
      ->method('create')
      ->willReturn($mockSubscription);
    $stripeClient->subscriptions
      ->method('retrieve')
      ->with($this->equalTo('sub_mock'))
      ->willReturn($mockSubscription);

    if ($subscriptionParams['hasPaidInvoice']) {
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

      $mockCharge = new PropertySpy('Charge', [
        'id' => 'ch_mock',
        'object' => 'charge',
        'captured' => TRUE,
        'status' => 'succeeded',
        'balance_transaction' => 'txn_mock',
        'invoice' => 'in_mock'
      ]);
      $stripeClient->charges = $this->createMock('Stripe\\Service\\ChargeService');
      $stripeClient->charges
        ->method('retrieve')
        ->with($this->equalTo('ch_mock'))
        ->willReturn($mockCharge);

      $mockInvoice = new PropertySpy('Invoice', [
        'amount_due' => $this->total * 100,
        'charge' => 'ch_mock', //xxx
        'created' => time(),
        'currency' => 'usd',
        'customer' => 'cus_mock',
        'id' => 'in_mock',
        'object' => 'invoice',
        'subscription' => 'sub_mock',
        'status' => 'paid',
      ]);
      $mockInvoicesCollection = new \Stripe\Collection();
      $mockInvoicesCollection->data = [$mockInvoice];
      $stripeClient->invoices = $this->createMock('Stripe\\Service\\InvoiceService');
      $stripeClient->invoices
        ->method('all')
        ->willReturn($mockInvoicesCollection);
      $stripeClient->invoices
        ->method('retrieve')
        ->with($this->equalTo('in_mock'))
        ->willReturn($mockInvoice);
    }
    else {
      // No invoices
      $mockInvoicesCollection = new \Stripe\Collection();
      $mockInvoicesCollection->data = [];
      $stripeClient->invoices = $this->createMock('Stripe\\Service\\InvoiceService');
      $stripeClient->invoices
        ->method('all')
        ->willReturn($mockInvoicesCollection);
    }
  }

  /**
   *
   */
  protected function returnValueMapOrDie($map) :ValueMapOrDie {
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
