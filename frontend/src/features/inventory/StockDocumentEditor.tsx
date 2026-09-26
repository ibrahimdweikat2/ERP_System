import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { api, allPages, type ApiEnvelope } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import { Field, ErrorNotice, Modal } from "../../components/ui/Primitives";
import { MoneyInput } from "../../components/money/MoneyInput";
import { SerialSelector } from "../../components/forms/SerialSelector";
import {
  type Location,
  type StockKind,
  type StockDocument,
  type StockProduct,
  type Serial,
  stockTitles,
  stockLabels,
} from "./types";
type LineForm = {
  key: string;
  product_id: number;
  quantity: string;
  unit_cost: string;
  serials: string[];
  counted_quantity: string;
  counted_serials: string[];
  expected_quantity?: string;
};
const blank = (): LineForm => ({
  key: crypto.randomUUID(),
  product_id: 0,
  quantity: "1",
  unit_cost: "",
  serials: [],
  counted_quantity: "",
  counted_serials: [],
});
const localDate = () => {
  const d = new Date();
  return [
    d.getFullYear(),
    String(d.getMonth() + 1).padStart(2, "0"),
    String(d.getDate()).padStart(2, "0"),
  ].join("-");
};
export function StockDocumentEditor({
  kind,
  document: doc,
  onClose,
  onSaved,
}: {
  kind: StockKind;
  document?: StockDocument;
  onClose: () => void;
  onSaved: (doc: StockDocument) => Promise<void>;
}) {
  const { can } = useAuth();
  const isCount = kind === "stock-counts";
  const startCount = isCount && !doc;
  const [date, setDate] = useState(doc?.document_date ?? localDate());
  const [reason, setReason] = useState(doc?.reason ?? "");
  const [location, setLocation] = useState(doc?.location_id ?? 0);
  const [destination, setDestination] = useState(doc?.destination_id ?? 0);
  const [adjustment, setAdjustment] = useState<
    NonNullable<StockDocument["adjustment_kind"]>
  >(
    doc?.adjustment_kind ??
      (can("inventory.receive") && can("inventory.view_cost")
        ? "opening"
        : "loss"),
  );
  const [lines, setLines] = useState<LineForm[]>(
    () =>
      doc?.lines.map((l) => ({
        key: crypto.randomUUID(),
        product_id: l.product_id,
        quantity: l.quantity ?? "1",
        unit_cost: l.unit_cost ?? "",
        serials: l.serials ?? [],
        expected_quantity: l.expected_quantity,
        counted_quantity: l.counted_quantity ?? "",
        counted_serials: l.counted_serials ?? [],
      })) ?? [blank()],
  );
  const [key] = useState(() => crypto.randomUUID());
  const loc = useQuery({
    queryKey: ["stock-location-options"],
    queryFn: ({ signal }) => allPages<Location>("stock-locations", signal),
  });
  const products = useQuery({
    queryKey: ["stock-product-options"],
    queryFn: ({ signal }) =>
      allPages<StockProduct>("products?active=1", signal),
  });
  const incoming =
    kind === "stock-adjustments" && ["opening", "gain"].includes(adjustment);
  const save = useMutation({
    mutationFn: () =>
      api<ApiEnvelope<StockDocument>>(doc ? `${kind}/${doc.id}` : kind, {
        method: doc ? "PUT" : "POST",
        key: doc ? undefined : key,
        body: {
          document_date: date,
          reason,
          location_id: location,
          version: doc?.version,
          destination_id: kind === "stock-transfers" ? destination : undefined,
          adjustment_kind:
            kind === "stock-adjustments" ? adjustment : undefined,
          lines: lines.map((l) => ({
            product_id: l.product_id,
            ...(isCount
              ? {
                  counted_quantity: startCount
                    ? null
                    : l.counted_quantity || null,
                  counted_serials: startCount
                    ? []
                    : l.counted_serials.map((s) => s.trim()).filter(Boolean),
                }
              : {
                  quantity: l.quantity,
                  serials: l.serials.map((s) => s.trim()).filter(Boolean),
                }),
            ...((incoming || isCount) && can("inventory.view_cost")
              ? { unit_cost: l.unit_cost || null }
              : {}),
          })),
        },
      }),
    onSuccess: (r) => onSaved(r.data),
  });
  return (
    <Modal
      title={
        startCount
          ? "بدء لقطة الجرد"
          : (doc ? "تعديل " : "إضافة ") + stockTitles[kind]
      }
      onClose={() => {
        if (!save.isPending) onClose();
      }}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <fieldset className="plain-fieldset" disabled={save.isPending}>
          <div className="form-grid">
            <Field
              label="تاريخ المستند"
              type="date"
              value={date}
              onChange={(e) => setDate(e.target.value)}
              required
              max={localDate()}
            />
            <label className="field">
              <span>
                {kind === "stock-transfers"
                  ? "الموقع المصدر"
                  : "الموقع الداخلي"}
              </span>
              <SearchableSelect
                required
                disabled={isCount && !!doc}
                value={location || ""}
                onChange={(e) => {
                  setLocation(Number(e.target.value));
                  setLines((ls) => ls.map((l) => ({ ...l, serials: [] })));
                }}
              >
                <option value="">اختر الموقع…</option>
                {loc.data
                  ?.filter((l) => l.active)
                  .map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.name_ar}
                    </option>
                  ))}
              </SearchableSelect>
            </label>
            {kind === "stock-transfers" && (
              <label className="field">
                <span>الموقع الوجهة</span>
                <SearchableSelect
                  required
                  value={destination || ""}
                  onChange={(e) => setDestination(Number(e.target.value))}
                >
                  <option value="">اختر الوجهة…</option>
                  {loc.data
                    ?.filter((l) => l.active && l.id !== location)
                    .map((l) => (
                      <option value={l.id} key={l.id}>
                        {l.name_ar}
                      </option>
                    ))}
                </SearchableSelect>
              </label>
            )}
            {kind === "stock-adjustments" && (
              <label className="field">
                <span>نوع التسوية</span>
                <SearchableSelect
                  value={adjustment}
                  onChange={(e) =>
                    setAdjustment(e.target.value as typeof adjustment)
                  }
                >
                  {(["opening", "gain", "loss", "write_off"] as const)
                    .filter((v) =>
                      v === "opening"
                        ? can("inventory.receive") && can("inventory.view_cost")
                        : can("inventory.adjust") &&
                          (v !== "gain" || can("inventory.view_cost")),
                    )
                    .map((v) => (
                      <option value={v} key={v}>
                        {stockLabels[v]}
                      </option>
                    ))}
                </SearchableSelect>
              </label>
            )}
          </div>
          <Field
            label="سبب المستند"
            required
            minLength={5}
            maxLength={1000}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          {startCount && (
            <p className="notice">
              احفظ المنتجات والموقع لالتقاط الرصيد المتوقع، ثم سجّل الكميات
              والأرقام التي تجدها فعلياً. تُرفض اللقطة إذا تحرك مخزون المنتجات
              قبل اعتماد الجرد.
            </p>
          )}
          {lines.map((line, i) => (
            <section className="stock-line" key={line.key}>
              <header>
                <h3>السطر {i + 1}</h3>
                {(!isCount || !doc) && lines.length > 1 && (
                  <button
                    type="button"
                    className="text-link"
                    onClick={() =>
                      setLines((ls) => ls.filter((l) => l.key !== line.key))
                    }
                  >
                    حذف السطر
                  </button>
                )}
              </header>
              <label className="field">
                <span>المنتج {i + 1}</span>
                <SearchableSelect
                  required
                  disabled={isCount && !!doc}
                  value={line.product_id || ""}
                  onChange={(e) =>
                    setLines((ls) =>
                      ls.map((l) =>
                        l.key === line.key
                          ? {
                              ...blank(),
                              key: l.key,
                              product_id: Number(e.target.value),
                            }
                          : l,
                      ),
                    )
                  }
                >
                  <option value="">اختر المنتج…</option>
                  {products.data?.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.sku} — {p.name_ar}
                    </option>
                  ))}
                </SearchableSelect>
              </label>
              {!startCount && (
                <StockLineFields
                  line={line}
                  index={i}
                  count={isCount}
                  incoming={incoming}
                  cost={can("inventory.view_cost")}
                  location={location}
                  product={products.data?.find((p) => p.id === line.product_id)}
                  onChange={(v) =>
                    setLines((ls) =>
                      ls.map((l) => (l.key === line.key ? { ...l, ...v } : l)),
                    )
                  }
                />
              )}
            </section>
          ))}
          {(!isCount || !doc) && (
            <button
              type="button"
              className="button"
              onClick={() => setLines((ls) => [...ls, blank()])}
            >
              إضافة سطر
            </button>
          )}
        </fieldset>
        <ErrorNotice error={save.error ?? loc.error ?? products.error} />
        <footer className="form-actions">
          <button className="button primary" disabled={save.isPending}>
            {save.isPending
              ? "جارٍ الحفظ…"
              : startCount
                ? "بدء الجرد وحفظ اللقطة"
                : "حفظ المسودة"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}
function StockLineFields({
  line,
  index,
  count,
  incoming,
  cost,
  location,
  product,
  onChange,
}: {
  line: LineForm;
  index: number;
  count: boolean;
  incoming: boolean;
  cost: boolean;
  location: number;
  product?: StockProduct;
  onChange: (v: Partial<LineForm>) => void;
}) {
  const useSelector = !!product?.serial_tracked && !count && !incoming;
  const serials = useQuery({
    queryKey: ["stock-serial-options", line.product_id, location],
    queryFn: ({ signal }) =>
      allPages<Serial>(
        `inventory/serials?product_id=${line.product_id}&location_id=${location}`,
        signal,
      ),
    enabled: useSelector && !!line.product_id && !!location,
  });
  const selected = (serials.data ?? [])
    .filter((s) => line.serials.includes(s.serial_no))
    .map((s) => s.id);
  // Opening stock often lacks known serials: fill the missing ones with internal numbers.
  const written = line.serials.map((s) => s.trim()).filter(Boolean);
  const missing = Math.max(
    0,
    Math.floor(Number(line.quantity) || 0) - written.length,
  );
  const generate = useMutation({
    mutationFn: () => {
      const params = new URLSearchParams({
        product_id: String(line.product_id),
        count: String(missing),
      });
      written.forEach((s) => params.append("exclude[]", s));
      return api<ApiEnvelope<string[]>>(`inventory/serials/suggest?${params}`);
    },
    onSuccess: (r) => {
      const all = [...written, ...r.data];
      onChange({ serials: all, quantity: String(all.length) });
    },
  });
  return (
    <>
      <div className="form-grid">
        {count && (
          <div className="field">
            <span>الكمية المتوقعة</span>
            <strong>
              <bdi>{line.expected_quantity}</bdi>
            </strong>
          </div>
        )}
        <MoneyInput
          label={count ? `الكمية المعدودة ${index + 1}` : `الكمية ${index + 1}`}
          value={count ? line.counted_quantity : line.quantity}
          onChange={(v) =>
            onChange(count ? { counted_quantity: v } : { quantity: v })
          }
        />
        {cost && (incoming || count) && (
          <MoneyInput
            label={
              count
                ? `تكلفة الزيادة الاختيارية ${index + 1}`
                : `تكلفة الوحدة ${index + 1}`
            }
            value={line.unit_cost}
            required={incoming}
            onChange={(v) => onChange({ unit_cost: v })}
          />
        )}
      </div>
      {product?.serial_tracked &&
        (useSelector ? (
          <>
            <ErrorNotice error={serials.error} />
            {serials.isLoading ? (
              <p>جارٍ تحميل الأجهزة في الموقع…</p>
            ) : (
              <SerialSelector
                options={serials.data ?? []}
                value={selected}
                allowedStatuses={[
                  "in_stock",
                  "damaged",
                  "returned_pending_inspection",
                  "warranty_service",
                  "reserved",
                ]}
                onChange={(ids) => {
                  const numbers = (serials.data ?? [])
                    .filter((s) => ids.includes(s.id))
                    .map((s) => s.serial_no);
                  onChange({
                    serials: numbers,
                    quantity: String(numbers.length),
                  });
                }}
              />
            )}
          </>
        ) : (
          <label className="field">
            <span>
              {count
                ? `الأرقام المعدودة ${index + 1}`
                : `الأرقام الجديدة ${index + 1}`}{" "}
              — رقم واحد في كل سطر
            </span>
            <textarea
              dir="ltr"
              rows={4}
              value={(count ? line.counted_serials : line.serials).join("\n")}
              onChange={(e) => {
                const values = e.target.value.split("\n");
                const filled = values.filter((s) => s.trim()).length;
                // Incoming stock keeps a larger typed quantity so the rest can be generated.
                const qty = String(
                  incoming
                    ? Math.max(filled, Math.floor(Number(line.quantity) || 0))
                    : filled,
                );
                onChange(
                  count
                    ? { counted_serials: values, counted_quantity: qty }
                    : { serials: values, quantity: qty },
                );
              }}
            />
            {incoming && (
              <span className="serial-generate">
                <button
                  type="button"
                  className="button"
                  disabled={
                    !line.product_id || missing === 0 || generate.isPending
                  }
                  onClick={() => generate.mutate()}
                >
                  {generate.isPending
                    ? "جارٍ التوليد…"
                    : missing > 0
                      ? `توليد ${missing} رقم للقطع الناقصة`
                      : "توليد أرقام للقطع الناقصة"}
                </button>
                <small>
                  اكتب الأرقام الحقيقية المتوفرة أولاً، ثم حدّد الكمية الكلية
                  واضغط التوليد لإكمال الباقي بأرقام داخلية (OPEN-…).
                </small>
                <ErrorNotice error={generate.error} />
              </span>
            )}
          </label>
        ))}
    </>
  );
}
