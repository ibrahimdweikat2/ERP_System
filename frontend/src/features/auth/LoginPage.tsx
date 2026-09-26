import { useState } from "react";
import { Link, Navigate, useLocation, useNavigate } from "react-router-dom";
import {
  ArrowLeft,
  Check,
  Eye,
  EyeOff,
  LockKeyhole,
  LockKeyholeOpen,
  Mail,
  Refrigerator,
  ShieldCheck,
} from "lucide-react";
import { api, ApiError, csrf } from "../../lib/api/client";
import { useQuery } from "@tanstack/react-query";
import { useBrandHead } from "../../lib/hooks/useBrandHead";
import { useAuth } from "../../lib/auth/context";
import { ErrorNotice, Field } from "../../components/ui/Primitives";
import { OtpInput, type OtpStatus } from "../../components/forms/OtpInput";
import { QRCodeSVG } from "qrcode.react";
type MfaChallenge =
  { mfa: "verify" } | { mfa: "setup"; secret: string; uri: string };
// "تذكرني" keeps the email on this device; the server also sets a long-lived login cookie.
const REMEMBER_KEY = "erp.remembered-email";
function readRemembered(): string | null {
  try {
    return localStorage.getItem(REMEMBER_KEY);
  } catch {
    return null;
  }
}
function saveRemembered(email: string | null) {
  try {
    if (email) localStorage.setItem(REMEMBER_KEY, email);
    else localStorage.removeItem(REMEMBER_KEY);
  } catch {
    // Storage may be blocked; remembering is only a convenience.
  }
}

// Asks the browser's own password manager to save the login (Chrome/Edge). The
// password is never stored by the app itself.
function offerToSavePassword(email: string, password: string) {
  const PasswordCredential = (
    window as unknown as {
      PasswordCredential?: new (data: {
        id: string;
        password: string;
      }) => Credential;
    }
  ).PasswordCredential;
  if (!PasswordCredential || !navigator.credentials) return;
  navigator.credentials
    .store(new PasswordCredential({ id: email, password }))
    .catch(() => undefined);
}

export function LoginPage() {
  const { user, refresh } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const reset = location.pathname === "/reset-password";
  const forgot = location.pathname === "/forgot-password";
  const params = new URLSearchParams(location.search);
  const [email, setEmail] = useState(
    params.get("email") ?? readRemembered() ?? "",
  );
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [remember, setRemember] = useState(() => readRemembered() !== null);
  const [challenge, setChallenge] = useState<MfaChallenge>();
  const [code, setCode] = useState("");
  const [verified, setVerified] = useState(false);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [confirmation, setConfirmation] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>();
  const [message, setMessage] = useState("");
  const branding = useQuery({
    queryKey: ["store-branding"],
    queryFn: () =>
      api<{ data: { platform_name: string | null; logo_url: string | null } }>(
        "store/branding",
      ),
    staleTime: 5 * 60_000,
  });
  const platformName = branding.data?.data.platform_name || "دفتر";
  const logoUrl = branding.data?.data.logo_url;
  useBrandHead(platformName, logoUrl);
  if (user) return <Navigate to="/" replace />;
  return (
    <main className="login-page">
      <section className="login-story">
        <Link to="/login" className="brand">
          <span className={"brand-mark" + (logoUrl ? " has-logo" : "")}>
            {logoUrl ? (
              <img src={logoUrl} alt="" />
            ) : (
              <Refrigerator size={23} />
            )}
          </span>
          <span className="brand-name">
            {platformName}
            <small>إدارة متجر الأجهزة</small>
          </span>
        </Link>
        <div>
          <p className="eyebrow">مساحة عمل واحدة. تفاصيل واضحة.</p>
          <h1>
            كل جهاز له قصة.
            <br />
            وكل حركة لها سجل.
          </h1>
          <p className="story-copy">
            المبيعات، المخزون، الأقساط والشيكات.
            <br />
            إدارة يومك تبدأ من هنا.
          </p>
          <div className="ledger-art" aria-hidden="true">
            <div className="art-label">
              سجل المتجر <span>{platformName}</span>
            </div>
            {[
              "المبيعات والتحصيل",
              "الأجهزة والأرقام التسلسلية",
              "العملاء والأقساط",
              "الحسابات والتدقيق",
            ].map((t, i) => (
              <div className="art-row" key={t}>
                <span>{t}</span>
                <span className={"art-bar bar-" + i} />
              </div>
            ))}
          </div>
        </div>
        <p className="story-footer">
          <ShieldCheck size={17} /> وصول حسب الصلاحيات · سجل لكل عملية
        </p>
      </section>
      <section className="login-form-area">
        <div className="login-form">
          {!challenge && (
            <>
              <span className="login-lock">
                <LockKeyhole size={23} />
              </span>
              <h2>
                {reset
                  ? "كلمة مرور جديدة"
                  : forgot
                    ? "استعادة الوصول"
                    : "أهلاً بعودتك"}
              </h2>
              <p className="muted">
                {reset
                  ? "اختر كلمة مرور قوية لحسابك."
                  : forgot
                    ? "أدخل البريد الإلكتروني المرتبط بحسابك."
                    : "سجّل دخولك للوصول إلى مساحة عمل المتجر."}
              </p>
            </>
          )}
          {challenge ? (
            <MfaStep
              challenge={challenge}
              code={code}
              setCode={setCode}
              recoveryCodes={recoveryCodes}
              pending={pending}
              verified={verified}
              error={error}
              onCancel={() => {
                setChallenge(undefined);
                setCode("");
                setVerified(false);
                setError(null);
              }}
              onDone={async () => {
                await refresh();
                navigate("/");
              }}
              onSubmit={async (value) => {
                setPending(true);
                setError(null);
                try {
                  const r = await api<{ data: { recovery_codes?: string[] } }>(
                    "auth/login/mfa",
                    { method: "POST", body: { code: value } },
                  );
                  // Let the success animation play before leaving the step.
                  setVerified(true);
                  await new Promise((done) => setTimeout(done, 900));
                  if (r.data.recovery_codes?.length) {
                    setRecoveryCodes(r.data.recovery_codes);
                  } else {
                    await refresh();
                    navigate("/");
                  }
                } catch (err) {
                  if (
                    err instanceof ApiError &&
                    err.code === "MFA_SESSION_EXPIRED"
                  ) {
                    setChallenge(undefined);
                    setPassword("");
                  }
                  setCode("");
                  setError(err);
                } finally {
                  setPending(false);
                }
              }}
            />
          ) : (
            <form
              onSubmit={async (e) => {
                e.preventDefault();
                setPending(true);
                setError(null);
                try {
                  await csrf();
                  if (forgot) {
                    const r = await api<{ message: string }>(
                      "auth/forgot-password",
                      { method: "POST", body: { email } },
                    );
                    setMessage(r.message);
                  } else if (reset) {
                    await api("auth/reset-password", {
                      method: "POST",
                      body: {
                        email,
                        token: params.get("token"),
                        password,
                        password_confirmation: confirmation,
                      },
                    });
                    navigate("/login");
                    setMessage("تم تحديث كلمة المرور.");
                  } else {
                    const r = await api<{ data: Partial<MfaChallenge> }>(
                      "auth/login",
                      { method: "POST", body: { email, password, remember } },
                    );
                    saveRemembered(remember ? email : null);
                    if (remember) offerToSavePassword(email, password);
                    if (r.data.mfa) {
                      setChallenge(r.data as MfaChallenge);
                      setCode("");
                    } else {
                      await refresh();
                      navigate("/");
                    }
                  }
                } catch (err) {
                  setError(err);
                } finally {
                  setPending(false);
                }
              }}
            >
              <Field
                label="البريد الإلكتروني"
                type="email"
                icon={<Mail size={18} />}
                autoComplete="username"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                dir="ltr"
                placeholder="name@store.com"
              />
              {!forgot && (
                <Field
                  label="كلمة المرور"
                  type={showPassword ? "text" : "password"}
                  icon={
                    showPassword ? (
                      <LockKeyholeOpen size={18} />
                    ) : (
                      <LockKeyhole size={18} />
                    )
                  }
                  trailing={
                    <button
                      type="button"
                      className="field-toggle"
                      aria-label={
                        showPassword ? "إخفاء كلمة المرور" : "عرض كلمة المرور"
                      }
                      aria-pressed={showPassword}
                      onClick={() => setShowPassword((v) => !v)}
                    >
                      {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                    </button>
                  }
                  autoComplete={reset ? "new-password" : "current-password"}
                  required
                  minLength={reset ? 12 : undefined}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  dir="ltr"
                />
              )}
              {reset && (
                <Field
                  label="تأكيد كلمة المرور"
                  type="password"
                  autoComplete="new-password"
                  required
                  value={confirmation}
                  onChange={(e) => setConfirmation(e.target.value)}
                  dir="ltr"
                />
              )}
              {!reset && !forgot && (
                <div className="login-options">
                  <label className="remember-me">
                    <input
                      type="checkbox"
                      checked={remember}
                      onChange={(e) => setRemember(e.target.checked)}
                    />
                    تذكرني
                  </label>
                </div>
              )}
              <ErrorNotice error={error} />
              {message && (
                <p className="notice" role="status">
                  {message}
                </p>
              )}
              <button className="button primary wide" disabled={pending}>
                {pending
                  ? "جارٍ التنفيذ…"
                  : reset
                    ? "حفظ كلمة المرور"
                    : forgot
                      ? "إرسال رابط الاستعادة"
                      : "تسجيل الدخول"}
                <ArrowLeft size={18} />
              </button>
              {(forgot || reset) && (
                <Link className="text-link" to="/login">
                  العودة إلى تسجيل الدخول
                </Link>
              )}
            </form>
          )}
          <p className="login-note">
            للوصول إلى حسابك أو تعديل صلاحياتك، تواصل مع مدير المتجر.
          </p>
        </div>
        <footer>{platformName} · نظام إدارة متجر الأجهزة الكهربائية</footer>
      </section>
    </main>
  );
}

function MfaStep({
  challenge,
  code,
  setCode,
  recoveryCodes,
  pending,
  verified,
  error,
  onSubmit,
  onCancel,
  onDone,
}: {
  challenge: MfaChallenge;
  code: string;
  setCode: (v: string) => void;
  recoveryCodes: string[];
  pending: boolean;
  verified: boolean;
  error: unknown;
  onSubmit: (code: string) => Promise<void>;
  onCancel: () => void;
  onDone: () => Promise<void>;
}) {
  const [useRecovery, setUseRecovery] = useState(false);
  if (recoveryCodes.length)
    return (
      <section className="mfa-step">
        <MfaBadge status="success" />
        <h2>احفظ رموز الاسترداد</h2>
        <p className="muted">
          تم تفعيل المصادقة الثنائية. استخدم أحد هذه الرموز إن فقدت هاتفك؛ كل
          رمز يعمل مرة واحدة ولن يُعرض مجدداً.
        </p>
        <pre className="mfa-codes" dir="ltr">
          {recoveryCodes.join("\n")}
        </pre>
        <button className="button primary wide" type="button" onClick={onDone}>
          حفظتها، متابعة <ArrowLeft size={18} />
        </button>
      </section>
    );
  const setup = challenge.mfa === "setup";
  const recovery = !setup && useRecovery;
  const status: OtpStatus = verified
    ? "success"
    : pending
      ? "checking"
      : error
        ? "error"
        : "idle";
  const hint =
    status === "checking"
      ? "جارٍ التحقق…"
      : status === "success"
        ? "تم التحقق بنجاح"
        : recovery
          ? "أدخل أحد رموز الاسترداد المكوّنة من 10 خانات."
          : "يتم التحقق تلقائياً بعد إدخال الرقم الأخير.";
  return (
    <section className="mfa-step">
      <MfaBadge status={status} />
      <h2>{setup ? "إعداد المصادقة الثنائية" : "التحقق بخطوتين"}</h2>
      {setup ? (
        <>
          <p className="muted">
            امسح الرمز بتطبيق المصادقة (Google Authenticator أو Microsoft
            Authenticator)، ثم أدخل الرمز المكوّن من 6 أرقام.
          </p>
          <div className="mfa-qr">
            <QRCodeSVG value={challenge.uri} size={180} marginSize={2} />
          </div>
          <details>
            <summary>لا يمكنك المسح؟ أدخل المفتاح يدوياً</summary>
            <code dir="ltr" className="mfa-secret">
              {challenge.secret}
            </code>
          </details>
        </>
      ) : (
        <p className="muted">
          {recovery
            ? "استخدم رمز استرداد إذا لم يكن هاتفك متاحاً."
            : "أدخل الرمز المكوّن من 6 أرقام من تطبيق المصادقة."}
        </p>
      )}
      <OtpInput
        key={recovery ? "recovery" : "app"}
        length={recovery ? 10 : 6}
        numeric={!recovery}
        label={recovery ? "رمز الاسترداد" : "رمز التحقق"}
        value={code}
        onChange={setCode}
        onComplete={(v) => void onSubmit(v)}
        status={status}
        autoFocus
      />
      <p className={"otp-hint otp-hint-" + status} aria-live="polite">
        {hint}
      </p>
      <ErrorNotice error={error} />
      <div className="mfa-links">
        {!setup && (
          <button
            className="text-link"
            type="button"
            disabled={pending || verified}
            onClick={() => {
              setUseRecovery((v) => !v);
              setCode("");
            }}
          >
            {recovery ? "استخدام رمز التطبيق" : "استخدام رمز استرداد"}
          </button>
        )}
        <button className="text-link" type="button" onClick={onCancel}>
          العودة إلى تسجيل الدخول
        </button>
      </div>
    </section>
  );
}
function MfaBadge({ status }: { status: OtpStatus }) {
  return (
    <span className={"mfa-badge mfa-badge-" + status} aria-hidden="true">
      {status === "success" ? <Check size={30} /> : <ShieldCheck size={30} />}
    </span>
  );
}
