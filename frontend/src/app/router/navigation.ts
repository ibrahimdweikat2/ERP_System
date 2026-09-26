import type { PermissionName } from "../../types/identity";
export type NavItem = {
  label: string;
  path?: string;
  permission?: PermissionName;
};
export type NavGroup = { label: string; icon: string; items: NavItem[] };
export const navigation: NavGroup[] = [
  {label:"الخزينة",icon:"accounting",items:[{label:"ورديات الصندوق",path:"/treasury/sessions",permission:"sales.post"},{label:"التحويلات وحركات المالك",path:"/treasury/transfers",permission:"cashbank.view"},{label:"التسوية البنكية",path:"/treasury/bank-reconciliation",permission:"cashbank.view"}]},
  {
    label: "مساحة العمل",
    icon: "home",
    items: [{ label: "لوحة المتجر", path: "/" },{label:"التنبيهات",path:"/notifications"},{label:"أمان حسابي",path:"/account/security"},{label:"مركز التقارير",path:"/reports"}],
  },
  {
    label: "المبيعات",
    icon: "sales",
    items: [
      {label:"التسويات وأرصدة العملاء",path:"/sales/settlements",permission:"sales.view"},
      { label: "بيع جديد / نقطة البيع", path: "/sales/new", permission: "sales.create" },
      { label: "طلبات البيع", path: "/sales/orders", permission: "sales.view" },
      { label: "فواتير المبيعات", path: "/sales/invoices", permission: "sales.view" },
      { label: "المرتجعات والإشعارات الدائنة", path: "/sales/returns", permission: "sales.view" },
      { label: "سندات قبض العملاء", path: "/sales/receipts", permission: "payments.view" },
    ],
  },
  {
    label: "الأقساط",
    icon: "installments",
    items: [
      { label: "العقود", path: "/installments/contracts", permission: "installments.view" },
      { label: "المستحق اليوم", path: "/installments/due", permission: "installments.view" },
      { label: "الأقساط المتأخرة", path: "/installments/overdue", permission: "installments.view" },
      { label: "التحصيل", path: "/installments/collections", permission: "installments.view" },
      { label: "طلبات إعادة الجدولة", path: "/admin/workflow-approvals", permission: "installments.reschedule" },
    ],
  },
  {
    label: "الشيكات",
    icon: "checks",
    items: [
      { label: "مركز الشيكات", path: "/checks/register", permission: "checks.view" },
      { label: "الشيكات المستحقة", path: "/checks/due", permission: "checks.view" },
      { label: "دفعات الإيداع", path: "/checks/deposits", permission: "checks.view" },
      { label: "الشيكات المرتجعة", path: "/checks/bounced", permission: "checks.view" },
      { label: "متابعة الاستبدال والتسوية", path: "/checks/bounced", permission: "checks.view" },
    ],
  },
  {
    label: "العملاء",
    icon: "customers",
    items: [
      { label: "قائمة العملاء", path: "/customers", permission: "customers.view" },
    ],
  },
  {
    label: "المنتجات",
    icon: "products",
    items: [
      {
        label: "المنتجات والأسعار",
        path: "/catalog/products",
        permission: "catalog.view",
      },
      {
        label: "الفئات",
        path: "/catalog/masters/categories",
        permission: "catalog.view",
      },
      {
        label: "العلامات التجارية",
        path: "/catalog/masters/brands",
        permission: "catalog.view",
      },
      {
        label: "وحدات القياس",
        path: "/catalog/masters/units",
        permission: "catalog.view",
      },
      {
        label: "سياسات الضمان",
        path: "/catalog/masters/warranty-policies",
        permission: "catalog.view",
      },
    ],
  },
  {
    label: "المخزون",
    icon: "inventory",
    items: [
      {
        label: "الضمان والصيانة",path:"/inventory/warranty",permission:"inventory.view"},
      {label: "نظرة عامة",
        path: "/inventory/balances",
        permission: "inventory.view",
      },
      {
        label: "الأرقام التسلسلية",
        path: "/inventory/serials",
        permission: "inventory.view",
      },
      {
        label: "الحركات",
        path: "/inventory/movements",
        permission: "inventory.view",
      },
      {
        label: "التحويلات",
        path: "/inventory/documents/stock-transfers",
        permission: "inventory.view",
      },
      {
        label: "الجرد",
        path: "/inventory/documents/stock-counts",
        permission: "inventory.view",
      },
      {
        label: "التسويات",
        path: "/inventory/documents/stock-adjustments",
        permission: "inventory.view",
      },
      {
        label: "المخزون المنخفض",
        path: "/inventory/low-stock",
        permission: "inventory.view",
      },
      {
        label: "التالف والمفتوح",
        path: "/inventory/quarantine",
        permission: "inventory.view",
      },
      {
        label: "المواقع الداخلية",
        path: "/catalog/masters/stock-locations",
        permission: "inventory.view",
      },
    ],
  },
  {
    label: "المشتريات",
    icon: "purchasing",
    items: [
      {
        label: "الموردون",
        path: "/purchasing/suppliers",
        permission: "purchasing.view",
      },
      {
        label: "أوامر الشراء",
        path: "/purchasing/orders",
        permission: "purchasing.view",
      },
      {
        label: "استلام البضائع",
        path: "/purchasing/receipts",
        permission: "purchasing.view",
      },
      { label: "فواتير الموردين", path: "/purchasing/invoices", permission: "purchasing.view" },
      { label: "مرتجعات الموردين", path: "/purchasing/returns", permission: "purchasing.view" },
      { label: "دفعات الموردين", path: "/purchasing/payments", permission: "purchasing.view" },
    ],
  },
  {
    label: "المحاسبة",
    icon: "accounting",
    items: [
      {
        label: "ربط الحسابات",
        path: "/accounting/mappings",
        permission: "accounting.view",
      },
      {
        label: "دليل الحسابات",
        path: "/accounting/masters/accounts",
        permission: "accounting.view",
      },
      {
        label: "دفاتر اليومية",
        path: "/accounting/masters/journals",
        permission: "accounting.view",
      },
      {
        label: "القيود اليومية",
        path: "/accounting/journals",
        permission: "accounting.view",
      },
      { label: "الأستاذ العام", path: "/reports/general-ledger", permission: "reports.financial" },
      { label: "الذمم المدينة", path: "/reports/receivables", permission: "reports.financial" },
      { label: "الذمم الدائنة", path: "/reports/payables", permission: "reports.financial" },
      {
        label: "الصناديق",
        path: "/accounting/masters/cashboxes",
        permission: "accounting.view",
      },
      {
        label: "الحسابات البنكية",
        path: "/accounting/masters/bank-accounts",
        permission: "accounting.view",
      },
      { label: "المصروفات", path: "/treasury/expenses", permission: "expenses.view" },
      {
        label: "ضريبة القيمة المضافة",
        path: "/accounting/masters/tax-codes",
        permission: "accounting.view",
      },
      {
        label: "السنوات المالية",
        path: "/accounting/masters/fiscal-years",
        permission: "accounting.view",
      },
      {
        label: "الفترات المالية",
        path: "/accounting/periods",
        permission: "accounting.view",
      },
    ],
  },
  {
    label: "التقارير",
    icon: "reports",
    items: [
      { label: "المبيعات", path: "/reports/sales", permission: "sales.view" },
      { label: "المخزون", path: "/reports/inventory", permission: "inventory.view" },
      { label: "الأقساط", path: "/reports/installments", permission: "installments.view" },
      { label: "الشيكات", path: "/reports/checks", permission: "checks.view" },
      { label: "العملاء", path: "/reports/receivables", permission: "reports.financial" },
      { label: "الموردون", path: "/reports/payables", permission: "reports.financial" },
      { label: "المحاسبة", path: "/reports/trial-balance", permission: "reports.financial" },
      { label: "الضريبة", path: "/reports/vat-summary", permission: "reports.vat" },
      { label: "الربحية", path: "/reports/profitability", permission: "reports.financial" },
    ],
  },
  {
    label: "الإدارة",
    icon: "admin",
    items: [
      {label:"المهام والنسخ الاحتياطية",path:"/admin/operations-health",permission:"settings.manage"},{label:"موافقات العمليات",path:"/admin/workflow-approvals"},{label:"السياسات التشغيلية",path:"/admin/operations-policies",permission:"settings.manage"},{label:"سياسة فواتير الموردين",path:"/admin/supplier-invoice-policy",permission:"settings.manage"},{label:"سياسة أوامر الشراء",path:"/admin/purchase-order-policy",permission:"settings.manage"},
      { label: "المستخدمون", path: "/admin/users", permission: "users.manage" },
      {
        label: "الأدوار والصلاحيات",
        path: "/admin/roles",
        permission: "roles.manage",
      },
      {
        label: "الموافقات",
        path: "/admin/approvals",
        permission: "approvals.view",
      },
      {
        label: "سياسة تسويات المخزون",
        path: "/admin/inventory-policy",
        permission: "settings.manage",
      },
      { label: "سجل التدقيق", path: "/admin/audit", permission: "audit.view" },
      {
        label: "إعدادات المتجر",
        path: "/admin/setup",
        permission: "settings.manage",
      },
      {
        label: "تسلسل المستندات",
        path: "/admin/sequences",
        permission: "settings.manage",
      },
      {
        label: "العملات",
        path: "/accounting/masters/currencies",
        permission: "accounting.view",
      },
      {
        label: "أسعار الصرف",
        path: "/accounting/masters/exchange-rates",
        permission: "accounting.view",
      },
      {
        label: "حالة النظام",
        path: "/admin/health",
        permission: "settings.manage",
      },
    ],
  },
];
