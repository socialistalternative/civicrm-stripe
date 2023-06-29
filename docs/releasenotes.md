## Information

Releases use the following numbering system:
**{major}.{minor}.{incremental}**

* major: Major refactoring or rewrite - make sure you read and test very carefully!
* minor: Breaking change in some circumstances, or a new feature. Read carefully and make sure you understand the impact of the change.
* incremental: A "safe" change / improvement. Should *always* be safe to upgrade.

* **[BC]**: Items marked with [BC] indicate a breaking change that will require updates to your code if you are using that code in your extension.

## Release 6.9 (2023-06-29)

### Features

* Support Stripe Checkout (https://stripe.com/en-pt/payments/checkout): Currently supports Card payments, SEPA debit (EUR), ACH debit (USD) and Google/Apple Pay.
  * Add setting for Stripe Checkout payment methods and enable Card/SEPA/ACH.

* Add custom fields for available on/payout amount/currency and retrieve from balance transaction when processing charge/invoice (currently does not work when charge is completed immediately (ie. single contribution)).
* Add API `StripeCharge.GetBalanceTransactionDetails`.

### Improvements / Fixes

* Fixes for PHP8.1/8.2.
* Multiple test improvements.
* Check for fee in tests. Switch to getDetailsForBalanceTransaction() for all webhooks that use it.
* Add prefix to IPN/Webhook log messages.
* Improve parsing of Stripe exceptions.
* Set order_reference correctly when it's empty (use trxn_id).
* If stripe customer is stored in CiviCRM but does not exist, delete and recreate it instead of failing.

#### Webhooks

* Replace IPN / Webhook processing with new \Civi\Stripe\Webhook\Events processor.
* Fix `charge.failed` webhook - handle both collapsed and expanded invoice in charge.
* Support `checkout.session.completed` webhook event.


## Release 6.8.2 (2023-04-09)

* Fix [#422](https://lab.civicrm.org/extensions/stripe/-/issues/422) The resource ID cannot be null or whitespace.

## Release 6.8.1 (2023-03-13)

* Add mixin required for versions older than 5.59 (ie. 5.58).

## Release 6.8 (2023-03-12)

**You don't need "Access AJAX API" permission for anonymous user to make payments**

### Features:
* Implement optional MOTO payments for backoffice.
* Update name/email address at Stripe if contact has a Stripe Customer when updating contact / email (primary/billing).
* Allow to configure a minimum amount that Stripe can process. Anything below this will fail with 'Bad Request'. This helps reduce card testing in some circumstances.
* Add APIv3 `Stripe.membershipcheck` api.

### Breaking changes:
* In APIv3 StripeCustomer, the `id` field is renamed to `customer_id` and a standard autoincrement `id` field is added.  Note that previously `customer_id` was an alias for the `id` field so scripts using this Api may already be using `customer_id`.
* You DO NOT need "Access AJAX API" permission for anonymous user to make payments.
* You DO need "Make online contributions" permission for anonymous user to make payments.
* Deprecate API3 Stripe.ipn (it's not yet removed but will be).

### Detailed changes:
* Implement optional MOTO payments for backoffice.
* Refactor webhook processing to be more 'standard'. Deprecate API3 Stripe.ipn.
* Switch to API4 endpoints (`StripePaymentintent.ProcessPublic`, `StripePaymentintent.ProcessMOTO`) for processing/creating paymentIntents.
* Make `StripePaymentintent.ProcessPublic` conditional on 'make online contributions' permission.
* Fix [#294](https://lab.civicrm.org/extensions/stripe/-/issues/294) Stripe customer data not updated when contacts are merged.
* Add APIv4 for StripeCustomer.
* In APIv3 StripeCustomer, the `id` field is renamed to `customer_id` and a standard autoincrement `id` field is added.  Note that previously `customer_id` was an alias for the `id` field so scripts using this Api may already be using `customer_id`.
* Fix StripeCustomer.updatemetadata() to use correct metadata.
* Add StripeCustomer.getFromStripe and StripeCustomer.updateStripe API4 actions.
* Record IP address and error message when processIntent fails.
* Convert StripeCustomer to an entity and add API4 methods to access it.
* Update Stripe PHP library to v9.
* Change recommended Stripe API version to 2022-11-15.
* Fix loading stripe element for some WordPress sites.
* New framework for handling webhook events.

## Release 6.7.14 (2022-12-19)

* Fix [#404](https://lab.civicrm.org/extensions/stripe/-/issues/404)

## Release 6.7.13 (2022-11-29)
**Add support for Stripe API version 2022-11-15**

## Release 6.7.12 (2022-11-28)
**Requires mjwshared (Payment Shared) 1.2.10 and Firewall 1.5.6**

* Require Firewall 1.5.6.
* Cleanup and remove legacy error handling.
* Add check for extra data.
* Make firewall a required extension.
* Add check for failed paymentIntents.
* Add `civi.stripe.authorize` event which is used by other extensions (eg. Firewall, Formprotection with ReCAPTCHA)
to authorize or deny access to StripePaymentintent API calls.
* Add support for passing ReCAPTCHA token to backend so it can be used to validate API calls.
* Fix [#397](https://lab.civicrm.org/extensions/stripe/-/issues/397) Error: Call to undefined method Stripe\Exception\InvalidArgumentException::getJsonBody().

## Release 6.7.11 (2022-10-14)

* Add psr0 prefix (might fix classloader issues / class not found).
* Postcode element should always be visible but readonly (only if billing fields enabled).
* Don't activate stripe job if it was disabled (stops the job auto-enabling on cache clear).

## Release 6.7.10 (2022-09-22)

* Fix [#388](https://lab.civicrm.org/extensions/stripe/-/issues/388) Recurring payments not being recorded in CiviCRM.
* [!198](https://lab.civicrm.org/extensions/stripe/-/merge_requests/198) Handle null balance trxn id when calculating fees.
* [!195](https://lab.civicrm.org/extensions/stripe/-/merge_requests/195) Make errors more visible.

## Release 6.7.9 (2022-09-01)

* Fix [#387](https://lab.civicrm.org/extensions/stripe/-/issues/387) Paid multi-participant registration fails.

## Release 6.7.8 (2022-08-20)
**Requires mjwshared (Payment Shared) 1.2.8**

* Fix [#377](https://lab.civicrm.org/extensions/stripe/-/issues/377) Webhook failures when using webhook signing.
* Simplify and standardise webhook processing.
* Fix [#380](https://lab.civicrm.org/extensions/stripe/-/issues/380) Update civi address if donor updates their postal code.
* Fix [#385](https://lab.civicrm.org/extensions/stripe/-/issues/385) Checkbox price field not submitted for non-stripe payments.

## Release 6.7.7 (2022-07-24)

* Fix recording refund via webhook (it was detecting that the refund was already recorded when it wasn't because of a typo).

## Release 6.7.6 (2022-06-24)

* Improve handling of already canceled payment intents.
* Fix [#358](https://lab.civicrm.org/extensions/stripe/-/issues/358) Update Stripe PHP SDK from 7.67.0 to 7.128.0 to resolve curl certificates issue.

## Release 6.7.5 (2022-06-19)

* Fix [#365](https://lab.civicrm.org/extensions/stripe/-/issues/365) - don't try to cancel intents without an id.
* Don't null stripe_intent_id on error.
* Test fixes following cleanup of invoice/trxn_id assignment.
* Add tests for charge.succeeded and charge.captured.
* Only process charge.captured if captured is set TRUE/1.
* Deprecate unused functions.
* Upgrade autogenerated code (civix).

## Release 6.7.4 (2022-05-19)

* Fix [#320](https://lab.civicrm.org/extensions/stripe/-/issues/320) Using the new refund UI results in refund recorded twice.
* Stripe does not refund fees. Don't record a fees refund in the refund FinancialTrxn in CiviCRM.
* When submitting a recurring real-time payment (eg. using a contribution page) that starts immediately the Stripe Invoice now includes a Stripe Charge ID.
Return this Charge ID instead of the Invoice ID so that the `trxn_id` field on the Contribution and FinancialTrxn is set to the Charge ID and not the Invoice ID.
This was only affecting the first payment as the webhook for subsequent payments set the parameters correctly.
* Set the OrderID and TrxnID during doPayment for a recur - this means that both are available as return params and can be stored in the financial_trxn if supported.

## Release 6.7.3

* Fix "Undefined index" when getting webhook secret if not set.
* Don't overwrite params passed to `doPayment()` - they are passed by reference for legacy reasons so we should not modify them because it can impact the calling code (we were overwriting the array with a `\Civi\Payment\PropertyBag` object in some cases).
* Fix [#332](https://lab.civicrm.org/extensions/stripe/-/issues/315) Ensure customer name is in stripe report.
* Use `processor_id` instead of deprecated `trxn_id` for recurring contributions.

## Release 6.7.2

* Use calculated amount for multiple event participant registration. This allows use to use paymentIntent instead of setupIntent which means that cards requiring 3DSs will not fail for multiple event participant registration.

## Release 6.7.1

* Fix Multiple event participant registration when 3DSecure validation is required.

## Release 6.7

#### Features

* Add support for webhook signing.
* Implement setupIntents to perform 3DSecure etc on same form as card details. Previously it would prompt the user for the 3DSecure check on the "Thankyou" page.

#### Fixes / Changes

* Drop support for CiviCRM older than 5.35.
* Update Job.process_stripe to use API4 and clarify that it is NOT domain-specific.
* Don't initialise paymentprocessor twice for IPN. Change stripe_webhook_processing_limit so that 0=never process immediately.
* Fix getSubscriptionDetails when contributionRecur not in CiviCRM.
* Set paymentTypeLabel to 'Stripe'
* Test improvements / fixes.
* Use paymentProcessor + stripeClient object in all API calls. Translation fixes.

#### Developers / Integration

* Remove hook `civicrm_stripe_updateRecurringContribution` (we may need to implement something new in future with support for PropertyBag/API4).
* Throw exception when repeatTransaction fails.
* Throw exception on payment failure. Always update the paymentintent status in CiviCRM database.
* Switch final instances of static Stripe library access to object.
* Convert js to functions on CRM.payment and move more shared code to CRM.payment.
* Refactor checks class and move some checks to mjwshared.
* Add getters for Stripe IDs to IPN class.
* Add unit tests for Stripe.Importsubscription/ImportCharge API. Support importing subscriptions with future startdate/trial period (no invoice so we create a template contribution).
* * Provide a more helpful reason instead of 'Bad Request' when payment fails due to expired CSRF token from firewall.

##### Webhooks

* Add new hook [webhookEventNotMatched](https://docs.civicrm.org/mjwshared/en/latest/hooks/#webhookeventnotmatched) that can be used for creating/matching missing events (eg. contributions created by other systems).
* Implement new `processWebhookEvent` for processing events from the webhook queue and store error message in 'message' field.
* Improve webhook queue error handling.


## Release 6.6.3

* Fix [!170](https://lab.civicrm.org/extensions/stripe/-/merge_requests/170) Stripe.importsubscription API tweaks.
* Fix [!171](https://lab.civicrm.org/extensions/stripe/-/merge_requests/171) Fix Stripe.importcharge API for is_test support and some notices.
* Feature [!172](https://lab.civicrm.org/extensions/stripe/-/merge_requests/172) Add composer's package name (for CiviCRM-level composer deployment).
* Compatibility with Payment Shared 1.1.

## Release 6.6.2

* SweetAlert 10/11 compatibility - require SweetAlert extension 1.5.
* Fix [#330](https://lab.civicrm.org/extensions/stripe/-/issues/330) Fix system checks when extension 'id' is 0.
* Fix typo in confirm script that was causing some payments to show 'Payment Failed' on ThankYou page.
* Disable civicrmStripeConfirm script on ThankYou page if there is no paymentIntent to confirm (eg. non-recurring or delayed start-date).

## Release 6.6.1

* Fix PHP notice when there are no Stripe customers.
* Fix [#313](https://lab.civicrm.org/extensions/stripe/-/issues/315) Stripe and CiviDiscount, Stripe payment is cancelled if the Discount Code is NOT first applied on the Event Registration form.
* Implement [!164](https://lab.civicrm.org/extensions/stripe/-/merge_requests/164) Add configurable limit on maximum number of webhooks that will be processed simultaneously (default 50).
* Add API3 Stripe.Populatewebhookqueue and fix Stripe.Populatelog.
* Fixes to PaymentRequest button [#313](https://lab.civicrm.org/extensions/stripe/-/issues/313):
    * Hide submit buttons when paymentRequest button is active.
    * Fix loading of paymentRequest element when multiple payment processors available.
    * Fix no payment element loading in browser when no saved cards and paymentRequest is default.
* Fix [#331](https://lab.civicrm.org/extensions/stripe/-/issues/331) The API Stripe.importsubscription returns authorization failed.

## Release 6.6
**Requires mjwshared (Payment Shared) 1.0**

**Access AJAX API permission is required** for all users that make payments using Stripe (including the anonymous user).
Make sure you update your CMS user roles to include this permission.

* Support for [Payment Request Button](https://stripe.com/docs/stripe-js/elements/payment-request-button) which provides Google Pay and Apple Pay support.
    * To enable, you must set the "Country" in Stripe Settings and then the payment request button will replace the card element when the client browser supports it.
* Collect billing name/email when possible and store in `civicrm_stripe_paymentintent.extra_data` table. This should help trace the donor when a payment does not complete.
* Handle 3d-secure authentication on thankyou page for "setupIntents". This will happen when a delayed recurring contribution is created and the card issuer requests additional authentication.
* Remove AJAX endpoint `civicrm/stripe/confirm-payment` and replace with `StripePaymentintent.Process` API call.
* Add `StripePaymentintent.createorupdate` API which is used by the frontend javascript and requires "make online contributions" permission.
* Simplify Stripe.Ipn API.
* Fully remove support for CiviCRM older than 5.28.
* Use new PaymentProcessorWebhook entity to track/process webhooks and avoid simultaneous processing of events.
* Fixes to processing `invoice.payment_failed` IPN event (also triggered during 3d secure verification).
* [!146](https://lab.civicrm.org/extensions/stripe/-/merge_requests/146) Listevent API improvements.
* Fix [#305](https://lab.civicrm.org/extensions/stripe/-/issues/305),[#293](https://lab.civicrm.org/extensions/stripe/-/issues/293) Allow specifying a static statement descriptor.
* [!148](https://lab.civicrm.org/extensions/stripe/-/merge_requests/148) Implement testing infrastructure with mock Stripe client and add multiple IPN tests - thankyou [@artfulrobot](https://artfulrobot.uk/).
* [!147](https://lab.civicrm.org/extensions/stripe/-/merge_requests/147) Multiple improvements to import API - thankyou [Jamie McClelland - ProgressiveTech](https://progressivetech.org/).
* Retrieve subscriptionID so we don't process charge.failed/invoice.payment_failed simultaneously.
* Fix showing 'successful' on thankyou page when payment failed because of error 'requires payment method'.
* Make more javascript strings translatable.
* Disable billing fields by default.
* Add 6.6 upgrade message to system checks.
* Fix [#306](https://lab.civicrm.org/extensions/stripe/-/issues/306) Can't pay for event with more then one participant.
* Fix [#293](https://lab.civicrm.org/extensions/stripe/-/issues/293) Statement descriptors require at least one alphanumeric character.

## Release 6.5.8

* Fix [#298](https://lab.civicrm.org/extensions/stripe/-/issues/298) jQuery validation on checkboxes in 'On behalf of' profiles.

## Release 6.5.7

* Remove handling for `customer.subscription.updated` webhooks - they were not working and could lead to broken recurring contributions.
* Rename ProcessStripe job to "Stripe: Cleanup".

## Release 6.5.6

* Fix [#126](https://lab.civicrm.org/extensions/stripe/-/issues/126) setting user locale for stripe elements.
* Fix [!143](https://lab.civicrm.org/extensions/stripe/-/merge_requests/143) "paymentintent ID missing error" on Drupal 8 webforms.

## Release 6.5.5
**Requires mjwshared (Payment Shared) 0.9.9**

* Catch and log error if Stripe tries to process a duplicate IPN at the same time. This should resolve issues with "Contribution already completed" exceptions in the logs.
    * The attempted processing of a duplicate does not seem to cause any data issues but does trigger an exception which is logged. This doesn't fix that underlying issue but
    does write a more user-friendly error with context to the CiviCRM logs.
* [!140](https://lab.civicrm.org/extensions/stripe/-/merge_requests/140) Fix option to not send receipt when running `Stripe.Ipn` API.
* When not submitting via stripe reset billing fields so CiviCRM interprets them correctly (Fix JS rewrite of name attribute for checkbox field).
* Fix [#256](https://lab.civicrm.org/extensions/stripe/-/issues/256) Can't submit contribution page online form for 0 amount (when membership fee is 0 and confirmation page enabled).

## Release 6.5.4
**This release REQUIRES that you upgrade mjwshared to 0.9.7**.

* Use `CRM.payment.isAJAXPaymentForm()` to check if we should load on backend forms. There is no change for the user, we are just switching to an identical shared function to reduce code duplication.
* Fix [#272](https://lab.civicrm.org/extensions/stripe/-/issues/272) "undefined property" PHP notice.
* Response to test webhook now includes payment processor name from CiviCRM (makes it easier to be sure which payment processor is responding).
* [!137](https://lab.civicrm.org/extensions/stripe/-/merge_requests/137) Update StripePaymentintent.Process API.
* [!136](https://lab.civicrm.org/extensions/stripe/-/merge_requests/136):
    * Use CiviCRM log file for Stripe library errors.
    * Retry once if we can't connect to stripe servers.
* [!135](https://lab.civicrm.org/extensions/stripe/-/merge_requests/135) Add hidden Stripe Country setting which will be required for [paymentRequest](https://stripe.com/docs/stripe-js/elements/payment-request-button) button (not yet implemented).
* Return refund_status_name from `doRefund()` - this allows you to issue refunds via the UI following
[mjwshared!12](https://lab.civicrm.org/extensions/mjwshared/-/merge_requests/12) - Add support for issuing refunds via the payment UI for payment processors that support refunds (eg. Stripe).

## Release 6.5.3

* Fix [#258](https://lab.civicrm.org/extensions/stripe/-/issues/258) Credit card element doesn't load in "Submit credit card contribution" popup form on backend (fixed for memberships and contributions).
* Fix [#262](https://lab.civicrm.org/extensions/stripe/-/issues/262) Fix `customer.subscription.deleted` webhook event not working (500 internal server error).
* Fix [#270](https://lab.civicrm.org/extensions/stripe/-/issues/270) Fix 500 Internal Server error for the customer.subscription.updated event

## Release 6.5.2

* [!129](https://lab.civicrm.org/extensions/stripe/-/merge_requests/129) Don't check inactive membership blocks for separate payment setting.
* [!128](https://lab.civicrm.org/extensions/stripe/-/merge_requests/128) Don't check for webhooks on non-production instances.

## Release 6.5.1
**You must update to this version if using API version 2020-08-27**.

* Fix subscription parameter (prorate -> proration_behavior) for API version 2020-08-27.

## Release 6.5

**This release REQUIRES that you upgrade mjwshared to 0.9.4**.

**The recommended Stripe API version is 2020-08-27**.

* Implement [#199](https://lab.civicrm.org/extensions/stripe/-/issues/199):
    * Support future recurring start date on backend forms
    * Add support for selecting and creating subscriptions with future start date on frontend forms
    * Allow selection of which frequency intervals to enable public recurring start date
    * Support future recur start date for memberships on frontend.
* Fix [#221](https://lab.civicrm.org/extensions/stripe/-/issues/199) Return 200 OK for webhooks that stripe can't match to CiviCRM. Look for contribution using subscription_id for future recurring start date
* Map customer to contact ID in IPN
* Handle invoice.finalized IPN event - we now create the new contribution once we receive the invoice.finalized event. It will then be transitioned to Completed by invoice.payment_succeeded.
* Record refund against the already recorded payment in CiviCRM so we update financial items correctly
* API3 Stripe.Listevents [!117](https://lab.civicrm.org/extensions/stripe/-/merge_requests/117) Provide additional information about stripe events.
* If a contribution status is `Failed` and it later receives a successful payment notification it is updated from `Failed` to `Completed`.
* Add system check for 'Is separate membership payment' on contribution pages which is not supported by the Stripe extension
* Fix [#225](https://lab.civicrm.org/extensions/stripe/-/issues/225) No credit card display in Internet Explorer 11 (Support ECMAScript 5.1 javascript syntax).
* Support translating text strings in javascript.
* Fix issues with popup notifications not showing in some circumstances (eg. "Card declined").
* Disable logging for `civicrm_stripe_paymentintent` table.
* Fix [#239](https://lab.civicrm.org/extensions/stripe/-/issues/239) Hide configuration fields that we don't use.
* Fix [#241](https://lab.civicrm.org/extensions/stripe/-/issues/241) Incorrect Form Validation for checkboxes on profiles
* Fix [#242](https://lab.civicrm.org/extensions/stripe/-/issues/242) Stripe IPN events arriving out of order causing contributions to be stuck in "Pending" status.

* Fix PHP notices:
    * When a checking for an extension dependency that is not yet downloaded or installed.
    * When checking for recur frequency on billing form.

* IPN Code:
    * Pass json string to IPN class for decoding instead of decoding before passing
    * handlePaymentNotification should not be a static function.
    * Set cancel_date/cancel_reason for failed contribution. Don't update `receive_date`.

## Release 6.4.2

* Fix [#210](https://lab.civicrm.org/extensions/stripe/-/issues/210): If there are multiple reCaptcha on the page check and validate the one on the Stripe billing form only.
* Update implementation for cancel subscription. Option to notify (default Yes) is now available on the backend cancel subscription form.
* Fix [#218](https://lab.civicrm.org/extensions/stripe/-/issues/218): Ensure disallowed characters in description don't stop contributions from being processed.
* Fixes to system checks (check for sweetalert was showing description for firewall extension).
* Fix [#215](https://lab.civicrm.org/extensions/stripe/-/issues/218) Errors encountered when anonymous users switch payment processors.
* Fix for 5.28 and propertybag.

## Release 6.4.1
**This release REQUIRES that you upgrade mjwshared to 0.8.**

* Fix [#196](https://lab.civicrm.org/extensions/stripe/issues/196): Recurring contributions with incorrect amount per default currency in stripe.
* Fix [#198](https://lab.civicrm.org/extensions/stripe/issues/198): Trigger postInstall hook so we set the revision and don't trigger the upgrader on install.
* Fix [#182](https://lab.civicrm.org/extensions/stripe/issues/182): Failed subscription payment, receipt sent, but contribution not updated when retried.
* Change validator error class to match CiviCRM (use crm-inline-error instead of error css class - see https://github.com/civicrm/civicrm-core/pull/16495)
* Don't specify charset/collation when creating tables on new installs (use the database default).
* Return 200 OK for webhook events we don't handle (normally they won't be sent as the extension specifies what it requires but if configured manually it may receive events that we don't handle).
* Switch to new "recommended" contribution.repeattransaction and payment.create for new IPN payments.
* Implement new doCancelRecur functionality and hide optional notify processor on 5.27+ (this has no impact on older versions of CiviCRM).
* Fix CSRF token issues with civicrmStripeConfirm.js (3ds challenge was not triggering on thankyou page).
* civicrmStripeConfirm.js is now a library available at CRM.payment.confirm and builds on CRM.payment.
* Initial support for \Civi\Payment\PropertyBag.
* Improve handling of network errors when loading Stripe elements and add a new jquery event: *crmBillingFormReloadFailed*.
* Improve user notifications during pre-authentication and confirmation.
* Add check for recommended SweetAlert extension.
* Fix [#208](https://lab.civicrm.org/extensions/stripe/issues/182) use window.alert if SweetAlert is not installed.
* Make sure we reset submitted flag if we are not able to submit the form.
* Fix issues with form validation when you enable the "On behalf of Organisation" block on contribution pages - see https://lab.civicrm.org/extensions/stripe/-/issues/147#note_38994 and https://github.com/civicrm/civicrm-core/pull/17672.

## Release 6.4
**This release REQUIRES that you upgrade mjwshared to 0.7 and your Stripe API version must be 2019-12-03 or newer.**

#### New Features:

* The Stripe "element" now follows the current CMS/CiviCRM locale.
* Add jquery form events:
    * 'crmBillingFormReloadComplete' and document jquery events.
    * 'crmBillingFormNotValid' so 3rd-party integrations can re-enable custom submit buttons etc.
      Add custom property on billing form to allow for custom validations

* Add support for sweetalert library on form validation errors so we popup nice messages when you are missing required fields and for card errors and you click submit.
* Make sure we don't submit the form if we have a reCaptcha and it is not valid.
* Add setting to disable billing address fields.
* Major improvements to form validation before submission - this significantly reduces the number of payments that are authorised but not captured.
* Add a minimum API version so we don't have problems every time Stripe release a new API version.
* Change style of card element

#### Bugfixes:

* Make sure we generate backend contact links for customer metadata (previously they would sometimes get generated as frontend links).
* Fix missing receipts for recurring subscription payment [#122](https://lab.civicrm.org/extensions/stripe/issues/122).
* Fix [#178](https://lab.civicrm.org/extensions/stripe/issues/178) recurring payments for webform_civicrm when "Interval of installments" is selected.
* If Stripe is not using the same currency as the payment was made we need to convert the fees/net amounts back to the CiviCRM currency.
* Fix [#196](https://lab.civicrm.org/extensions/stripe/issues/196) Recurring contributions with incorrect amount per default currency in stripe - if Stripe uses a different currency to CiviCRM the amounts for recurring contributions were not being recorded correctly in CiviCRM.
* Fix [#189](https://lab.civicrm.org/extensions/stripe/issues/189) Error on membership contribution page with autorenew set to automatic.

#### Behind the scenes:

* Further tweaks to get tests working
* Initial steps to modernize the testing infrastructure.
* Add some docblocks to the code.
* Switch to event.code from deprecated event.keyCode.

##### Client side (javascript):

* Add support for a function getTotalAmount that could be used to retrieve amount from form if defined.
* Restrict use of amount when creating paymentIntents.
* Fix issues with stripe js on thankyou pages.
* Call IPN->main() from inside a try catch to allow loops [!94](https://lab.civicrm.org/extensions/stripe/-/merge_requests/94)
* Use minifier extension to minify js/css assets (much easier for development as we don't ship minified files anymore).

## Release 6.3.2 - Security Release

If you are using Stripe on public forms (without authentication) it is **strongly** recommended that you upgrade and consider installing the new **firewall** extension.

Increasingly spammers are finding CiviCRM sites and spamming the linked Stripe account with 1000s of attempted payments
and potentially causing your Stripe account to be temporarily blocked.

#### Changes
* Add support for firewall extension
* Add system check to recommend installing firewall extension
* Add checks and restrictions to AJAX endpoint
* Add cache code to js/css resources so they are reloaded immediately after cache clear.

* [#168](https://lab.civicrm.org/extensions/stripe/issues/168) Improve handling of webhooks with mismatched API versions - now we track the dashboard API version and don't try to explicitly set a webhook API version.
You may still need to delete and re-add your webhook but should not need to next time the API version changes.

#### Features
* [#126](https://lab.civicrm.org/extensions/stripe/issues/126) Stripe element now uses the CMS/CiviCRM locale so it will appear in the same language as the page instead of the browser language.

## Release 6.3.1

* Add crm-error class to stripe card errors block so it is highlighted on non bootstrap themes
* Fix Stripe.ipn API when working with charge.captured/succeeded
* Update documentation to mention contributiontransactlegacy extension
* [#147](https://lab.civicrm.org/extensions/stripe/issues/147) Add workaround and set required billing fields via jquery
* [#153](https://lab.civicrm.org/extensions/stripe/issues/153) Support multiple participant registration and CiviDiscount with zero amount.
* Fix non-stripe submit check - if amount is zero.

## Release 6.3
**This release REQUIRES that you upgrade mjwshared to 0.6 and your Stripe API version to 2019-12-03.**

*If you wish to test the upgrade you can remain on an older version or later but should update the API version as soon as you are happy.*

* **Update required Stripe API version to 2019-12-03**
* Add support for recording partial refunds from Stripe.
* For forms that have multiple submit buttons (eg. Save, Save and New) override the submit handler on all of them *(This fixes some more instances of missing PaymentIntentID on the backend forms).*
* Resolve issues with backend forms and tax amounts *(fixes issues with backend forms that include an additional tax amount)*.
* Resolve issues with money formats that don't use a dot as decimal separator (eg. €1.024,20).
* Update required Stripe API verison to 2019-12-03.
* Fix issues with StripeSubscription.import and mismatched id/customer_id params.
* Fix [#125](https://lab.civicrm.org/extensions/stripe/issues/125) Thousands of failed/spam transactions for charge.failed webhook *(We ignore and return 200 OK to Stripe so it does not retry if there is no customer ID)*.
* Change default to 1 hour to cancel uncaptured payments based on client feedback.
* Update definition of getAmount to match current version in CiviCRM core.
* Pre-fill existing billing postcode if we already have address.
* Fix recurring contribution issue on drupal webform.
* [#148](https://lab.civicrm.org/extensions/stripe/issues/148) Fix Credit or debit card translation.
* Fix [#149](https://lab.civicrm.org/extensions/stripe/issues/149) Cannot submit payment from back end when tax and invoicing disabled.

## Release 6.2.2

* Make sure we detect memberships as auto-renew when they are "forced".
* Make sure we always load the recurring contribution ID properly.

## Release 6.2.1

* [#121](https://lab.civicrm.org/extensions/stripe/issues/121) Fix auto-recurring membership payments.
* Stripe.ListEvents API - properly handle newer way to record trxn_id value.
* Change doRefund signature to match what is in CiviCRM core (fixes a PHP warning).

## Release 6.2

* Track paymentIntents and cancel uncaptured ones after 24 hours.
  > Configurable via scheduled Job.process_stripe and API.
* Refactor to support updating amount and re-confirming once we reach the thankyou page.
* When we don't know the amount, pre-auth the card but don't confirm, then update the amount requested.
  > This resolves, for example, registering multiple participants. Users may receive an additional confirmation step such as 3d secure on the *Thankyou* page if their card issuer requires it.
* Refactor passing of token parameters to use pre_approval_parameters.
  > This should resolve some issues with *PaymentIntent not found*.
* Improve support for refunds in preparation for work in CiviCRM core (#15476-15479).
* Add CiviCRM version info to stripe customer - this is useful when troubleshooting issues on client sites as it is important to know whether a reported issue may have been fixed in a later version.
* Fix [#110](https://lab.civicrm.org/extensions/stripe/issues/110) -Allow submit if amount is 0.
* Fix and record paymentIntents for recurring contributions - show authentication to user on thankyou page.
  > this checks loads 3d secure etc from Stripe if required.
* Don't try to record refund for an uncaptured payment.
  > When an uncaptured payment is cancelled it triggers a charge.refunded event. But we don't want to record this in CiviCRM as it was never "captured" and the payment was never really taken.

## Release 6.1.6

* Fix [#103](https://lab.civicrm.org/extensions/stripe/issues/103) - Allow submission of drupal webform when there are multiple processors on the page (eg. Stripe + Pay later).

## Release 6.1.5

* Send email receipts from Stripe by default (as this was what 5.x did). Add a setting under Administer->CiviContribute->Stripe Settings to enable/disable receipts from Stripe.
* Support recording full refunds from Stripe.

## Release 6.1.4
**This release fixes a MAJOR issue that caused duplicate payments to be taken when a new recurring contribution (subscription) was setup.  All users of 6.x should upgrade.**

* Don't take payment twice on recurring payments (This was happening because a payment was being created via a paymentIntent and subsequently via the first invoice generated by the subscription - currently we don't support 3D secure on subscriptions, this will be fixed in a future release).
* If we get an error when submitting, make sure we run Stripe submit next time as well so we generate a paymentIntent/paymentMethod for the payment (this fixes the issue where only the first submission attempt would succeeed - subsequent submission attempts would fail with "Missing paymentIntentID").
* Validate payment forms using javascript so we don't create multiple uncaptured paymentIntents when the form is not valid (each time the form was submitted and failed because eg. the email address was invalid a new paymentIntent would be created).

## Release 6.1.3

**You need to Fix/Create webhook after installing this update to add the `charge.captured` event to the list of events sent to the webhook.**

* Handle charge.succeeded/charge.captured when no customer_id is provided - fixes 400 errors / missing customer_id.
* Remove invalid setting of customer on paymentIntent (no user impact).
* Small improvements to Stripe Plan code (no user impact).

*Note: You should use [this CiviCRM core patch](https://github.com/civicrm/civicrm-core/pull/15340) if using webform_civicrm 4.28.*

## Release 6.1.2

* Fix [#89](https://lab.civicrm.org/extensions/stripe/issues/89) - Payment Element is not loading for backend "Credit Card Event Registration".
* Fix repeatContribution - pass the found contribution ID instead of using a separate previous_contribution variable - fixes !63

## Release 6.1.1

* Fix issue with charge.succeeded triggering error on recurring contributions

## Release 6.1

*This release fixes a number of bugs/issues identified after the release of 6.0.*

#### Upgrade Advice

**IMPORTANT!** If upgrading to 6.x for the first time make sure you install the [mjwshared](https://lab.civicrm.org/extensions/mjwshared) extension
**BEFORE** you upgrade Stripe.

This release requires an upgrade to version 0.4 of the MJWShared extension.

 **ALL users of 6.0 should upgrade to this release.**

 If upgrading to 6.x for the first time, please upgrade directly to 6.1 (do not install 6.0 first).

#### Changes

* Support cards that do not request a postal/zip code (*fixes [#80](https://lab.civicrm.org/extensions/stripe/issues/80)*).
* Enable payments on backend (*fixes [#79](https://lab.civicrm.org/extensions/stripe/issues/79)*).
* Resolve multiple issues with "more than one" payment processor on the form and stripe failing to submit if it wasn't the first to be selected:
    * Fix issue when script is reloaded by changes of payment processors.
    * Improve handling for multiple processors and pay later.
    * Make sure we clear the paymentIntentID from the session once we've used it (this prevents a browser refresh trying to use an already captured paymentIntent).

## Release 6.0

*Switch to Stripe Elements for SAQ-A compliance on most sites and support the European Secure Customer Authentication (SCA) payments directive.*

**This is a major new release. You cannot rollback once you've upgraded.**

**This extension REQUIRES the [mjwshared](https://lab.civicrm.org/extensions/mjwshared) extension.**

**You MUST update your API version on the stripe dashboard!**

* Use [Stripe Elements](https://stripe.com/payments/elements).
* Use PaymentIntents and comply with the European [SCA directive](https://stripe.com/docs/strong-customer-authentication).
* Require Stripe API Version: 2019-09-09 and ensure that all codepaths specify the API version.
* Switch publishable key/secret key in settings (upgrader does this automatically) so they are now "correct" per CiviCRM settings pages.
* Support cards using 3dsecure and cards not using 3dsecure (workflows with Stripe are slightly different but both are now handled).
* Use minified versions of js/css.
* Improve payment descriptors and customer information that is sent from CiviCRM to Stripe.
* Add basic support for PaymentProcessor.refund API.

#### What is NOT supported:

* CiviCRM Event Cart (requires additional funding, changes should probably be made in CiviCRM core to standardize that workflow rather than adding support via this extension).
* Card payments via the admin backend (this was supported in 5.4.1 but has unresolved issues with Stripe Elements when used via popup forms and is not allowed in most situations when complying with the SCA payments directive unless you are approved to accept "MOTO" payments).

## Release 5.4.1

* Don't overwrite system messages when performing webhook checks.
* Add form to handle creating/updating webhooks instead of automatically during system check (Thanks @artfulrobot)

## Release 5.4

This release fixes multiple bugs and introduces a few small features.

**A major feature for this release is the automatic management of webhooks:**
Note that when you upgrade you may end up with duplicate webhooks in Stripe with slightly different addresses (particularly on Wordpress where the path should be urlencoded).  Just delete the older (duplicate) webhooks manually from your Stripe dashboard.

* Fix drupal webform detection so it doesn't generate a false positive if we also have a webform on the same page.
* Fix Stripe create customer in test mode.
* Fix offline (live) event payments for Wordpress.
* If payment fails and we have no contribution don't crash when trying to create a note.
* Fix null dates returning as December 31, 1969 or 1 Jan 1970 (depending on your
    timezone) - also see the commandline script in `utils/fix-issue-44.php` to
    correct your Contributions data.

* Support Drupal 8 Webform.
* Automatically manage and create webhooks.
* Add StripeCustomer.updatestripemetadata API.
* Add a system check for invalid API key.
* Add StripeCustomer.delete to delete a customer from CiviCRM.
* Add StripeSubscription.import API to import subscriptions into CiviCRM.
* Add Stripe.cleanup API.
* Report all Stripe errors, not just authentication when running status checks.

* Remove `is_live` field from `civicrm_stripe_customer` - we can get this from the payment processor ID.

## Release 5.3.2

* Fix retrieving email receipt parameter on stripe IPN which stopped contributions from being marked as completed.
* Fix webhook check for wordpress so we don't get false positives when everything is configured ok.

## Releae 5.3.1

* Fix issue with event/membership payments failing to record in CiviCRM (introduced in 5.3).

## Release 5.3

**All users should upgrade to 5.3.1 due to an issue with event/membership payments**

There are no database changes in this release but you should update your Stripe webhook API version to 2019-02-19.

### Changes
* Update required Stripe API version from 2018-11-08 to 2019-02-19.
* Update stripe-php library from 6.19.5 to 6.30.4.

### Fixes
* Make sure we clear processor specific metadata from payment form when switching payment processor (fixes https://lab.civicrm.org/extensions/stripe/issues/26).
* Fix saving of fee amount and transaction ID on contribution record.

### Features
* Add a Webhook System Check.
* Send a friendly success response if we receive the test webhook.
* Webhooks now work in test mode.
* Use the parameter on the recurring contribution to decide whether to send out email receipts.

## Release 5.2

*This release introduces a number of new features, standardises the behaviour of recurring contributions/memberships to match standard CiviCRM functionality and does a major cleanup of the backend code to improve stability and allow for new features.*

### Highlights:

* Support Cancel Subscription from CiviCRM and from Stripe.

### Breaking changes:

* The extension now uses the standard CiviCRM Contribution.completetransaction and Contribution.repeattransaction API to handle creation/update of recurring contributions. This means that automatic membership renewal etc. is handled in the standard CiviCRM way instead of using custom code in the Stripe extension. The behaviour *should* be the same but some edge-cases may be fixed while others may appear. Any bugs in this area will now need to be fixed in CiviCRM core - if you want to help with that see https://github.com/civicrm/civicrm-core/pull/11556.
* When recurring contributions were updated by Stripe, they were marked cancelled and a new one created in CiviCRM. This was non-standard behaviour and causes issues with CiviCRM core functionality for membership renewal etc. This has now been changed so only one recurring contribution per subscription will ever exist, which will be updated as necessary during it's lifecycle.
* Different payment amounts are now supported for each contribution in a recurring contribution. Previously they were explicitly rejected by the extension.

### Changes:

* Add http response codes for webhook (invalid parameters now returns 400 Bad Request).
* Major refactor of webhook / events handling (fixes multiple issues, now tested and working on Joomla / Wordpress / Drupal 7).
* Update to latest version of stripe-php library.
* Handle "Customer Deleted" from Stripe.
* Drop use of civicrm_stripe_plans table and just query Stripe each time. This prevents errors when they get out of sync

### Upgrading
**Please upgrade to 5.0 if you are on ANY older version. Then upgrade directly to 5.2. You do not need to install 5.1 first.**

Make sure you run the extension upgrades immediately after updating the code.  There are two MAJOR upgrade steps:
1. Migrate civicrm_stripe_customers table to match on contact_id instead of email address. This can be re-run if necessary using StripeCustomer.updatecontactids API.
2. Migrate data from civicrm_stripe_subscriptions to use the recurring contribution (trxn_id = Stripe subscription ID). This can be re-run if necessary using StripeSubscription.updatetransactionids API.


## Release 5.1
*This was a limited release to selected clients in order to test new functionality.  **Production sites should upgrade to 5.2 directly from 5.0**.*

### Changes:
* Use contact_id as reference in civicrm_stripe_customers and don't require an email address for payment.
* Drop old webhook code / endpoint at https://{yoursitename.org}/civicrm/stripe/webhook. You **MUST** update your webhooks to use the standard CiviCRM endpoint at https://{yoursitename.org}/civicrm/payment/ipn/XX (see [Webhooks and Recurring Payments](/recur.md) for details).


## Release 5.0
*This is the first release with a new maintainer (mattwire https://www.mjwconsult.co.uk) and repository move to https://lab.civicrm.org/extensions/stripe.*

**If upgrading from ANY version before 5.0 you should upgrade to this version first. It should be a safe upgrade for all sites on previous versions unless you are running a customised version of the extension.**

### Highlights:
* Fix all known "Stripe.js token was not passed".
* Tested support for Drupal 7 / Wordpress / Joomla for contributions/event payments.
* Improvements to recurring payments (though you will want to upgrade to 5.2 if using recurring payments as recurring payments has had a major rewrite for 5.2).


# Alpha / Beta releases

## Release 6.0.beta1

*Thanks to Rich Lott (@artfulrobot) for contributing and testing this release.*

* We don't need to confirm the payment until we capture it
* payment method id is not required when passing in an existing payment intent
* Use minified versions of js/css.
* Remove onclick attribute from submit form so that CiviContribute forms do stripe processing before submission
* Description and Customer fields in Stripe backend - fixes #78

## Release 6.0.alpha3

* Support recurring payments with paymentIntents/Elements. Cancel subscription with Stripe when we reach recurring end date
* **Update required Stripe API version to 2019-09-09**
* Handle confirmation pages properly for contribution pages (make sure we pass through paymentIntentID).
* Handle card declined on client side.
* Support creating recurring payment (subscription).
* Handle IPN events for charges / invoices (support cancel/refund etc).
* Add basic support for PaymentProcessor.refund API.
* Remove membership_type_tag from plan name.

## Release 6.0.alpha2

* Support Drupal Webform CiviCRM.
* Support Event Registration.
* Support Confirm/Thankyou pages on contribution pages / events.
* Support cards using 3dsecure and cards not using 3dsecure.

### Not Supported (should be in final 6.0 release):
* Recurring payments.
* Backend payments.

## Release 6.0.alpha1

* ONLY contribution pages with no confirm pages are supported.
