import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Package, Plus, Trash2 } from "lucide-react";
import {
  api,
  allPages,
  type ApiEnvelope,
  type Page,
} from "../../lib/api/client";
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
import { formatMoney } from "../../lib/money/format";
import { MasterInput } from "../accounting/MasterPage";
type Product = {
  id: number;
  sku: string;
  name_ar: string;
  name_en: string | null;
  manufacturer_model: string | null;
  brand_id: number | null;
  category_id: number | null;
  unit_id: number;
  warranty_policy_id: number | null;
  tax_code_id: number | null;
  serial_tracked: boolean;
  active: boolean;
  standard_cost?: string;
  cash_price: string;
  installment_price: string;
  minimum_price: string;
  reorder_level: string;
  energy_rating: string | null;
  country_of_origin: string | null;
  specifications: { name: string; value: string }[] | null;
  barcodes: { barcode: string }[];
  brand: { name_ar: string } | null;
  category: { name_ar: string } | null;
  image_url: string | null;
};
type ProductForm = Omit<
  Product,
  "id" | "barcodes" | "brand" | "category" | "specifications" | "image_url"
> & { barcodes: string[]; specifications: { name: string; value: string }[] };
const empty: ProductForm = {
  sku: "",
  name_ar: "",
  name_en: "",
  manufacturer_model: "",
  brand_id: null,
  category_id: null,
  unit_id: 0,
  warranty_policy_id: null,
  tax_code_id: null,
  serial_tracked: true,
  active: true,
  standard_cost: "0",
  cash_price: "0",
  installment_price: "0",
  minimum_price: "0",
  reorder_level: "0",
  energy_rating: "",
  country_of_origin: "",
  specifications: [],
  barcodes: [],
};
export function ProductsPage() {
  const { can } = useAuth();
  const client = useQueryClient();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Product | null | undefined>();
  const [editorNotice, setEditorNotice] = useState<string>();
  const [active, setActive] = useState("");
  const q = useQuery({
    queryKey: ["products", search, page, active],
    queryFn: ({ signal }) =>
      api<Page<Product>>(
        `products?search=${encodeURIComponent(search)}&page=${page}${active !== "" ? "&active=" + active : ""}`,
        { signal },
      ),
  });
  const cost = can("inventory.view_cost") || can("sales.view_cost");
  const settings = useQuery({
    queryKey: ["inventory-settings"],
    queryFn: () =>
      api<{ data: { base_currency: string } }>("inventory/settings"),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المنتجات</p>
          <h1>كتالوج المنتجات</h1>
          <p>
            الهوية والمواصفات والأسعار والضمان لكل جهاز.{" "}
            {settings.data && `الأسعار بـ ${settings.data.data.base_currency}.`}
          </p>
        </div>
        {can("catalog.manage") && (
          <button className="button primary" onClick={() => setEditing(null)}>
            <Plus size={18} />
            إضافة منتج
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
            aria-label="حالة المنتج"
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
                key: "product",
                label: "المنتج",
                render: (r) => (
                  <div className="product-cell">
                    {r.image_url ? (
                      <img
                        src={r.image_url}
                        alt=""
                        className="product-thumb"
                        loading="lazy"
                      />
                    ) : (
                      <span className="product-thumb empty" aria-hidden="true">
                        <Package size={18} />
                      </span>
                    )}
                    <div>
                      <strong>{r.name_ar}</strong>
                      <small className="cell-sub">
                        <bdi>{r.sku}</bdi> · {r.manufacturer_model ?? "—"}
                      </small>
                    </div>
                  </div>
                ),
              },
              {
                key: "brand",
                label: "العلامة / الفئة",
                render: (r) => (
                  <div>
                    {r.brand?.name_ar ?? "—"}
                    <small className="cell-sub">
                      {r.category?.name_ar ?? "—"}
                    </small>
                  </div>
                ),
              },
              {
                key: "cash_price",
                label: "سعر النقد",
                render: (r) => <bdi>{formatMoney(r.cash_price, "", 4)}</bdi>,
              },
              {
                key: "installment_price",
                label: "سعر التقسيط",
                render: (r) => (
                  <bdi>{formatMoney(r.installment_price, "", 4)}</bdi>
                ),
              },
              ...(cost
                ? [
                    {
                      key: "standard_cost",
                      label: "التكلفة المرجعية",
                      render: (r: Product) => (
                        <bdi>{formatMoney(r.standard_cost ?? "0", "", 4)}</bdi>
                      ),
                    },
                  ]
                : []),
              {
                key: "tracking",
                label: "التتبع",
                render: (r) => (r.serial_tracked ? "رقم تسلسلي" : "كمية"),
              },
              {
                key: "active",
                label: "الحالة",
                render: (r) => (
                  <Badge tone={r.active ? "good" : "neutral"}>
                    {r.active ? "نشط" : "غير نشط"}
                  </Badge>
                ),
              },
              {
                key: "edit",
                label: "الإجراءات",
                render: (r) => (
                  <button className="text-link" onClick={() => setEditing(r)}>
                    {can("catalog.manage") ? "عرض / تعديل" : "عرض التفاصيل"}
                  </button>
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
      {editing !== undefined && (
        <ProductEditor
          key={editing?.id ?? "new"}
          product={editing}
          notice={editorNotice}
          writable={can("catalog.manage")}
          cost={cost}
          onClose={() => {
            setEditing(undefined);
            setEditorNotice(undefined);
          }}
          onSaved={async () => {
            await client.invalidateQueries({ queryKey: ["products"] });
            setEditing(undefined);
            setEditorNotice(undefined);
          }}
          onCreatedWithoutImage={async (created, message) => {
            // The product exists; reopen it so the image can be retried from its own section.
            await client.invalidateQueries({ queryKey: ["products"] });
            setEditorNotice(message);
            setEditing(created);
          }}
        />
      )}
    </>
  );
}
const IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"];
const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
function ProductEditor({
  product,
  notice,
  writable,
  cost,
  onClose,
  onSaved,
  onCreatedWithoutImage,
}: {
  product: Product | null;
  notice?: string;
  writable: boolean;
  cost: boolean;
  onClose: () => void;
  onSaved: () => Promise<void>;
  onCreatedWithoutImage: (created: Product, message: string) => Promise<void>;
}) {
  // Optional image chosen while creating; uploaded right after the product is saved.
  const [newImage, setNewImage] = useState<File | null>(null);
  const [newImageError, setNewImageError] = useState<string>();
  const preview = useMemo(
    () => (newImage ? URL.createObjectURL(newImage) : null),
    [newImage],
  );
  useEffect(
    () => () => {
      if (preview) URL.revokeObjectURL(preview);
    },
    [preview],
  );
  const chooseImage = (file: File | undefined) => {
    setNewImageError(undefined);
    if (!file) return setNewImage(null);
    // Checked here so a bad file is caught before the product is created.
    if (!IMAGE_TYPES.includes(file.type) || file.size > IMAGE_MAX_BYTES) {
      setNewImage(null);
      setNewImageError("اختر صورة JPEG أو PNG أو WEBP بحد أقصى 5 ميغابايت.");
      return;
    }
    setNewImage(file);
  };
  const [form, setForm] = useState<ProductForm>(() =>
    product
      ? {
          ...product,
          barcodes: product.barcodes.map((b) => b.barcode),
          specifications: product.specifications ?? [],
        }
      : { ...empty, standard_cost: cost ? "0" : undefined },
  );
  const [barcode, setBarcode] = useState(form.barcodes.join("\n"));
  // A new product gets a suggested SKU that follows its brand and category until
  // the user types their own. An existing SKU is never replaced.
  const [skuTouched, setSkuTouched] = useState(false);
  const suggestSku = !product && !skuTouched;
  const skuSuggestion = useQuery({
    queryKey: ["sku-suggestion", form.brand_id, form.category_id],
    queryFn: ({ signal }) => {
      const query = new URLSearchParams();
      if (form.brand_id) query.set("brand_id", String(form.brand_id));
      if (form.category_id) query.set("category_id", String(form.category_id));
      return api<ApiEnvelope<{ sku: string }>>(
        `products/sku-suggestion?${query}`,
        { signal },
      );
    },
    enabled: suggestSku && writable,
  });
  const sku = suggestSku
    ? (skuSuggestion.data?.data.sku ?? form.sku)
    : form.sku;
  const update = <K extends keyof ProductForm>(key: K, value: ProductForm[K]) =>
    setForm((f) => ({ ...f, [key]: value }));
  const units = useQuery({
    queryKey: ["master-options", "units"],
    queryFn: ({ signal }) =>
      allPages<{ id: number; code: string; name_ar: string }>("units", signal),
  });
  const unitId =
    form.unit_id || units.data?.find((u) => u.code === "piece")?.id || 0;
  const save = useMutation({
    mutationFn: async () => {
      const saved = await api<ApiEnvelope<Product>>(
        product ? `products/${product.id}` : "products",
        {
          method: product ? "PUT" : "POST",
          body: {
            ...form,
            sku,
            unit_id: unitId,
            standard_cost: cost ? form.standard_cost : undefined,
            barcodes: barcode
              .split(/\r?\n/)
              .map((b) => b.trim())
              .filter(Boolean),
          },
        },
      );
      if (product || !newImage) return { saved, imageError: undefined };
      try {
        const body = new FormData();
        body.append("file", newImage);
        await api(`products/${saved.data.id}/image`, { method: "POST", body });
        return { saved, imageError: undefined };
      } catch (error) {
        // The product is already created; only the image needs another attempt.
        return {
          saved,
          imageError: `تم حفظ المنتج، لكن تعذّر رفع الصورة: ${error instanceof Error ? error.message : "خطأ غير معروف"}. أعد المحاولة من قسم الصورة أدناه.`,
        };
      }
    },
    onSuccess: ({ saved, imageError }) =>
      imageError ? onCreatedWithoutImage(saved.data, imageError) : onSaved(),
  });
  // Only an unused product can be deleted; the server explains when it is in use.
  const [confirmDelete, setConfirmDelete] = useState(false);
  const remove = useMutation({
    mutationFn: () => api(`products/${product!.id}`, { method: "DELETE" }),
    onSuccess: onSaved,
    onError: () => setConfirmDelete(false),
  });
  return (
    <Modal
      title={product ? product.name_ar : "إضافة منتج"}
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
        <fieldset
          disabled={!writable || save.isPending}
          className="plain-fieldset"
        >
          <div className="form-grid">
            <div>
              <Field
                label="رمز المنتج SKU"
                value={sku}
                onChange={(e) => {
                  setSkuTouched(true);
                  update("sku", e.target.value);
                }}
                required={!!product}
                placeholder={product ? undefined : "يُولَّد تلقائياً"}
                dir="ltr"
              />
              {!product && writable && (
                <p className="muted small">
                  {skuTouched ? (
                    <button
                      type="button"
                      className="text-link"
                      onClick={() => {
                        setSkuTouched(false);
                        update("sku", "");
                      }}
                    >
                      استخدام الرمز التلقائي
                    </button>
                  ) : (
                    "مقترح حسب العلامة والفئة، ويمكنك تعديله."
                  )}
                </p>
              )}
            </div>
            <Field
              label="اسم المنتج بالعربية"
              value={form.name_ar}
              onChange={(e) => update("name_ar", e.target.value)}
              required
            />
            <Field
              label="الاسم بالإنجليزية"
              value={form.name_en ?? ""}
              onChange={(e) => update("name_en", e.target.value)}
            />
            <Field
              label="موديل المصنّع"
              value={form.manufacturer_model ?? ""}
              onChange={(e) => update("manufacturer_model", e.target.value)}
            />
            {(
              [
                {
                  key: "brand_id",
                  label: "العلامة التجارية",
                  source: "brands",
                },
                { key: "category_id", label: "الفئة", source: "categories" },
                {
                  key: "unit_id",
                  label: "وحدة القياس",
                  source: "units",
                  required: true,
                },
                {
                  key: "warranty_policy_id",
                  label: "سياسة الضمان",
                  source: "warranty-policies",
                },
                {
                  key: "tax_code_id",
                  label: "الرمز الضريبي",
                  source: "catalog/tax-options",
                },
              ] as const
            ).map((f) => (
              <MasterInput
                key={f.key}
                field={{ ...f, type: "select" }}
                value={f.key === "unit_id" ? unitId : form[f.key]}
                onChange={(v) => update(f.key, v ? Number(v) : (null as never))}
              />
            ))}
            <Field
              label="تصنيف الطاقة"
              value={form.energy_rating ?? ""}
              onChange={(e) => update("energy_rating", e.target.value)}
            />
            <Field
              label="بلد المنشأ"
              value={form.country_of_origin ?? ""}
              onChange={(e) => update("country_of_origin", e.target.value)}
            />
            {(
              [
                { key: "cash_price", label: "سعر البيع النقدي" },
                { key: "installment_price", label: "سعر البيع بالتقسيط" },
                { key: "minimum_price", label: "الحد الأدنى للبيع" },
                { key: "reorder_level", label: "حد إعادة الطلب" },
              ] as const
            ).map((f) => (
              <MoneyInput
                key={f.key}
                label={f.label}
                value={form[f.key]}
                onChange={(v) => update(f.key, v)}
              />
            ))}
            {cost && (
              <MoneyInput
                label="التكلفة المرجعية"
                value={form.standard_cost ?? "0"}
                onChange={(v) => update("standard_cost", v)}
              />
            )}
            <label className="checkbox-inline">
              <input
                type="checkbox"
                checked={form.serial_tracked}
                onChange={(e) => update("serial_tracked", e.target.checked)}
              />
              تتبع الرقم التسلسلي
            </label>
            <label className="checkbox-inline">
              <input
                type="checkbox"
                checked={form.active}
                onChange={(e) => update("active", e.target.checked)}
              />
              منتج نشط
            </label>
          </div>
          <label className="field">
            <span>الباركود — رمز واحد في كل سطر</span>
            <textarea
              rows={3}
              dir="ltr"
              value={barcode}
              onChange={(e) => setBarcode(e.target.value)}
            />
          </label>
          <h3>المواصفات</h3>
          {form.specifications.map((s, i) => (
            <div className="form-grid" key={i}>
              <Field
                label={`المواصفة ${i + 1}`}
                value={s.name}
                required
                onChange={(e) =>
                  update(
                    "specifications",
                    form.specifications.map((v, j) =>
                      i === j ? { ...v, name: e.target.value } : v,
                    ),
                  )
                }
              />
              <Field
                label={`القيمة ${i + 1}`}
                value={s.value}
                required
                onChange={(e) =>
                  update(
                    "specifications",
                    form.specifications.map((v, j) =>
                      i === j ? { ...v, value: e.target.value } : v,
                    ),
                  )
                }
              />
              <button
                type="button"
                className="text-link"
                aria-label={`حذف المواصفة ${i + 1}`}
                onClick={() =>
                  update(
                    "specifications",
                    form.specifications.filter((_, j) => i !== j),
                  )
                }
              >
                <Trash2 size={16} />
              </button>
            </div>
          ))}
          {writable && (
            <button
              type="button"
              className="button"
              onClick={() =>
                update("specifications", [
                  ...form.specifications,
                  { name: "", value: "" },
                ])
              }
            >
              إضافة مواصفة
            </button>
          )}
        </fieldset>
        {product && (
          <ProductImage
            productId={product.id}
            imageUrl={product.image_url}
            writable={writable}
            onChanged={onSaved}
          />
        )}
        {!product && writable && (
          <fieldset className="plain-fieldset product-image">
            <legend>صورة المنتج (اختيارية)</legend>
            {preview && (
              <img
                src={preview}
                alt="معاينة صورة المنتج"
                className="product-image-preview"
              />
            )}
            <label className="field">
              <span>اختر صورة JPEG أو PNG أو WEBP بحد أقصى 5 ميغابايت</span>
              <input
                type="file"
                accept={IMAGE_TYPES.join(",")}
                onChange={(e) => chooseImage(e.target.files?.[0])}
              />
            </label>
            {newImageError && (
              <p className="notice error" role="alert">
                {newImageError}
              </p>
            )}
            {newImage && (
              <button
                type="button"
                className="text-link"
                onClick={() => chooseImage(undefined)}
              >
                إزالة الصورة المختارة
              </button>
            )}
          </fieldset>
        )}
        {notice && (
          <p className="notice error" role="alert">
            {notice}
          </p>
        )}
        <ErrorNotice error={save.error ?? remove.error} />
        {writable && (
          <footer className="form-actions">
            {product &&
              (confirmDelete ? (
                <span className="delete-confirm">
                  <span>حذف المنتج نهائياً؟</span>
                  <button
                    type="button"
                    className="button danger"
                    disabled={remove.isPending}
                    onClick={() => remove.mutate()}
                  >
                    {remove.isPending ? "جارٍ الحذف…" : "تأكيد الحذف"}
                  </button>
                  <button
                    type="button"
                    className="button"
                    disabled={remove.isPending}
                    onClick={() => setConfirmDelete(false)}
                  >
                    إلغاء
                  </button>
                </span>
              ) : (
                <button
                  type="button"
                  className="button danger-outline"
                  disabled={save.isPending}
                  onClick={() => setConfirmDelete(true)}
                >
                  <Trash2 size={16} />
                  حذف المنتج
                </button>
              ))}
            <button
              className="button primary"
              disabled={save.isPending || units.isPending || remove.isPending}
            >
              {save.isPending ? "جارٍ الحفظ…" : "حفظ المنتج"}
            </button>
          </footer>
        )}
      </form>
    </Modal>
  );
}

type ProductImagePayload = { data: { image_url: string } };
function ProductImage({
  productId,
  imageUrl,
  writable,
  onChanged,
}: {
  productId: number;
  imageUrl: string | null;
  writable: boolean;
  onChanged: () => Promise<void>;
}) {
  const [file, setFile] = useState<File | null>(null);
  const [current, setCurrent] = useState(imageUrl);
  // Bumped after every change so the browser reloads instead of showing a cached file.
  const [version, setVersion] = useState(0);
  const present = current !== null;
  const source = present ? `${current}?v=${version}` : "";
  const done = async (url: string | null) => {
    setFile(null);
    setCurrent(url);
    setVersion((v) => v + 1);
    await onChanged();
  };
  const upload = useMutation({
    mutationFn: () => {
      const body = new FormData();
      body.append("file", file!);
      return api<ProductImagePayload>(`products/${productId}/image`, {
        method: "POST",
        body,
      });
    },
    onSuccess: (r) => done(r.data.image_url),
  });
  const remove = useMutation({
    mutationFn: () => api(`products/${productId}/image`, { method: "DELETE" }),
    onSuccess: () => done(null),
  });
  return (
    <fieldset className="plain-fieldset product-image">
      <legend>صورة المنتج (اختيارية)</legend>
      {present ? (
        <img src={source} alt="صورة المنتج" className="product-image-preview" />
      ) : (
        <p className="muted small">لا توجد صورة لهذا المنتج.</p>
      )}
      {writable && (
        <>
          <label className="field">
            <span>اختر صورة JPEG أو PNG أو WEBP بحد أقصى 5 ميغابايت</span>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
          </label>
          <ErrorNotice error={upload.error ?? remove.error} />
          <div className="form-actions">
            <button
              type="button"
              className="button"
              disabled={!file || upload.isPending}
              onClick={() => upload.mutate()}
            >
              {upload.isPending
                ? "جارٍ الرفع…"
                : present
                  ? "استبدال الصورة"
                  : "رفع الصورة"}
            </button>
            {present && (
              <button
                type="button"
                className="button"
                disabled={remove.isPending}
                onClick={() => remove.mutate()}
              >
                {remove.isPending ? "جارٍ الحذف…" : "حذف الصورة"}
              </button>
            )}
          </div>
        </>
      )}
    </fieldset>
  );
}
