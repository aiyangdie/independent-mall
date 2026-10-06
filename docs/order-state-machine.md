# Order and payment state machines

Amounts are integer minor units. Transitions are enforced in `IndependentMall\Checkout\Checkout` and `IndependentMall\Payment\FakePaymentGateway`.

## Orders

```text
pending_payment → paid → fulfilling → completed
pending_payment → cancelled
completed → refund_requested → refunded
refund_requested → completed (refund rejected)
```

Current Fake adapter marks paid orders `completed` immediately after fulfillment.

Illegal jumps (for example `pending_payment` → `refunded`, or a shopper POST that sets `status=paid`) are rejected.

## Payments

`created → succeeded | failed | cancelled`

Notify must match `merchant_order`, `amount_minor`, `currency`, and HMAC signature. Duplicate succeeded notifications are no-ops.

## Refunds

`requested → succeeded | rejected`

Fake adapter does not move real money. Duplicate `idempotency_key` returns the same refund row.

## Inventory

`reserve` on place, `release` on cancel, `sale` on successful payment. `(reason, reference)` is unique.
