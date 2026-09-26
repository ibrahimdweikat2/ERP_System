import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { Field, ErrorNotice, Loading } from "../../components/ui/Primitives";
import type { InvoicePolicy } from "./invoiceTypes";
export function SupplierInvoicePolicyPage() {
  const q = useQuery({
    queryKey: ["invoice-policy"],
    queryFn: () => api<ApiEnvelope<InvoicePolicy>>("purchasing/invoice-policy"),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">الإدارة / المشتريات</p>
          <h1>سياسة فواتير الموردين</h1>
          <p>
            حدد المعالجة المحاسبية للفروق والضريبة غير القابلة للاسترداد
            ومتطلبات المرفقات.
          </p>
        </div>
      </header>
      <ErrorNotice error={q.error} />
      {q.data ? (
        <PolicyForm key={q.data.data.version} policy={q.data.data} />
      ) : (
        <Loading />
      )}
    </>
  );
}
function PolicyForm({ policy }: { policy: InvoicePolicy }) {
  const client = useQueryClient();
  const [d, setData] = useState(policy);
  const [reason, setReason] = useState("");
  const [saved, setSaved] = useState(false);
  const accounts = useQuery({
    queryKey: ["invoice-policy-accounts"],
    queryFn: () =>
      api<ApiEnvelope<{ id: number; code: string; name_ar: string }[]>>(
        "purchasing/invoice-policy-accounts",
      ),
  });
  const save = useMutation({
    mutationFn: () =>
      api("purchasing/invoice-policy", {
        method: "PUT",
        body: { ...d, reason },
      }),
    onSuccess: async () => {
      setSaved(true);
      await client.invalidateQueries({ queryKey: ["invoice-policy"] });
    },
  });
  const accountSelect = (
    field: "price_variance_account_id" | "nonrecoverable_tax_account_id",
    label: string,
  ) => (
    <label className="field">
      <span>{label}</span>
      <SearchableSelect
        required
        value={d[field] ?? ""}
        onChange={(e) =>
          setData({ ...d, [field]: Number(e.target.value) || null })
        }
      >
        <option value="">اختر حساب المصروف</option>
        {accounts.data?.data.map((a) => (
          <option key={a.id} value={a.id}>
            {a.code} — {a.name_ar}
          </option>
        ))}
      </SearchableSelect>
    </label>
  );
  return (
    <form
      className="panel settings-form"
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate();
      }}
    >
      <label className="field">
        <span>معالجة فرق سعر الشراء</span>
        <SearchableSelect
          value={d.price_variance_mode}
          onChange={(e) =>
            setData({
              ...d,
              price_variance_mode: e.target
                .value as InvoicePolicy["price_variance_mode"],
            })
          }
        >
          <option value="block">منع الترحيل عند وجود فرق</option>
          <option value="post_to_expense">
            ترحيل الفرق إلى حساب مصروف مع توثيق السبب
          </option>
        </SearchableSelect>
      </label>
      {d.price_variance_mode === "post_to_expense" &&
        accountSelect("price_variance_account_id", "حساب فرق سعر الشراء")}
      <label className="field">
        <span>معالجة الضريبة غير القابلة للاسترداد</span>
        <SearchableSelect
          value={d.nonrecoverable_tax_mode}
          onChange={(e) =>
            setData({
              ...d,
              nonrecoverable_tax_mode: e.target
                .value as InvoicePolicy["nonrecoverable_tax_mode"],
            })
          }
        >
          <option value="block">منع الترحيل حتى مراجعة المعالجة</option>
          <option value="expense">تحميل الضريبة على المصروف المعتمد</option>
        </SearchableSelect>
      </label>
      {d.nonrecoverable_tax_mode === "expense" &&
        accountSelect(
          "nonrecoverable_tax_account_id",
          "حساب الضريبة غير القابلة للاسترداد",
        )}
      <label className="checkbox-inline">
        <input
          type="checkbox"
          checked={d.require_attachment}
          onChange={(e) =>
            setData({ ...d, require_attachment: e.target.checked })
          }
        />
        إلزام إرفاق نسخة فاتورة المورد قبل الترحيل
      </label>
      <p className="notice">
        تظل قيمة المخزون المسجلة عند الاستلام ثابتة. تسجل فروق العملة في حساب
        فروق العملات المربوط. تغيير السياسة يستلزم مراجعة المسودات وحفظها
        مجدداً.
      </p>
      <Field
        label="سبب تغيير سياسة الفواتير"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        minLength={5}
        maxLength={1000}
        required
      />
      <ErrorNotice error={save.error || accounts.error} />
      {saved && <p role="status">تم حفظ السياسة</p>}
      <footer className="form-actions">
        <button className="button primary" disabled={save.isPending}>
          حفظ سياسة الفواتير
        </button>
      </footer>
    </form>
  );
}
