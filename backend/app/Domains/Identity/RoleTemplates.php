<?php

namespace App\Domains\Identity;

/**
 * The roles every company starts with. New companies receive exactly these,
 * so all companies share the same role names, labels and permissions until
 * their owner customizes them.
 */
final class RoleTemplates
{
    public const OWNER = 'owner';

    /** name => [Arabic label, permission names; the owner holds every permission] */
    public const ROLES = [
        'owner' => ['المالك', ['*']],
        'accountant' => ['المحاسب', ['accounting.view', 'accounting.journal_create', 'accounting.post', 'accounting.reverse', 'reports.view', 'reports.financial', 'reports.vat', 'customers.view', 'purchasing.view', 'purchasing.invoice', 'purchasing.pay', 'checks.view', 'checks.deposit', 'checks.clear', 'checks.bounce', 'payments.view', 'payments.receive', 'cashbank.view', 'expenses.view', 'expenses.create', 'audit.view']],
        'cashier' => ['المبيعات والصندوق', ['sales.view', 'sales.create', 'sales.post', 'catalog.view', 'inventory.view', 'customers.view', 'customers.manage', 'payments.view', 'payments.receive']],
        'inventory' => ['المخزون والمشتريات', ['catalog.view', 'catalog.manage', 'inventory.view', 'inventory.receive', 'inventory.transfer', 'inventory.count', 'purchasing.view', 'purchasing.create', 'purchasing.receive']],
        'collections' => ['التحصيل', ['customers.view', 'installments.view', 'payments.view', 'payments.receive', 'checks.view', 'checks.receive']],
    ];

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        $permissions = self::ROLES[$role][1];

        return $permissions === ['*'] ? PermissionCatalog::names() : $permissions;
    }
}
