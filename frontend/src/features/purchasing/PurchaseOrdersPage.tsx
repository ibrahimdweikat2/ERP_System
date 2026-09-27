import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { DeleteDraftButton } from "../../components/ui/DeleteAction";
import { useRef, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, type ApiEnvelope, type Page } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import {
  DataTable,
  Filters,
  Pagination,
} from "../../components/data-table/DataTable";
import { Badge, ErrorNotice, Loading } from "../../components/ui/Primitives";
import { ApprovalDialog } from "../../components/forms/ApprovalDialog";
import { PurchaseOrderEditor } from "./PurchaseOrderEditor";
import {
  PurchaseOrderPrint,
  OrderLines,
  OrderTotals,
} from "./PurchaseOrderPrint";
import { orderStatus, type PurchaseOrder } from "./orderTypes";
export function PurchaseOrdersPage() {
  const { id } = useParams();
  return id ? <OrderDetail key={id} id={Number(id)} /> : <OrderList />;
}
function OrderList() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [adding, setAdding] = useState(false);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["purchase-orders", search, status, page],
    queryFn: ({ signal }) =>
      api<Page<PurchaseOrder>>(
        `purchase-orders?search=${encodeURIComponent(search)}&page=${page}${status ? "&status=" + status : ""}`,
        { signal },
      ),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المشتريات</p>
          <h1>أوامر الشراء</h1>
          <p>تحديد الكميات والأسعار، ثم المراجعة والإصدار للمورد.</p>
        </div>
        <div className="heading-actions">
          {can("settings.manage") && (
            <Link className="button" to="/admin/purchase-order-policy">
              سياسة الموافقة
            </Link>
          )}
          {can("purchasing.create") && (
            <button className="button primary" onClick={() => setAdding(true)}>
              أمر شراء جديد
            </button>
          )}
        </div>
      </header>
      <section className="panel">
        <Filters
          value={search}
          onChange={(v) => {
            setSearch(v);
            setPage(1);
          }}
        >
          <SearchableSelect
            aria-label="حالة أمر الشراء"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">كل الحالات</option>
            {Object.entries(orderStatus).map(([v, label]) => (
              <option key={v} value={v}>
                {label}
              </option>
            ))}
          </SearchableSelect>
        </Filters>
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            columns={[
              {
                key: "number",
                label: "أمر الشراء",
                render: (d) => (
                  <Link className="text-link" to={`/purchasing/orders/${d.id}`}>
                    <bdi>{d.document_no ?? `مسودة #${d.id}`}</bdi>
                  </Link>
                ),
              },
              {
                key: "supplier",
                label: "المورد",
                render: (d) =>
                  d.supplier_snapshot.trade_name ||
                  d.supplier_snapshot.legal_name,
              },
              {
                key: "date",
                label: "التاريخ",
                render: (d) => <bdi>{d.document_date}</bdi>,
              },
              {
                key: "total",
                label: "الإجمالي",
                render: (d) => (
                  <bdi>
                    {d.total} {d.currency}
                  </bdi>
                ),
              },
              {
                key: "status",
                label: "الحالة",
                render: (d) => (
                  <Badge
                    tone={
                      d.status === "issued"
                        ? "good"
                        : d.status === "pending"
                          ? "warning"
                          : "neutral"
                    }
                  >
                    {orderStatus[d.status]}
                  </Badge>
                ),
              },
            ]}
          />
        )}
        <Pagination
          page={page}
          last={q.data?.meta?.last_page ?? 1}
          total={q.data?.meta?.total ?? 0}
          onPage={setPage}
        />
      </section>
      {adding && (
        <PurchaseOrderEditor
          onClose={() => setAdding(false)}
          onSaved={(d) => {
            setAdding(false);
            navigate(`/purchasing/orders/${d.id}`);
          }}
        />
      )}
    </>
  );
}
function OrderDetail({ id }: { id: number }) {
  const { can } = useAuth();
  const client = useQueryClient();
  const keys = useRef<Record<string, string>>({});
  const [editing, setEditing] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [decision, setDecision] = useState<"approved" | "rejected" | null>(
    null,
  );
  const q = useQuery({
    queryKey: ["purchase-order", id],
    queryFn: () => api<ApiEnvelope<PurchaseOrder>>(`purchase-orders/${id}`),
  });
  const refresh = async () => {
    await Promise.all([
      client.invalidateQueries({ queryKey: ["purchase-order", id] }),
      client.invalidateQueries({ queryKey: ["purchase-orders"] }),
      client.invalidateQueries({ queryKey: ["approvals"] }),
    ]);
  };
  const action = useMutation({
    mutationFn: (event: "submit" | "issue") => {
      const k = event + "." + q.data!.data.version;
      keys.current[k] ??= crypto.randomUUID();
      return api(`purchase-orders/${id}/${event}`, {
        method: "POST",
        body: { version: q.data!.data.version },
        key: keys.current[k],
      });
    },
    onSuccess: refresh,
  });
  if (q.isPending) return <Loading />;
  if (!q.data) return <ErrorNotice error={q.error} />;
  const d = q.data.data;
  return (
    <>
      <header className="page-heading">
        <div>
          <Link className="text-link" to="/purchasing/orders">
            أوامر الشراء
          </Link>
          <h1>
            <bdi>{d.document_no ?? `مسودة #${id}`}</bdi>
          </h1>
          <p>{d.supplier_snapshot.legal_name}</p>
        </div>
        <Badge tone={d.status === "issued" ? "good" : "neutral"}>
          {orderStatus[d.status]}
        </Badge>
      </header>
      <section className="panel stock-summary">
        <dl>
          <div>
            <dt>التاريخ</dt>
            <dd>
              <bdi>{d.document_date}</bdi>
            </dd>
          </div>
          <div>
            <dt>التوريد المتوقع</dt>
            <dd>
              <bdi>{d.expected_on || "—"}</bdi>
            </dd>
          </div>
          <div>
            <dt>مرجع المورد</dt>
            <dd>{d.supplier_reference || "—"}</dd>
          </div>
          <div>
            <dt>النسخة</dt>
            <dd>{d.version}</dd>
          </div>
        </dl>
        <OrderLines order={d} />
        <OrderTotals order={d} />
        {d.notes && <p className="print-footer">{d.notes}</p>}
        {d.approval?.decision_reason && (
          <p className="notice">{d.approval.decision_reason}</p>
        )}
        <ErrorNotice error={action.error || q.error} />
        <footer className="form-actions">
          <button className="button" onClick={() => setPrinting(true)}>
            معاينة الطباعة
          </button>
          {can("purchasing.create") && d.status !== "issued" && (
            <button className="button" onClick={() => setEditing(true)}>
              تعديل أمر الشراء
            </button>
          )}
          {can("purchasing.create") && d.status === "draft" && (
            <button
              className="button primary"
              disabled={action.isPending}
              onClick={() => action.mutate("submit")}
            >
              إرسال للموافقة
            </button>
          )}
          {can("purchasing.create") &&
            (d.status === "draft" || d.status === "rejected") && (
              <DeleteDraftButton
                endpoint={`purchase-orders/${d.id}`}
                name={`أمر الشراء #${d.id}`}
                backTo="/purchasing/orders"
              />
            )}
          {can("purchasing.approve") && d.status === "pending" && (
            <>
              <button
                className="button primary"
                onClick={() => setDecision("approved")}
              >
                اعتماد أمر الشراء
              </button>
              <button
                className="button"
                onClick={() => setDecision("rejected")}
              >
                رفض أمر الشراء
              </button>
            </>
          )}
          {can("purchasing.create") && d.status === "approved" && (
            <button
              className="button primary"
              disabled={action.isPending}
              onClick={() => action.mutate("issue")}
            >
              إصدار أمر الشراء
            </button>
          )}
        </footer>
      </section>
      {editing && (
        <PurchaseOrderEditor
          order={d}
          onClose={() => setEditing(false)}
          onSaved={async () => {
            await refresh();
            setEditing(false);
          }}
        />
      )}
      {printing && (
        <PurchaseOrderPrint order={d} onClose={() => setPrinting(false)} />
      )}
      {decision && (
        <ApprovalDialog
          title={
            decision === "approved" ? "اعتماد أمر الشراء" : "رفض أمر الشراء"
          }
          onClose={() => setDecision(null)}
          onConfirm={async (reason) => {
            await api(`purchase-orders/${id}/decide`, {
              method: "POST",
              key: crypto.randomUUID(),
              body: { version: d.version, decision, reason },
            });
            await refresh();
          }}
        >
          <p>{d.supplier_snapshot.legal_name}</p>
          <p>
            الإجمالي:{" "}
            <bdi>
              {d.total} {d.currency}
            </bdi>
          </p>
          <p>
            القيمة للموافقة:{" "}
            <bdi>
              {d.base_total} {d.base_currency}
            </bdi>
          </p>
        </ApprovalDialog>
      )}
    </>
  );
}
