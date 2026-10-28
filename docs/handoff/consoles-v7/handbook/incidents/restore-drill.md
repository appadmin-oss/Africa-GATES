---
slug: restore-drill
chapter: incidents
title: Playbook: backups and the restore drill
roles: [admin]
owner: Sade Bello
reviewed: 28 Oct 2026
minutes: 2
pages: [root.alerts, settings.security]
related: [alert-levels]
---

# Playbook: backups and the restore drill

A backup you’ve never restored isn’t a backup. Run the drill every quarter.

> **Watch out:** Today nothing backs up the database. One copy on one machine. This is the top risk on Alerts.

1. **Set up a nightly backup off the machine.** Settings › Security and sign-in › Database backup.
2. **Restore into Test data.** Every quarter, restore last night’s backup into the sandbox.
3. **Check three things.** A recent vote, a recent payment and a recent nomination exist.

