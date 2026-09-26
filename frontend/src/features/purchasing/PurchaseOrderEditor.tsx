import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { api, allPages, type ApiEnvelope } from "../../lib/api/client";
import { Field, ErrorNotice, Modal } from "../../components/ui/Primitives";
import { MoneyInput } from "../../components/money/MoneyInput";
import type { Supplier } from "./SuppliersPage";
import type { PurchaseOrder } from "./orderTypes";
type Line = {
  product_id: number;
  quantity: string;
  unit_price: string;
  discount_amount: string;
  tax_code_id: number | null;
  tax_inclusive: boolean;
};
const emptyLine = (): Line => ({
  product_id: 0,
  quantity: "1",
  unit_price: "0",
  discount_amount: "0",
  tax_code_id: null,
  tax_inclusive: false,
});
export function PurchaseOrderEditor({
  order,
  onClose,
  onSaved,
}: {
  order?: PurchaseOrder;
  onClose: () => void;
  onSaved: (order: PurchaseOrder) => void | Promise<void>;
}) {
  const [key] = useState(() => crypto.randomUUID());
  const [supplier, setSupplier] = useState(order?.supplier_id ?? 0);
  const [date, setDate] = useState(
    order?.document_date ?? new Date().toLocaleDateString("en-CA"),
  );
  const [expected, setExpected] = useState(order?.expected_on ?? "");
  const [currency, setCurrency] = useState(order?.currency ?? "");
  const [reference, setReference] = useState(order?.supplier_reference ?? "");
  const [notes, setNotes] = useState(order?.notes ?? "");
  const [lines, setLines] = useState<Line[]>(
    () =>
      order?.lines.map((l) => ({
        product_id: l.product_id,
        quantity: l.quantity,
        unit_price: l.unit_price,
        discount_amount: l.discount_amount,
        tax_code_id: l.tax_code_id,
        tax_inclusive: l.tax_inclusive,
      })) ?? [emptyLine()],
  );
  const suppliers = useQuery({
    queryKey: ["supplier-options"],
    queryFn: ({ signal }) => allPages<Supplier>("suppliers?active=1", signal),
  });
  const products = useQuery({
    queryKey: ["purchase-product-options"],
    queryFn: ({ signal }) =>
      allPages<{
        id: number;
        sku: string;
        name_ar: string;
        tax_code_id: number | null;
      }>("purchasing/products", signal),
  });
  const currencies = useQuery({
    queryKey: ["purchasing-currencies"],
    queryFn: () =>
      api<ApiEnvelope<{ code: string; name: string }[]>>(
        "purchasing/currencies",
      ),
  });
  const taxes = useQuery({
    queryKey: ["purchase-tax-options"],
    queryFn: () =>
      api<
        ApiEnvelope<
          {
            id: number;
            code: string;
            name_ar: string;
            rate: string;
            effective_from: string;
            effective_to: string | null;
          }[]
        >
      >("purchasing/taxes"),
  });
  const setLine = <K extends keyof Line>(i: number, k: K, v: Line[K]) =>
    setLines((ls) => ls.map((l, j) => (i === j ? { ...l, [k]: v } : l)));
  const save = useMutation({
    mutationFn: () =>
      api<ApiEnvelope<PurchaseOrder>>(
        `purchase-orders${order ? "/" + order.id : ""}`,
        {
          method: order ? "PUT" : "POST",
          ...(order ? {} : { key }),
          body: {
            supplier_id: supplier,
            document_date: date,
            expected_on: expected || null,
            currency,
            supplier_reference: reference,
            notes,
            lines,
            ...(order ? { version: order.version } : {}),
          },
        },
      ),
    onSuccess: (res) => onSaved(res.data),
  });
  return (
    <Modal
      title={order ? "تعديل أمر الشراء" : "أمر شراء جديد"}
      onClose={onClose}
    >
      <form
        className="padded-form"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <div className="form-grid">
          <label className="field">
            <span>المورد</span>
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
          <Field
            label="تاريخ أمر الشراء"
            type="date"
            required
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
          <Field
            label="تاريخ التوريد المتوقع"
            type="date"
            min={date}
            value={expected}
            onChange={(e) => setExpected(e.target.value)}
          />
          <label className="field">
            <span>عملة أمر الشراء</span>
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
          <Field
            label="مرجع المورد"
            value={reference}
            onChange={(e) => setReference(e.target.value)}
          />
        </div>
        {lines.map((l, i) => (
          <fieldset className="stock-line" key={i}>
            <header>
              <h3>بند {i + 1}</h3>
              {lines.length > 1 && (
                <button
                  type="button"
                  className="text-link"
                  onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}
                >
                  حذف البند
                </button>
              )}
            </header>
            <div className="form-grid">
              <label className="field">
                <span>منتج الشراء {i + 1}</span>
                <SearchableSelect
                  required
                  value={l.product_id || ""}
                  onChange={(e) =>
                    setLine(i, "product_id", Number(e.target.value))
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
              <MoneyInput
                label={`كمية الشراء ${i + 1}`}
                value={l.quantity}
                onChange={(v) => setLine(i, "quantity", v)}
              />
              <MoneyInput
                label={`سعر الوحدة ${i + 1}`}
                value={l.unit_price}
                onChange={(v) => setLine(i, "unit_price", v)}
              />
              <MoneyInput
                label={`خصم البند ${i + 1}`}
                value={l.discount_amount}
                onChange={(v) => setLine(i, "discount_amount", v)}
              />
              <label className="field">
                <span>ضريبة البند {i + 1}</span>
                <SearchableSelect
                  value={l.tax_code_id ?? ""}
                  onChange={(e) =>
                    setLine(
                      i,
                      "tax_code_id",
                      e.target.value ? Number(e.target.value) : null,
                    )
                  }
                >
                  <option value="">غير مطبقة على عرض السعر</option>
                  {taxes.data?.data
                    .filter(
                      (t) =>
                        t.effective_from <= date &&
                        (!t.effective_to ||
                          t.effective_to >= date ||
                          t.id === l.tax_code_id),
                    )
                    .map((t) => (
                      <option key={t.id} value={t.id}>
                        {t.code} — {t.name_ar} ({t.rate}%)
                      </option>
                    ))}
                </SearchableSelect>
              </label>
              <label className="checkbox-inline">
                <input
                  type="checkbox"
                  checked={l.tax_inclusive}
                  onChange={(e) =>
                    setLine(i, "tax_inclusive", e.target.checked)
                  }
                />
                السعر والخصم شاملان الضريبة
              </label>
            </div>
          </fieldset>
        ))}
        <button
          className="button"
          type="button"
          disabled={lines.length >= 100}
          onClick={() => setLines((ls) => [...ls, emptyLine()])}
        >
          إضافة بند
        </button>
        <label className="field">
          <span>ملاحظات أمر الشراء</span>
          <textarea
            value={notes}
            maxLength={3000}
            onChange={(e) => setNotes(e.target.value)}
          />
        </label>
        <p className="muted small">
          احفظ المسودة لمراجعة المجاميع وسعر الصرف قبل طلب الموافقة.
        </p>
        <ErrorNotice
          error={
            save.error ||
            suppliers.error ||
            products.error ||
            currencies.error ||
            taxes.error
          }
        />
        <footer className="form-actions">
          <button
            className="button primary"
            disabled={
              save.isPending ||
              suppliers.isPending ||
              products.isPending ||
              taxes.isPending ||
              currencies.isPending
            }
          >
            {save.isPending ? "جارٍ الحفظ…" : "حفظ أمر الشراء"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}
