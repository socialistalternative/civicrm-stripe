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

class CRM_Stripe_Api {

  /**
   * @param string $name
   * @param \Stripe\StripeObject $stripeObject
   *
   * @return bool|float|int|string|null
   * @throws \Stripe\Exception\ApiErrorException
   */
  public static function getObjectParam($name, $stripeObject) {
    // object is a string containing the Stripe object name
    switch ($stripeObject->object) {
      case 'charge':
        /** @var \Stripe\Charge $stripeObject */
        switch ($name) {
          case 'charge_id':
            return (string) $stripeObject->id;

          case 'failure_code':
            return (string) $stripeObject->failure_code;

          case 'failure_message':
            return (string) $stripeObject->failure_message;

          case 'amount':
            return (float) $stripeObject->amount / 100;

          case 'refunded':
            return (bool) $stripeObject->refunded;

          case 'amount_refunded':
            return (float) $stripeObject->amount_refunded / 100;

          case 'customer_id':
            return (string) $stripeObject->customer;

          case 'balance_transaction':
            return (string) $stripeObject->balance_transaction;

          case 'receive_date':
            return self::formatDate($stripeObject->created);

          case 'invoice_id':
            if (!isset($stripeObject->invoice)) {
              return '';
            }
            // Handle both "expanded" and "collapsed" response
            elseif (is_object($stripeObject->invoice)) {
              return (string) $stripeObject->invoice->id;
            }
            else {
              return (string) $stripeObject->invoice;
            }

          case 'captured':
            return (bool) $stripeObject->captured;

          case 'currency':
            return self::formatCurrency($stripeObject->currency);

          case 'payment_intent_id':
            return (string) $stripeObject->payment_intent;

        }
        break;

      case 'invoice':
        /** @var \Stripe\Invoice $stripeObject */
        switch ($name) {
          case 'charge_id':
            return (string) $stripeObject->charge;

          case 'invoice_id':
            return (string) $stripeObject->id;

          case 'receive_date':
            return self::formatDate($stripeObject->created);

          case 'subscription_id':
            return (string) $stripeObject->subscription;

          case 'amount':
            return (float) $stripeObject->amount_due / 100;

          case 'amount_paid':
            return (float) $stripeObject->amount_paid / 100;

          case 'amount_remaining':
            return (float) $stripeObject->amount_remaining / 100;

          case 'currency':
            return self::formatCurrency($stripeObject->currency);

          case 'description':
            return (string) $stripeObject->description;

          case 'customer_id':
            return (string) $stripeObject->customer;

          case 'failure_message':
            // This is a coding error, but it looks like the general policy here is to return something. Could otherwise consider throwing an exception.
            Civi::log()->error("Coding error: CRM_Stripe_Api::getObjectParam failure_message is not a property on a Stripe Invoice object. Please alter your code to fetch the Charge and obtain the failure_message from that.");
            return '';

          case 'status':
            return self::mapInvoiceStatusToContributionStatus($stripeObject->status);

        }
        break;

      case 'subscription':
        /** @var \Stripe\Subscription $stripeObject */
        switch ($name) {
          case 'frequency_interval':
          case 'frequency_unit':
          case 'amount':
            $plan = [
              'amount' => 0,
              'interval' => '',
              'interval_count' => 0,
            ];
            foreach ($stripeObject->items as $item) {
              if ($item->price->active && ($item->quantity > 0)) {
                $plan['amount'] += $item->price->unit_amount * $item->quantity;
                $plan['interval'] = $item->plan->interval;
                $plan['interval_count'] = $item->plan->interval_count;
              }
            }

            switch($name) {
              case 'frequency_interval':
                return (int) $plan['interval_count'];

              case 'frequency_unit':
                return (string) $plan['interval'];

              case 'amount':
                return (float) $plan['amount'] / 100;
            }
            break;

          case 'currency':
            return self::formatCurrency($stripeObject->currency);

          case 'plan_start':
            return self::formatDate($stripeObject->start_date);

          case 'cancel_date':
            return self::formatDate($stripeObject->canceled_at);

          case 'cycle_day':
            return date("d", $stripeObject->billing_cycle_anchor);

          case 'subscription_id':
            return (string) $stripeObject->id;

          case 'status_id':
            switch ($stripeObject->status) {
              case \Stripe\Subscription::STATUS_INCOMPLETE:
                return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

              case \Stripe\Subscription::STATUS_ACTIVE:
              case \Stripe\Subscription::STATUS_TRIALING:
                return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress');

              case \Stripe\Subscription::STATUS_PAST_DUE:
                return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Overdue');

              case \Stripe\Subscription::STATUS_CANCELED:
              case \Stripe\Subscription::STATUS_UNPAID:
              case \Stripe\Subscription::STATUS_INCOMPLETE_EXPIRED:
              default:
                return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Cancelled');
            }

          case 'status':
            return self::mapSubscriptionStatusToRecurStatus($stripeObject->status);

          case 'customer_id':
            return (string) $stripeObject->customer;
        }
        break;

      case 'checkout.session':
        /** @var \Stripe\Checkout\Session $stripeObject */
        switch ($name) {
          case 'checkout_session_id':
            return (string) $stripeObject->id;

          case 'client_reference_id':
            return (string) $stripeObject->client_reference_id;

          case 'customer_id':
            return (string) $stripeObject->customer;

          case 'invoice_id':
            return (string) $stripeObject->invoice;

          case 'payment_intent_id':
            return (string) $stripeObject->payment_intent;

          case 'subscription_id':
            return (string) $stripeObject->subscription;
        }
        break;

      case 'subscription_item':
        /** @var \Stripe\SubscriptionItem $stripeObject */
        switch ($name) {
          default:
            if (isset($stripeObject->$name)) {
              return $stripeObject->$name;
            }
            \Civi::log('stripe')->error('getObjectParam: Tried to get param "' . $name . '" from "' . $stripeObject->object . '" but it is not set');
            return NULL;
          // unit_amount
        }
        break;

      case 'price':
        /** @var \Stripe\Price $stripeObject */
        switch ($name) {
          case 'unit_amount':
            return (float) $stripeObject->unit_amount / 100;

          case 'recurring_interval':
            // eg. "year"
            return (string) $stripeObject->recurring->interval ?? '';

          case 'recurring_interval_count':
            // eg 1
            return (int) $stripeObject->recurring->interval_count ?? 0;

          default:
            if (isset($stripeObject->$name)) {
              return $stripeObject->$name;
            }
            \Civi::log('stripe')->error('getObjectParam: Tried to get param "' . $name . '" from "' . $stripeObject->object . '" but it is not set');
            return NULL;
          // unit_amount
        }
        break;

    }

    return NULL;
  }

  /**
   * Return a formatted date from a stripe timestamp or NULL if not set
   * @param int $stripeTimestamp
   *
   * @return string|null
   */
  public static function formatDate($stripeTimestamp) {
    return $stripeTimestamp ? date('YmdHis', $stripeTimestamp) : NULL;
  }

  /**
   * @param string $stripeCurrency
   *
   * @return string
   */
  public static function formatCurrency(string $stripeCurrency): string {
    return (string) mb_strtoupper($stripeCurrency);
  }

  /**
   * Convert amount to a new currency
   *
   * @param float $amount
   * @param float $exchangeRate
   * @param string $currency
   *
   * @return float
   */
  public static function currencyConversion($amount, $exchangeRate, $currency) {
    $amount = ($amount / $exchangeRate) / 100;
    // We must round to currency precision otherwise payments may fail because Contribute BAO saves but then
    // can't retrieve because it tries to use the full unrounded number when it only got saved with 2dp.
    $amount = round($amount, CRM_Utils_Money::getCurrencyPrecision($currency));
    return $amount;
  }

  /**
   * We have to map CiviCRM locales to a specific set of Stripe locales for elements to set the user language correctly.
   * Reference: https://stripe.com/docs/js/appendix/supported_locales
   * @param string $civiCRMLocale (eg. en_GB).
   *
   * @return string
   */
  public static function mapCiviCRMLocaleToStripeLocale($civiCRMLocale = '') {
    if (empty($civiCRMLocale)) {
      $civiCRMLocale = CRM_Core_I18n::getLocale();
    }
    $localeMap = [
      'en_AU' => 'en',
      'en_CA' => 'en',
      'en_GB' => 'en-GB',
      'en_US' => 'en',
      'es_ES' => 'es',
      'es_MX' => 'es-419',
      'es_PR' => 'es-419',
      'fr_FR' => 'fr',
      'fr_CA' => 'fr-CA',
      'pt_BR' => 'pt-BR',
      'pt_PT' => 'pt',
      'zh_CN' => 'zh',
      'zh_HK' => 'zh-HK',
      'zh_TW' => 'zh-TW'
    ];
    if (array_key_exists($civiCRMLocale, $localeMap)) {
      return $localeMap[$civiCRMLocale];
    }
    // Most stripe locale codes are two characters which match the first two chars
    //   of the CiviCRM locale. If it doesn't match the Stripe element will fallback
    //   to "auto"
    return substr($civiCRMLocale,0, 2);
  }

  public static function getListOfSupportedPaymentMethodsCheckout() {
    return [
      'card' => E::ts('Card'),
      // 'acss_debit',
      // 'affirm',
      // 'afterpay_clearpay',
      // 'alipay',
      // 'au_becs_debit',
      'bacs_debit' => E::ts('BACS Direct Debit'),
      // 'bancontact',
      // 'blik',
      // 'boleto',
      // 'cashapp',
      // 'customer_balance',
      // 'eps',
      // 'fpx',
      // 'giropay',
      // 'grabpay',
      // 'ideal',
      // 'klarna',
      // 'konbini',
      // 'oxxo',
      // 'p24',
      // 'paynow',
      // 'pix',
      // 'promptpay',
      'sepa_debit' => E::ts('SEPA Direct Debit'),
      // 'sofort',
      'us_bank_account' => E::ts('ACH Direct Debit'),
      // 'wechat_pay',
    ];
  }

  /**
   * Map the Stripe Subscription Status to the CiviCRM ContributionRecur status.
   *
   * @param string $subscriptionStatus
   *
   * @return string
   */
  public static function mapSubscriptionStatusToRecurStatus(string $subscriptionStatus): string {
    $statusMap = [
      'incomplete' => 'Failed',
      'incomplete_expired' => 'Failed',
      'trialing' => 'In Progress',
      'active' => 'In Progress',
      'past_due' => 'Overdue',
      'canceled' => 'Cancelled',
      'unpaid' => 'Failed',
    ];
    return $statusMap[$subscriptionStatus] ?? '';
  }

  /**
   * Map the Stripe Invoice Status to the CiviCRM Contribution status.
   * https://stripe.com/docs/invoicing/overview#invoice-statuses
   *
   * @param string $invoiceStatus
   *
   * @return string
   */
  public static function mapInvoiceStatusToContributionStatus(string $invoiceStatus): string {
    $statusMap = [
      'draft' => 'Pending',
      'open' => 'Pending',
      'paid' => 'Completed',
      'void' => 'Cancelled',
      'uncollectible' => 'Failed',
    ];
    return $statusMap[$invoiceStatus] ?? '';
  }

}
