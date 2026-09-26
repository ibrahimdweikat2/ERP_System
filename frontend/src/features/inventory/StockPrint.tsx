import { useQuery } from "@tanstack/react-query";
import Decimal from "decimal.js";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import { ErrorNotice, Loading } from "../../components/ui/Primitives";
import { PrintDialog } from "../../components/print/PrintDialog";
import { PrintView } from "../../components/print/PrintView";
import { stockLabels, type StockDocument, type StockKind } from "./types";
type Store = {
  trade_name: string;
  legal_name: string | null;
  address: string | null;
  phone: string | null;
  tax_number: string | null;
  vat_registered: boolean;
  invoice_footer: string | null;
  base_currency: string;
};
export function StockPrint({
  kind,
  document: d,
  onClose,
}: {
  kind: StockKind;
  document: StockDocument;
  onClose: () => void;
}) {
  const { can, user } = useAuth();
  const q = useQuery({
    queryKey: ["store-context"],
    queryFn: () => api<ApiEnvelope<Store>>("store/context"),
  });
  const cost = can("inventory.view_cost");
  const count = kind === "stock-counts";
  const title =
    kind === "stock-transfers"
      ? "سند تحويل مخزون"
      : count
        ? "محضر جرد المخزون"
        : "سند تسوية مخزون";
  const store = q.data?.data;
  return (
    <PrintDialog onClose={onClose}>
      <ErrorNotice error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : (
        store && (
          <PrintView title={title} number={d.document_no ?? `مسودة #${d.id}`}>
            <div className="print-store">
              <strong>{store.trade_name}</strong>
              {store.legal_name && <p>{store.legal_name}</p>}
              {store.address && <p>{store.address}</p>}
              {store.phone && (
                <p>
                  هاتف: <bdi>{store.phone}</bdi>
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
                <dt>الحالة</dt>
                <dd>{stockLabels[d.status]}</dd>
              </div>
              <div>
                <dt>الموقع</dt>
                <dd>{d.location.name_ar}</dd>
              </div>
              {d.destination && (
                <div>
                  <dt>الوجهة</dt>
                  <dd>{d.destination.name_ar}</dd>
                </div>
              )}
              {d.adjustment_kind && (
                <div>
                  <dt>النوع</dt>
                  <dd>{stockLabels[d.adjustment_kind]}</dd>
                </div>
              )}
              {cost && (
                <div>
                  <dt>عملة التقييم</dt>
                  <dd>
                    <bdi>{store.base_currency}</bdi>
                  </dd>
                </div>
              )}
            </dl>
            <p className="print-reason">{d.reason}</p>
            <table className="print-lines">
              <thead>
                <tr>
                  <th>المنتج / SKU</th>
                  {count ? (
                    <>
                      <th>المتوقعة</th>
                      <th>المعدودة</th>
                      <th>الفرق</th>
                    </>
                  ) : (
                    <th>الكمية</th>
                  )}
                  <th>الأرقام التسلسلية</th>
                  {cost && <th>التغير المرحّل في القيمة</th>}
                </tr>
              </thead>
              <tbody>
                {d.lines.map((l) => (
                  <tr key={l.id}>
                    <td>
                      {l.product.name_ar}
                      <br />
                      <bdi>{l.product.sku}</bdi>
                    </td>
                    {count ? (
                      <>
                        <td>
                          <bdi>{l.expected_quantity}</bdi>
                        </td>
                        <td>
                          <bdi>{l.counted_quantity ?? "لم تُسجل"}</bdi>
                        </td>
                        <td>
                          <bdi>
                            {l.counted_quantity == null
                              ? "—"
                              : new Decimal(l.counted_quantity)
                                  .minus(l.expected_quantity ?? "0")
                                  .toFixed(4)}
                          </bdi>
                        </td>
                      </>
                    ) : (
                      <td>
                        <bdi>{l.quantity}</bdi>
                      </td>
                    )}
                    <td>
                      <bdi>
                        {(l.serials ?? l.counted_serials ?? []).join("، ") ||
                          "—"}
                      </bdi>
                      {count &&
                        l.expected_serials?.some(
                          (s) => !l.counted_serials?.includes(s),
                        ) && (
                          <p>
                            مفقودة:{" "}
                            <bdi>
                              {l.expected_serials
                                .filter((s) => !l.counted_serials?.includes(s))
                                .join("، ")}
                            </bdi>
                          </p>
                        )}
                    </td>
                    {cost && (
                      <td>
                        <bdi>{l.posted_value ?? "—"}</bdi>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
            {cost && d.status === "posted" && (
              <p className="print-total">
                صافي التغير في قيمة المخزون:{" "}
                <bdi>
                  {kind === "stock-transfers"
                    ? "0.0000"
                    : d.lines
                        .reduce(
                          (total, l) => total.plus(l.posted_value ?? "0"),
                          new Decimal(0),
                        )
                        .toFixed(4)}{" "}
                  {store.base_currency}
                </bdi>
              </p>
            )}
            {kind === "stock-transfers" && (
              <p>تحويل داخلي؛ لا يغيّر إجمالي قيمة مخزون المتجر.</p>
            )}
            {d.status !== "posted" && (
              <p className="notice">نسخة للمراجعة — المستند غير مرحّل.</p>
            )}
            <div className="print-signatures">
              <span>أعد النسخة: {user?.name}</span>
              <span>التسليم: __________________</span>
              <span>الاستلام / المراجعة: __________________</span>
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
