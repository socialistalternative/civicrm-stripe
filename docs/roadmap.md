# Roadmap of planned features
This roadmap may not always be up to date but gives an idea of what is planned and where funding/support is required.

## Implement Overdue/Failed statuses for Stripe recur

For recurring contributions we currently only implement `In Progress`, `Cancelled` and I think `Completed`
but we need to change that to support `Overdue` and `Failed` status like is implemented and described in GoCardless here: https://docs.civicrm.org/gocardless/en/latest/reference/technical/

### Estimate: 6 hours.

## Automatic import of subscriptions / payments

If we already have the Stripe customer in CiviCRM we can import subscriptions and one-off contributions that were
created outside of CiviCRM (eg. via WooCommerce, Stripe Dashboard).

### Specification
1. Add a setting to control whether import should happen automatically.
2. Update Stripe IPN code to automatically create:
    - A recurring contribution when an unknown Stripe subscription ID is received.
    - A contribution linked to a recurring contribution when an unknown invoice ID is received.
    - A contribution when an unknown charge ID is received (that does not have an associated invoice ID).
    - A payment linked to a contribution via charge ID / invoice ID.

### Estimate: 16 hours.

See https://github.com/mjwconsult/civicrm-stripewebhookrules for a partial implementation.

## UI to map customers to CiviCRM contacts

Based on the existing `Stripe.Importcustomers` API we can build a UI in CiviCRM that allows to manually
confirm and map Stripe customer IDs to CiviCRM contacts.

### Specification

1. Build a UI that lists Stripe customers which do not exist in CiviCRM.
2. Provide options to:
    - Confirm the auto-detected mapping.
    - Manually find and map contact.
    - Import all missing subscriptions/contributions for customer (up to certain date?).

### Estimate: 16 hours.

## Card on File / Updating card details

See: [Issue#64](https://lab.civicrm.org/extensions/stripe/-/issues/64)

Stripe supports saving cards and other payment methods. These can be retrieved, re-used and updated.
We would like to provide support for re-using saved cards in CiviCRM.

### Specification

#### 1. Allow users to update card details (edit current or add new)

Implement API to retrieve all cards for customer and build UI to display cards.

* Stripe API to list cards: https://stripe.com/docs/api/cards/list

Implement API and UI to update card details.

* Use stripe card element with SetupIntent: https://support.stripe.com/questions/update-card-details-with-a-setupintent

Add form to CiviCRM specifically for card updates.

#### 2. Allow admin to update card details

Extend above to work on admin as well. Will be more complex and won't work as well because of card
security requiremetns (eg. 3dsecure).

Administrators can do it through the stripe portal currently so user self-service
is probably a priority for civi?

#### 3. Integrate card-selection UI into payment flow

(so for example a form validation failure will remember the card you just entered and verified).

#### 4. Develop a method to deduplicate payment methods

See: eg. [stripe-payments-demo/issues/45](https://github.com/stripe/stripe-payments-demo/issues/45).
Cards are duplicated by default and we need to clean this up before we can provide a UI to retrieve cards in CiviCRM.

### Estimate: 24-30 hours.

# Payment Methods

## Stripe Connect

See [Stripe Connect](https://stripe.com/connect).

## Bancontact

See [Stripe documentation - bancontact](https://stripe.com/docs/payments/bancontact)

This is a "Bank Redirect" type of payment.

### Implementation

1. Create a paymentIntent of type "bancontact" and return the client secret.
2. Collect payment method details (name) on the client form.
3. Handle redirect to bancontact to make payment and return to CiviCRM.
4. Handle payment_intent.succeeded webhook to confirm payment.

### Estimate

20 hours. This represents approximately 12 hours for the generic [Bank Redirect](https://stripe.com/docs/payments/bank-redirects) functionality
in the Stripe extension and then 8 hours for the specific payment method "Bancontact".

Adding additional payment methods such as Sofort would then require approximately 8 hours each
to implement.

## Update Subscription

See: [Issue#18](https://lab.civicrm.org/extensions/stripe/-/issues/18)

#### Stripe -> CiviCRM

The actual amount is taken and processed via Stripe so the sync from Stripe -> CiviCRM is *information only*.

This requires handling the `customer.subscription.updated` webhook and extracting the required
information to update the recurring contribution in CiviCRM.

#### CiviCRM -> Stripe

CiviCRM has native forms for updating the subscription in CiviCRM. The parameters to support are:

* Amount.
* Schedule (frequency unit/interval).

To setup a subscription in Stripe there are 3 objects involved:

* Subscription - one or more "plans" that make up a subscription.
* Plan - A payment plan (eg. once a month).
* Product - one or more "products" that are included in a plan.

Currently we create a product, add it to a plan and add that plan to a subscription. Then we link the
subscription to CiviCRM via the Stripe subscription ID.

We create plans based on the frequency interval, unit, amount + currency and re-use existing ones if we have already created them:

```php
$planId = "every-{$params['recurFrequencyInterval']}-{$params['recurFrequencyUnit']}-{$amount}-" . strtolower($currency);
```

### Estimate: 12-16 hours.

# Completed

## Stripe ACH/EFT - Available in 6.9 release.

See [Make it Happen](https://civicrm.org/make-it-happen/stripe-ach-payments) campaign on CiviCRM.org.
