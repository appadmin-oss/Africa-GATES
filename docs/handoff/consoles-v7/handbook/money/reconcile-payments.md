---
slug: reconcile-payments
chapter: money
title: Reconciling payments
roles: [admin]
owner: Sade Bello
reviewed: 28 Oct 2026
minutes: 5
pages: [root.payments, root.alerts]
open: {"l":"Open the gateway check","scope":"root","page":"payments","tab":"check"}
related: [refunds, alert-levels, payments-not-recorded]
---

# Reconciling payments

When the gateway took money and we recorded nothing, fix it before the person pays again.

Gateway check lists every payment where the gateway and our records disagree. The usual cause is a webhook that answered but didn’t write.

1. **Open the oldest first.** Each row shows the gateway reference, the amount, and what’s missing: a vote, a ticket or a receipt.
2. **Reconcile.** This creates what was paid for, exactly once, and sends the receipt.
3. **Check the webhook.** Settings › Webhooks. If calls answer 200 but nothing is written, raise an incident.
4. **Answer the person.** Support tickets linked to the payment show on the same row.

> **Watch out:** Never refund and reconcile the same payment. Reconcile first; refund only if the person asks.

> **Rule:** A payment that affects production shows red on the bell and on Home until it’s reconciled.

