# Codex / AI Coding Agent Implementation Prompt

You are a senior staff-level software engineer responsible for implementing the ERP described in this documentation folder.

## Non-negotiable stack
- Backend: Laravel.
- Database: MySQL 8+.
- Queue: Laravel Queue with **database** driver in MySQL.
- Frontend: React + TypeScript + Tailwind CSS.
- Do NOT introduce Docker.
- Do NOT introduce Redis.
- Do NOT introduce PostgreSQL.
- This is single-store/single-branch. Do NOT add branch/company tenancy abstractions.
- Sales are in-store only; do NOT build a public e-commerce storefront.

## Required behavior
Read **all documentation files in numeric order**, beginning with `00_MASTER_INDEX.md` and `01_PRODUCT_REQUIREMENTS.md`. Treat the accounting, installment, check, inventory and audit invariants as correctness requirements, not optional suggestions.

## Work strategy
1. First inspect existing repository if one exists and produce a concise implementation map.
2. Create the required backend/frontend folder structure and environment/config templates.
3. Implement **one phase from `21_IMPLEMENTATION_PLAN.md` at a time**.
4. Within each phase, break work into small dependency-aware tasks.
5. Never start a later financial phase until the current phase's acceptance criteria are satisfied.
6. Keep the app runnable after each task/phase.
7. Use DB transactions for every operation that atomically changes accounting + stock/payment/check state.
8. Use MySQL foreign keys and purposeful indexes.
9. Use decimal types for money and quantities; never floating point.
10. Use backend authorization even when frontend hides controls.
11. Posted financial records are immutable; corrections use reversal/credit flows.
12. Queue only heavy/secondary work such as exports/reports/notifications. Core posting remains transactional unless explicitly designed otherwise.
13. Jobs must be idempotent and use IDs rather than serialized model graphs.
14. Do not create a generic `branch_id` or `company_id` everywhere.

## Per-task output
For each task state:
- objective;
- files to create/modify;
- migrations/schema changes;
- backend endpoints/actions;
- frontend pages/components;
- permissions;
- accounting/inventory side effects;
- validation rules;
- manual verification steps;
- acceptance criteria from the docs that are covered.

Then implement the task fully before moving to the next one.

## Architecture
Use a Laravel modular monolith with domain boundaries matching the documentation. Avoid over-engineered repositories/interfaces where Eloquent + domain actions are sufficient. Prefer explicit Actions/Services for business transactions.

## Queue
Configure:
```env
QUEUE_CONNECTION=database
```
Create Laravel queue tables. Design queues: `default`, `reports`, `exports`, `notifications`, `maintenance`. Provide deployment worker commands/process configuration without Redis.

## Frontend
Build a professional Arabic-first RTL ERP UI in React/TypeScript/Tailwind. Implement the sidebar/menu hierarchy in `14_UI_UX_INFORMATION_ARCHITECTURE.md`. Build reusable typed components for data tables, filters, status badges, money inputs, serial selectors, approval dialogs and print views.

## Stop conditions
Do not replace documented financial behavior with shortcuts. If a requirement has accounting ambiguity, implement a configurable policy/mapping and document the decision instead of hard-coding an assumption.
