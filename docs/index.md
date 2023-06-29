# Stripe Payment Processor for CiviCRM.
Integrates the Stripe payment processor into CiviCRM so you can use it to handle payments on your site.

[![Stripe Logo](images/stripe.png)](https://stripe.com/)

View/Download this extension in the [Extension Directory](https://civicrm.org/extensions/stripe-payment-processor).

## Supports
* Credit/Debit Card payments using Stripe.js for simple [PCI compliance](https://stripe.com/docs/security/guide).
* Credit/Debit Card / SEPA / ACH using Stripe Checkout.
* Google/Microsoft/Apple Pay via [PaymentRequest](https://www.w3.org/TR/payment-request/) web API.
* Cancellation of subscriptions from Stripe / CiviCRM.
* Refund of payments from Stripe.

There are a number of planned features that depend on funding/resources. See [Roadmap](./roadmap.md).

## Compatibility / Requirements
* Jquery 1.10+ (Use jquery_update module on Drupal 7).
* Drupal 7 / Drupal 9 / Joomla / Wordpress (latest supported release).
* Stripe API version: 2020-08-27+ (recommended: 2022-11-15).
* Drupal webform_civicrm 7.x-5.5+ (if using webform integration) - see [Integration](integration.md) for more details.

#### Required extensions

* [MJWShared extension](https://civicrm.org/extensions/mjwshared).
* [SweetAlert extension](https://civicrm.org/extensions/sweetalert).

#### Recommended extensions

* [Firewall extension](https://civicrm.org/extensions/firewall).
* [contributiontransactlegacy extension](https://civicrm.org/extensions/contribution-transact-api) if using drupal 7 webform.

**Please ensure that you are running the "Stripe: Cleanup" scheduled job every hour or you will have issues with failed/uncaptured payments appearing on customer credit cards and blocking their balance for up to a week!**

## Troubleshooting
Under *Administer->CiviContribute->Stripe Settings* you can find a setting:
* Enable Stripe Javascript debugging?

> This can be switched on to output debug info to the browser console and can be used to debug problems with submitting your payments.

## Support and Maintenance
This extension is supported and maintained with the help and support of the CiviCRM community by:

[![MJW Consulting](images/mjwconsulting.jpg)](https://www.mjwconsult.co.uk)

We offer paid [support and development](https://mjw.pt/support) as well as a [troubleshooting/investigation service](https://mjw.pt/investigation).
