import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { DeleteDraftButton } from "../../components/ui/DeleteAction";
import { useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate, useParams } from "react-router-dom";
import Decimal from "decimal.js";
import { api, type Page, type ApiEnvelope } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import {
  DataTable,
  Filters,
  Pagination,
} from "../../components/data-table/DataTable";
import { Badge, ErrorNotice, Loading } from "../../components/ui/Primitives";
import { ApprovalDialog } from "../../components/forms/ApprovalDialog";
import { StockDocumentEditor } from "./StockDocumentEditor";
import { StockPrint } from "./StockPrint";
import {
  stockTitles,
  stockLabels,
  type StockKind,
  type StockDocument,
} from "./types";
export function StockDocumentsPage() {
  const { kind: rawKind, id } = useParams();
  const kind = rawKind as StockKind;
  if (!stockTitles[kind]) return <p>نوع المستند غير موجود.</p>;
  return id ? (
    <StockDetail key={kind + id} kind={kind} id={Number(id)} />
  ) : (
    <StockList key={kind} kind={kind} />
  );
}
function StockList({ kind }: { kind: StockKind }) {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [adding, setAdding] = useState(false);
  const { can } = useAuth();
  const nav = useNavigate();
  const q = useQuery({
    queryKey: ["stock-documents", kind, search, status, page],
    queryFn: ({ signal }) =>
      api<Page<StockDocument>>(
        `${kind}?search=${encodeURIComponent(search)}&page=${page}${status ? "&status=" + status : ""}`,
        { signal },
      ),
  });
  const write =
    kind === "stock-transfers"
      ? can("inventory.transfer")
      : kind === "stock-counts"
        ? can("inventory.count")
        : can("inventory.adjust") ||
          (can("inventory.receive") && can("inventory.view_cost"));
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المخزون</p>
          <h1>{stockTitles[kind]}</h1>
          <p>
            {kind === "stock-transfers"
              ? "نقل الكمية والأجهزة بين المواقع مع حفظ قيمتها."
              : kind === "stock-counts"
                ? "لقطة رصيد، عدّ فعلي، ثم اعتماد الفروقات."
                : "تسجيل الافتتاحي وفروقات المخزون مع المراجعة والترحيل."}
          </p>
        </div>
        {write && (
          <button className="button primary" onClick={() => setAdding(true)}>
            {kind === "stock-counts" ? "بدء جرد جديد" : "مستند جديد"}
          </button>
        )}
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
            aria-label="حالة المستند"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">كل الحالات</option>
            {["draft", "pending", "approved", "rejected", "posted"].map((s) => (
              <option key={s} value={s}>
                {stockLabels[s]}
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
                label: "المستند",
                render: (r) => (
                  <Link
                    className="text-link"
                    to={`/inventory/documents/${kind}/${r.id}`}
                  >
                    <bdi>{r.document_no ?? `مسودة #${r.id}`}</bdi>
                  </Link>
                ),
              },
              {
                key: "date",
                label: "التاريخ",
                render: (r) => <bdi>{r.document_date}</bdi>,
              },
              {
                key: "location",
                label: "الموقع",
                render: (r) => r.location.name_ar,
              },
              { key: "reason", label: "البيان", render: (r) => r.reason },
              {
                key: "status",
                label: "الحالة",
                render: (r) => (
                  <Badge
                    tone={
                      r.status === "posted"
                        ? "good"
                        : r.status === "pending"
                          ? "warning"
                          : "neutral"
                    }
                  >
                    {stockLabels[r.status]}
                  </Badge>
                ),
              },
              ...(can("inventory.view_cost")
                ? [
                    {
                      key: "value",
                      label: "قيمة الحركات",
                      render: (r: StockDocument) => <bdi>{r.gross_value}</bdi>,
                    },
                  ]
                : []),
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
        <StockDocumentEditor
          kind={kind}
          onClose={() => setAdding(false)}
          onSaved={async (d) => {
            setAdding(false);
            nav(`/inventory/documents/${kind}/${d.id}`);
          }}
        />
      )}
    </>
  );
}
function StockDetail({ kind, id }: { kind: StockKind; id: number }) {
  const { can } = useAuth();
  const client = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [decision, setDecision] = useState<"approved" | "rejected" | null>(
    null,
  );
  const keys = useRef<Record<string, string>>({});
  const q = useQuery({
    queryKey: ["stock-document", kind, id],
    queryFn: () => api<ApiEnvelope<StockDocument>>(`${kind}/${id}`),
  });
  const refresh = async () => {
    await Promise.all([
      client.invalidateQueries({ queryKey: ["stock-document", kind, id] }),
      client.invalidateQueries({ queryKey: ["stock-documents"] }),
      client.invalidateQueries({ queryKey: ["inventory"] }),
      client.invalidateQueries({ queryKey: ["stock-serial-options"] }),
      client.invalidateQueries({ queryKey: ["approvals"] }),
      client.invalidateQueries({ queryKey: ["journal-entries"] }),
    ]);
  };
  const action = useMutation({
    mutationFn: (event: "submit" | "post") => {
      const keyName = event + "." + q.data!.data.version;
      keys.current[keyName] ??= crypto.randomUUID();
      return api(`${kind}/${id}/${event}`, {
        method: "POST",
        body: { version: q.data!.data.version },
        key: keys.current[keyName],
      });
    },
    onSuccess: refresh,
  });
  if (q.isPending) return <Loading />;
  if (!q.data) return <ErrorNotice error={q.error} />;
  const d = q.data.data;
  const count = kind === "stock-counts";
  const write =
    kind === "stock-transfers"
      ? can("inventory.transfer")
      : count
        ? can("inventory.count")
        : can(
            d.adjustment_kind === "opening"
              ? "inventory.receive"
              : "inventory.adjust",
          );
  return (
    <>
      <header className="page-heading">
        <div>
          <Link className="text-link" to={`/inventory/documents/${kind}`}>
            {stockTitles[kind]}
          </Link>
          <h1>
            <bdi>{d.document_no ?? `مسودة #${d.id}`}</bdi>
          </h1>
          <p>{d.reason}</p>
        </div>
        <Badge
          tone={
            d.status === "posted"
              ? "good"
              : d.status === "pending"
                ? "warning"
                : "neutral"
          }
        >
          {stockLabels[d.status]}
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
            <dt>الموقع</dt>
            <dd>{d.location.name_ar}</dd>
          </div>
          {d.destination_id && (
            <div>
              <dt>موقع الوجهة</dt>
              <dd>{d.destination?.name_ar ?? `#${d.destination_id}`}</dd>
            </div>
          )}
          {d.adjustment_kind && (
            <div>
              <dt>النوع</dt>
              <dd>{stockLabels[d.adjustment_kind]}</dd>
            </div>
          )}
          <div>
            <dt>النسخة</dt>
            <dd>{d.version}</dd>
          </div>
          {can("inventory.view_cost") && (
            <div>
              <dt>قيمة الحركات للموافقة</dt>
              <dd>
                <bdi>{d.gross_value}</bdi>
              </dd>
            </div>
          )}
          {d.snapshot_at && (
            <div>
              <dt>بدء لقطة الجرد</dt>
              <dd>
                <bdi>{new Date(d.snapshot_at).toLocaleString("ar-PS")}</bdi>
              </dd>
            </div>
          )}
        </dl>
        {d.approval && (
          <p className="notice">
            الموافقة #{d.approval.id}: {stockLabels[d.approval.status]}
            {d.approval.decision_reason && ` — ${d.approval.decision_reason}`}
          </p>
        )}
        <DataTable
          rows={d.lines}
          columns={[
            {
              key: "product",
              label: "المنتج",
              render: (r) => (
                <div>
                  {r.product.name_ar}
                  <small className="cell-sub">
                    <bdi>{r.product.sku}</bdi>
                  </small>
                </div>
              ),
            },
            ...(count
              ? [
                  {
                    key: "expected",
                    label: "المتوقعة",
                    render: (r: StockDocument["lines"][number]) => (
                      <bdi>{r.expected_quantity}</bdi>
                    ),
                  },
                  {
                    key: "counted",
                    label: "المعدودة",
                    render: (r: StockDocument["lines"][number]) => (
                      <bdi>{r.counted_quantity ?? "لم تُسجل"}</bdi>
                    ),
                  },
                  {
                    key: "variance",
                    label: "الفرق",
                    render: (r: StockDocument["lines"][number]) => (
                      <bdi>
                        {r.counted_quantity == null
                          ? "—"
                          : new Decimal(r.counted_quantity)
                              .minus(r.expected_quantity ?? "0")
                              .toFixed(4)}
                      </bdi>
                    ),
                  },
                ]
              : [
                  {
                    key: "quantity",
                    label: "الكمية",
                    render: (r: StockDocument["lines"][number]) => (
                      <bdi>{r.quantity}</bdi>
                    ),
                  },
                ]),
            {
              key: "serials",
              label: "الأرقام التسلسلية",
              render: (r) => (
                <bdi>
                  {(r.serials ?? r.counted_serials ?? []).join("، ") || "—"}
                </bdi>
              ),
            },
            ...(can("inventory.view_cost")
              ? [
                  {
                    key: "value",
                    label: "التغير المرحّل في القيمة",
                    render: (r: StockDocument["lines"][number]) => (
                      <bdi>{r.posted_value ?? "—"}</bdi>
                    ),
                  },
                ]
              : []),
          ]}
        />
        {count &&
          d.lines
            .filter((l) => l.product.serial_tracked)
            .map((l) => (
              <div className="stock-serial-diff" key={l.id}>
                <strong>{l.product.name_ar}</strong>
                <p>
                  مفقودة:{" "}
                  <bdi>
                    {l.expected_serials
                      ?.filter((s) => !l.counted_serials?.includes(s))
                      .join("، ") || "لا يوجد"}
                  </bdi>
                </p>
                <p>
                  إضافية:{" "}
                  <bdi>
                    {l.counted_serials
                      ?.filter((s) => !l.expected_serials?.includes(s))
                      .join("، ") || "لا يوجد"}
                  </bdi>
                </p>
              </div>
            ))}
        <ErrorNotice error={q.error ?? action.error} />
        <footer className="form-actions">
          <button className="button" onClick={() => setPrinting(true)}>
            معاينة الطباعة
          </button>
          {write && d.status !== "posted" && (
            <button
              className="button"
              disabled={action.isPending}
              onClick={() => setEditing(true)}
            >
              {count ? "تسجيل / تعديل العدّ" : "تعديل المسودة"}
            </button>
          )}
          {write && (d.status === "draft" || d.status === "rejected") && (
            <DeleteDraftButton
              endpoint={`${kind}/${d.id}`}
              name={`مسودة ${stockTitles[kind]} #${d.id}`}
              backTo={`/inventory/documents/${kind}`}
            />
          )}
          {write && d.status === "draft" && kind !== "stock-transfers" && (
            <button
              className="button primary"
              disabled={action.isPending}
              onClick={() => action.mutate("submit")}
            >
              إرسال للموافقة
            </button>
          )}
          {d.status === "pending" &&
            can("approvals.decide") &&
            can("inventory.view_cost") && (
              <>
                <button
                  className="button primary"
                  onClick={() => setDecision("approved")}
                >
                  اعتماد المستند
                </button>
                <button
                  className="button"
                  onClick={() => setDecision("rejected")}
                >
                  رفض المستند
                </button>
              </>
            )}
          {write &&
            (d.status === "approved" ||
              (kind === "stock-transfers" && d.status === "draft")) && (
              <button
                className="button primary"
                disabled={action.isPending}
                onClick={() => action.mutate("post")}
              >
                {action.isPending ? "جارٍ الترحيل…" : "ترحيل المستند"}
              </button>
            )}
          {d.posted_journal_entry_id && can("accounting.view") && (
            <Link className="button" to="/accounting/journals">
              القيد المحاسبي #{d.posted_journal_entry_id}
            </Link>
          )}
        </footer>
      </section>
      {printing && (
        <StockPrint
          kind={kind}
          document={d}
          onClose={() => setPrinting(false)}
        />
      )}
      {editing && (
        <StockDocumentEditor
          kind={kind}
          document={d}
          onClose={() => setEditing(false)}
          onSaved={async () => {
            await refresh();
            setEditing(false);
          }}
        />
      )}
      {decision && (
        <ApprovalDialog
          title={
            decision === "approved"
              ? "اعتماد مستند المخزون"
              : "رفض مستند المخزون"
          }
          onClose={() => setDecision(null)}
          onConfirm={async (reason) => {
            await api(`approvals/${d.approval_id}/decide`, {
              method: "POST",
              key: crypto.randomUUID(),
              body: { decision, reason },
            });
            await refresh();
          }}
        >
          <p>{d.reason}</p>
          <p>
            قيمة الحركات المطلوب مراجعتها: <bdi>{d.gross_value}</bdi>
          </p>
        </ApprovalDialog>
      )}
    </>
  );
}
