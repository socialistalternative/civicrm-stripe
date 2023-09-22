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

namespace Civi\Api4\Action\StripePaymentintent;

/**
 * Process a Stripe Intent with MOTO enabled.
 *
 */
class ProcessMOTO extends \Civi\Api4\Generic\AbstractAction {

  /**
   * The Stripe PaymentMethod ID
   *
   * @var string
   */
  protected $paymentMethodID = '';

  /**
   * The Stripe intent ID
   *
   * @var string
   */
  protected $intentID = '';

  /**
   * The amount formatted as eg. 12.10
   *
   * @var string
   */
  protected $amount = '';

  /**
   * Should we create a setupIntent?
   *
   * @var bool
   */
  protected $setup = FALSE;

  /**
   * The CiviCRM Payment Processor ID
   *
   * @var int
   */
  protected $paymentProcessorID;

  /**
   * An optional description to describe where the request came from
   *
   * @var string
   */
  protected $description = '';

  /**
   * The currency (eg. USD)
   *
   * @var string
   */
  protected $currency;

  /**
   * An array of extra data to store with the intent.
   *
   * @var string
   */
  protected $extraData = '';

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    $authorizeEvent = new \Civi\Stripe\Event\AuthorizeEvent($this->getEntityName(), $this->getActionName(), $this->getParams());
    $event = \Civi::dispatcher()->dispatch('civi.stripe.authorize', $authorizeEvent);
    if ($event->isAuthorized() === FALSE) {
      throw new \CRM_Core_Exception('Bad Request');
    }

    if (empty($this->amount) && !$this->setup) {
      \Civi::log('stripe')->error(__CLASS__ . 'missing amount and not capture or setup');
      throw new \API_Exception('Bad request');
    }
    if (empty($this->paymentProcessorID)) {
      \Civi::log('stripe')->error(__CLASS__ . ' missing paymentProcessorID');
      throw new \API_Exception('Bad request');
    }
    /** @var \CRM_Core_Payment_Stripe $paymentProcessor */
    $paymentProcessor = \Civi\Payment\System::singleton()->getById($this->paymentProcessorID);
    $intentProcessor = new \CRM_Stripe_PaymentIntent($paymentProcessor);
    $intentProcessor->setDescription($this->description);
    $intentProcessor->setReferrer($_SERVER['HTTP_REFERER'] ?? '');
    $intentProcessor->setExtraData($this->extraData ?? '');

    $processIntentParams = [
      'paymentProcessorID' => $this->paymentProcessorID,
      'setup' => $this->setup,
      'paymentMethodID' => $this->paymentMethodID,
      'intentID' => $this->intentID,
      'amount' => $this->amount,
      'currency' => $this->currency,
      'moto' => \Civi::settings()->get('stripe_moto'),
    ];

    $processIntentResult = $intentProcessor->processIntent($processIntentParams);

    if ($processIntentResult->ok) {
      $result->exchangeArray($processIntentResult->data);
    }
    else {
      throw new \API_Exception($processIntentResult->message);
    }
  }

}
