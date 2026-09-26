import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { Statement } from "../operations/CustomersPage";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { api, type ApiEnvelope, type Page } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import {
  DataTable,
  Filters,
  Pagination,
} from "../../components/data-table/DataTable";
import {
  Badge,
  ErrorNotice,
  Field,
  Loading,
  Modal,
} from "../../components/ui/Primitives";
import { MoneyInput } from "../../components/money/MoneyInput";

type Contact = {
  name: string;
  phone: string | null;
  email: string | null;
  title: string | null;
};
type Bank = {
  bank_name?: string | null;
  beneficiary?: string | null;
  iban?: string | null;
  account_number?: string | null;
  swift?: string | null;
};
export type Supplier = {
  id: number;
  code: string;
  legal_name: string;
  trade_name: string | null;
  tax_number: string | null;
  address: string | null;
  contacts: Contact[];
  currency: string;
  payment_terms_days: number;
  credit_limit: string;
  bank_info?: Bank | null;
  notes: string | null;
  active: boolean;
  version: number;
};
type Form = Omit<Supplier, "id" | "version">;
const empty: Form = {
  code: "",
  legal_name: "",
  trade_name: "",
  tax_number: "",
  address: "",
  contacts: [],
  currency: "",
  payment_terms_days: 0,
  credit_limit: "0",
  notes: "",
  active: true,
};

export function SuppliersPage() {
  const { can } = useAuth();
  const client = useQueryClient();
  const [search, setSearch] = useState("");
  const [active, setActive] = useState("");
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Supplier | null | undefined>();
  const [detail, setDetail] = useState<Supplier | null>(null);
  const [deleting, setDeleting] = useState<Supplier>();
  const q = useQuery({
    queryKey: ["suppliers", search, active, page],
    queryFn: ({ signal }) =>
      api<Page<Supplier>>(
        `suppliers?search=${encodeURIComponent(search)}&page=${page}${active !== "" ? "&active=" + active : ""}`,
        { signal },
      ),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المشتريات</p>
          <h1>الموردون</h1>
          <p>بيانات الموردين وجهات الاتصال وشروط التعامل.</p>
        </div>
        {can("purchasing.create") && (
          <button className="button primary" onClick={() => setEditing(null)}>
            <Plus size={18} />
            إضافة مورد
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
            aria-label="حالة المورد"
            value={active}
            onChange={(e) => {
              setActive(e.target.value);
              setPage(1);
            }}
          >
            <option value="">كل الحالات</option>
            <option value="1">نشط</option>
            <option value="0">غير نشط</option>
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
                key: "name",
                label: "المورد",
                render: (s) => (
                  <button className="text-link" onClick={() => setDetail(s)}>
                    {s.trade_name || s.legal_name}
                    <small className="cell-sub">
                      <bdi>{s.code}</bdi>
                    </small>
                  </button>
                ),
              },
              {
                key: "tax",
                label: "الرقم الضريبي",
                render: (s) => <bdi>{s.tax_number || "—"}</bdi>,
              },
              {
                key: "contact",
                label: "الاتصال",
                render: (s) => (
                  <>
                    {s.contacts[0]?.name || "—"}
                    <small className="cell-sub">
                      <bdi>{s.contacts[0]?.phone}</bdi>
                    </small>
                  </>
                ),
              },
              {
                key: "terms",
                label: "شروط الدفع",
                render: (s) =>
                  s.payment_terms_days === 0
                    ? "عند الاستحقاق المباشر"
                    : `${s.payment_terms_days} يوم`,
              },
              {
                key: "credit",
                label: "حد الائتمان لدى المورد",
                render: (s) => (
                  <bdi>
                    {s.credit_limit} {s.currency}
                  </bdi>
                ),
              },
              {
                key: "status",
                label: "الحالة",
                render: (s) => (
                  <Badge tone={s.active ? "good" : "neutral"}>
                    {s.active ? "نشط" : "غير نشط"}
                  </Badge>
                ),
              },
              ...(can("purchasing.create") || can("purchasing.delete_supplier")
                ? [
                    {
                      key: "actions",
                      label: "الإجراءات",
                      render: (s: Supplier) => (
                        <span className="row-actions">
                          {can("purchasing.create") && (
                            <button
                              type="button"
                              className="icon-button"
                              title="تعديل"
                              aria-label={`تعديل ${s.trade_name || s.legal_name}`}
                              onClick={() => setEditing(s)}
                            >
                              <Pencil size={16} />
                            </button>
                          )}
                          {can("purchasing.delete_supplier") && (
                            <button
                              type="button"
                              className="icon-button danger"
                              title="حذف"
                              aria-label={`حذف ${s.trade_name || s.legal_name}`}
                              onClick={() => setDeleting(s)}
                            >
                              <Trash2 size={16} />
                            </button>
                          )}
                        </span>
                      ),
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
      {editing !== undefined && (
        <SupplierEditor
          supplier={editing}
          onClose={() => setEditing(undefined)}
          onSaved={async () => {
            await client.invalidateQueries({ queryKey: ["suppliers"] });
            setEditing(undefined);
          }}
        />
      )}
      {deleting && (
        <DeleteSupplier
          supplier={deleting}
          onClose={() => setDeleting(undefined)}
        />
      )}
      {detail && (
        <Modal
          title={detail.trade_name || detail.legal_name}
          onClose={() => setDetail(null)}
        >
          <div className="padded-form">
            <p>
              <bdi>{detail.code}</bdi> · {detail.legal_name}
            </p>
            <p>{detail.address}</p>
            <p>
              الرقم الضريبي: <bdi>{detail.tax_number || "—"}</bdi>
            </p>
            <details>
              <summary>كشف حساب المورد</summary>
              <Statement
                kind="suppliers"
                id={detail.id}
                title={detail.legal_name}
                currency={detail.currency}
              />
            </details>
            <h3>جهات الاتصال</h3>
            {detail.contacts.length ? (
              detail.contacts.map((c, i) => (
                <p key={i}>
                  {c.name} {c.title && `(${c.title})`} · <bdi>{c.phone}</bdi> ·{" "}
                  <bdi>{c.email}</bdi>
                </p>
              ))
            ) : (
              <p className="muted">لم تسجل جهات اتصال.</p>
            )}
            <p>
              شروط الدفع: {detail.payment_terms_days} يوم · حد الائتمان:{" "}
              <bdi>
                {detail.credit_limit} {detail.currency}
              </bdi>
            </p>
            {can("purchasing.pay") && detail.bank_info && (
              <>
                <h3>بيانات الدفع البنكية</h3>
                <p>
                  {detail.bank_info.bank_name} · {detail.bank_info.beneficiary}
                </p>
                <p>
                  <bdi>
                    {detail.bank_info.iban || detail.bank_info.account_number}
                  </bdi>
                </p>
                <p>
                  <bdi>{detail.bank_info.swift}</bdi>
                </p>
              </>
            )}
            {detail.notes && <p className="print-footer">{detail.notes}</p>}
          </div>
        </Modal>
      )}
    </>
  );
}
// Only a supplier with no documents can be deleted; the server explains what blocks it otherwise.
function DeleteSupplier({
  supplier,
  onClose,
}: {
  supplier: Supplier;
  onClose: () => void;
}) {
  const client = useQueryClient();
  const remove = useMutation({
    mutationFn: () => api(`suppliers/${supplier.id}`, { method: "DELETE" }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["suppliers"] });
      onClose();
    },
  });
  return (
    <Modal title="حذف المورد" onClose={onClose}>
      <div className="padded-form">
        <p>
          سيُحذف المورد{" "}
          <strong>{supplier.trade_name || supplier.legal_name}</strong> (
          <bdi>{supplier.code}</bdi>) نهائياً. لا يمكن التراجع عن هذه العملية.
        </p>
        <p className="muted">
          المورد الذي له أوامر شراء أو استلام أو فواتير أو دفعات لا يُحذف؛ أوقفه
          بدلاً من ذلك بإلغاء «نشط» من التعديل.
        </p>
        <ErrorNotice error={remove.error} />
        <footer className="form-actions">
          <button
            type="button"
            className="button"
            disabled={remove.isPending}
            onClick={onClose}
          >
            إلغاء
          </button>
          <button
            type="button"
            className="button danger"
            disabled={remove.isPending}
            onClick={() => remove.mutate()}
          >
            {remove.isPending ? "جارٍ الحذف…" : "تأكيد الحذف"}
          </button>
        </footer>
      </div>
    </Modal>
  );
}
function SupplierEditor({
  supplier,
  onClose,
  onSaved,
}: {
  supplier: Supplier | null;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const { can } = useAuth();
  const [form, setForm] = useState<Form>(() =>
    supplier
      ? { ...supplier, contacts: [...supplier.contacts] }
      : { ...empty, contacts: [] },
  );
  const [key] = useState(() => crypto.randomUUID());
  const currencies = useQuery({
    queryKey: ["purchasing-currencies"],
    queryFn: () =>
      api<ApiEnvelope<{ code: string; name: string }[]>>(
        "purchasing/currencies",
      ),
  });
  const store = useQuery({
    queryKey: ["store-context"],
    queryFn: () => api<ApiEnvelope<{ base_currency: string }>>("store/context"),
  });
  const currency = form.currency || store.data?.data.base_currency || "";
  const set = <K extends keyof Form>(k: K, value: Form[K]) =>
    setForm((f) => ({ ...f, [k]: value }));
  const save = useMutation({
    mutationFn: () => {
      const { bank_info, ...rest } = form;
      // Use an explicit editable payload, excluding identifiers/derived state from list records.
      const body = {
        code: rest.code,
        legal_name: rest.legal_name,
        trade_name: rest.trade_name,
        tax_number: rest.tax_number,
        address: rest.address,
        contacts: rest.contacts,
        currency,
        payment_terms_days: rest.payment_terms_days,
        credit_limit: rest.credit_limit,
        notes: rest.notes,
        active: rest.active,
        ...(supplier ? { version: supplier.version } : {}),
        ...(can("purchasing.pay") && bank_info !== undefined
          ? { bank_info }
          : {}),
      };
      return api(`suppliers${supplier ? "/" + supplier.id : ""}`, {
        method: supplier ? "PUT" : "POST",
        body,
        ...(supplier ? {} : { key }),
      });
    },
    onSuccess: onSaved,
  });
  return (
    <Modal title={supplier ? "تعديل المورد" : "إضافة مورد"} onClose={onClose}>
      <form
        className="padded-form"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <div className="form-grid">
          <Field
            label="رمز المورد"
            required
            value={form.code}
            dir="ltr"
            maxLength={40}
            onChange={(e) => set("code", e.target.value)}
          />
          <Field
            label="الاسم القانوني"
            required
            value={form.legal_name}
            maxLength={180}
            onChange={(e) => set("legal_name", e.target.value)}
          />
          <Field
            label="الاسم التجاري"
            value={form.trade_name ?? ""}
            onChange={(e) => set("trade_name", e.target.value)}
          />
          <Field
            label="الرقم الضريبي"
            value={form.tax_number ?? ""}
            onChange={(e) => set("tax_number", e.target.value)}
          />
          <Field
            label="عنوان المورد"
            value={form.address ?? ""}
            maxLength={500}
            onChange={(e) => set("address", e.target.value)}
          />
          <label className="field">
            <span>عملة التعامل الافتراضية</span>
            <SearchableSelect
              required
              value={currency}
              onChange={(e) => set("currency", e.target.value)}
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
            label="أجل الدفع بالأيام"
            type="number"
            min={0}
            max={3650}
            step={1}
            required
            value={form.payment_terms_days}
            onChange={(e) => set("payment_terms_days", Number(e.target.value))}
          />
          <MoneyInput
            label="حد الائتمان لدى المورد"
            value={form.credit_limit}
            onChange={(v) => set("credit_limit", v)}
          />
        </div>
        <fieldset className="plain-fieldset">
          <legend>جهات الاتصال</legend>
          {form.contacts.map((c, i) => (
            <div className="stock-line" key={i}>
              <header>
                <h3>جهة اتصال {i + 1}</h3>
                <button
                  type="button"
                  className="icon-button"
                  aria-label={`حذف جهة الاتصال ${i + 1}`}
                  onClick={() =>
                    set(
                      "contacts",
                      form.contacts.filter((_, j) => j !== i),
                    )
                  }
                >
                  <Trash2 size={16} />
                </button>
              </header>
              <div className="form-grid">
                {(["name", "phone", "email", "title"] as const).map((k) => (
                  <Field
                    key={k}
                    label={`${{ name: "اسم جهة الاتصال", phone: "الهاتف", email: "البريد الإلكتروني", title: "الصفة" }[k]} ${i + 1}`}
                    required={k === "name"}
                    type={k === "email" ? "email" : "text"}
                    value={c[k] ?? ""}
                    onChange={(e) =>
                      set(
                        "contacts",
                        form.contacts.map((v, j) =>
                          j === i ? { ...v, [k]: e.target.value } : v,
                        ),
                      )
                    }
                  />
                ))}
              </div>
            </div>
          ))}
          <button
            type="button"
            className="button"
            disabled={form.contacts.length >= 20}
            onClick={() =>
              set("contacts", [
                ...form.contacts,
                { name: "", phone: "", email: "", title: "" },
              ])
            }
          >
            إضافة جهة اتصال
          </button>
        </fieldset>
        {can("purchasing.pay") && (
          <fieldset className="stock-line">
            <legend>بيانات الدفع البنكية</legend>
            <div className="form-grid">
              {(
                [
                  "bank_name",
                  "beneficiary",
                  "iban",
                  "account_number",
                  "swift",
                ] as const
              ).map((k) => (
                <Field
                  key={k}
                  label={
                    {
                      bank_name: "اسم البنك",
                      beneficiary: "اسم المستفيد",
                      iban: "IBAN",
                      account_number: "رقم الحساب",
                      swift: "SWIFT",
                    }[k]
                  }
                  value={form.bank_info?.[k] ?? ""}
                  onChange={(e) =>
                    set("bank_info", { ...form.bank_info, [k]: e.target.value })
                  }
                />
              ))}
            </div>
            <p className="muted small">
              تظهر هذه البيانات للمستخدمين المخوّلين بدفعات الموردين.
            </p>
          </fieldset>
        )}
        <label className="field">
          <span>ملاحظات المورد</span>
          <textarea
            maxLength={5000}
            value={form.notes ?? ""}
            onChange={(e) => set("notes", e.target.value)}
          />
        </label>
        <label className="checkbox-field">
          <input
            type="checkbox"
            checked={form.active}
            onChange={(e) => set("active", e.target.checked)}
          />
          المورد نشط
        </label>
        <ErrorNotice error={save.error || currencies.error || store.error} />
        <footer className="form-actions">
          <button
            className="button primary"
            disabled={save.isPending || currencies.isPending || !currency}
          >
            {save.isPending ? "جارٍ الحفظ…" : "حفظ المورد"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}
