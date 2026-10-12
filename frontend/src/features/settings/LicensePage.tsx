import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { KeyRound, RefreshCw, ShieldOff } from "lucide-react";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import {
  Badge,
  ErrorNotice,
  Field,
  Loading,
  Modal,
} from "../../components/ui/Primitives";
import { Heading, useCommand } from "../operations/shared";

type LicenseState =
  | "missing"
  | "active"
  | "rejected"
  | "invalid"
  | "lapsed"
  | "untrusted";

type CompanyLicense = {
  enforced: boolean;
  server_configured: boolean;
  domain: string | null;
  valid: boolean;
  state: LicenseState;
  license_key: string | null;
  license_type: string | null;
  expires_at: string | null;
  updates_expire_at: string | null;
  activations_used: number | null;
  max_activations: number | null;
  valid_until: string | null;
  activated_at: string | null;
  last_checked_at: string | null;
  next_check_at: string | null;
  reason: string | null;
  message: string | null;
  last_error: string | null;
};

const states: Record<
  LicenseState,
  { label: string; tone: "good" | "warning" | "danger" | "neutral"; text: string }
> = {
  missing: {
    label: "غير مفعّل",
    tone: "neutral",
    text: "لا يوجد ترخيص لهذه الشركة. يرى جميع المستخدمين لوحة المتجر فقط حتى تفعيل الترخيص.",
  },
  active: {
    label: "فعّال",
    tone: "good",
    text: "الترخيص فعّال، ويحصل كل مستخدم على صلاحيات أدواره.",
  },
  rejected: {
    label: "موقوف",
    tone: "warning",
    text: "رفض خادم التراخيص الترخيص حالياً. يُعاد التحقق تلقائياً كل ساعة، ويعود العمل عند قبوله.",
  },
  invalid: {
    label: "غير صالح",
    tone: "danger",
    text: "لم يعد الترخيص صالحاً. أدخل مفتاح ترخيص صالحاً لإعادة تفعيل الشركة.",
  },
  lapsed: {
    label: "انتهت مهلة التحقق",
    tone: "danger",
    text: "تعذر التحقق من الترخيص عبر الخادم حتى انتهت مهلة العمل دون اتصال، أو انتهت مدة الترخيص. اضغط «التحقق الآن».",
  },
  untrusted: {
    label: "غير موثوق",
    tone: "danger",
    text: "تعذر التحقق من توقيع الترخيص المحفوظ. فعّل الترخيص من جديد.",
  },
};

const types: Record<string, string> = {
  lifetime: "دائم",
  subscription: "اشتراك",
  trial: "تجريبي",
};

const when = (value: string | null) =>
  value ? value.replace("T", " ").slice(0, 16) : "—";

/** The company's LicenseBox license: owner only, and open without a valid license. */
export function LicensePage() {
  const { user } = useAuth();
  const command = useCommand();
  const [key, setKey] = useState("");
  const [confirmRemove, setConfirmRemove] = useState(false);
  const owner = !!user?.is_owner;
  const query = useQuery({
    queryKey: ["company-license"],
    queryFn: () => api<ApiEnvelope<CompanyLicense>>("company-license"),
    enabled: owner,
  });
  if (!owner)
    return (
      <div className="state">
        <h1>الوصول غير متاح</h1>
        <p>إدارة ترخيص الشركة متاحة لمالك الشركة فقط.</p>
      </div>
    );
  const license = query.data?.data;
  const state = license ? states[license.state] : undefined;
  const hasLicense = !!license && license.state !== "missing";
  return (
    <>
      <Heading title="ترخيص الشركة" section="الإدارة">
        {hasLicense && (
          <button
            type="button"
            className="button secondary"
            disabled={command.pending}
            onClick={() => command.run("company-license/verify")}
          >
            <RefreshCw size={16} />
            التحقق الآن
          </button>
        )}
      </Heading>
      <ErrorNotice error={query.error ?? command.error} />
      {query.isPending ? (
        <Loading />
      ) : (
        license &&
        state && (
          <>
            {!license.server_configured && (
              <div className="notice warning" role="alert">
                <p>
                  لم يُضبط الاتصال بخادم التراخيص على هذا النظام، فلا يمكن
                  التفعيل أو التحقق الآن. راجع إدارة المنصة.
                </p>
              </div>
            )}
            {!license.enforced && (
              <div className="notice warning" role="status">
                <p>التحقق من الترخيص متوقف على هذا الخادم؛ تعمل الشركة دون قيود.</p>
              </div>
            )}
            <section className="ops-summary">
              <div>
                <small>الحالة</small>
                <Badge tone={state.tone}>{state.label}</Badge>
              </div>
              <div>
                <small>مفتاح الترخيص</small>
                <strong dir="ltr">{license.license_key ?? "—"}</strong>
              </div>
              <div>
                <small>النوع</small>
                <strong>
                  {license.license_type
                    ? (types[license.license_type] ?? license.license_type)
                    : "—"}
                </strong>
              </div>
              <div>
                <small>ينتهي في</small>
                <strong>
                  {hasLicense ? (license.expires_at ? when(license.expires_at) : "بلا انتهاء") : "—"}
                </strong>
              </div>
              <div>
                <small>التفعيلات</small>
                <strong>
                  {license.activations_used != null
                    ? `${license.activations_used} / ${license.max_activations || "غير محدود"}`
                    : "—"}
                </strong>
              </div>
            </section>
            <section className="panel ops-panel">
              <h2>حالة الترخيص</h2>
              <p>{state.text}</p>
              {license.message && (
                <div className="notice warning" role="alert">
                  <p>{license.message}</p>
                </div>
              )}
              {hasLicense && (
                <div className="ops-grid three">
                  <div>
                    <p className="muted">تاريخ التفعيل</p>
                    <strong>{when(license.activated_at)}</strong>
                  </div>
                  <div>
                    <p className="muted">آخر تحقق</p>
                    <strong>{when(license.last_checked_at)}</strong>
                  </div>
                  <div>
                    <p className="muted">التحقق القادم</p>
                    <strong>{when(license.next_check_at)}</strong>
                  </div>
                  <div>
                    <p className="muted">صالح دون اتصال حتى</p>
                    <strong>{when(license.valid_until)}</strong>
                  </div>
                  <div>
                    <p className="muted">انتهاء التحديثات</p>
                    <strong>{when(license.updates_expire_at)}</strong>
                  </div>
                  <div>
                    <p className="muted">النطاق</p>
                    <strong dir="ltr">{license.domain ?? "—"}</strong>
                  </div>
                </div>
              )}
              {license.last_error && (
                <p className="ops-status-note">
                  آخر خطأ اتصال: <span dir="ltr">{license.last_error}</span>
                </p>
              )}
            </section>
            <section className="panel ops-panel">
              <h2>{hasLicense ? "استبدال الترخيص" : "تفعيل الترخيص"}</h2>
              <p>
                أدخل مفتاح الترخيص الذي حصلت عليه. يتحقق النظام منه لدى خادم
                التراخيص، وعند قبوله يحصل كل مستخدم في الشركة على صلاحيات
                أدواره.
              </p>
              <form
                className="ops-toolbar"
                onSubmit={async (e) => {
                  e.preventDefault();
                  if (await command.run("company-license", { license_key: key.trim() }))
                    setKey("");
                }}
              >
                <Field
                  label="مفتاح الترخيص"
                  dir="ltr"
                  placeholder="XXXX-XXXX-XXXX-XXXX"
                  autoComplete="off"
                  spellCheck={false}
                  required
                  minLength={4}
                  maxLength={64}
                  value={key}
                  onChange={(e) => setKey(e.target.value)}
                  icon={<KeyRound size={16} />}
                />
                <button
                  className="button primary"
                  disabled={command.pending || !license.server_configured || !key.trim()}
                >
                  {command.pending ? "جارٍ التحقق…" : "تحقق وفعّل"}
                </button>
              </form>
              {hasLicense && (
                <button
                  type="button"
                  className="button danger-outline"
                  disabled={command.pending}
                  onClick={() => setConfirmRemove(true)}
                >
                  <ShieldOff size={16} />
                  إلغاء تفعيل الترخيص
                </button>
              )}
            </section>
          </>
        )
      )}
      {confirmRemove && (
        <Modal title="إلغاء تفعيل الترخيص" onClose={() => setConfirmRemove(false)}>
          <form
            className="ops-form"
            onSubmit={async (e) => {
              e.preventDefault();
              if (await command.run("company-license", {}, "DELETE"))
                setConfirmRemove(false);
            }}
          >
            <div className="notice warning" role="alert">
              <ul>
                <li>يُحرَّر مقعد هذا التفعيل لدى خادم التراخيص.</li>
                <li>
                  يفقد جميع مستخدمي الشركة صلاحياتهم فوراً، ولا تظهر لهم إلا لوحة
                  المتجر حتى تفعيل ترخيص جديد.
                </li>
                <li>لا تتأثر بيانات الشركة.</li>
              </ul>
            </div>
            <ErrorNotice error={command.error} />
            <div className="dialog-actions">
              <button className="button danger" disabled={command.pending}>
                {command.pending ? "جارٍ الإلغاء…" : "تأكيد إلغاء التفعيل"}
              </button>
              <button
                type="button"
                className="button secondary"
                onClick={() => setConfirmRemove(false)}
              >
                تراجع
              </button>
            </div>
          </form>
        </Modal>
      )}
    </>
  );
}
