# Roles, Permissions and Approvals

## 1. Permission Naming
Use granular action permissions:
```text
sales.view
sales.create
sales.post
sales.cancel
sales.discount
sales.override_price
sales.view_cost
sales.view_profit

installments.view
installments.create
installments.approve
installments.reschedule
installments.early_settlement

checks.view
checks.receive
checks.deposit
checks.clear
checks.bounce
checks.replace
checks.return_to_customer

inventory.view
inventory.receive
inventory.transfer
inventory.adjust
inventory.count
inventory.view_cost

purchasing.create
purchasing.approve
purchasing.receive
purchasing.invoice
purchasing.pay

accounting.view
accounting.journal_create
accounting.post
accounting.reverse
accounting.period_lock
reports.financial
reports.vat

audit.view
users.manage
roles.manage
settings.manage
```

## 2. Suggested Roles
### Owner
All permissions.

### Accountant
Financial accounting, tax, reconciliation, reports and controlled posting; no user/role administration unless granted.

### Cashier/Sales
Sales, customer lookup/create and allowed payment receipt; restricted cancellation, discount, cost/profit, journals.

### Inventory/Purchasing
Catalog, stock and procurement permissions; financial supplier payment only if explicitly granted.

### Collections
Customer statement, installments, payment receipt, checks follow-up; no general accounting configuration.

## 3. Approval Rules
Configurable approval triggers:
- sale below minimum price;
- discount above role threshold;
- installment with down payment below minimum;
- credit limit exceeded;
- customer has overdue > threshold;
- bounced-check risk;
- installment rescheduling;
- early settlement discount;
- large stock adjustment;
- large expense;
- void/cancellation of posted-like operational document where policy permits reversal;
- backdated posting;
- large purchase order.

## 4. Approval Record
Store source entity/action, requested payload/diff, requester, reason, status, approver, decision note, timestamps and expiration if applicable.

Approval must not merely unlock a button; the subsequent action verifies the exact approved payload or relevant hash/version so the requester cannot modify numbers after approval.
