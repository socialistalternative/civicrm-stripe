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

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

define('STRIPE_PHPUNIT_TEST', 1);

/**
 * This class provides helper functions for other Stripe Tests. There are no
 * tests in this class.
 *
 * @group headless
 */
abstract class CRM_Stripe_BaseTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /** @var int */
  protected $created_ts;
  /** @var int */
  protected $contributionID;
  /** @var int */
  protected $financialTypeID = 1;
  /** @var array */
  protected $contact;
  /** @var int */
  protected $contactID;
  /** @var int */
  protected $paymentProcessorID;
  /** @var array of payment processor configuration values */
  protected $paymentProcessor;
  /** @var CRM_Core_Payment_Stripe */
  protected $paymentObject;
  /** @var string */
  protected $trxn_id;
  /** @var string */
  protected $processorID;
  /** @var string */
  protected $cc = '4111111111111111';
  /** @var string */
  protected $total = '400.00';

  /** @var array */
  protected $contributionRecur = [
    'frequency_unit' => 'month',
    'frequency_interval' => 1,
    'installments' => 5,
  ];

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    static $reInstallOnce = TRUE;

    $reInstall = FALSE;
    if (!isset($reInstallOnce)) {
      $reInstallOnce=TRUE;
      $reInstall = TRUE;
    }
    if (!is_dir(__DIR__ . '/../../../../../mjwshared')) {
      civicrm_api3('Extension', 'download', ['key' => 'mjwshared']);
    }
    if (!is_dir(__DIR__ . '/../../../../../firewall')) {
      civicrm_api3('Extension', 'download', ['key' => 'firewall']);
    }

    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->install('mjwshared')
      ->install('firewall')
      ->apply($reInstall);
  }

  public function setUp(): void {
    civicrm_api3('Extension', 'install', ['keys' => 'com.drastikbydesign.stripe']);
    require_once('vendor/stripe/stripe-php/init.php');
    // Create Stripe Checkout processor
    $this->setOrCreateStripeCheckoutPaymentProcessor();
    // Create Stripe processor
    $this->setOrCreateStripePaymentProcessor();
    $this->createContact();
    $this->created_ts = time();
  }

  /**
   *
   */
  protected function returnValueMapOrDie($map): ValueMapOrDie {
    return new ValueMapOrDie($map);
  }

  /**
   * Create contact.
   */
  function createContact() {
    if (!empty($this->contactID)) {
      return;
    }
    $results = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Jose',
      'last_name' => 'Lopez'
    ]);;
    $this->contactID = $results['id'];
    $this->contact = (Object) array_pop($results['values']);

    // Now we have to add an email address.
    $email = 'susie@example.org';
    civicrm_api3('email', 'create', [
      'contact_id' => $this->contactID,
      'email' => $email,
      'location_type_id' => 1
    ]);
    $this->contact->email = $email;
  }

  /**
   * Create a stripe payment processor.
   *
   */
  function createPaymentProcessor($overrideParams = []) {
    $params = array_merge([
      'name' => 'Stripe',
      'domain_id' => 'current_domain',
      'payment_processor_type_id:name' => 'Stripe',
      'title' => 'Stripe',
      'is_active' => 1,
      'is_default' => 0,
      'is_test' => 1,
      'is_recur' => 1,
      'user_name' => 'pk_test_k2hELLGpBLsOJr6jZ2z9RaYh',
      'password' => 'sk_test_TlGdeoi8e1EOPC3nvcJ4q5UZ',
      'class_name' => 'Payment_Stripe',
      'billing_mode' => 1,
      'payment_instrument_id' => 1,
    ], $overrideParams);

    // First see if it already exists.
    $paymentProcessor = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addWhere('class_name', '=', $params['class_name'])
      ->addWhere('is_test', '=', $params['is_test'])
      ->execute()
      ->first();
    if (empty($paymentProcessor)) {
      // Nope, create it.
      $paymentProcessor = \Civi\Api4\PaymentProcessor::create(FALSE)
        ->setValues($params)
        ->execute()
        ->first();
    }

    $this->paymentProcessor = $paymentProcessor;
    $this->paymentProcessorID = $paymentProcessor['id'];
    $this->paymentObject = \Civi\Payment\System::singleton()->getById($paymentProcessor['id']);
  }

  public function setOrCreateStripeCheckoutPaymentProcessor() {
    $this->createPaymentProcessor([
      'name' => 'StripeCheckout',
      'payment_processor_type_id:name' => 'StripeCheckout',
      'title' => 'Stripe Checkout',
      'class_name' => 'Payment_StripeCheckout',
    ]);
  }

  public function setOrCreateStripePaymentProcessor() {
    $this->createPaymentProcessor();
  }

  /**
   * When storing DateTime in database we have to convert to local timezone when running tests
   * Used for checking that available_on custom field is set.
   *
   * @param string $dateInUTCTimezone eg. '2023-06-10 20:05:05'
   *
   * @return string
   * @throws \Exception
   */
  public function getDateinCurrentTimezone(string $dateInUTCTimezone) {
    // create a $dt object with the UTC timezone
    $dt = new DateTime($dateInUTCTimezone, new DateTimeZone('UTC'));

    // get the local timezone
    $loc = (new DateTime)->getTimezone();

    // change the timezone of the object without changing its time
    $dt->setTimezone($loc);

    // format the datetime
    return $dt->format('Y-m-d H:i:s');
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
   * Submit to stripe
   *
   * @param array $params
   *
   * @return array The result from PaymentProcessor->doPayment
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function doPaymentStripe(array $params = []): array {
    // Send in credit card to get payment method. xxx mock here
    $paymentMethod = $this->paymentObject->stripeClient->paymentMethods->create([
      'type' => 'card',
      'card' => [
        'number' => $this->cc,
        'exp_month' => 12,
        'exp_year' => date('Y') + 1,
        'cvc' => '123',
      ],
    ]);

    $paymentIntentID = NULL;
    $paymentMethodID = NULL;

    $firewall = new \Civi\Firewall\Firewall();
    if (!isset($params['is_recur'])) {
      // Send in payment method to get payment intent.
      $paymentIntentParams = [
        'payment_method_id' => $paymentMethod->id,
        'amount' => $this->total,
        'payment_processor_id' => $this->paymentProcessorID,
        'payment_intent_id' => $params['paymentIntentID'] ?? NULL,
        'description' => NULL,
        'csrfToken' => $firewall->generateCSRFToken(),
      ];
      $result = \Civi\Api4\StripePaymentintent::processPublic(TRUE)
        ->setPaymentMethodID($paymentMethod->id)
        ->setAmount($this->total)
        ->setPaymentProcessorID($this->paymentProcessorID)
        ->setIntentID($params['paymentIntentID'] ?? NULL)
        ->setDescription(NULL)
        ->setCsrfToken($firewall->generateCSRFToken())
        ->execute();
      // $result = civicrm_api3('StripePaymentintent', 'process', $paymentIntentParams);

      if (empty($result['success'])) {
        throw new CRM_Core_Exception('StripePaymentintent::processPublic did not return success');
      }
      $paymentIntentID = $result['paymentIntent']['id'];
    }
    else {
      $paymentMethodID = $paymentMethod->id;
    }

    $params = array_merge([
      'payment_processor_id' => $this->paymentProcessorID,
      'amount' => $this->total,
      'paymentIntentID' => $paymentIntentID,
      'paymentMethodID' => $paymentMethodID,
      'email' => $this->contact->email,
      'contactID' => $this->contact->id,
      'description' => 'Test from Stripe Test Code',
      'currencyID' => 'USD',
      // Avoid missing key php errors by adding these un-needed parameters.
      'qfKey' => NULL,
      'entryURL' => 'http://civicrm.localhost/civicrm/test?foo',
      'query' => NULL,
      'additional_participants' => [],
    ], $params);

    $ret = $this->paymentObject->doPayment($params);

    /*if ($ret['payment_status'] === 'Completed') {
      civicrm_api3('Payment', 'create', [
        'trxn_id' => $ret['trxn_id'],
        'total_amount' => $params['amount'],
        'fee_amount' => $ret['fee_amount'],
        'order_reference' => $ret['order_reference'],
        'contribution_id' => $params['contributionID'],
      ]);
    }*/
    if (array_key_exists('trxn_id', $ret)) {
      $this->trxn_id = $ret['trxn_id'];
      $contribution = new CRM_Contribute_BAO_Contribution();
      $contribution->id = $params['contributionID'];
      $contribution->trxn_id = $ret['trxn_id'];
      $contribution->save();
    }
    if (array_key_exists('contributionRecurID', $ret)) {
      // Get processor id.
      $sql = "SELECT processor_id FROM civicrm_contribution_recur WHERE id = %0";
      $params = [ 0 => [ $ret['contributionRecurID'], 'Integer' ] ];
      $dao = CRM_Core_DAO::executeQuery($sql, $params);
      if ($dao->N > 0) {
        $dao->fetch();
        $this->processorID = $dao->processor_id;
      }
    }
    return $ret;
  }

  /**
   * Confirm that transaction id is legit and went through.
   *
   */
  public function assertValidTrxn() {
    $this->assertNotEmpty($this->trxn_id, "A trxn id was assigned");

    $processor = \Civi\Payment\System::singleton()->getById($this->paymentProcessorID);

    try {
      $processor->stripeClient->charges->retrieve($this->trxn_id);
      $found = TRUE;
    }
    catch (Exception $e) {
      $found = FALSE;
    }

    $this->assertTrue($found, 'Assigned trxn_id is valid.');
  }

  /**
   * Create contribition
   *
   * @param array $params
   *
   * @return array The created contribution
   * @throws \CRM_Core_Exception
   */
  public function setupPendingContribution($params = []): array {
     $contribution = civicrm_api3('contribution', 'create', array_merge([
      'contact_id' => $this->contactID,
      'payment_processor_id' => $this->paymentProcessorID,
      // processor provided ID - use contact ID as proxy.
      'processor_id' => $this->contactID,
      'total_amount' => $this->total,
      'financial_type_id' => $this->financialTypeID,
      'contribution_status_id' => 'Pending',
      'is_test' => 1,
     ], $params));
    $this->assertEquals(0, $contribution['is_error']);
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->execute()
      ->first();
    $this->contributionID = $contribution['id'];
    return $contribution;
  }

  /**
   * Sugar for checking things on the contribution.
   *
   * @param array $expectations key => value pairs.
   * @param mixed $contribution
   *   - if null, use this->contributionID
   *   - if array, assume it's the result of a contribution.getsingle
   *   - if int, load that contrib.
   */
  protected function checkContrib(array $expectations, $contribution = NULL) {
    if (!empty($expectations['contribution_status_id'])) {
      $expectations['contribution_status_id'] = CRM_Core_PseudoConstant::getKey(
        'CRM_Contribute_BAO_Contribution', 'contribution_status_id', $expectations['contribution_status_id']);
    }

    if (!is_array($contribution)) {
      $contributionID = $contribution ?? $this->contributionID;
      $this->assertGreaterThan(0, $contributionID);
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addWhere('id', '=', $contributionID)
        ->execute()
        ->first();
    }

    foreach ($expectations as $field => $expect) {
      $this->assertArrayHasKey($field, $contribution);
      $this->assertEquals($expect, $contribution[$field], "Expected Contribution.$field = " . json_encode($expect));
    }
  }

  /**
   * Sugar for checking things on the FinancialTrxn.
   *
   * @param array $expectations key => value pairs.
   * @param int $contributionID
   *   - if null, use this->contributionID
   *   - if array, assume it's the result of a contribution.getsingle
   *   - if int, load that contrib.
   */
  protected function checkFinancialTrxn(array $expectations, int $contributionID) {
    $this->assertGreaterThan(0, $contributionID);
    $latestFinancialTrxn = \Civi\Api4\FinancialTrxn::get(FALSE)
      ->addSelect('*', 'custom.*')
      ->addJoin('Contribution AS contribution', 'LEFT', 'EntityFinancialTrxn')
      ->addWhere('contribution.id', '=', $contributionID)
      ->addWhere('is_payment', '=', TRUE)
      ->addOrderBy('id', 'DESC')
      ->execute()
      ->first();

    foreach ($expectations as $field => $expect) {
      $this->assertArrayHasKey($field, $latestFinancialTrxn);
      $this->assertEquals($expect, $latestFinancialTrxn[$field], "Expected FinancialTrxn.$field = " . json_encode($expect));
    }
  }

  /**
   * Sugar for checking things on the contribution recur.
   */
  protected function checkContribRecur(array $expectations) {
    if (!empty($expectations['contribution_status_id'])) {
      $expectations['contribution_status_id'] = CRM_Core_PseudoConstant::getKey(
        'CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $expectations['contribution_status_id']);
    }
    $this->assertGreaterThan(0, $this->contributionRecurID);
    $contributionRecur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $this->contributionRecurID)
      ->execute()
      ->first();
    foreach ($expectations as $field => $expect) {
      $this->assertArrayHasKey($field, $contributionRecur);
      $this->assertEquals($expect, $contributionRecur[$field]);
    }
  }

  /**
   * Sugar for checking things on the payment (financial_trxn).
   *
   * @param array $expectations key => value pairs.
   * @param int $contributionID
   *   - if null, use this->contributionID
   *   - Retrieve the payment(s) linked to the contributionID (currently expects one payment only)
   */
  protected function checkPayment(array $expectations, $contributionID = NULL) {
    if (!empty($expectations['contribution_status_id'])) {
      $expectations['contribution_status_id'] = CRM_Core_PseudoConstant::getKey(
        'CRM_Contribute_BAO_Contribution', 'contribution_status_id', $expectations['contribution_status_id']);
    }

    $contributionID = $contributionID ?? $this->contributionID;
    $this->assertGreaterThan(0, $contributionID);
    // We (currently) only support the first payment if there are multiple
    $payment = civicrm_api3('Payment', 'get', ['contribution_id' => $contributionID])['values'];
    $payment = reset($payment);

    foreach ($expectations as $field => $expect) {
      $this->assertArrayHasKey($field, $payment);
      $this->assertEquals($expect, $payment[$field], "Expected Payment.$field = " . json_encode($expect));
    }
  }

}

/**
 * This class provides a data structure for mocked stripe responses, and will detect
 * if a property is requested that is not already mocked.
 *
 * This enables us to only need to mock the things we actually use, which
 * hopefully makes the code more readable/maintainable.
 *
 * It implements the same interfaces as StripeObject does.
 *
 *
 */
class PropertySpy implements ArrayAccess, Iterator, Countable, JsonSerializable {

  /**
   * @var string $outputMode print|log|exception
   *
   * log means Civi::log()->debug()
   * exception means throw a RuntimeException. Use this once your tests are passing,
   * so that in future if the code starts relying on something we have not
   * mocked we can figure it out quickly.
   */
  public static $outputMode = 'print';

  /**
   * @var string $buffer
   *
   * - 'none' output immediately.
   * - 'global' tries to output things chronologically at end when all objects have been killed.
   * - 'local' outputs everything that happened to this object on destruction
   */
  public static $buffer = 'none'; /* none|global|local */
  protected $_name;
  protected $_props;
  protected $localLog = [];
  public static $globalLog = [];
  public static $globalObjects = 0;

  protected $iteratorIdx=0;
  // Iterator
  public function current() {
    // $this->warning("Iterating " . array_keys($this->_props)[$this->key()]);
    return current($this->_props);
  }

  /**
   * Implemetns Countable
   */
  public function count() {
    return \count($this->_props);
  }

  public function key() {
    return key($this->_props);
  }

  public function next() {
    return next($this->_props);
  }

  public function rewind() {
    return reset($this->_props);
  }

  public function valid() {
    return array_key_exists(key($this->_props), $this->_props);
  }

  public function toArray() {
    return $this->_props;
  }

  public function __construct($name, $props) {
    $this->_name = $name;
    foreach ($props as $k => $v) {
      $this->$k = $v;
    }
    static::$globalObjects++;
  }

  /**
   * Factory method
   *
   * @param array|PropertySpy
   */
  public static function fromMixed($name, $data) {
    if ($data instanceof PropertySpy) {
      return $data;
    }
    if (is_array($data)) {
      return new static($name, $data);
    }
    throw new \Exception("PropertySpy::fromMixed requires array|PropertySpy, got "
    . is_object($data) ? get_class($data) : gettype($data)
    );
  }

  public function __destruct() {
    static::$globalObjects--;
    if (static::$buffer === 'local') {
      $msg = "PropertySpy: $this->_name\n"
        . json_encode($this->localLog, JSON_PRETTY_PRINT) . "\n";
      if (static::$outputMode === 'print') {
        print $msg;
      }
      elseif (static::$outputMode === 'log') {
        \Civi::log()->debug($msg);
      }
      elseif (static::$outputMode === 'exception') {
        throw new \RuntimeException($msg);
      }
    }
    elseif (static::$buffer === 'global' && static::$globalObjects === 0) {
      // End of run.
      $msg = "PropertySpy:\n" . json_encode(static::$globalLog, JSON_PRETTY_PRINT) . "\n";
      if (static::$outputMode === 'print') {
        print $msg;
      }
      elseif (static::$outputMode === 'log') {
        \Civi::log()->debug($msg);
      }
      elseif (static::$outputMode === 'exception') {
        throw new \RuntimeException($msg);
      }
    }
  }

  protected function warning($msg) {
    if (static::$buffer === 'none') {
      // Immediate output
      if (static::$outputMode === 'print') {
        print "$this->_name $msg\n";
      }
      elseif (static::$outputMode === 'log') {
        Civi::log()->debug("$this->_name $msg\n");
      }
    }
    elseif (static::$buffer === 'global') {
      static::$globalLog[] = "$this->_name $msg";
    }
    elseif (static::$buffer === 'local') {
      $this->localLog[] = $msg;
    }
  }

  public function __get($prop) {
    if ($prop === 'log') {
      throw new \Exception("stop");
    }
    if (array_key_exists($prop, $this->_props)) {
      return $this->_props[$prop];
    }
    $this->warning("->$prop requested but not defined");
    return NULL;
  }

  public function __set($prop, $value) {
    $this->_props[$prop] = $value;

    if (is_array($value)) {
      // Iterative spies.
      $value = new static($this->_name . "{" . "$prop}", $value);
    }
    $this->_props[$prop] = $value;
  }

  public function offsetGet($prop) {
    if (array_key_exists($prop, $this->_props)) {
      return $this->_props[$prop];
    }
    $this->warning("['$prop'] requested but not defined");
  }

  public function offsetExists($prop) {
    if (!array_key_exists($prop, $this->_props)) {
      $this->warning("['$prop'] offsetExists requested but not defined");
      return FALSE;
    }
    return TRUE;
  }

  public function __isset($prop) {
    if (!array_key_exists($prop, $this->_props)) {
      $this->warning("isset(->$prop) but not defined");
    }
    return isset($this->_props[$prop]);
  }

  public function offsetSet($prop, $value) {
    $this->warning("['$prop'] offsetSet");
    $this->_props[$prop] = $value;
  }

  public function offsetUnset($prop) {
    $this->warning("['$prop'] offsetUnset");
    unset($this->_props[$prop]);
  }

  /**
   * Implement JsonSerializable
   */
  public function jsonSerialize() {
    return $this->_props;
  }

}

/**
 * Stubs a method by returning a value from a map.
 */
class ValueMapOrDie implements \PHPUnit\Framework\MockObject\Stub\Stub {

  use \PHPUnit\Framework\MockObject\Api;

  protected $valueMap;

  public function __construct(array $valueMap) {
    $this->valueMap = $valueMap;
  }

  public function invoke(PHPUnit\Framework\MockObject\Invocation $invocation) {
    // This is functionally identical to phpunit 6's ReturnValueMap
    $params = $invocation->getParameters();
    $parameterCount = \count($params);

    foreach ($this->valueMap as $map) {
      if (!\is_array($map) || $parameterCount !== (\count($map) - 1)) {
        continue;
      }

      $return = \array_pop($map);

      if ($params === $map) {
        return $return;
      }
    }

    // ...until here, where we throw an exception if not found.
    throw new \InvalidArgumentException("Mock called with unexpected arguments: "
      . $invocation->toString());
  }

  public function toString(): string {
    return 'return value from a map or throw InvalidArgumentException';
  }

}
