import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Pencil } from "lucide-react";
import { api, type ApiEnvelope } from "../../lib/api/client";
import type { Role } from "../../types/identity";
import { DataTable } from "../../components/data-table/DataTable";
import {
  ErrorNotice,
  Field,
  Loading,
  Modal,
  Badge,
} from "../../components/ui/Primitives";
import { useAuth } from "../../lib/auth/context";
type Permission = { id: number; name: string };
export function RolesPage() {
  const client = useQueryClient();
  const { user } = useAuth();
  const [editing, setEditing] = useState<Role | null | undefined>();
  const q = useQuery({
    queryKey: ["roles"],
    queryFn: () => api<ApiEnvelope<Role[]>>("roles"),
  });
  const p = useQuery({
    queryKey: ["permissions"],
    queryFn: () => api<ApiEnvelope<Permission[]>>("permissions"),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">الإدارة / الوصول</p>
          <h1>الأدوار والصلاحيات</h1>
          <p>حدد ما يستطيع كل دور الاطلاع عليه وتنفيذه.</p>
        </div>
        {user?.is_owner && (
          <button className="button primary" onClick={() => setEditing(null)}>
            <Plus size={18} />
            إضافة دور
          </button>
        )}
      </header>
      <section className="panel">
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            columns={[
              {
                key: "label",
                label: "الدور",
                render: (r) => <strong>{r.label}</strong>,
              },
              {
                key: "name",
                label: "الرمز",
                render: (r) => <bdi className="mono">{r.name}</bdi>,
              },
              {
                key: "count",
                label: "الصلاحيات",
                render: (r) =>
                  r.name === "owner" ? (
                    <Badge>كل الصلاحيات</Badge>
                  ) : (
                    `${r.permissions?.length ?? 0} صلاحية`
                  ),
              },
              {
                key: "edit",
                label: "الإجراءات",
                render: (r) =>
                  r.name === "owner" ? (
                    <span className="muted small">دور محمي</span>
                  ) : user?.is_owner ? (
                    <button
                      className="icon-button"
                      aria-label={"تعديل " + r.label}
                      onClick={() => setEditing(r)}
                    >
                      <Pencil size={17} />
                    </button>
                  ) : null,
              },
            ]}
          />
        )}
      </section>
      {editing !== undefined && (
        <RoleEditor
          role={editing}
          permissions={p.data?.data ?? []}
          onClose={() => setEditing(undefined)}
          onSaved={async () => {
            await client.invalidateQueries({ queryKey: ["roles"] });
            await client.invalidateQueries({ queryKey: ["me"] });
            setEditing(undefined);
          }}
        />
      )}
    </>
  );
}
function RoleEditor({
  role,
  permissions,
  onClose,
  onSaved,
}: {
  role: Role | null;
  permissions: Permission[];
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [name, setName] = useState(role?.name ?? "");
  const [label, setLabel] = useState(role?.label ?? "");
  const [selected, setSelected] = useState(
    role?.permissions?.map((p) => p.id) ?? [],
  );
  const save = useMutation({
    mutationFn: () =>
      api(role ? `roles/${role.id}` : "roles", {
        method: role ? "PUT" : "POST",
        body: { name, label, permission_ids: selected },
      }),
    onSuccess: onSaved,
  });
  const groups = [...new Set(permissions.map((p) => p.name.split(".")[0]))];
  return (
    <Modal
      title={role ? "تعديل الدور" : "إضافة دور"}
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
            label="اسم الدور"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            required
          />
          <Field
            label="الرمز بالإنجليزية"
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
            pattern="[a-z][a-z0-9_]{1,79}"
            dir="ltr"
          />
        </div>
        <div className="permission-grid">
          {groups.map((group) => (
            <fieldset key={group} className="checkbox-group">
              <legend>{group}</legend>
              {permissions
                .filter((p) => p.name.startsWith(group + "."))
                .map((p) => (
                  <label key={p.id}>
                    <input
                      type="checkbox"
                      checked={selected.includes(p.id)}
                      onChange={(e) =>
                        setSelected(
                          e.target.checked
                            ? [...selected, p.id]
                            : selected.filter((id) => id !== p.id),
                        )
                      }
                    />
                    <bdi className="mono">{p.name}</bdi>
                  </label>
                ))}
            </fieldset>
          ))}
        </div>
        <ErrorNotice error={save.error} />
        <footer className="form-actions">
          <button className="button primary" disabled={save.isPending}>
            {save.isPending ? "جارٍ الحفظ…" : "حفظ الدور"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}
