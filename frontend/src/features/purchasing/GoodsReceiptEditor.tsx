import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { api, allPages, type ApiEnvelope } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import {
  ErrorNotice,
  Field,
  Modal,
  Loading,
} from "../../components/ui/Primitives";
import { MoneyInput } from "../../components/money/MoneyInput";
import type { Location } from "../inventory/types";
import type { Supplier } from "./SuppliersPage";
import type { PurchaseOrder } from "./orderTypes";
import { conditionLabels, type GoodsReceipt } from "./receiptTypes";
type Line = {
  key: string;
  purchase_order_line_id: number;
  product_id: number;
  location_id: number;
  condition: string;
  condition_notes: string;
  quantity: string;
  unit_cost: string;
  serialText: string;
};
const blank = (): Line => ({
  key: crypto.randomUUID(),
  purchase_order_line_id: 0,
  product_id: 0,
  location_id: 0,
  condition: "new",
  condition_notes: "",
  quantity: "1",
  unit_cost: "",
  serialText: "",
});
const serials = (text: string) =>
  text
    .split(/\r?\n/)
    .map((s) => s.trim())
    .filter(Boolean);
export function GoodsReceiptEditor({
  receipt,
  onClose,
  onSaved,
}: {
  receipt?: GoodsReceipt;
  onClose: () => void;
  onSaved: (receipt: GoodsReceipt) => void | Promise<void>;
}) {
  const { can } = useAuth();
  const [key] = useState(() => crypto.randomUUID());
  const [direct, setDirect] = useState(!!receipt && !receipt.purchase_order_id);
  const [poId, setPoId] = useState(receipt?.purchase_order_id ?? 0);
  const [supplier, setSupplier] = useState(receipt?.supplier_id ?? 0);
  const [currency, setCurrency] = useState(receipt?.currency ?? "");
  const [date, setDate] = useState(
    receipt?.document_date ?? new Date().toLocaleDateString("en-CA"),
  );
  const [reference, setReference] = useState(receipt?.delivery_reference ?? "");
  const [notes, setNotes] = useState(receipt?.notes ?? "");
  const [lines, setLines] = useState<Line[]>(
    () =>
      receipt?.lines.map((l) => ({
        key: crypto.randomUUID(),
        purchase_order_line_id: l.purchase_order_line_id ?? 0,
        product_id: l.product_id,
        location_id: l.location_id,
        condition: l.condition,
        condition_notes: l.condition_notes ?? "",
        quantity: l.quantity,
        unit_cost: l.unit_cost.replace(/0+$/, "").replace(/[.]$/, ""),
        serialText: l.serials.join("\n"),
      })) ?? [blank()],
  );
  const orders = useQuery({
    queryKey: ["receiving-orders"],
    queryFn: ({ signal }) =>
      allPages<PurchaseOrder>("purchase-orders?status=issued", signal),
  });
  const order = useQuery({
    queryKey: ["receiving-order", poId],
    queryFn: () => api<ApiEnvelope<PurchaseOrder>>(`purchase-orders/${poId}`),
    enabled: !direct && !!poId,
  });
  const suppliers = useQuery({
    queryKey: ["supplier-options"],
    queryFn: ({ signal }) => allPages<Supplier>("suppliers?active=1", signal),
    enabled: direct,
  });
  const products = useQuery({
    queryKey: ["purchase-product-options"],
    queryFn: ({ signal }) =>
      allPages<{
        id: number;
        sku: string;
        name_ar: string;
        serial_tracked: boolean;
      }>("purchasing/products", signal),
  });
  const locations = useQuery({
    queryKey: ["receiving-locations"],
    queryFn: () =>
      api<ApiEnvelope<Location[]>>("purchasing/receiving-locations"),
  });
  const currencies = useQuery({
    queryKey: ["purchasing-currencies"],
    queryFn: () =>
      api<ApiEnvelope<{ code: string; name: string }[]>>(
        "purchasing/currencies",
      ),
    enabled: direct,
  });
  const warehouse =
    locations.data?.data.find((l) => l.purpose === "warehouse")?.id ?? 0;
  const po = order.data?.data;
  const selectedSupplier = direct ? supplier : (po?.supplier_id ?? 0);
  const selectedCurrency = direct ? currency : (po?.currency ?? "");
  const update = (i: number, data: Partial<Line>) =>
    setLines((ls) => ls.map((l, j) => (i === j ? { ...l, ...data } : l)));
  const save = useMutation({
    mutationFn: () =>
      api<ApiEnvelope<GoodsReceipt>>(
        `goods-receipts${receipt ? "/" + receipt.id : ""}`,
        {
          method: receipt ? "PUT" : "POST",
          ...(receipt ? {} : { key }),
          body: {
            supplier_id: selectedSupplier,
            currency: selectedCurrency,
            purchase_order_id: direct ? null : poId,
            document_date: date,
            delivery_reference: reference,
            notes,
            ...(receipt ? { version: receipt.version } : {}),
            lines: lines.map((l) => ({
              product_id: l.product_id,
              location_id: l.location_id || warehouse,
              condition: l.condition,
              condition_notes: l.condition_notes,
              quantity: l.quantity,
              serials: serials(l.serialText),
              ...(direct
                ? { unit_cost: l.unit_cost }
                : { purchase_order_line_id: l.purchase_order_line_id }),
            })),
          },
        },
      ),
    onSuccess: (r) => onSaved(r.data),
  });
  const waiting =
    products.isPending ||
    locations.isPending ||
    (!direct && poId > 0 && order.isPending);
  return (
    <Modal
      title={receipt ? "تعديل الاستلام" : "سند استلام جديد"}
      onClose={onClose}
    >
      <form
        className="padded-form"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        {can("purchasing.receive_without_po") && (
          <label className="checkbox-inline">
            <input
              type="checkbox"
              checked={direct}
              onChange={(e) => {
                setDirect(e.target.checked);
                setLines([blank()]);
              }}
            />
            استلام مباشر دون أمر شراء
          </label>
        )}
        <div className="form-grid">
          {!direct ? (
            <label className="field">
              <span>أمر الشراء المعتمد والصادر</span>
              <SearchableSelect
                required
                value={poId || ""}
                onChange={(e) => {
                  setPoId(Number(e.target.value));
                  setLines([blank()]);
                }}
              >
                <option value="">اختر أمر الشراء</option>
                {orders.data?.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.document_no} — {o.supplier_snapshot.legal_name}
                  </option>
                ))}
              </SearchableSelect>
            </label>
          ) : (
            <>
              <label className="field">
                <span>مورد الاستلام</span>
                <SearchableSelect
                  required
                  value={supplier || ""}
                  onChange={(e) => {
                    const id = Number(e.target.value);
                    setSupplier(id);
                    setCurrency(
                      suppliers.data?.find((s) => s.id === id)?.currency ?? "",
                    );
                  }}
                >
                  <option value="">اختر المورد</option>
                  {suppliers.data?.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.code} — {s.trade_name || s.legal_name}
                    </option>
                  ))}
                </SearchableSelect>
              </label>
              <label className="field">
                <span>عملة الاستلام</span>
                <SearchableSelect
                  required
                  value={currency}
                  onChange={(e) => setCurrency(e.target.value)}
                >
                  <option value="">اختر العملة</option>
                  {currencies.data?.data.map((c) => (
                    <option key={c.code} value={c.code}>
                      {c.code} — {c.name}
                    </option>
                  ))}
                </SearchableSelect>
              </label>
            </>
          )}
          <Field
            label="تاريخ استلام البضاعة"
            type="date"
            required
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
          <Field
            label="رقم سند تسليم المورد"
            required
            maxLength={120}
            value={reference}
            onChange={(e) => setReference(e.target.value)}
          />
        </div>
        {!direct && po && (
          <p className="notice">
            المورد: {po.supplier_snapshot.legal_name} · العملة:{" "}
            <bdi>{po.currency}</bdi> · تكلفة البضاعة من أمر الشراء بعد الخصم
            وقبل الضريبة.
          </p>
        )}
        {waiting ? (
          <Loading />
        ) : (
          lines.map((l, i) => {
            const product = products.data?.find((p) => p.id === l.product_id);
            return (
              <fieldset className="stock-line" key={l.key}>
                <header>
                  <h3>استلام بند {i + 1}</h3>
                  {lines.length > 1 && (
                    <button
                      className="text-link"
                      type="button"
                      onClick={() =>
                        setLines((ls) => ls.filter((_, j) => j !== i))
                      }
                    >
                      حذف البند
                    </button>
                  )}
                </header>
                <div className="form-grid">
                  {!direct ? (
                    <label className="field">
                      <span>بند أمر الشراء {i + 1}</span>
                      <SearchableSelect
                        required
                        value={l.purchase_order_line_id || ""}
                        onChange={(e) => {
                          const id = Number(e.target.value);
                          const selected = po?.lines.find((p) => p.id === id);
                          update(i, {
                            purchase_order_line_id: id,
                            product_id: selected?.product_id ?? 0,
                            quantity: "1",
                            serialText: "",
                          });
                        }}
                      >
                        <option value="">اختر البند</option>
                        {po?.lines.map((p) => (
                          <option key={p.id} value={p.id}>
                            {p.product_snapshot.sku} —{" "}
                            {p.product_snapshot.name_ar} (متبقي{" "}
                            {p.remaining_quantity})
                          </option>
                        ))}
                      </SearchableSelect>
                    </label>
                  ) : (
                    <label className="field">
                      <span>منتج الاستلام {i + 1}</span>
                      <SearchableSelect
                        required
                        value={l.product_id || ""}
                        onChange={(e) =>
                          update(i, {
                            product_id: Number(e.target.value),
                            serialText: "",
                            quantity: "1",
                          })
                        }
                      >
                        <option value="">اختر المنتج</option>
                        {products.data?.map((p) => (
                          <option key={p.id} value={p.id}>
                            {p.sku} — {p.name_ar}
                          </option>
                        ))}
                      </SearchableSelect>
                    </label>
                  )}
                  <Field
                    label={`الكمية المستلمة ${i + 1}`}
                    value={l.quantity}
                    required
                    inputMode="decimal"
                    readOnly={product?.serial_tracked}
                    onChange={(e) => update(i, { quantity: e.target.value })}
                  />
                  <label className="field">
                    <span>حالة البضاعة {i + 1}</span>
                    <SearchableSelect
                      value={l.condition}
                      onChange={(e) => {
                        const condition = e.target.value;
                        const location =
                          condition === "new"
                            ? l.location_id || warehouse
                            : (locations.data?.data.find(
                                (v) =>
                                  !v.sellable &&
                                  ["returns", "damaged"].includes(v.purpose),
                              )?.id ?? 0);
                        update(i, { condition, location_id: location });
                      }}
                    >
                      {Object.entries(conditionLabels).map(([k, label]) => (
                        <option key={k} value={k}>
                          {label}
                        </option>
                      ))}
                    </SearchableSelect>
                  </label>
                  <label className="field">
                    <span>موقع الاستلام {i + 1}</span>
                    <SearchableSelect
                      required
                      value={l.location_id || warehouse || ""}
                      onChange={(e) =>
                        update(i, { location_id: Number(e.target.value) })
                      }
                    >
                      <option value="">اختر الموقع</option>
                      {locations.data?.data
                        .filter(
                          (v) =>
                            l.condition === "new" ||
                            (!v.sellable &&
                              ["returns", "damaged"].includes(v.purpose)),
                        )
                        .map((v) => (
                          <option key={v.id} value={v.id}>
                            {v.name_ar}
                          </option>
                        ))}
                    </SearchableSelect>
                  </label>
                  {direct && (
                    <MoneyInput
                      label={`تكلفة الوحدة قبل الضريبة ${i + 1}`}
                      value={l.unit_cost}
                      onChange={(v) => update(i, { unit_cost: v })}
                    />
                  )}
                  <Field
                    label={`وصف حالة البضاعة ${i + 1}`}
                    required={l.condition !== "new"}
                    value={l.condition_notes}
                    maxLength={500}
                    onChange={(e) =>
                      update(i, { condition_notes: e.target.value })
                    }
                  />
                </div>
                {product?.serial_tracked && (
                  <label className="field">
                    <span>أرقام الأجهزة المستلمة {i + 1} — رقم في كل سطر</span>
                    <textarea
                      dir="ltr"
                      rows={4}
                      required
                      value={l.serialText}
                      onChange={(e) =>
                        update(i, {
                          serialText: e.target.value,
                          quantity: String(serials(e.target.value).length),
                        })
                      }
                    />
                    <small>
                      عدد الأجهزة المسجلة: {serials(l.serialText).length}
                    </small>
                  </label>
                )}
              </fieldset>
            );
          })
        )}
        <button
          className="button"
          type="button"
          disabled={lines.length >= 100}
          onClick={() => setLines((ls) => [...ls, blank()])}
        >
          إضافة بند استلام
        </button>
        <label className="field">
          <span>ملاحظات الاستلام</span>
          <textarea
            value={notes}
            maxLength={3000}
            onChange={(e) => setNotes(e.target.value)}
          />
        </label>
        <ErrorNotice
          error={
            save.error ||
            orders.error ||
            order.error ||
            suppliers.error ||
            products.error ||
            locations.error ||
            currencies.error
          }
        />
        <footer className="form-actions">
          <button
            className="button primary"
            disabled={
              save.isPending ||
              waiting ||
              !selectedSupplier ||
              !selectedCurrency
            }
          >
            حفظ سند الاستلام
          </button>
        </footer>
      </form>
    </Modal>
  );
}
