# 144. Courier tracking: decision record (Q6, 31 October 2026)

## Question

How does HQ know where a customer's parcel is, across Pathao, CarryBee, Steadfast and RedX, without writing to the ERP, which owns the
courier booking and the payout (the Courier hub, `project_courier_hub`)?

## Default chosen (the most conservative: read and listen only, HQ's own read model)

- **HQ reads and listens, it books nothing.** `CourierConnector` has two methods: `track(accountRef, consignmentRef)` and `receiveWebhook(body,
  headers, secret)`. No booking, cancel, pickup-change or payout method exists, so no driver can be asked for one. Nothing is written to the ERP.
- **One capability per courier**, `courier.<provider>.track`, contract `CourierConnector` (see the overview for why not one capability with a
  provider choice). Real drivers `PathaoCourier`, `CarryBeeCourier`, `SteadfastCourier`, `RedxCourier`; sandbox stand-ins `Fake*Courier`.
- **One vocabulary** (`ParcelStatus`: created, picked up, in transit, out for delivery, delivered, on hold, returned, cancelled, unknown). The
  courier's own word is stored beside it. An unrecognised word is `unknown`, never guessed into delivered. `delivered_approval_pending` (Steadfast's
  review step) reads as in transit.
- **HQ's own tables** `courier_parcels` and `courier_parcel_events`, each row with a `business_id`. `ParcelLedger` writes them idempotently
  (an event polled twice, pushed twice, or both is one row by a dedupe key; a late older event never moves a parcel backwards; the newest event
  decides, ties by journey rank). A status change is audited (`courier.parcel_status_changed`).
- **Order link**: a parcel links to a `facts_orders` row of the SAME business when the courier echoes the merchant's reference (Steadfast
  `invoice`, Pathao and CarryBee `merchant_order_id`, RedX `invoice_number`) and exactly one order matches. Two matches or none leave it
  unlinked and the screen says so. `ParcelTimeline` never shows a parcel beside an order of another business.
- **Webhook intake** `POST /api/webhooks/courier/{courier}/{business-slug}` (flag `connectors.courier`): 404 if off or unknown; 503 if no secret is
  stored; 401 for a wrong or missing secret (counted by the signature-failure alert, body never parsed); an authentic call is recorded. The secret is
  `courier_webhook_secret` under the account `<business>.<courier>`. Throttled 300 a minute. Pathao-style couriers get their pairing secret echoed in the
  reply on an authentic call only (`CourierWebhookHandshake`), and their `webhook_integration` test ping is acknowledged (202) and not recorded.
- **Polling** `courier.poll_parcels` (open parcels not checked in 15 minutes, 25 at a time; a down courier does not stop the rest; a failing parcel
  is counted on its row so it is visible, not silently stale) and `courier.track_parcel` (one consignment now). Neither is scheduled by default.
- **Screens**: Parcels (`/parcels`, track-now form), the order view (`/orders/{id}`), and a Parcel column on Customer 360 linking to it. Bangla and
  English, light and dark, phone width.

## Unproven until real keys arrive (stated, not hidden)

The hosts, paths and field names are from each courier's published merchant API as recalled when written, and are configuration
(`hq.courier.*`): Steadfast `GET /status_by_cid/{id}` with `Api-Key` and `Secret-Key`; RedX `GET /parcel/track/{id}` with `API-ACCESS-TOKEN: Bearer`;
Pathao `POST /aladdin/api/v1/issue-token` then `GET /aladdin/api/v1/orders/{id}/info`; CarryBee is the least certain (the API is documented to
merchants only; the shape follows the ERP's own CarryBee integration, and its base URL is empty until Joy sets it). The first live call per courier
proves them. The Pathao pairing reply (`X-Pathao-Merchant-Webhook-Integration-Secret`) follows `reference_courier_webhook_pairing`.

## Questions for ChatGPT (adjust later if the ruling differs)

1. Should a parcel with no order link be offered to a person to link by hand? (Default: shown as unlinked, no manual link.)
2. Should HQ ever tell a customer where the parcel is? (Default: no; that is an outbound message with consent and approval, a later tool.)
