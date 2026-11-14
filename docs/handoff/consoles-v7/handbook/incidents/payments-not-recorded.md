---
slug: payments-not-recorded
chapter: incidents
title: Playbook: payments not recorded
roles: [admin]
owner: Sade Bello
reviewed: 28 Oct 2026
minutes: 3
pages: [root.alerts, root.payments]
open: {"l":"Open the gateway check","scope":"root","page":"payments","tab":"check"}
related: [reconcile-payments, alert-levels]
---

# Playbook: payments not recorded

The gateway takes money, the webhook answers, and nothing is written. Stop new losses, then fix each payment.

1. **Confirm.** Gateway check shows payments with no vote, ticket or receipt.
2. **Switch provider.** Settings › Payments: make the fallback provider primary while you investigate.
3. **Tell support.** Pin a reply to tickets that mention a missing vote.
4. **Reconcile each payment.** Oldest first. Each one creates what was paid for, once.
5. **Write it up.** Add a decision to the audit log with cause and fix.

> **Watch out:** Don’t ask people to pay again. Reconciling makes their payment count.

