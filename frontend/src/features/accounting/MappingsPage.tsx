import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { api, allPages, type ApiEnvelope } from "../../lib/api/client";
import { DataTable } from "../../components/data-table/DataTable";
import { ApprovalDialog } from "../../components/forms/ApprovalDialog";
import { ErrorNotice, Loading } from "../../components/ui/Primitives";
import { useAuth } from "../../lib/auth/context";
import type { MasterRow } from "./masterDefinitions";
type Mapping = {
  key: string;
  account_id: number;
  code: string;
  name_ar: string;
  account_type: string;
};
const labels: Record<string, string> = {
  cash: "الصندوق الافتراضي",
  bank: "البنك الافتراضي",
  ar: "ذمم العملاء",
  ap: "ذمم الموردين",
  inventory: "المخزون",
  cogs: "تكلفة البضاعة المباعة",
  vat_input: "ضريبة المدخلات",
  vat_output: "ضريبة المخرجات",
  vat_payable: "الضريبة المستحقة",
  checks_receivable: "الشيكات الآجلة",
  checks_collection: "الشيكات تحت التحصيل",
  sales: "إيراد المبيعات",
  sales_returns: "مردودات المبيعات",
  installment_revenue: "إيراد فرق التقسيط",
  capital: "رأس المال",
  drawings: "مسحوبات المالك",
  opening: "تسوية الافتتاح",
  retained_earnings: "أرباح محتجزة",
  grni: "بضاعة مستلمة غير مفوترة",
  inventory_adjustment: "فروق المخزون",
  expense: "المصروف الافتراضي",
  bank_fees: "رسوم البنك",
  exchange_difference: "فروق العملات",
  cash_variance: "فروق الصندوق",
};
export function MappingsPage() {
  const q = useQuery({
    queryKey: ["account-mappings"],
    queryFn: () => api<ApiEnvelope<Mapping[]>>("account-mappings"),
  });
  const accounts = useQuery({
    queryKey: ["master-options", "accounts"],
    queryFn: () => allPages<MasterRow>("accounts"),
  });
  const [selected, setSelected] = useState<Mapping | null>(null);
  const [account, setAccount] = useState("");
  const client = useQueryClient();
  const { can } = useAuth();
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المحاسبة / الإعداد</p>
          <h1>ربط الحسابات</h1>
          <p>
            حسابات الترحيل لكل نوع عملية. راجع الروابط قبل تسجيل الأرصدة
            الافتتاحية.
          </p>
        </div>
      </header>
      <section className="panel">
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={(q.data?.data ?? []).map((m) => ({ ...m, id: m.key }))}
            columns={[
              {
                key: "key",
                label: "الاستخدام",
                render: (m) => labels[m.key] ?? m.key,
              },
              {
                key: "account",
                label: "حساب الأستاذ",
                render: (m) => m.code + " — " + m.name_ar,
              },
              {
                key: "edit",
                label: "الإجراءات",
                render: (m) =>
                  can("settings.manage") ? (
                    <button
                      className="text-link"
                      onClick={() => {
                        setSelected(m);
                        setAccount(String(m.account_id));
                      }}
                    >
                      تغيير الرابط
                    </button>
                  ) : null,
              },
            ]}
          />
        )}
      </section>
      {selected && (
        <ApprovalDialog
          title={"ربط حساب " + (labels[selected.key] ?? selected.key)}
          onClose={() => setSelected(null)}
          onConfirm={async (reason) => {
            await api("account-mappings/" + selected.key, {
              method: "PUT",
              body: { account_id: Number(account), reason },
            });
            await client.invalidateQueries({ queryKey: ["account-mappings"] });
          }}
        >
          <label className="field">
            <span>حساب الأستاذ</span>
            <SearchableSelect
              required
              value={account}
              onChange={(e) => setAccount(e.target.value)}
            >
              {accounts.data
                ?.filter(
                  (a) => a.active && a.account_type === selected.account_type,
                )
                .map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} — {String(a.name_ar)}
                  </option>
                ))}
            </SearchableSelect>
          </label>
          <p className="muted">
            لا يمكن تغيير ربط حساب له قيود مسجلة دون تسوية مالية مستقلة.
          </p>
        </ApprovalDialog>
      )}
    </>
  );
}
