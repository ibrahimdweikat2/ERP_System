import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Pencil, UserPlus } from "lucide-react";
import { api, type ApiEnvelope } from "../../lib/api/client";
import type { Company, CompanyOwner } from "../../types/platform";
import { DataTable } from "../../components/data-table/DataTable";
import {
  Badge,
  ErrorNotice,
  Field,
  Loading,
  Modal,
} from "../../components/ui/Primitives";
import { CompanyStatusBadge } from "./CompaniesPage";

/** Superadmin: one company, its standard roles and its owner accounts. */
export function CompanyDetailPage() {
  const { id } = useParams();
  const client = useQueryClient();
  const [creatingOwner, setCreatingOwner] = useState(false);
  const [renaming, setRenaming] = useState(false);
  const company = useQuery({
    queryKey: ["platform-company", id],
    queryFn: () => api<ApiEnvelope<Company>>(`platform/companies/${id}`),
  });
  const owners = useQuery({
    queryKey: ["platform-company-owners", id],
    queryFn: () => api<ApiEnvelope<CompanyOwner[]>>(`platform/companies/${id}/owners`),
  });
  const refresh = async () => {
    await client.invalidateQueries({ queryKey: ["platform-company", id] });
    await client.invalidateQueries({ queryKey: ["platform-company-owners", id] });
    await client.invalidateQueries({ queryKey: ["platform-companies"] });
  };
  const setStatus = useMutation({
    mutationFn: (status: Company["status"]) =>
      api(`platform/companies/${id}`, { method: "PATCH", body: { status } }),
    onSuccess: refresh,
  });
  const c = company.data?.data;
  if (company.isPending) return <Loading />;
  if (!c) return <ErrorNotice error={company.error} />;
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">
            <Link to="/platform">المنصة / الشركات</Link>
          </p>
          <h1>{c.name}</h1>
          <p>
            <CompanyStatusBadge status={c.status} /> · أنشئت في{" "}
            <bdi>{new Date(c.created_at).toLocaleDateString("ar-PS")}</bdi>
          </p>
        </div>
        <div className="actions">
          <button className="button secondary" onClick={() => setRenaming(true)}>
            <Pencil size={16} />
            تعديل الاسم
          </button>
          {c.status === "active" ? (
            <button
              className="button danger"
              disabled={setStatus.isPending}
              onClick={() => {
                if (window.confirm("إيقاف الشركة يمنع جميع مستخدميها من الدخول فوراً. متابعة؟"))
                  setStatus.mutate("suspended");
              }}
            >
              إيقاف الشركة
            </button>
          ) : (
            <button
              className="button primary"
              disabled={setStatus.isPending}
              onClick={() => setStatus.mutate("active")}
            >
              إعادة تفعيل الشركة
            </button>
          )}
        </div>
      </header>
      <ErrorNotice error={setStatus.error} />
      <section className="panel">
        <div className="panel-heading">
          <h2>مالكو الشركة</h2>
          <button className="button primary" onClick={() => setCreatingOwner(true)}>
            <UserPlus size={18} />
            إنشاء مالك
          </button>
        </div>
        <p className="muted small">
          المالك يملك كل صلاحيات الشركة، ويضيف بقية فريقها من صفحة المستخدمين
          بعد تسجيل دخوله.
        </p>
        <ErrorNotice error={owners.error} />
        {owners.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={owners.data?.data ?? []}
            empty="لا يوجد مالك بعد. أنشئ مالكاً ليتمكن من الدخول وإدارة الشركة."
            columns={[
              { key: "name", label: "الاسم", render: (o) => o.name },
              { key: "email", label: "البريد الإلكتروني", render: (o) => <bdi>{o.email}</bdi> },
              { key: "phone", label: "الهاتف", render: (o) => <bdi>{o.phone ?? "—"}</bdi> },
              {
                key: "mfa",
                label: "المصادقة الثنائية",
                render: (o) =>
                  o.mfa_enabled ? (
                    <Badge tone="good">مفعّلة</Badge>
                  ) : o.mfa_required ? (
                    <Badge tone="warning">تُطلب عند الدخول</Badge>
                  ) : (
                    <Badge>غير مفعّلة</Badge>
                  ),
              },
              {
                key: "login",
                label: "آخر دخول",
                render: (o) =>
                  o.last_login_at ? (
                    <bdi>{new Date(o.last_login_at).toLocaleString("ar-PS")}</bdi>
                  ) : (
                    "لم يدخل بعد"
                  ),
              },
            ]}
          />
        )}
      </section>
      <section className="panel">
        <div className="panel-heading">
          <h2>أدوار الشركة</h2>
          <Badge>أُنشئت تلقائياً مع الشركة</Badge>
        </div>
        <DataTable
          rows={c.roles ?? []}
          columns={[
            { key: "label", label: "الدور", render: (r) => r.label },
            { key: "name", label: "الرمز", render: (r) => <bdi className="mono">{r.name}</bdi> },
          ]}
        />
      </section>
      {creatingOwner && (
        <CreateOwner
          companyId={c.id}
          onClose={() => setCreatingOwner(false)}
          onSaved={async () => {
            await refresh();
            setCreatingOwner(false);
          }}
        />
      )}
      {renaming && (
        <RenameCompany
          company={c}
          onClose={() => setRenaming(false)}
          onSaved={async () => {
            await refresh();
            setRenaming(false);
          }}
        />
      )}
    </>
  );
}

function CreateOwner({
  companyId,
  onClose,
  onSaved,
}: {
  companyId: number;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [mfaRequired, setMfaRequired] = useState(true);
  const save = useMutation({
    mutationFn: () =>
      api(`platform/companies/${companyId}/owners`, {
        method: "POST",
        body: { name, email, phone: phone || null, password, mfa_required: mfaRequired },
      }),
    onSuccess: onSaved,
  });
  return (
    <Modal
      title="إنشاء مالك للشركة"
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
        <div className="form-grid">
          <Field label="الاسم الكامل" value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} />
          <Field
            label="البريد الإلكتروني"
            type="email"
            dir="ltr"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
          <Field label="رقم الهاتف" dir="ltr" value={phone} onChange={(e) => setPhone(e.target.value)} />
          <Field
            label="كلمة المرور"
            type="password"
            autoComplete="new-password"
            dir="ltr"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            minLength={12}
          />
        </div>
        <p className="muted small">
          12 حرفاً على الأقل، تتضمن أحرفاً كبيرة وصغيرة وأرقاماً. البريد يجب ألا
          يكون مستخدماً في أي شركة أخرى؛ منه يعرف النظام شركة المستخدم عند الدخول.
        </p>
        <label className="checkbox-inline">
          <input type="checkbox" checked={mfaRequired} onChange={(e) => setMfaRequired(e.target.checked)} />
          إلزام المصادقة الثنائية عند تسجيل الدخول
        </label>
        <ErrorNotice error={save.error} />
        <footer className="form-actions">
          <button className="button" type="button" disabled={save.isPending} onClick={onClose}>
            إلغاء
          </button>
          <button className="button primary" disabled={save.isPending}>
            {save.isPending ? "جارٍ الإنشاء…" : "إنشاء المالك"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}

function RenameCompany({
  company,
  onClose,
  onSaved,
}: {
  company: Company;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [name, setName] = useState(company.name);
  const save = useMutation({
    mutationFn: () =>
      api(`platform/companies/${company.id}`, { method: "PATCH", body: { name: name.trim() } }),
    onSuccess: onSaved,
  });
  return (
    <Modal
      title="تعديل اسم الشركة"
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
        <Field label="اسم الشركة" value={name} onChange={(e) => setName(e.target.value)} required maxLength={160} />
        <ErrorNotice error={save.error} />
        <footer className="form-actions">
          <button className="button" type="button" disabled={save.isPending} onClick={onClose}>
            إلغاء
          </button>
          <button className="button primary" disabled={save.isPending || !name.trim()}>
            حفظ
          </button>
        </footer>
      </form>
    </Modal>
  );
}
