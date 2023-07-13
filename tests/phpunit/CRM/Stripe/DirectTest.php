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
 * Test a simple, direct payment via Stripe.
 *
 * @group headless
 */
require_once('BaseTest.php');
class CRM_Stripe_DirectTest extends CRM_Stripe_BaseTest {

  public function setUp(): void {
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  /**
   * Test making a one-off contribution.
   */
  public function testDirectSuccess() {
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

    //
    // Check the Contribution
    // ...should be Completed
    // ...its transaction ID should be our Charge ID.
    //
    $this->checkContrib([
      'contribution_status_id' => 'Completed',
      'trxn_id'                => 'ch_mock',
    ]);
  }

  public function testDummy() {
    return;
  }

}
