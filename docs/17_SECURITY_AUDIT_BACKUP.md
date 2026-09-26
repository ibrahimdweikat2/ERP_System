# Security, Audit, Backup and Recovery

## 1. Authentication
- secure password hashing using Laravel defaults;
- rate-limit login and reset endpoints;
- optional/strongly recommended MFA for owner/accountant;
- secure cookie/session settings when using SPA auth;
- revoke sessions/tokens on user disable.

## 2. Authorization
Backend policy enforcement for every sensitive action. Never trust frontend role checks.

## 3. Data Protection
- HTTPS mandatory in production;
- application secrets in environment/server secret management, never source control;
- least-privileged MySQL application user;
- sensitive attachments access-controlled;
- avoid unnecessary storage of personal data;
- mask sensitive audit fields.

## 4. Audit Log
Audit at least:
- authentication/security events;
- master-data changes affecting prices/tax/accounts;
- discounts/price overrides;
- approvals;
- invoice posting/reversal;
- payments;
- check status transitions;
- installment reschedules;
- stock adjustments/counts;
- account/permission changes;
- period lock/unlock;
- exports of sensitive financial data.

## 5. Database Backup
Minimum policy:
- automated nightly MySQL backup;
- multiple retention tiers (daily/weekly/monthly);
- encrypted off-server copy;
- attachment/files backup synchronized with DB backup policy;
- backup job monitoring and failure alerts.

## 6. Restore Testing
A backup is not valid until restoration is tested. Schedule periodic restore to a non-production environment and verify:
- DB integrity;
- journal balance;
- inventory/AR/AP reconciliation;
- attachment availability;
- application login.

## 7. Recovery Objectives
Business decides RPO/RTO. Recommended starting target for a single store:
- RPO <= 24h from nightly backup, improved with more frequent DB backups/binlogs if affordable;
- RTO documented and rehearsed.

## 8. MySQL Binlogs
Enable and retain binary logs if server capacity/operations allow point-in-time recovery. Protect them as sensitive financial data.

## 9. Application Logging
Separate:
- technical application logs;
- audit logs;
- queue failures;
- scheduled task failures.
Do not log passwords, tokens, full card data, or unnecessary identity secrets.
