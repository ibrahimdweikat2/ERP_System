import { useState } from "react";
import { DeleteDraftButton } from "../../components/ui/DeleteAction";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useParams, useNavigate } from "react-router-dom";
import { api, type ApiEnvelope, type Page } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import {
  DataTable,
  Filters,
  Pagination,
} from "../../components/data-table/DataTable";
import { Badge, ErrorNotice, Loading } from "../../components/ui/Primitives";
import { GoodsReceiptEditor } from "./GoodsReceiptEditor";
import { ReceiptLines, GoodsReceiptPrint } from "./GoodsReceiptPrint";
import type { GoodsReceipt } from "./receiptTypes";
export function GoodsReceiptsPage() {
  const { id } = useParams();
  return id ? <ReceiptDetail key={id} id={Number(id)} /> : <ReceiptList />;
}
function ReceiptList() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [adding, setAdding] = useState(false);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["goods-receipts", search, page],
    queryFn: ({ signal }) =>
      api<Page<GoodsReceipt>>(
        `goods-receipts?search=${encodeURIComponent(search)}&page=${page}`,
        { signal },
      ),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المشتريات / الاستلام</p>
          <h1>استلام البضائع</h1>
          <p>تسجيل التسليم الفعلي والأجهزة ومواقعها، ثم ترحيل المخزون.</p>
        </div>
        {can("purchasing.receive") && (
          <button className="button primary" onClick={() => setAdding(true)}>
            سند استلام جديد
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
        />
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            columns={[
              {
                key: "number",
                label: "سند الاستلام",
                render: (d) => (
                  <Link
                    className="text-link"
                    to={`/purchasing/receipts/${d.id}`}
                  >
                    <bdi>{d.document_no ?? `مسودة #${d.id}`}</bdi>
                  </Link>
                ),
              },
              {
                key: "supplier",
                label: "المورد",
                render: (d) => d.supplier_snapshot.legal_name,
              },
              {
                key: "date",
                label: "التاريخ",
                render: (d) => <bdi>{d.document_date}</bdi>,
              },
              {
                key: "reference",
                label: "مرجع التسليم",
                render: (d) => d.delivery_reference,
              },
              {
                key: "total",
                label: "قيمة المخزون",
                render: (d) => (
                  <bdi>
                    {d.base_total} {d.base_currency}
                  </bdi>
                ),
              },
              {
                key: "status",
                label: "الحالة",
                render: (d) => (
                  <Badge tone={d.status === "posted" ? "good" : "neutral"}>
                    {d.status === "posted" ? "مرحّل" : "مسودة"}
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
        <GoodsReceiptEditor
          onClose={() => setAdding(false)}
          onSaved={(d) => {
            setAdding(false);
            navigate(`/purchasing/receipts/${d.id}`);
          }}
        />
      )}
    </>
  );
}
function ReceiptDetail({ id }: { id: number }) {
  const { can } = useAuth();
  const client = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [key] = useState(() => crypto.randomUUID());
  const q = useQuery({
    queryKey: ["goods-receipt", id],
    queryFn: () => api<ApiEnvelope<GoodsReceipt>>(`goods-receipts/${id}`),
  });
  const refresh = async () => {
    await Promise.all([
      client.invalidateQueries({ queryKey: ["goods-receipt", id] }),
      client.invalidateQueries({ queryKey: ["goods-receipts"] }),
      client.invalidateQueries({ queryKey: ["purchase-order"] }),
      client.invalidateQueries({ queryKey: ["receiving-order"] }),
      client.invalidateQueries({ queryKey: ["inventory"] }),
      client.invalidateQueries({ queryKey: ["journal-entries"] }),
    ]);
  };
  const post = useMutation({
    mutationFn: () =>
      api(`goods-receipts/${id}/post`, {
        method: "POST",
        key: key + "." + q.data!.data.version,
        body: { version: q.data!.data.version },
      }),
    onSuccess: refresh,
  });
  if (q.isPending) return <Loading />;
  if (!q.data) return <ErrorNotice error={q.error} />;
  const d = q.data.data;
  const write =
    can("purchasing.receive") &&
    (d.purchase_order_id || can("purchasing.receive_without_po"));
  return (
    <>
      <header className="page-heading">
        <div>
          <Link className="text-link" to="/purchasing/receipts">
            استلام البضائع
          </Link>
          <h1>
            <bdi>{d.document_no ?? `مسودة #${id}`}</bdi>
          </h1>
          <p>{d.supplier_snapshot.legal_name}</p>
        </div>
        <Badge tone={d.status === "posted" ? "good" : "neutral"}>
          {d.status === "posted" ? "مرحّل" : "مسودة"}
        </Badge>
      </header>
      <section className="panel stock-summary">
        <dl>
          <div>
            <dt>تاريخ الاستلام</dt>
            <dd>
              <bdi>{d.document_date}</bdi>
            </dd>
          </div>
          <div>
            <dt>مرجع التسليم</dt>
            <dd>{d.delivery_reference}</dd>
          </div>
          <div>
            <dt>أمر الشراء</dt>
            <dd>
              {d.purchase_order ? (
                <Link
                  className="text-link"
                  to={`/purchasing/orders/${d.purchase_order_id}`}
                >
                  <bdi>{d.purchase_order.document_no}</bdi>
                </Link>
              ) : (
                "استلام مباشر"
              )}
            </dd>
          </div>
          <div>
            <dt>النسخة</dt>
            <dd>{d.version}</dd>
          </div>
        </dl>
        <ReceiptLines receipt={d} />
        <div className="order-totals">
          <p>
            قيمة البضاعة قبل الضريبة{" "}
            <bdi>
              {d.foreign_total} {d.currency}
            </bdi>
          </p>
          <p>
            سعر الصرف المحفوظ <bdi>{d.exchange_rate}</bdi>
          </p>
          <p className="print-total">
            قيمة المخزون{" "}
            <bdi>
              {d.base_total} {d.base_currency}
            </bdi>
          </p>
        </div>
        {d.status === "draft" && d.purchase_order_id && (
          <p className="notice">
            تتأكد الكميات عند الترحيل. يُوزع كسر التقريب على الاستلامات الجزئية
            لاستيفاء قيمة أمر الشراء تماماً.
          </p>
        )}
        {d.notes && <p>{d.notes}</p>}
        <ErrorNotice error={q.error || post.error} />
        <footer className="form-actions">
          <button className="button" onClick={() => setPrinting(true)}>
            معاينة الطباعة
          </button>
          {write && d.status === "draft" && (
            <>
              <button className="button" onClick={() => setEditing(true)}>
                تعديل الاستلام
              </button>
              <DeleteDraftButton
                endpoint={`goods-receipts/${d.id}`}
                name={`مسودة الاستلام #${d.id}`}
                backTo="/purchasing/receipts"
              />
              <button
                className="button primary"
                disabled={post.isPending}
                onClick={() => post.mutate()}
              >
                ترحيل الاستلام
              </button>
            </>
          )}
        </footer>
      </section>
      {editing && (
        <GoodsReceiptEditor
          receipt={d}
          onClose={() => setEditing(false)}
          onSaved={async () => {
            await refresh();
            setEditing(false);
          }}
        />
      )}
      {printing && (
        <GoodsReceiptPrint receipt={d} onClose={() => setPrinting(false)} />
      )}
    </>
  );
}
