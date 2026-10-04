<?php

namespace App\Domains\Identity;

/** Every permission the application checks. Shared by all companies. */
final class PermissionCatalog
{
    public const GROUPS = [
        'sales' => 'view,create,post,cancel,discount,override_price,view_cost,view_profit',
        'installments' => 'view,create,approve,reschedule,early_settlement',
        'checks' => 'view,receive,deposit,clear,bounce,replace,return_to_customer',
        'inventory' => 'view,receive,transfer,adjust,count,view_cost',
        'purchasing' => 'view,create,approve,receive,receive_without_po,invoice,pay,delete_supplier',
        'accounting' => 'view,journal_create,post,reverse,period_lock',
        'reports' => 'view,financial,vat', 'catalog' => 'view,manage',
        'customers' => 'view,manage,delete', 'payments' => 'view,receive',
        'cashbank' => 'view,manage', 'expenses' => 'view,create,approve',
        'approvals' => 'view,decide', 'audit' => 'view',
        'users' => 'manage', 'roles' => 'manage', 'settings' => 'manage',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];
        foreach (self::GROUPS as $domain => $actions) {
            foreach (explode(',', $actions) as $action) {
                $names[] = "$domain.$action";
            }
        }

        return $names;
    }
}
