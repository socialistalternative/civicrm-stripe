> [!NOTE]
> This is a fork of the [CiviCRM Stripe extension](https://lab.civicrm.org/extensions/stripe).

## Sync with upstream

To update this fork with changes from the upstream repo:

```sh
# Add upstream remote
git remote add upstream https://lab.civicrm.org/extensions/stripe.git

# Fetch and merge changes
git fetch upstream
git merge upstream/master

# Reset back to the latest tagged release
git reset --hard <version>
```

## Patches in this fork

- Don't pass `cancel_url` to Checkout, since it's currently broken for event registration ([`6bd6e7f`](https://github.com/socialistalternative/civicrm-stripe/commit/6bd6e7ff1a49de84f89de6161e82785e6c1fb1be))
- Pass `receipt_email` in the payment intent for Checkout, to force receipt emails to be sent when disabled in Stripe account settings ([`4f0d039`](https://github.com/socialistalternative/civicrm-stripe/commit/4f0d039977d36c3d2470bb3a06d9d7e38b81b89c))

---

# CiviCRM Stripe Payment Processor

Integrates the Stripe payment processor (for Credit/Debit cards) into CiviCRM so you can use it to accept Credit / Debit card payments on your site.

* https://stripe.com/

Latest releases can be found in the [CiviCRM extensions directory](https://civicrm.org/extensions/stripe-payment-processor)

**Always read the [Release Notes](https://docs.civicrm.org/stripe/en/latest/releasenotes/) carefully before upgrading!**

Stripe generously supports CiviCRM, providing a portion of every transaction processed by users of CiviCRM through its platform at no cost to the organizations using CiviCRM.

## Documentation

[Available here](https://docs.civicrm.org/stripe/en/latest).

## Configuration
All configuration is in the standard Payment Processors settings area in CiviCRM admin.
You will enter your "Publishable" & "Secret" key given by stripe.com.

## Installation
See Documentation above.

**If using Drupal 7 webform or other integrations that use Contribution.transact API you MUST install the [contributiontransactlegacy](https://github.com/mjwconsult/civicrm-contributiontransactlegacy) extension to work around issues with that API.**

The extension will show up in the extensions browser for automated installation.
Otherwise, download and install as you would for any other CiviCRM extension.
