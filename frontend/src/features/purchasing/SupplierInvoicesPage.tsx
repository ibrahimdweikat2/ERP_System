import { useState } from "react";
import { DeleteDraftButton } from "../../components/ui/DeleteAction";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useParams, useNavigate } from "react-router-dom";
import Decimal from "decimal.js";
import { api, type ApiEnvelope, type Page } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import {
  DataTable,
  Filters,
  Pagination,
} from "../../components/data-table/DataTable";
import { Badge, ErrorNotice, Loading } from "../../components/ui/Primitives";
import { SupplierInvoiceEditor } from "./SupplierInvoiceEditor";
import {
  InvoiceLines,
  InvoiceTotals,
  SupplierInvoicePrint,
} from "./SupplierInvoicePrint";
import { InvoiceAttachments } from "./InvoiceAttachments";
import type { SupplierInvoice } from "./invoiceTypes";
export function SupplierInvoicesPage() {
  const { id } = useParams();
  return id ? <InvoiceDetail key={id} id={Number(id)} /> : <InvoiceList />;
}
function InvoiceList() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [adding, setAdding] = useState(false);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["supplier-invoices", search, page],
    queryFn: ({ signal }) =>
      api<Page<SupplierInvoice>>(
        `supplier-invoices?search=${encodeURIComponent(search)}&page=${page}`,
        { signal },
      ),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المشتريات / ذمم الموردين</p>
          <h1>فواتير الموردين</h1>
          <p>مطابقة البضائع المستلمة وتسجيل الضريبة والاستحقاق.</p>
        </div>
        {can("purchasing.invoice") && (
          <button className="button primary" onClick={() => setAdding(true)}>
            فاتورة مورد جديدة
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
                key: "document",
                label: "السجل / فاتورة المورد",
                render: (d) => (
                  <>
                    <Link
                      className="text-link"
                      to={`/purchasing/invoices/${d.id}`}
                    >
                      <bdi>{d.document_no ?? `مسودة #${d.id}`}</bdi>
                    </Link>
                    <small className="cell-sub">
                      <bdi>{d.supplier_invoice_no}</bdi>
                    </small>
                  </>
                ),
              },
              {
                key: "supplier",
                label: "المورد",
                render: (d) => d.supplier_snapshot.legal_name,
              },
              {
                key: "date",
                label: "تاريخ الفاتورة",
                render: (d) => <bdi>{d.invoice_date}</bdi>,
              },
              {
                key: "due",
                label: "الاستحقاق",
                render: (d) => <bdi>{d.due_date}</bdi>,
              },
              {
                key: "total",
                label: "إجمالي الفاتورة",
                render: (d) => (
                  <bdi>
                    {d.foreign_total} {d.currency}
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
        <SupplierInvoiceEditor
          onClose={() => setAdding(false)}
          onSaved={(d) => {
            setAdding(false);
            navigate(`/purchasing/invoices/${d.id}`);
          }}
        />
      )}
    </>
  );
}
function InvoiceDetail({ id }: { id: number }) {
  const { can } = useAuth();
  const client = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [key] = useState(() => crypto.randomUUID());
  const q = useQuery({
    queryKey: ["supplier-invoice", id],
    queryFn: () => api<ApiEnvelope<SupplierInvoice>>(`supplier-invoices/${id}`),
  });
  const refresh = async () => {
    await Promise.all(
      [
        ["supplier-invoice", id],
        ["supplier-invoices"],
        ["invoice-receipts"],
        ["journal-entries"],
        ["supplier-statement"],
      ].map((queryKey) => client.invalidateQueries({ queryKey })),
    );
  };
  const post = useMutation({
    mutationFn: () =>
      api(`supplier-invoices/${id}/post`, {
        method: "POST",
        key: key + "." + q.data!.data.version,
        body: { version: q.data!.data.version },
      }),
    onSuccess: refresh,
  });
  if (q.isPending) return <Loading />;
  if (!q.data) return <ErrorNotice error={q.error} />;
  const d = q.data.data;
  const hasVariance = d.lines.some(
    (l) => !new Decimal(l.taxable_base).equals(l.receipt_foreign_value),
  );
  return (
    <>
      <header className="page-heading">
        <div>
          <Link className="text-link" to="/purchasing/invoices">
            فواتير الموردين
          </Link>
          <h1>
            <bdi>{d.document_no ?? `مسودة #${id}`}</bdi>
          </h1>
          <p>
            {d.supplier_snapshot.legal_name} · فاتورة المورد{" "}
            <bdi>{d.supplier_invoice_no}</bdi>
          </p>
        </div>
        <Badge tone={d.status === "posted" ? "good" : "neutral"}>
          {d.status === "posted" ? "مرحّل" : "مسودة"}
        </Badge>
      </header>
      <section className="panel stock-summary">
        <dl>
          {[
            ["تاريخ الفاتورة", d.invoice_date],
            ["تاريخ الترحيل", d.posting_date],
            ["الاستحقاق", d.due_date],
            ["النسخة", String(d.version)],
          ].map(([label, value]) => (
            <div key={label}>
              <dt>{label}</dt>
              <dd>
                <bdi>{value}</bdi>
              </dd>
            </div>
          ))}
        </dl>
        {hasVariance && (
          <p className="notice">
            توجد فروق بين صافي الفاتورة وقيم الاستلام.{" "}
            {d.policy_snapshot.price_variance_mode === "block"
              ? "السياسة الحالية تمنع ترحيل فرق السعر."
              : "راجع سبب الفرق قبل الترحيل إلى حساب المصروف المعتمد."}
          </p>
        )}
        <InvoiceLines invoice={d} />
        <InvoiceTotals invoice={d} />
        {d.variance_reason && <p>سبب فرق السعر: {d.variance_reason}</p>}
        {d.notes && <p>{d.notes}</p>}
        <p>
          {d.lines.some((l) => l.tax_recoverable)
            ? "تتضمن الفاتورة ضريبة مصنفة قابلة للاسترداد."
            : "الضريبة غير مصنفة للاسترداد؛ تطبق سياسة المعالجة المعتمدة."}
        </p>
        {d.posted_journal_entry_id && can("accounting.view") && (
          <Link
            className="text-link"
            to={`/accounting/journals/${d.posted_journal_entry_id}`}
          >
            عرض القيد المحاسبي
          </Link>
        )}
        <ErrorNotice error={q.error || post.error} />
        <footer className="form-actions">
          <button className="button" onClick={() => setPrinting(true)}>
            معاينة الطباعة
          </button>
          {can("purchasing.invoice") && d.status === "draft" && (
            <>
              <button className="button" onClick={() => setEditing(true)}>
                تعديل فاتورة المورد
              </button>
              <DeleteDraftButton
                endpoint={`supplier-invoices/${d.id}`}
                name={`مسودة فاتورة المورد #${d.id}`}
                backTo="/purchasing/invoices"
              />
              <button
                className="button primary"
                disabled={post.isPending}
                onClick={() => post.mutate()}
              >
                ترحيل فاتورة المورد
              </button>
            </>
          )}
        </footer>
      </section>
      <InvoiceAttachments
        id={id}
        required={d.policy_snapshot.require_attachment}
      />
      {editing && (
        <SupplierInvoiceEditor
          invoice={d}
          onClose={() => setEditing(false)}
          onSaved={async () => {
            await refresh();
            setEditing(false);
          }}
        />
      )}
      {printing && (
        <SupplierInvoicePrint invoice={d} onClose={() => setPrinting(false)} />
      )}
    </>
  );
}
