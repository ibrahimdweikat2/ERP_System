import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Pencil } from "lucide-react";
import { api, type ApiEnvelope, type Page } from "../../lib/api/client";
import type { User, Role } from "../../types/identity";
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
export function UsersPage() {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<User | null | undefined>();
  const client = useQueryClient();
  const q = useQuery({
    queryKey: ["users", search, page],
    queryFn: ({ signal }) =>
      api<Page<User>>(
        `users?search=${encodeURIComponent(search)}&page=${page}`,
        { signal },
      ),
  });
  const roles = useQuery({
    queryKey: ["role-options"],
    queryFn: () => api<ApiEnvelope<Role[]>>("role-options"),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">الإدارة / الوصول</p>
          <h1>فريق المتجر</h1>
          <p>حساب مستقل وصلاحيات واضحة لكل مستخدم.</p>
        </div>
        <button className="button primary" onClick={() => setEditing(null)}>
          <Plus size={18} />
          إضافة مستخدم
        </button>
      </header>
      <section className="panel">
        <Filters
          value={search}
          onChange={(v) => {
            setSearch(v);
            setPage(1);
          }}
          placeholder="ابحث بالاسم أو البريد الإلكتروني"
        />
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            columns={[
              {
                key: "name",
                label: "المستخدم",
                render: (u) => <strong>{u.name}</strong>,
              },
              {
                key: "email",
                label: "البريد الإلكتروني",
                render: (u) => <bdi>{u.email}</bdi>,
              },
              {
                key: "roles",
                label: "دور العمل",
                render: (u) => u.roles.map((r) => r.label).join("، "),
              },
              {
                key: "status",
                label: "الحالة",
                render: (u) => (
                  <Badge tone={u.status === "active" ? "good" : "danger"}>
                    {u.status === "active" ? "نشط" : "معطّل"}
                  </Badge>
                ),
              },
              {
                key: "actions",
                label: "الإجراءات",
                render: (u) => (
                  <button
                    className="icon-button"
                    aria-label={"تعديل " + u.name}
                    onClick={() => setEditing(u)}
                  >
                    <Pencil size={17} />
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
        <UserEditor
          user={editing}
          roles={roles.data?.data ?? []}
          onClose={() => setEditing(undefined)}
          onSaved={async () => {
            await client.invalidateQueries({ queryKey: ["users"] });
            await client.invalidateQueries({ queryKey: ["me"] });
            setEditing(undefined);
          }}
        />
      )}
    </>
  );
}
function UserEditor({
  user,
  roles,
  onClose,
  onSaved,
}: {
  user: User | null;
  roles: Role[];
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [name, setName] = useState(user?.name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [phone, setPhone] = useState(user?.phone ?? "");
  const [password, setPassword] = useState("");
  const [status, setStatus] = useState(user?.status ?? "active");
  const [mfaRequired, setMfaRequired] = useState(user?.mfa_required ?? false);
  const [selected, setSelected] = useState(user?.roles.map((r) => r.id) ?? []);
  const save = useMutation({
    mutationFn: () =>
      api(user ? `users/${user.id}` : "users", {
        method: user ? "PUT" : "POST",
        body: {
          name,
          email,
          phone: phone || null,
          status,
          mfa_required: mfaRequired,
          password: password || undefined,
          role_ids: selected,
        },
      }),
    onSuccess: onSaved,
  });
  return (
    <Modal
      title={user ? "تعديل المستخدم" : "إضافة مستخدم"}
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
          <Field
            label="الاسم الكامل"
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
            maxLength={120}
          />
          <Field
            label="البريد الإلكتروني"
            type="email"
            dir="ltr"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
          <Field
            label="رقم الهاتف"
            dir="ltr"
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
          />
          <Field
            label={user ? "كلمة مرور جديدة (اختياري)" : "كلمة المرور"}
            type="password"
            autoComplete="new-password"
            dir="ltr"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required={!user}
            minLength={12}
          />
        </div>
        <p className="muted small">
          12 حرفاً على الأقل، تتضمن أحرفاً كبيرة وصغيرة وأرقاماً.
        </p>
        <fieldset className="checkbox-group">
          <legend>أدوار العمل</legend>
          {roles.map((r) => (
            <label key={r.id}>
              <input
                type="checkbox"
                checked={selected.includes(r.id)}
                onChange={(e) =>
                  setSelected(
                    e.target.checked
                      ? [...selected, r.id]
                      : selected.filter((id) => id !== r.id),
                  )
                }
              />
              {r.label}
            </label>
          ))}
        </fieldset>
        <label className="field">
          <span>حالة الحساب</span>
          <SearchableSelect
            value={status}
            onChange={(e) => setStatus(e.target.value as User["status"])}
          >
            <option value="active">نشط</option>
            <option value="disabled">معطّل</option>
          </SearchableSelect>
        </label>
        <label className="checkbox-inline">
          <input
            type="checkbox"
            checked={mfaRequired}
            onChange={(e) => setMfaRequired(e.target.checked)}
          />
          إلزام المصادقة الثنائية عند تسجيل الدخول
          {user?.mfa_enabled
            ? " (مفعّلة)"
            : mfaRequired
              ? " (يُطلب إعدادها عند الدخول القادم)"
              : ""}
        </label>
        <ErrorNotice error={save.error} />
        <footer className="form-actions">
          <button
            className="button"
            type="button"
            disabled={save.isPending}
            onClick={onClose}
          >
            إلغاء
          </button>
          <button
            className="button primary"
            disabled={save.isPending || !selected.length}
          >
            {save.isPending ? "جارٍ الحفظ…" : "حفظ المستخدم"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}
