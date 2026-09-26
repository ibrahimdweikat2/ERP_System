import {
  BrowserRouter,
  Navigate,
  Outlet,
  Route,
  Routes,
  Link,
} from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AuthProvider } from "./app/providers/AuthProvider";
import { useAuth } from "./lib/auth/context";
import { ErpShell } from "./components/ErpShell";
import { ErrorNotice, Loading } from "./components/ui/Primitives";
import { LoginPage } from "./features/auth/LoginPage";
import { DashboardPage, ReportsPage, ExportsPage } from "./features/operations/ReportsPage";
import { SalesPage } from "./features/operations/SalesPage";
import { CustomersPage } from "./features/operations/CustomersPage";
import { InstallmentsPage } from "./features/operations/InstallmentsPage";
import { PaymentsPage } from "./features/operations/PaymentsPage";
import { ReturnsPage } from "./features/operations/ReturnsPage";
import { ChecksPage, CheckDepositsPage } from "./features/operations/ChecksPage";
import { TreasuryPage, CashSessionsPage, BankReconciliationPage } from "./features/operations/TreasuryPage";
import { OperationsPoliciesPage, WorkflowApprovalsPage, AccountSecurityPage, NotificationsPage } from "./features/operations/OperationsSettingsPage";
import { SupplierInvoicesPage } from "./features/purchasing/SupplierInvoicesPage";
import { SupplierInvoicePolicyPage } from "./features/purchasing/SupplierInvoicePolicyPage";
import "./features/operations/operations.css";
import { CustomerSettlementsPage } from "./features/operations/CustomerSettlementsPage";
import { WarrantyPage } from "./features/operations/WarrantyPage";
import { OperationsHealthPage } from "./features/operations/OperationsHealthPage";
import { UsersPage } from "./features/settings/UsersPage";
import { RolesPage } from "./features/settings/RolesPage";
import { SequencesPage } from "./features/settings/SequencesPage";
import { HealthPage } from "./features/settings/HealthPage";
import { AuditPage } from "./features/settings/AuditPage";
import { StoreSetupPage } from "./features/settings/StoreSetupPage";
import { MasterPage } from "./features/accounting/MasterPage";
import { JournalsPage } from "./features/accounting/JournalsPage";
import { PeriodsPage } from "./features/accounting/PeriodsPage";
import { MappingsPage } from "./features/accounting/MappingsPage";
import { ProductsPage } from "./features/catalog/ProductsPage";
import { SuppliersPage } from "./features/purchasing/SuppliersPage";
import { PurchaseOrdersPage } from "./features/purchasing/PurchaseOrdersPage";
import { GoodsReceiptsPage } from "./features/purchasing/GoodsReceiptsPage";
import { InventoryPage } from "./features/inventory/InventoryPage";
import { ReorderPage } from "./features/inventory/ReorderPage";
import { StockDocumentsPage } from "./features/inventory/StockDocumentsPage";
import {
  ApprovalsPage,
  InventoryPolicyPage,
  PurchaseOrderPolicyPage,
} from "./features/inventory/ApprovalsPage";
import type { PermissionName } from "./types/identity";
const client = new QueryClient({
  defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: true } },
});
function RequireAuth() {
  const { user, loading, error, refresh } = useAuth();
  if (loading) return <Loading />;
  if (error)
    return (
      <div className="state">
        <ErrorNotice error={error} />
        <button className="button" onClick={() => void refresh()}>
          إعادة المحاولة
        </button>
      </div>
    );
  return user ? <Outlet /> : <Navigate to="/login" replace />;
}
function Permit({
  permission,
  children,
}: {
  permission: PermissionName | PermissionName[];
  children: React.ReactNode;
}) {
  const { can } = useAuth();
  return (
    Array.isArray(permission) ? permission.some(can) : can(permission)
  ) ? (
    children
  ) : (
    <div className="state">
      <h1>الوصول غير متاح</h1>
      <p>لا تملك صلاحية عرض هذه الصفحة.</p>
      <Link to="/">العودة إلى مساحة العمل</Link>
    </div>
  );
}
function App() {
  return (
    <QueryClientProvider client={client}>
      <BrowserRouter>
        <AuthProvider>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            {/* Password reset by email is switched off; the owner resets passwords from Users. */}
            <Route path="/forgot-password" element={<Navigate to="/login" replace />} />
            <Route path="/reset-password" element={<Navigate to="/login" replace />} />
            <Route element={<RequireAuth />}>
              <Route element={<ErpShell />}>
                <Route index element={<DashboardPage />} />
                <Route path="/admin/operations-health" element={<Permit permission="settings.manage"><OperationsHealthPage/></Permit>}/>
                <Route path="/sales/settlements" element={<Permit permission="sales.view"><CustomerSettlementsPage/></Permit>}/>
                <Route path="/sales/settlements/:id" element={<Permit permission="sales.view"><CustomerSettlementsPage/></Permit>}/>
                <Route path="/inventory/warranty" element={<Permit permission="inventory.view"><WarrantyPage/></Permit>}/>
                <Route path="/inventory/warranty/:id" element={<Permit permission="inventory.view"><WarrantyPage/></Permit>}/>
                <Route path="/sales/new" element={<Permit permission="sales.create"><SalesPage pos/></Permit>}/>
                <Route path="/sales/orders" element={<Permit permission="sales.view"><SalesPage orders/></Permit>}/>
                <Route path="/sales/invoices" element={<Permit permission="sales.view"><SalesPage/></Permit>}/>
                <Route path="/sales/invoices/:id" element={<Permit permission="sales.view"><SalesPage/></Permit>}/>
                <Route path="/sales/returns" element={<Permit permission="sales.view"><ReturnsPage/></Permit>}/>
                <Route path="/sales/returns/:id" element={<Permit permission="sales.view"><ReturnsPage/></Permit>}/>
                <Route path="/sales/receipts" element={<Permit permission="payments.view"><PaymentsPage/></Permit>}/>
                <Route path="/sales/receipts/:id" element={<Permit permission="payments.view"><PaymentsPage/></Permit>}/>
                <Route path="/customers" element={<Permit permission="customers.view"><CustomersPage/></Permit>}/>
                <Route path="/customers/:id" element={<Permit permission="customers.view"><CustomersPage/></Permit>}/>
                <Route path="/customers/:id/:tab" element={<Permit permission="customers.view"><CustomersPage/></Permit>}/>
                <Route path="/installments/contracts" element={<Permit permission="installments.view"><InstallmentsPage/></Permit>}/>
                <Route path="/installments/contracts/:id" element={<Permit permission="installments.view"><InstallmentsPage/></Permit>}/>
                <Route path="/installments/:view" element={<Permit permission="installments.view"><InstallmentsPage worklist/></Permit>}/>
                <Route path="/checks/register" element={<Permit permission="checks.view"><ChecksPage/></Permit>}/>
                <Route path="/checks/register/:id" element={<Permit permission="checks.view"><ChecksPage/></Permit>}/>
                <Route path="/checks/center/:id" element={<Permit permission="checks.view"><ChecksPage/></Permit>}/>
                <Route path="/checks/due" element={<Permit permission="checks.view"><ChecksPage view="due"/></Permit>}/>
                <Route path="/checks/bounced" element={<Permit permission="checks.view"><ChecksPage view="bounced"/></Permit>}/>
                <Route path="/checks/deposits" element={<Permit permission="checks.view"><CheckDepositsPage/></Permit>}/>
                <Route path="/checks/deposits/:id" element={<Permit permission="checks.view"><CheckDepositsPage/></Permit>}/>
                <Route path="/purchasing/invoices" element={<Permit permission="purchasing.view"><SupplierInvoicesPage/></Permit>}/>
                <Route path="/purchasing/invoices/:id" element={<Permit permission="purchasing.view"><SupplierInvoicesPage/></Permit>}/>
                <Route path="/purchasing/returns" element={<Permit permission="purchasing.view"><ReturnsPage supplier/></Permit>}/>
                <Route path="/purchasing/returns/:id" element={<Permit permission="purchasing.view"><ReturnsPage supplier/></Permit>}/>
                <Route path="/purchasing/payments" element={<Permit permission="purchasing.view"><PaymentsPage supplier/></Permit>}/>
                <Route path="/purchasing/payments/:id" element={<Permit permission="purchasing.view"><PaymentsPage supplier/></Permit>}/>
                <Route path="/treasury/expenses" element={<Permit permission="expenses.view"><TreasuryPage expense/></Permit>}/>
                <Route path="/treasury/expenses/:id" element={<Permit permission="expenses.view"><TreasuryPage expense/></Permit>}/>
                <Route path="/treasury/transfers" element={<Permit permission="cashbank.view"><TreasuryPage/></Permit>}/>
                <Route path="/treasury/transfers/:id" element={<Permit permission="cashbank.view"><TreasuryPage/></Permit>}/>
                <Route path="/treasury/bank-reconciliation" element={<Permit permission="cashbank.view"><BankReconciliationPage/></Permit>}/>
                <Route path="/admin/operations-policies" element={<Permit permission="settings.manage"><OperationsPoliciesPage/></Permit>}/>
                <Route path="/admin/supplier-invoice-policy" element={<Permit permission="settings.manage"><SupplierInvoicePolicyPage/></Permit>}/>
                <Route path="/treasury/sessions" element={<Permit permission={["cashbank.view","sales.post","payments.receive"]}><CashSessionsPage/></Permit>}/>
                <Route path="/treasury/sessions/:id" element={<Permit permission={["cashbank.view","sales.post","payments.receive"]}><CashSessionsPage/></Permit>}/>
                <Route path="/reports" element={<ReportsPage/>}/>
                <Route path="/reports/exports" element={<ExportsPage/>}/>
                <Route path="/reports/:report" element={<ReportsPage/>}/>
                <Route path="/admin/workflow-approvals" element={<WorkflowApprovalsPage/>}/>
                <Route path="/account/security" element={<AccountSecurityPage/>}/>
                <Route path="/notifications" element={<NotificationsPage/>}/>
                <Route
                  path="/purchasing/receipts"
                  element={
                    <Permit permission="purchasing.view">
                      <GoodsReceiptsPage />
                    </Permit>
                  }
                />
                <Route
                  path="/purchasing/receipts/:id"
                  element={
                    <Permit permission="purchasing.view">
                      <GoodsReceiptsPage />
                    </Permit>
                  }
                />
                <Route
                  path="/admin/purchase-order-policy"
                  element={
                    <Permit permission="settings.manage">
                      <PurchaseOrderPolicyPage />
                    </Permit>
                  }
                />
                <Route
                  path="/purchasing/orders"
                  element={
                    <Permit permission="purchasing.view">
                      <PurchaseOrdersPage />
                    </Permit>
                  }
                />
                <Route
                  path="/purchasing/orders/:id"
                  element={
                    <Permit permission="purchasing.view">
                      <PurchaseOrdersPage />
                    </Permit>
                  }
                />
                <Route
                  path="/purchasing/suppliers"
                  element={
                    <Permit permission="purchasing.view">
                      <SuppliersPage />
                    </Permit>
                  }
                />
                <Route
                  path="/inventory/low-stock"
                  element={
                    <Permit permission="inventory.view">
                      <ReorderPage />
                    </Permit>
                  }
                />
                <Route
                  path="/inventory/documents/:kind"
                  element={
                    <Permit permission="inventory.view">
                      <StockDocumentsPage />
                    </Permit>
                  }
                />
                <Route
                  path="/inventory/documents/:kind/:id"
                  element={
                    <Permit permission="inventory.view">
                      <StockDocumentsPage />
                    </Permit>
                  }
                />
                <Route
                  path="/inventory/:view"
                  element={
                    <Permit permission="inventory.view">
                      <InventoryPage />
                    </Permit>
                  }
                />
                <Route
                  path="/admin/approvals"
                  element={
                    <Permit permission={["approvals.view", "approvals.decide"]}>
                      <ApprovalsPage />
                    </Permit>
                  }
                />
                <Route
                  path="/admin/inventory-policy"
                  element={
                    <Permit permission="settings.manage">
                      <InventoryPolicyPage />
                    </Permit>
                  }
                />
                <Route
                  path="/catalog/products"
                  element={
                    <Permit permission={["catalog.view", "catalog.manage"]}>
                      <ProductsPage />
                    </Permit>
                  }
                />
                <Route
                  path="/catalog/masters/:kind"
                  element={
                    <Permit
                      permission={[
                        "catalog.view",
                        "catalog.manage",
                        "inventory.view",
                      ]}
                    >
                      <MasterPage
                        writePermission="catalog.manage"
                        section="المنتجات والمخزون / الإعداد"
                      />
                    </Permit>
                  }
                />
                <Route
                  path="/accounting/mappings"
                  element={
                    <Permit permission="accounting.view">
                      <MappingsPage />
                    </Permit>
                  }
                />
                <Route
                  path="/accounting/journals"
                  element={
                    <Permit permission="accounting.view">
                      <JournalsPage />
                    </Permit>
                  }
                />
                <Route
                  path="/accounting/periods"
                  element={
                    <Permit permission="accounting.view">
                      <PeriodsPage />
                    </Permit>
                  }
                />
                <Route
                  path="/admin/setup"
                  element={
                    <Permit permission="settings.manage">
                      <StoreSetupPage />
                    </Permit>
                  }
                />
                <Route
                  path="/accounting/masters/:kind"
                  element={
                    <Permit permission="accounting.view">
                      <MasterPage />
                    </Permit>
                  }
                />
                <Route
                  path="/admin/users"
                  element={
                    <Permit permission="users.manage">
                      <UsersPage />
                    </Permit>
                  }
                />
                <Route
                  path="/admin/roles"
                  element={
                    <Permit permission="roles.manage">
                      <RolesPage />
                    </Permit>
                  }
                />
                <Route
                  path="/admin/audit"
                  element={
                    <Permit permission="audit.view">
                      <AuditPage />
                    </Permit>
                  }
                />
                <Route
                  path="/admin/sequences"
                  element={
                    <Permit permission="settings.manage">
                      <SequencesPage />
                    </Permit>
                  }
                />
                <Route
                  path="/admin/health"
                  element={
                    <Permit permission="settings.manage">
                      <HealthPage />
                    </Permit>
                  }
                />
                <Route
                  path="*"
                  element={
                    <div className="state">
                      <h1>الصفحة غير موجودة</h1>
                      <Link to="/">العودة إلى مساحة العمل</Link>
                    </div>
                  }
                />
              </Route>
            </Route>
          </Routes>
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  );
}
export default App;
