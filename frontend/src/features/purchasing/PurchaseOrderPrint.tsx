import { useQuery } from "@tanstack/react-query";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { DataTable } from "../../components/data-table/DataTable";
import { ErrorNotice, Loading } from "../../components/ui/Primitives";
import { PrintDialog } from "../../components/print/PrintDialog";
import { PrintView } from "../../components/print/PrintView";
import { orderStatus, type PurchaseOrder } from "./orderTypes";
export function OrderLines({ order: d }: { order: PurchaseOrder }) {
  return (
    <DataTable
      rows={d.lines}
      columns={[
        {
          key: "product",
          label: "المنتج",
          render: (l) => (
            <>
              {l.product_snapshot.name_ar}
              <small className="cell-sub">
                <bdi>{l.product_snapshot.sku}</bdi> ·{" "}
                {l.product_snapshot.unit_name}
              </small>
            </>
          ),
        },
        { key: "qty", label: "الكمية", render: (l) => <bdi>{l.quantity}</bdi> },
        {
          key: "price",
          label: "سعر الوحدة",
          render: (l) => (
            <>
              <bdi>{l.unit_price}</bdi>
              <small className="cell-sub">
                {l.tax_inclusive ? "شامل الضريبة" : "قبل الضريبة"}
              </small>
            </>
          ),
        },
        {
          key: "discount",
          label: "الخصم",
          render: (l) => <bdi>{l.discount_amount}</bdi>,
        },
        {
          key: "base",
          label: "الأساس الضريبي",
          render: (l) => <bdi>{l.taxable_base}</bdi>,
        },
        {
          key: "tax",
          label: "الضريبة",
          render: (l) => (
            <>
              <bdi>{l.tax_amount}</bdi>
              <small className="cell-sub">
                {l.tax_snapshot?.name_ar ?? "غير مطبقة"} ·{" "}
                <bdi>{l.tax_rate}%</bdi>
              </small>
            </>
          ),
        },
        {
          key: "total",
          label: "الإجمالي",
          render: (l) => <bdi>{l.total}</bdi>,
        },
      ]}
    />
  );
}
export function OrderTotals({ order: d }: { order: PurchaseOrder }) {
  return (
    <div className="order-totals">
      <p>
        المجموع قبل الضريبة{" "}
        <bdi>
          {d.subtotal} {d.currency}
        </bdi>
      </p>
      <p>
        الضريبة{" "}
        <bdi>
          {d.tax_total} {d.currency}
        </bdi>
      </p>
      <p className="print-total">
        الإجمالي{" "}
        <bdi>
          {d.total} {d.currency}
        </bdi>
      </p>
      {d.currency !== d.base_currency && (
        <>
          <p>
            سعر الصرف المحفوظ <bdi>{d.exchange_rate}</bdi> ·{" "}
            <bdi>{d.exchange_rate_date}</bdi>
          </p>
          <p>
            المعادل بالعملة الأساسية{" "}
            <bdi>
              {d.base_total} {d.base_currency}
            </bdi>
          </p>
        </>
      )}
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
export function PurchaseOrderPrint({
  order: d,
  onClose,
}: {
  order: PurchaseOrder;
  onClose: () => void;
}) {
  const q = useQuery({
    queryKey: ["store-context"],
    queryFn: () => api<ApiEnvelope<Store>>("store/context"),
  });
  const store = q.data?.data;
  return (
    <PrintDialog onClose={onClose}>
      <ErrorNotice error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : (
        store && (
          <PrintView
            title="أمر شراء"
            number={d.document_no ?? `مسودة #${d.id}`}
          >
            <div className="print-store">
              <strong>{store.trade_name}</strong>
              {store.legal_name && <p>{store.legal_name}</p>}
              <p>
                {store.address} · <bdi>{store.phone}</bdi>
              </p>
              {store.tax_number && (
                <p>
                  الرقم الضريبي: <bdi>{store.tax_number}</bdi>
                </p>
              )}
            </div>
            <dl className="print-meta">
              <div>
                <dt>التاريخ</dt>
                <dd>
                  <bdi>{d.document_date}</bdi>
                </dd>
              </div>
              <div>
                <dt>الحالة</dt>
                <dd>{orderStatus[d.status]}</dd>
              </div>
              <div>
                <dt>المورد</dt>
                <dd>{d.supplier_snapshot.legal_name}</dd>
              </div>
              <div>
                <dt>رقم المورد الضريبي</dt>
                <dd>
                  <bdi>{d.supplier_snapshot.tax_number || "—"}</bdi>
                </dd>
              </div>
              <div>
                <dt>العنوان</dt>
                <dd>{d.supplier_snapshot.address || "—"}</dd>
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
            </dl>
            {d.status !== "issued" && (
              <p className="notice">نسخة للمراجعة — أمر الشراء لم يصدر بعد.</p>
            )}
            <OrderLines order={d} />
            <OrderTotals order={d} />
            {d.notes && <p className="print-footer">{d.notes}</p>}
            <p>أمر شراء؛ لا يُعدّ فاتورة ضريبية أو إثبات استلام.</p>
            <div className="print-signatures">
              <span>المشتريات: __________________</span>
              <span>المورد: __________________</span>
            </div>
            {store.invoice_footer && (
              <p className="print-footer">{store.invoice_footer}</p>
            )}
          </PrintView>
        )
      )}
    </PrintDialog>
  );
}
