import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { api, type Page } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import { DataTable, Pagination } from "../../components/data-table/DataTable";
import {
  Badge,
  ErrorNotice,
  Field,
  Loading,
} from "../../components/ui/Primitives";
import { MoneyInput } from "../../components/money/MoneyInput";
import { type Approval, stockLabels, documentPath } from "./types";
export function ApprovalsPage() {
  const [status, setStatus] = useState("pending");
  const [page, setPage] = useState(1);
  const { can } = useAuth();
  const q = useQuery({
    queryKey: ["approvals", status, page],
    queryFn: () =>
      api<Page<Approval>>(`approvals?status=${status}&page=${page}`),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">الإدارة</p>
          <h1>طلبات الموافقة</h1>
          <p>كل موافقة تخص نسخة محددة من المستند وقيمة حركاتها.</p>
        </div>
        {can("settings.manage") && (
          <Link className="button" to="/admin/inventory-policy">
            سياسة الموافقة
          </Link>
        )}
      </header>
      <section className="panel">
        <div className="filters">
          <label className="field">
            <span>حالة الموافقة</span>
            <SearchableSelect
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              {["pending", "approved", "rejected", "superseded"].map((s) => (
                <option key={s} value={s}>
                  {stockLabels[s]}
                </option>
              ))}
            </SearchableSelect>
          </label>
        </div>
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            columns={[
              { key: "id", label: "رقم الطلب", render: (r) => r.id },
              {
                key: "version",
                label: "نسخة المستند",
                render: (r) => r.source_version,
              },
              {
                key: "status",
                label: "الحالة",
                render: (r) => (
                  <Badge tone={r.status === "pending" ? "warning" : "neutral"}>
                    {stockLabels[r.status]}
                  </Badge>
                ),
              },
              ...(can("inventory.view_cost") || can("purchasing.view")
                ? [
                    {
                      key: "amount",
                      label: "القيمة للموافقة",
                      render: (r: Approval) => (
                        <bdi>
                          {r.amount ?? "—"} {r.amount != null ? r.currency : ""}
                        </bdi>
                      ),
                    },
                  ]
                : []),
              {
                key: "reason",
                label: "القرار",
                render: (r) => r.decision_reason ?? "—",
              },
              {
                key: "document",
                label: "المستند",
                render: (r) => (
                  <Link
                    className="text-link"
                    to={
                      r.source_type === "purchase_order"
                        ? `/purchasing/orders/${r.source_id}`
                        : documentPath(r.source_type, r.source_id)
                    }
                  >
                    مراجعة المستند #{r.source_id}
                  </Link>
                ),
              },
            ]}
          />
        )}
        <Pagination
          page={page}
          last={q.data?.last_page ?? 1}
          total={q.data?.total ?? 0}
          onPage={setPage}
        />
      </section>
    </>
  );
}
type Policy = {
  threshold: string;
  segregate_requester: boolean | number;
  version: number;
};
export function InventoryPolicyPage() {
  const q = useQuery({
    queryKey: ["inventory-policy"],
    queryFn: () => api<{ data: Policy }>("inventory/approval-policy"),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">الإدارة / المخزون</p>
          <h1>سياسة اعتماد التسويات</h1>
          <p>
            الحد يطبق على مجموع قيم الزيادة والنقص، وتشمل السياسة الجرد.
            الافتتاحي يحتاج موافقة دائماً.
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
export function PurchaseOrderPolicyPage() {
  const q = useQuery({
    queryKey: ["purchase-order-policy"],
    queryFn: () => api<{ data: Policy }>("purchasing/order-policy"),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">الإدارة / المشتريات</p>
          <h1>سياسة اعتماد أوامر الشراء</h1>
          <p>الحد يطبق على إجمالي أمر الشراء شاملاً ضريبته بالعملة الأساسية.</p>
        </div>
      </header>
      <ErrorNotice error={q.error} />
      {q.data ? (
        <PolicyForm key={q.data.data.version} policy={q.data.data} purchasing />
      ) : (
        <Loading />
      )}
    </>
  );
}
function PolicyForm({
  policy,
  purchasing = false,
}: {
  policy: Policy;
  purchasing?: boolean;
}) {
  const client = useQueryClient();
  const [threshold, setThreshold] = useState(policy.threshold);
  const [segregate, setSegregate] = useState(!!policy.segregate_requester);
  const [reason, setReason] = useState("");
  const save = useMutation({
    mutationFn: () =>
      api(
        purchasing ? "purchasing/order-policy" : "inventory/approval-policy",
        {
          method: "PUT",
          body: { threshold, segregate_requester: segregate, reason },
        },
      ),
    onSuccess: () =>
      client.invalidateQueries({
        queryKey: [purchasing ? "purchase-order-policy" : "inventory-policy"],
      }),
  });
  return (
    <form
      className="panel settings-form"
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate();
      }}
    >
      <MoneyInput
        label="قيمة الحد بالعملة الأساسية"
        value={threshold}
        onChange={setThreshold}
      />
      <p>
        {purchasing
          ? "القيمة صفر تعني طلب موافقة لكل أمر شراء. عند بلوغ الحد أو تجاوزه تُطلب موافقة."
          : "القيمة صفر تعني طلب موافقة لكل تسوية، حتى لو لم تغيّر قيمة المخزون."}
      </p>
      <label className="checkbox-inline">
        <input
          type="checkbox"
          checked={segregate}
          onChange={(e) => setSegregate(e.target.checked)}
        />
        يجب أن يعتمد المستند شخص آخر غير منشئه ومعدّله ومقدم الطلب
      </label>
      <Field
        label="سبب تغيير السياسة"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        required
        minLength={5}
      />
      <p className="notice">
        تغيير السياسة يتطلب إعادة اعتماد المستندات غير المرحّلة وفق السياسة
        الجديدة.
      </p>
      <ErrorNotice error={save.error} />
      <footer className="form-actions">
        <button className="button primary" disabled={save.isPending}>
          حفظ سياسة الاعتماد
        </button>
      </footer>
    </form>
  );
}
