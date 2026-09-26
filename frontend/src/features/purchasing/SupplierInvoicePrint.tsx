import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { DataTable } from "../../components/data-table/DataTable";
import { ErrorNotice, Loading } from "../../components/ui/Primitives";
import { PrintDialog } from "../../components/print/PrintDialog";
import { PrintView } from "../../components/print/PrintView";
import type { SupplierInvoice } from "./invoiceTypes";
export function InvoiceLines({
  invoice: d,
  print = false,
}: {
  invoice: SupplierInvoice;
  print?: boolean;
}) {
  return (
    <DataTable
      rows={d.lines}
      columns={[
        {
          key: "product",
          label: "المنتج / الاستلام",
          render: (l) => (
            <>
              {l.product_snapshot.name_ar}
              <small className="cell-sub">
                {print ? (
                  <bdi>{l.receipt_document_no}</bdi>
                ) : (
                  <Link
                    className="text-link"
                    to={`/purchasing/receipts/${l.goods_receipt_id}`}
                  >
                    <bdi>{l.receipt_document_no}</bdi>
                  </Link>
                )}
              </small>
            </>
          ),
        },
        {
          key: "quantity",
          label: "الكمية",
          render: (l) => <bdi>{l.quantity}</bdi>,
        },
        {
          key: "price",
          label: "السعر / الخصم",
          render: (l) => (
            <>
              <bdi>{l.unit_price}</bdi>
              <small className="cell-sub">
                خصم <bdi>{l.discount_amount}</bdi>
              </small>
            </>
          ),
        },
        {
          key: "net",
          label: "صافي البند",
          render: (l) => <bdi>{l.taxable_base}</bdi>,
        },
        {
          key: "tax",
          label: "الضريبة",
          render: (l) => (
            <>
              <bdi>{l.tax_amount}</bdi>
              <small className="cell-sub">
                {l.tax_snapshot.name_ar} · {l.tax_rate}% ·{" "}
                {l.tax_inclusive ? "شامل" : "غير شامل"}
              </small>
            </>
          ),
        },
        {
          key: "total",
          label: `الإجمالي ${d.currency}`,
          render: (l) => <bdi>{l.total}</bdi>,
        },
        {
          key: "variance",
          label: `فرق السعر / العملة ${d.base_currency}`,
          render: (l) => (
            <>
              <bdi>{l.price_variance}</bdi>
              <small className="cell-sub">
                <bdi>{l.fx_variance}</bdi>
              </small>
            </>
          ),
        },
      ]}
    />
  );
}
export function InvoiceTotals({ invoice: d }: { invoice: SupplierInvoice }) {
  return (
    <div className="order-totals">
      <p>
        الصافي قبل الضريبة{" "}
        <bdi>
          {d.net_total} {d.currency}
        </bdi>
      </p>
      <p>
        ضريبة الفاتورة{" "}
        <bdi>
          {d.tax_total} {d.currency}
        </bdi>
      </p>
      <p className="print-total">
        إجمالي المورد{" "}
        <bdi>
          {d.foreign_total} {d.currency}
        </bdi>
      </p>
      <p>
        قيمة الذمة بالعملة الأساسية{" "}
        <bdi>
          {d.base_total} {d.base_currency}
        </bdi>
      </p>
      <p>
        سعر الصرف{" "}
        <span>
          <bdi>{d.exchange_rate}</bdi> · <bdi>{d.exchange_rate_date}</bdi>
        </span>
      </p>
      <p>
        تسوية البضاعة المستلمة{" "}
        <bdi>
          {d.grni_total} {d.base_currency}
        </bdi>
      </p>
      <p>
        فرق سعر الشراء{" "}
        <bdi>
          {d.price_variance_total} {d.base_currency}
        </bdi>
      </p>
      <p>
        فرق العملة{" "}
        <bdi>
          {d.fx_variance_total} {d.base_currency}
        </bdi>
      </p>
    </div>
  );
}
type Store = {
  trade_name: string;
  legal_name: string | null;
  address: string | null;
  phone: string | null;
  tax_number: string | null;
  invoice_footer: string | null;
};
export function SupplierInvoicePrint({
  invoice: d,
  onClose,
}: {
  invoice: SupplierInvoice;
  onClose: () => void;
}) {
  const q = useQuery({
    queryKey: ["store-context"],
    queryFn: () => api<ApiEnvelope<Store>>("store/context"),
  });
  const s = q.data?.data;
  return (
    <PrintDialog onClose={onClose}>
      <ErrorNotice error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : (
        s && (
          <PrintView
            title="سجل فاتورة مورد"
            number={d.document_no ?? `مسودة #${d.id}`}
          >
            <div className="print-store">
              <strong>{s.trade_name}</strong>
              {s.legal_name && <p>{s.legal_name}</p>}
              {s.address && <p>{s.address}</p>}
              {s.phone && (
                <p>
                  <bdi>{s.phone}</bdi>
                </p>
              )}
              {s.tax_number && (
                <p>
                  الرقم الضريبي: <bdi>{s.tax_number}</bdi>
                </p>
              )}
            </div>
            <dl className="print-meta">
              {[
                ["المورد", d.supplier_snapshot.legal_name],
                ["رقم فاتورة المورد", d.supplier_invoice_no],
                ["تاريخ الفاتورة", d.invoice_date],
                ["تاريخ الترحيل", d.posting_date],
                ["تاريخ الاستحقاق", d.due_date],
                [
                  "الحالة",
                  d.status === "posted" ? "مرحّل" : "مسودة غير مرحّلة",
                ],
              ].map(([label, value]) => (
                <div key={label}>
                  <dt>{label}</dt>
                  <dd>
                    <bdi>{value}</bdi>
                  </dd>
                </div>
              ))}
            </dl>
            <InvoiceLines invoice={d} print />
            <InvoiceTotals invoice={d} />
            {d.variance_reason && <p>سبب فرق السعر: {d.variance_reason}</p>}
            {d.notes && <p>{d.notes}</p>}
            <p>سجل داخلي مرتبط بفاتورة المورد الأصلية ومرفقاتها.</p>
            <div className="print-signatures">
              <span>المراجع: __________________</span>
              <span>المحاسب: __________________</span>
            </div>
            {s.invoice_footer && (
              <p className="print-footer">{s.invoice_footer}</p>
            )}
          </PrintView>
        )
      )}
    </PrintDialog>
  );
}
