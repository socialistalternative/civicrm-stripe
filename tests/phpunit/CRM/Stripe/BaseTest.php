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
      $result = civicrm_api3('StripePaymentintent', 'process', $paymentIntentParams);

      $paymentIntentID = $result['values']['paymentIntent']['id'];
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
