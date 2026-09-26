# Project Structure and Coding Conventions

## Backend
```text
backend/
  app/
    Domains/
      Accounting/
      Approvals/
      Audit/
      Catalog/
      CashBank/
      Checks/
      Customers/
      Expenses/
      Identity/
      Installments/
      Inventory/
      Notifications/
      Payments/
      Purchasing/
      Reporting/
      Sales/
      StoreSetup/
      Tax/
      Warranty/
    Http/
      Controllers/Api/V1/
      Middleware/
      Requests/
      Resources/
    Support/
  database/
    migrations/
    seeders/
    factories/
  routes/api.php
  tests/
```

## Frontend
```text
frontend/
  src/
    app/
      router/
      providers/
    features/
      accounting/
      approvals/
      auth/
      catalog/
      checks/
      customers/
      installments/
      inventory/
      purchasing/
      reports/
      sales/
      settings/
    components/
      ui/
      data-table/
      forms/
      money/
      print/
    lib/
      api/
      auth/
      money/
      dates/
      permissions/
    types/
```

## Laravel Conventions
- Controllers are thin: authorization/request -> action -> resource response.
- Complex state changes live in explicit Action classes.
- Avoid service methods with dozens of optional booleans.
- Enums represent document/check/installment states.
- Use domain events for secondary effects, not to obscure required synchronous accounting logic.
- Business exceptions have stable machine codes.
- Migrations never contain application-model calls.

## TypeScript Conventions
- `strict: true`.
- No `any` except justified boundary adapters.
- API schemas/types centralized/generated where feasible.
- Monetary arithmetic uses decimal-safe strategy; do not use JS floating math for authoritative totals.
- Permissions are typed constants.

## Naming
- Tables snake_case plural.
- Laravel models singular PascalCase.
- React components PascalCase.
- REST resources plural nouns.
- Actions use verb + business noun: `PostSalesInvoice`, `BounceCheck`, `RescheduleInstallmentContract`.

## Error Contract
Example:
```json
{
  "message": "لا يمكن تنفيذ العملية",
  "code": "CHECK_INVALID_TRANSITION",
  "errors": {}
}
```

## API Versioning
Start `/api/v1`; breaking contract changes use a new version or explicit migration strategy.
