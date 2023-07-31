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

use Civi\Api4\PaymentprocessorWebhook;
use CRM_Stripe_ExtensionUtil as E;
use Civi\Payment\PropertyBag;
use Stripe\Stripe;
use Civi\Payment\Exception\PaymentProcessorException;
use Stripe\StripeObject;
use Stripe\Webhook;

/**
 * Class CRM_Core_Payment_Stripe
 */
class CRM_Core_Payment_StripeCheckout extends CRM_Core_Payment_Stripe {

  use CRM_Core_Payment_MJWTrait;

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeName() {
    return 'stripe-checkout';
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeLabel() {
    return E::ts('Stripe Checkout');
  }

  /**
   * We can use the stripe processor on the backend
   *
   * @return bool
   */
  public function supportsBackOffice() {
    return FALSE;
  }

  /**
   * We can edit stripe recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return FALSE;
  }

  public function supportsRecurring() {
    return TRUE;
  }

  /**
   * Does this payment processor support refund?
   *
   * @return bool
   */
  public function supportsRefund() {
    return TRUE;
  }

  /**
   * Can we set a future recur start date?
   *
   * @return bool
   */
  public function supportsFutureRecurStartDate() {
    return FALSE;
  }

  /**
   * Is an authorize-capture flow supported.
   *
   * @return bool
   */
  protected function supportsPreApproval() {
    return FALSE;
  }

  /**
   * Does this processor support cancelling recurring contributions through code.
   *
   * If the processor returns true it must be possible to take action from within CiviCRM
   * that will result in no further payments being processed.
   *
   * @return bool
   */
  protected function supportsCancelRecurring() {
    return TRUE;
  }

  /**
   * Does the processor support the user having a choice as to whether to cancel the recurring with the processor?
   *
   * If this returns TRUE then there will be an option to send a cancellation request in the cancellation form.
   *
   * This would normally be false for processors where CiviCRM maintains the schedule.
   *
   * @return bool
   */
  protected function supportsCancelRecurringNotifyOptional() {
    return TRUE;
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {}

  /**
   * Process payment
   * Submit a payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   * Payment processors should set payment_status_id/payment_status.
   *
   * @param array|PropertyBag $paymentParams
   *   Assoc array of input parameters for this transaction.
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$paymentParams, $component = 'contribute') {
    /* @var \Civi\Payment\PropertyBag $propertyBag */
    $propertyBag = \Civi\Payment\PropertyBag::cast($paymentParams);

    $zeroAmountPayment = $this->processZeroAmountPayment($propertyBag);
    if ($zeroAmountPayment) {
      return $zeroAmountPayment;
    }
    $propertyBag = $this->beginDoPayment($propertyBag);

    // Not sure what the point of this next line is.
    $this->_component = $component;
    $successUrl = $this->getReturnSuccessUrl($paymentParams['qfKey']);
    $failUrl = $this->getCancelUrl($paymentParams['qfKey'], NULL);

    // Get existing/saved Stripe customer or create a new one
    $existingStripeCustomer = \Civi\Api4\StripeCustomer::get(FALSE)
      ->addWhere('contact_id', '=', $propertyBag->getContactID())
      ->addWhere('processor_id', '=', $this->getPaymentProcessor()['id'])
      ->execute()
      ->first();
    if (empty($existingStripeCustomer)) {
      $stripeCustomer = $this->getStripeCustomer($propertyBag);
      $stripeCustomerID = $stripeCustomer->id;
    }
    else {
      $stripeCustomerID = $existingStripeCustomer['customer_id'];
    }

    $lineItems = [];
    if (!empty($paymentParams['skipLineItem']) || empty($paymentParams['line_item'])) {
      if (!empty($paymentParams['participants_info'])) {
        // Events: Multiple participants. Lineitem for each participant is in participantDetail.
        foreach ($paymentParams['participants_info'] as $participantID => $participantDetail) {
          $lineItems = array_merge($lineItems, $participantDetail['lineItem']);
        }
      }
      else {
        // Fallback if no lineitems (some contribution pages)
        $lineItems = [
          'priceset' => [
            'pricesetline' => [
              'unit_price' => $paymentParams['amount'],
              // source is available on contribution pages, description on event registration
              'field_title' => $paymentParams['source'] ?? $paymentParams['description'],
              'label' => $paymentParams['source'] ?? $paymentParams['description'],
              'qty' => 1,
            ]
          ]
        ];
      }
    }
    else {
      $lineItems = $paymentParams['line_item'];
    }
    // Build the checkout session parameters
    $checkoutSessionParams = [
      'line_items' => $this->buildCheckoutLineItems($lineItems, $propertyBag),
      'mode' => $propertyBag->getIsRecur() ? 'subscription' : 'payment',
      'success_url' => $successUrl,
      'cancel_url' => $failUrl,
      // 'customer_email' => $propertyBag->getEmail(),
      'customer' => $stripeCustomerID,
      // 'submit_type' => one of 'auto', pay, book, donate
      'client_reference_id' => $propertyBag->getInvoiceID(),
      'payment_method_types' => $this->getSupportedPaymentMethods($propertyBag),
    ];

    // Stripe Error:
    // Stripe\Exception\InvalidRequestException: You can not pass `payment_intent_data` in `subscription` mode.
    if ($propertyBag->getIsRecur()) {
      $checkoutSessionParams['subscription_data'] = [
        'description' => $this->getDescription($propertyBag, 'description'),
      ];
    }else {
      $checkoutSessionParams['payment_intent_data'] = [
        'description' => $this->getDescription($propertyBag, 'description'),
      ];
    }

    // Allows you to alter the params passed to StripeCheckout (eg. payment_method_types)
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $propertyBag, $checkoutSessionParams);

    try {
      $checkoutSession = $this->stripeClient->checkout->sessions->create($checkoutSessionParams);
    }
    catch (Exception $e) {
      $parsedError = $this->parseStripeException('doPayment', $e);
      throw new PaymentProcessorException($parsedError['message']);
    }

    CRM_Stripe_BAO_StripeCustomer::updateMetadata(['contact_id' => $propertyBag->getContactID()], $this, $checkoutSession['customer']);

    // Allow each CMS to do a pre-flight check before redirecting to PayPal.
    CRM_Core_Config::singleton()->userSystem->prePostRedirect();
    CRM_Utils_System::setHttpHeader("HTTP/1.1 303 See Other", '');
    CRM_Utils_System::redirect($checkoutSession->url);
  }

  /**
   * @param \Civi\Payment\PropertyBag $propertyBag
   *
   * @return array
   */
  private function getSupportedPaymentMethods(\Civi\Payment\PropertyBag $propertyBag): array {
    $paymentMethods = \Civi::settings()->get('stripe_checkout_supported_payment_methods');
    $result = [];
    foreach ($paymentMethods as $index => $paymentMethod) {
      switch ($paymentMethod) {
        case 'sepa_debit':
          if ($propertyBag->getCurrency() === 'EUR') {
            $result[] = $paymentMethod;
          }
          break;

        case 'ach_debit':
          if ($propertyBag->getCurrency() === 'USD') {
            $result[] = $paymentMethod;
          }
          break;

        default:
          $result[] = $paymentMethod;
      }
    }
    if (empty($result)) {
      throw new PaymentProcessorException('There are no valid Stripe payment methods enabled for this configuration. Check currency etc.');
    }
    return $result;
  }


  /**
   * Takes the lineitems passed into doPayment and converts them into an array suitable for passing to Stripe Checkout
   *
   * @param array $civicrmLineItems
   * @param \Civi\Payment\PropertyBag $propertyBag
   *
   * @return array
   * @throws \Brick\Money\Exception\UnknownCurrencyException
   */
  private function buildCheckoutLineItems(array $civicrmLineItems, PropertyBag $propertyBag) {
    foreach ($civicrmLineItems as $priceSetLines) {
      foreach ($priceSetLines as $lineItem) {
        $checkoutLineItem = [
          'price_data' => [
            'currency' => $propertyBag->getCurrency(),
            'unit_amount' => $this->getAmountFormattedForStripeAPI(PropertyBag::cast(['amount' => $lineItem['unit_price'], 'currency' => $propertyBag->getCurrency()])),
            'product_data' => [
              'name' => $lineItem['field_title'],
              // An empty label on a contribution page amounts configuration gives an empty $lineItem['label']. StripeCheckout needs it set.
              'description' => $lineItem['label'] ?: $lineItem['field_title'],
              //'images' => ['https://example.com/t-shirt.png'],
            ],
          ],
          'quantity' => $lineItem['qty'],
        ];
        if ($propertyBag->getIsRecur()) {
          $checkoutLineItem['price_data']['recurring'] = [
            'interval' => $propertyBag->getRecurFrequencyUnit(),
            'interval_count' => $propertyBag->getRecurFrequencyInterval(),
          ];
        }
        $checkoutLineItems[] = $checkoutLineItem;
      }
    }
    return $checkoutLineItems ?? [];
  }

}
