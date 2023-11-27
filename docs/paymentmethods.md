# Payment Methods

The Stripe extension implements the following payment methods:

## Credit / Debit Card

### Card Element

See: [Stripe Card Element](https://stripe.com/docs/payments/payment-methods/overview#cards).

![card element](images/element_card.png)

This is enabled by default. No additional configuration is required.

It is supported for all payment types.

#### MOTO (Mail Order Telephone Order) Payments

If you want to take payments in this way you have to:

1. Request that it is enabled on your Stripe account.
2. Enable "MOTO payments" in CiviCRM Stripe settings (and choose if you want it enabled on backoffice and/or frontend forms).
3. Give the "CiviCRM Stripe: Process MOTO transactions" permission to roles which are allowed to process MOTO payments (eg. administrator).

