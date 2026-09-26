import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import {
  Check,
  ArrowLeft,
  Store,
  CalendarDays,
  BookOpen,
  Percent,
  ImageUp,
  Trash2,
} from "lucide-react";
import { api, allPages, type ApiEnvelope } from "../../lib/api/client";
import {
  ErrorNotice,
  Field,
  Loading,
  Badge,
} from "../../components/ui/Primitives";
import type { MasterRow } from "../accounting/masterDefinitions";
type StoreSettings = {
  trade_name: string;
  platform_name: string | null;
  logo_url: string | null;
  legal_name: string | null;
  owner_name: string | null;
  address: string | null;
  phone: string | null;
  email: string | null;
  tax_number: string | null;
  vat_registered: boolean;
  base_currency: string;
  timezone: string;
  locale: string;
  price_display: string;
  invoice_footer: string | null;
  accounting_configured_at: string | null;
};
export function StoreSetupPage() {
  const q = useQuery({
    queryKey: ["store-settings"],
    queryFn: () => api<ApiEnvelope<StoreSettings>>("store/settings"),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">الإدارة / إعداد المتجر</p>
          <h1>لنجهّز متجرك للعمل</h1>
          <p>هوية المتجر وإعداداته المالية، بخطوات واضحة.</p>
        </div>
        <Badge>متجر واحد</Badge>
      </header>
      <ErrorNotice error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : (
        q.data && <SetupForm settings={q.data.data} />
      )}
    </>
  );
}
function SetupForm({ settings }: { settings: StoreSettings }) {
  const [values, setValues] = useState(settings);
  const [saved, setSaved] = useState(false);
  const client = useQueryClient();
  const currencies = useQuery({
    queryKey: ["master-options", "currencies"],
    queryFn: () => allPages<MasterRow>("currencies"),
  });
  const save = useMutation({
    mutationFn: () => api("store/settings", { method: "PUT", body: values }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["store-settings"] });
      await client.invalidateQueries({ queryKey: ["store-context"] });
      await client.invalidateQueries({ queryKey: ["store-branding"] });
      await client.invalidateQueries({ queryKey: ["inventory-settings"] });
      setSaved(true);
    },
  });
  const set = <K extends keyof StoreSettings>(
    key: K,
    value: StoreSettings[K],
  ) => {
    setValues((v) => ({ ...v, [key]: value }));
    setSaved(false);
  };
  return (
    <div className="setup-layout">
      <section className="panel">
        <div className="panel-heading">
          <h2>هوية المتجر واللغة</h2>
          <Store size={19} />
        </div>
        <form
          className="padded-form"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate();
          }}
        >
          <StoreLogo url={settings.logo_url} />
          <div className="form-grid">
            <Field
              label="اسم المتجر في المنصة"
              value={values.platform_name ?? ""}
              maxLength={60}
              placeholder="دفتر"
              onChange={(e) => set("platform_name", e.target.value || null)}
            />
            <Field
              label="الاسم التجاري للمتجر"
              value={values.trade_name}
              required
              maxLength={160}
              onChange={(e) => set("trade_name", e.target.value)}
            />
            <Field
              label="الاسم القانوني"
              value={values.legal_name ?? ""}
              onChange={(e) => set("legal_name", e.target.value)}
            />
            <Field
              label="اسم المالك / جهة الاتصال"
              value={values.owner_name ?? ""}
              onChange={(e) => set("owner_name", e.target.value)}
            />
            <Field
              label="رقم الهاتف"
              value={values.phone ?? ""}
              dir="ltr"
              onChange={(e) => set("phone", e.target.value)}
            />
            <Field
              label="البريد الإلكتروني"
              type="email"
              value={values.email ?? ""}
              dir="ltr"
              onChange={(e) => set("email", e.target.value)}
            />
            <Field
              label="العنوان"
              value={values.address ?? ""}
              onChange={(e) => set("address", e.target.value)}
            />
            <label className="field">
              <span>العملة الأساسية</span>
              <SearchableSelect
                value={values.base_currency}
                onChange={(e) => set("base_currency", e.target.value)}
              >
                {currencies.data?.map((c) => (
                  <option key={c.code} value={c.code}>
                    {String(c.name)} ({c.code})
                  </option>
                ))}
              </SearchableSelect>
            </label>
            <Field
              label="المنطقة الزمنية"
              required
              dir="ltr"
              value={values.timezone}
              onChange={(e) => set("timezone", e.target.value)}
            />
            <label className="field">
              <span>لغة الواجهة المفضلة</span>
              <SearchableSelect
                value={values.locale}
                onChange={(e) => set("locale", e.target.value)}
              >
                <option value="ar">العربية</option>
                <option value="en">English (بيانات جاهزة للترجمة)</option>
              </SearchableSelect>
            </label>
            <label className="field">
              <span>عرض أسعار المنتجات</span>
              <SearchableSelect
                value={values.price_display}
                onChange={(e) => set("price_display", e.target.value)}
              >
                <option value="exclusive">غير شاملة للضريبة</option>
                <option value="inclusive">شاملة للضريبة</option>
              </SearchableSelect>
            </label>
            <label className="checkbox-inline">
              <input
                type="checkbox"
                checked={values.vat_registered}
                onChange={(e) => set("vat_registered", e.target.checked)}
              />
              المتجر مسجل لضريبة القيمة المضافة
            </label>
            <Field
              label="رقم التسجيل الضريبي"
              required={values.vat_registered}
              value={values.tax_number ?? ""}
              onChange={(e) => set("tax_number", e.target.value)}
            />
          </div>
          <label className="field">
            <span>تذييل الفاتورة والشروط</span>
            <textarea
              maxLength={3000}
              value={values.invoice_footer ?? ""}
              onChange={(e) => set("invoice_footer", e.target.value)}
            />
          </label>
          <ErrorNotice error={save.error} />
          {saved && (
            <div className="notice" role="status">
              <Check size={18} />
              تم حفظ إعدادات المتجر.
            </div>
          )}
          <footer className="form-actions">
            <button className="button primary" disabled={save.isPending}>
              {save.isPending ? "جارٍ الحفظ…" : "حفظ إعدادات المتجر"}
            </button>
          </footer>
        </form>
      </section>
      <aside className="setup-checklist">
        <h2>الإعداد المالي</h2>
        <p className="muted small">أكمل هذه البيانات قبل ترحيل القيود.</p>
        {[
          {
            icon: CalendarDays,
            title: "السنة والفترات المالية",
            to: "fiscal-years",
            desc: "حدد بداية ونهاية السنة المالية.",
          },
          {
            icon: BookOpen,
            title: "دليل الحسابات",
            to: "accounts",
            desc: "راجع الحسابات والروابط الرقابية.",
          },
          {
            icon: Percent,
            title: "الرموز الضريبية",
            to: "tax-codes",
            desc: "أدخل النسب وتواريخ السريان المعتمدة.",
          },
          {
            icon: Store,
            title: "الصناديق والبنوك",
            to: "cashboxes",
            desc: "اربط حسابات النقد بالأستاذ العام.",
          },
        ].map((s) => (
          <Link
            className="setup-step"
            to={"/accounting/masters/" + s.to}
            key={s.to}
          >
            <s.icon size={20} />
            <div>
              <h3>{s.title}</h3>
              <p>{s.desc}</p>
            </div>
            <ArrowLeft size={15} />
          </Link>
        ))}
        <p className="setup-next">
          يأتي إعداد المنتجات والموردين والأرصدة الافتتاحية بعد هذه الخطوة.
        </p>
      </aside>
    </div>
  );
}

function StoreLogo({ url }: { url: string | null }) {
  const client = useQueryClient();
  const refresh = async () => {
    await client.invalidateQueries({ queryKey: ["store-settings"] });
    await client.invalidateQueries({ queryKey: ["store-context"] });
    await client.invalidateQueries({ queryKey: ["store-branding"] });
  };
  const upload = useMutation({
    mutationFn: (file: File) => {
      const body = new FormData();
      body.append("file", file);
      return api("store/logo", { method: "POST", body });
    },
    onSuccess: refresh,
  });
  const remove = useMutation({
    mutationFn: () => api("store/logo", { method: "DELETE" }),
    onSuccess: refresh,
  });
  return (
    <div className="store-logo-field">
      <div className="store-logo-preview">
        {url ? <img src={url} alt="شعار المتجر" /> : <Store size={28} />}
      </div>
      <div className="store-logo-actions">
        <strong>شعار المتجر</strong>
        <span className="muted small">
          يظهر في القائمة الجانبية وتبويب المتصفح. PNG أو JPEG أو WEBP حتى 2
          ميغابايت، ويفضّل مربعاً.
        </span>
        <div className="inline-actions">
          <label className="button">
            <ImageUp size={17} />
            {upload.isPending
              ? "جارٍ الرفع..."
              : url
                ? "تغيير الشعار"
                : "رفع شعار"}
            <input
              type="file"
              accept="image/png,image/jpeg,image/webp"
              hidden
              disabled={upload.isPending}
              onChange={(e) => {
                const file = e.target.files?.[0];
                e.target.value = "";
                if (file) upload.mutate(file);
              }}
            />
          </label>
          {url && (
            <button
              type="button"
              className="button"
              disabled={remove.isPending}
              onClick={() => remove.mutate()}
            >
              <Trash2 size={17} />
              حذف
            </button>
          )}
        </div>
        <ErrorNotice error={upload.error ?? remove.error} />
      </div>
    </div>
  );
}
