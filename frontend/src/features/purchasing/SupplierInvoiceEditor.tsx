import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import Decimal from "decimal.js";
import { useQuery, useMutation } from "@tanstack/react-query";
import { api, allPages, type ApiEnvelope } from "../../lib/api/client";
import {
  Field,
  Modal,
  ErrorNotice,
  Loading,
} from "../../components/ui/Primitives";
import { MoneyInput } from "../../components/money/MoneyInput";
import type { Supplier } from "./SuppliersPage";
import type { SupplierInvoice, MatchingReceipt } from "./invoiceTypes";
type Line = {
  key: string;
  goods_receipt_line_id: number;
  quantity: string;
  unit_price: string;
  discount_amount: string;
  tax_code_id: number;
  tax_inclusive: boolean;
  tax_recoverable: boolean;
};
const blank = (): Line => ({
  key: crypto.randomUUID(),
  goods_receipt_line_id: 0,
  quantity: "1",
  unit_price: "",
  discount_amount: "0",
  tax_code_id: 0,
  tax_inclusive: false,
  tax_recoverable: false,
});
export function SupplierInvoiceEditor({
  invoice,
  onClose,
  onSaved,
}: {
  invoice?: SupplierInvoice;
  onClose: () => void;
  onSaved: (d: SupplierInvoice) => void | Promise<void>;
}) {
  const [key] = useState(() => crypto.randomUUID());
  const [supplier, setSupplier] = useState(invoice?.supplier_id ?? 0);
  const [currency, setCurrency] = useState(invoice?.currency ?? "");
  const [number, setNumber] = useState(invoice?.supplier_invoice_no ?? "");
  const today = new Date().toLocaleDateString("en-CA");
  const [date, setDate] = useState(invoice?.invoice_date ?? today);
  const [posting, setPosting] = useState(invoice?.posting_date ?? today);
  const [due, setDue] = useState(invoice?.due_date ?? today);
  const [reason, setReason] = useState(invoice?.variance_reason ?? "");
  const [notes, setNotes] = useState(invoice?.notes ?? "");
  const [lines, setLines] = useState<Line[]>(
    () =>
      invoice?.lines.map((l) => ({
        key: crypto.randomUUID(),
        goods_receipt_line_id: l.goods_receipt_line_id,
        quantity: l.quantity,
        unit_price: l.unit_price,
        discount_amount: l.discount_amount,
        tax_code_id: l.tax_code_id,
        tax_inclusive: l.tax_inclusive,
        tax_recoverable: l.tax_recoverable,
      })) ?? [blank()],
  );
  const suppliers = useQuery({
    queryKey: ["supplier-options"],
    queryFn: ({ signal }) => allPages<Supplier>("suppliers?active=1", signal),
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
  const receipts = useQuery({
    queryKey: ["invoice-receipts", supplier, currency],
    queryFn: ({ signal }) =>
      allPages<MatchingReceipt>(
        `purchasing/invoice-receipts?supplier_id=${supplier}&currency=${currency}`,
        signal,
      ),
    enabled: !!supplier && !!currency,
  });
  const options =
    receipts.data?.flatMap((r) =>
      r.lines.map((l) => ({ ...l, document_no: r.document_no })),
    ) ?? [];
  const update = (i: number, patch: Partial<Line>) =>
    setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));
  const save = useMutation({
    mutationFn: () =>
      api<ApiEnvelope<SupplierInvoice>>(
        `supplier-invoices${invoice ? "/" + invoice.id : ""}`,
        {
          method: invoice ? "PUT" : "POST",
          ...(invoice ? {} : { key }),
          body: {
            supplier_id: supplier,
            supplier_invoice_no: number,
            invoice_date: date,
            posting_date: posting,
            due_date: due,
            currency,
            variance_reason: reason,
            notes,
            ...(invoice ? { version: invoice.version } : {}),
            lines: lines.map(({ key: _, ...line }) => line),
          },
        },
      ),
    onSuccess: (r) => onSaved(r.data),
  });
  return (
    <Modal
      title={invoice ? "تعديل فاتورة المورد" : "فاتورة مورد جديدة"}
      onClose={onClose}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <div className="form-grid">
          <label className="field">
            <span>مورد الفاتورة</span>
            <SearchableSelect
              required
              value={supplier}
              onChange={(e) => {
                const id = Number(e.target.value);
                setSupplier(id);
                setCurrency(
                  suppliers.data?.find((s) => s.id === id)?.currency ?? "",
                );
                setLines([blank()]);
              }}
            >
              <option value="">اختر المورد</option>
              {suppliers.data?.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.code} — {s.legal_name}
                </option>
              ))}
            </SearchableSelect>
          </label>
          <Field
            label="رقم فاتورة المورد"
            value={number}
            onChange={(e) => setNumber(e.target.value)}
            maxLength={120}
            required
          />
          <Field
            label="تاريخ فاتورة المورد"
            type="date"
            value={date}
            onChange={(e) => setDate(e.target.value)}
            required
          />
          <Field
            label="تاريخ ترحيل الفاتورة"
            type="date"
            value={posting}
            onChange={(e) => setPosting(e.target.value)}
            required
          />
          <Field
            label="تاريخ استحقاق الفاتورة"
            type="date"
            value={due}
            onChange={(e) => setDue(e.target.value)}
            required
          />
          <label className="field">
            <span>عملة الفاتورة</span>
            <SearchableSelect
              required
              value={currency}
              onChange={(e) => {
                setCurrency(e.target.value);
                setLines([blank()]);
              }}
            >
              <option value="">اختر العملة</option>
              {currencies.data?.data.map((c) => (
                <option key={c.code} value={c.code}>
                  {c.code} — {c.name}
                </option>
              ))}
            </SearchableSelect>
          </label>
        </div>
        <p className="notice">
          اختر البنود المستلمة وأدخل قيم فاتورة المورد. الحفظ يعرض الضريبة وسعر
          الصرف وفروق المطابقة قبل الترحيل. اختر رمزاً ضريبياً صريحاً، بما فيه
          الصفر أو الإعفاء عند انطباقه.
        </p>
        {receipts.isFetching && <Loading />}
        {lines.map((l, i) => (
          <fieldset className="order-line" key={l.key}>
            <legend>بند الفاتورة {i + 1}</legend>
            <div className="form-grid">
              <label className="field">
                <span>بند الاستلام {i + 1}</span>
                <SearchableSelect
                  required
                  value={l.goods_receipt_line_id}
                  onChange={(e) => {
                    const source = options.find(
                      (s) => s.id === Number(e.target.value),
                    );
                    update(i, {
                      goods_receipt_line_id: Number(e.target.value),
                      quantity: source?.uninvoiced_quantity ?? "1",
                      unit_price: source
                        ? new Decimal(source.unit_cost).toFixed(4)
                        : "",
                    });
                  }}
                >
                  <option value="">اختر بند الاستلام</option>
                  {options.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.document_no} — {s.product_snapshot.name_ar} — المتبقي{" "}
                      {s.uninvoiced_quantity}
                    </option>
                  ))}
                </SearchableSelect>
              </label>
              <MoneyInput
                label={`كمية الفاتورة ${i + 1}`}
                value={l.quantity}
                onChange={(quantity) => update(i, { quantity })}
              />
              <MoneyInput
                label={`سعر فاتورة المورد ${i + 1}`}
                value={l.unit_price}
                onChange={(unit_price) => update(i, { unit_price })}
              />
              <MoneyInput
                label={`خصم بند الفاتورة ${i + 1}`}
                value={l.discount_amount}
                onChange={(discount_amount) => update(i, { discount_amount })}
              />
              <label className="field">
                <span>ضريبة بند الفاتورة {i + 1}</span>
                <SearchableSelect
                  required
                  value={l.tax_code_id}
                  onChange={(e) =>
                    update(i, { tax_code_id: Number(e.target.value) })
                  }
                >
                  <option value="">اختر المعالجة الضريبية</option>
                  {taxes.data?.data
                    .filter(
                      (t) =>
                        t.effective_from <= date &&
                        (!t.effective_to || t.effective_to >= date),
                    )
                    .map((t) => (
                      <option value={t.id} key={t.id}>
                        {t.name_ar} — {t.rate}%
                      </option>
                    ))}
                </SearchableSelect>
              </label>
            </div>
            <div className="form-actions">
              <label className="checkbox-inline">
                <input
                  type="checkbox"
                  checked={l.tax_inclusive}
                  onChange={(e) =>
                    update(i, { tax_inclusive: e.target.checked })
                  }
                />
                السعر شامل الضريبة {i + 1}
              </label>
              <label className="checkbox-inline">
                <input
                  type="checkbox"
                  checked={l.tax_recoverable}
                  onChange={(e) =>
                    update(i, { tax_recoverable: e.target.checked })
                  }
                />
                ضريبة مدخلات قابلة للاسترداد {i + 1}
              </label>
              <button
                className="button"
                type="button"
                disabled={lines.length === 1}
                onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}
              >
                حذف البند {i + 1}
              </button>
            </div>
          </fieldset>
        ))}
        <button
          type="button"
          className="button"
          onClick={() => setLines((ls) => [...ls, blank()])}
          disabled={lines.length >= 100}
        >
          إضافة بند فاتورة
        </button>
        <Field
          label="سبب قبول فرق سعر الشراء"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          maxLength={1000}
        />
        <label className="field">
          <span>ملاحظات الفاتورة</span>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            maxLength={3000}
          />
        </label>
        <ErrorNotice
          error={
            save.error ||
            suppliers.error ||
            currencies.error ||
            taxes.error ||
            receipts.error
          }
        />
        <footer className="form-actions">
          <button className="button" type="button" onClick={onClose}>
            إلغاء
          </button>
          <button
            className="button primary"
            disabled={save.isPending || receipts.isFetching}
          >
            حفظ فاتورة المورد
          </button>
        </footer>
      </form>
    </Modal>
  );
}
