# Testing and Quality Strategy

Even if implementation prioritizes build-first, financial ERP correctness requires automated verification before production.

## Test Layers
### Unit
- installment schedule calculation/rounding;
- credit rule evaluation;
- check transition validator;
- money/currency conversion helpers;
- VAT calculation by effective tax code;
- document sequence formatting.

### Feature/Integration
Use real MySQL-compatible test environment where DB behavior matters.
- post sale and verify journals/stock/serial;
- post purchase receipt/invoice;
- installment payment allocation;
- check deposit/clear/bounce;
- return/credit note;
- period locking;
- permission/approval enforcement.

### Reconciliation Tests
Reusable assertions:
- journal balances;
- AR control = customer subledger;
- AP control = supplier subledger;
- inventory quantity/value = movement/valuation;
- check register = relevant GL accounts;
- schedule remaining = contract/customer balance mapping.

### Frontend
- key form validation;
- permission-aware actions;
- POS serialized-item flow;
- installment schedule preview;
- check lifecycle actions;
- Arabic RTL visual sanity.

## Critical Regression Scenarios
1. duplicate double-click payment must not create two receipts;
2. same serial cannot be sold concurrently;
3. two users generating invoice numbers cannot collide;
4. bounced check reverses correct customer exposure;
5. reschedule preserves paid history;
6. return in later tax period creates correct correction document;
7. foreign currency posting freezes rate/base amount;
8. queue retry does not duplicate export/notification side effects.

## Performance Tests
Seed realistic volumes:
- 50k+ products/variants if future catalog growth requires;
- 500k+ inventory movements;
- 250k+ invoice lines;
- 100k+ installment schedule rows;
- 50k+ check history rows;
- 1m+ journal lines for long-term validation.

Measure indexed queries, dashboard endpoints and large exports.

## Production Gate
No go-live with failing reconciliation tests, untested restore, or unresolved duplicated-posting risk.
