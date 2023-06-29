# Install / Configuration

## Installation
**The [mjwshared](https://lab.civicrm.org/extensions/mjwshared) extension is required and MUST be installed.**

**If using drupal webform or other integrations that use Contribution.transact API you should install the [contributiontransactlegacy](https://github.com/mjwconsult/civicrm-contributiontransactlegacy) extension to work around issues with that API.**

The extension will show up in the extensions browser for automated installation.
Otherwise, download and install as you would for any other CiviCRM extension.

## Configuration

### Stripe

Stripe automatically provides both a live and test account for you. Toggle the "test" switch in the Stripe dashboard to switch between the two accounts.

Create API keys and a webhook secret by logging in to your Stripe dashboard and selecting
[API keys](https://dashboard.stripe.com/account/apikeys) from the menu. You can use the
standard key, or you can click "Create restricted key" to have a more limited key.
Example key restrictions are listed below.

### CiviCRM

Configure a Stripe payment processor in the same way as you would for any other payment processor in CiviCRM.

All configuration is in the standard Payment Processors settings area in CiviCRM admin (**Administer menu > System Settings > Payment Processors**).

#### Stripe Card Element

Add a payment processor, select "Stripe" as the type and enter your
*Publishable* and *Secret* keys and your *webhook secret* from the Stripe Dashboard.

#### Stripe Checkout

Add a payment processor, select "Stripe Checkout" as the type and enter your
*Publishable* and *Secret* keys and your *webhook secret* from the Stripe Dashboard.

## Permissions

To accept payments the user must have the following permissions as a minimum:
* `access Ajax API`
* `make online contributions`

This applies to both unauthenticated (anonymous) and authenticated users.

### How to update Stripe API version
More info on how to change: [Stripe: Upgrading your API version](https://stripe.com/docs/upgrades#how-can-i-upgrade-my-api).

Go to **Stripe Dashboard > Developers** to view and upgrade your API version:
![Stripe Dashboard API Version](images/stripedashboard_apiversion.png)

The Webhook API versions will be configured / updated automatically by this Stripe extension but if you didn't create them using the automated method you may need to delete them and allow the extension to re-create them.

### Stripe API Key restrictions
If you prefer, you can restrict the permissions available to the API key you create.  The below is an example that may have more permissions than is needed, but works with one-time payments, recurring payments, and the webhook check built into this extension.  If a permission isn't listed below, leave it as *None*.

**Core resources**
Balance: *Read*
Charges: *Write*
Customers: *Write*
Disputes: *Write*
Events: *Read*
Products: *Write*
Sources: *Write*
Tokens: *Read*
**Billing resources**
Plans: *Write*
Subscriptions: *Write*
**Connect resources**
Application Fees: *Write*
**Orders resources**
SKUs: *Write*
**Webhook resources**
Webhook Endpoints: *Write* (required for the webhook system check/auto-create webhooks)
![Example Stripe API Permissions](images/example_api_perms.png)


