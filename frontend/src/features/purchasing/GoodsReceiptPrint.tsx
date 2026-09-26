import { useQuery } from "@tanstack/react-query";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { DataTable } from "../../components/data-table/DataTable";
import { ErrorNotice, Loading } from "../../components/ui/Primitives";
import { PrintDialog } from "../../components/print/PrintDialog";
import { PrintView } from "../../components/print/PrintView";
import { conditionLabels, type GoodsReceipt } from "./receiptTypes";
export function ReceiptLines({ receipt: d }: { receipt: GoodsReceipt }) {
  return (
    <DataTable
      rows={d.lines}
      columns={[
        {
          key: "product",
          label: "المنتج / الموديل",
          render: (l) => (
            <>
              {l.product_snapshot.name_ar}
              <small className="cell-sub">
                <bdi>{l.product_snapshot.sku}</bdi> ·{" "}
                {l.product_snapshot.manufacturer_model}
              </small>
            </>
          ),
        },
        {
          key: "quantity",
          label: "الكمية",
          render: (l) => (
            <>
              <bdi>{l.quantity}</bdi>
              <small className="cell-sub">{l.product_snapshot.unit_name}</small>
            </>
          ),
        },
        {
          key: "location",
          label: "الموقع والحالة",
          render: (l) => (
            <>
              {l.location.name_ar}
              <small className="cell-sub">
                {conditionLabels[l.condition]} · {l.condition_notes}
              </small>
            </>
          ),
        },
        {
          key: "serials",
          label: "الأرقام التسلسلية",
          render: (l) => <bdi>{l.serials.join("، ") || "—"}</bdi>,
        },
        {
          key: "foreign",
          label: `القيمة ${d.currency}`,
          render: (l) => <bdi>{l.foreign_value}</bdi>,
        },
        {
          key: "base",
          label: `قيمة المخزون ${d.base_currency}`,
          render: (l) => <bdi>{l.base_value}</bdi>,
        },
      ]}
    />
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
export function GoodsReceiptPrint({
  receipt: d,
  onClose,
}: {
  receipt: GoodsReceipt;
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
            title="سند استلام بضاعة"
            number={d.document_no ?? `مسودة #${d.id}`}
          >
            <div className="print-store">
              <strong>{store.trade_name}</strong>
              {store.legal_name && <p>{store.legal_name}</p>}
              {store.address && <p>{store.address}</p>}
              {store.phone && (
                <p>
                  <bdi>{store.phone}</bdi>
                </p>
              )}
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
                <dt>مرجع التسليم</dt>
                <dd>{d.delivery_reference}</dd>
              </div>
              <div>
                <dt>أمر الشراء</dt>
                <dd>
                  <bdi>{d.purchase_order?.document_no || "استلام مباشر"}</bdi>
                </dd>
              </div>
              <div>
                <dt>الحالة</dt>
                <dd>{d.status === "posted" ? "مرحّل" : "مسودة غير مرحّلة"}</dd>
              </div>
            </dl>
            <ReceiptLines receipt={d} />
            <div className="order-totals">
              <p>
                القيمة قبل الضريبة{" "}
                <bdi>
                  {d.foreign_total} {d.currency}
                </bdi>
              </p>
              <p>
                سعر الصرف{" "}
                <span>
                  <bdi>{d.exchange_rate}</bdi> ·{" "}
                  <bdi>{d.exchange_rate_date}</bdi>
                </span>
              </p>
              <p className="print-total">
                قيمة المخزون{" "}
                <bdi>
                  {d.base_total} {d.base_currency}
                </bdi>
              </p>
            </div>
            <p>{d.notes}</p>
            <p>
              سند استلام بضاعة؛ تُطابق فاتورة المورد وتُسجل ضريبتها بصورة
              مستقلة.
            </p>
            <div className="print-signatures">
              <span>المستلم: __________________</span>
              <span>مندوب المورد: __________________</span>
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
