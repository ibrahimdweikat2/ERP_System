# Notifications and Scheduler

## 1. Internal Notifications
Notification center for:
- installments due soon/today/overdue;
- checks due soon/today;
- bounced checks;
- low stock;
- pending approvals;
- failed report exports;
- backup/maintenance alerts for admins.

## 2. External Notifications
SMS/WhatsApp/email integrations are optional adapters, not core assumptions. Store consent/template/history before enabling automated customer messages.

## 3. Scheduled Tasks
Examples:
- daily: generate due/overdue collection worklist;
- daily: check due-date buckets for checks;
- daily: low-stock scan;
- nightly: refresh selected report aggregates;
- nightly: cleanup expired generated exports;
- periodic: notify pending approvals;
- periodic: queue retry/failed-job operational alert.

## 4. Queue Use
Notifications are queued on `notifications`. Failure to send a reminder must never rollback a completed sale/payment/accounting posting.
