import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import type { PermissionName } from "../../types/identity";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useParams } from "react-router-dom";
import { Pencil, Plus } from "lucide-react";
import {
  DeleteDialog,
  DeleteIconButton,
} from "../../components/ui/DeleteAction";
import { useAuth } from "../../lib/auth/context";
import { api, allPages, type Page } from "../../lib/api/client";
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
import {
  masterDefinitions,
  valueLabels,
  type MasterDefinition,
  type MasterField,
  type MasterRow,
  type MasterValue,
} from "./masterDefinitions";
export function MasterPage({
  kind: fixedKind,
  writePermission = "settings.manage",
  section = "المحاسبة / الإعداد",
}: {
  kind?: string;
  writePermission?: PermissionName;
  section?: string;
}) {
  const params = useParams();
  const kind = fixedKind ?? params.kind ?? "";
  const def = masterDefinitions[kind];
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<MasterRow | null | undefined>();
  const [deleting, setDeleting] = useState<MasterRow>();
  const client = useQueryClient();
  const { can } = useAuth();
  const q = useQuery({
    queryKey: ["master", kind, search, page],
    queryFn: ({ signal }) =>
      api<Page<MasterRow>>(
        `${kind}?search=${encodeURIComponent(search)}&page=${page}`,
        { signal },
      ),
    enabled: !!def,
  });
  if (!def) return <p>الصفحة غير موجودة.</p>;
  const rows = (q.data?.data ?? []).map((r) => ({
    ...r,
    id: r.id ?? r.code ?? "",
  }));
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">{section}</p>
          <h1>{def.title}</h1>
          <p>{def.description}</p>
        </div>
        {can(writePermission) && (
          <button className="button primary" onClick={() => setEditing(null)}>
            <Plus size={18} />
            إضافة سجل
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
        />
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={rows}
            columns={[
              ...def.columns.map((key) => ({
                key,
                label:
                  def.fields.find((f) => f.key === key)?.label ??
                  (key === "status" ? "الحالة" : key),
                render: (r: MasterRow) =>
                  typeof r[key] === "boolean" ? (
                    <Badge tone={r[key] ? "good" : "neutral"}>
                      {r[key] ? "نعم" : "لا"}
                    </Badge>
                  ) : (
                    <bdi>
                      {valueLabels[String(r[key])] ?? String(r[key] ?? "—")}
                    </bdi>
                  ),
              })),
              {
                key: "edit",
                label: "الإجراءات",
                render: (r: MasterRow) =>
                  can(writePermission) && !def.readonly ? (
                    <span className="row-actions">
                      <button
                        type="button"
                        className="icon-button"
                        title="تعديل"
                        aria-label={`تعديل ${rowName(r)}`}
                        onClick={() => setEditing(r)}
                      >
                        <Pencil size={16} />
                      </button>
                      {/* Exchange rates are an immutable audit record; they are never deleted. */}
                      {kind !== "exchange-rates" && (
                        <DeleteIconButton
                          label={rowName(r)}
                          onClick={() => setDeleting(r)}
                        />
                      )}
                    </span>
                  ) : null,
              },
            ]}
          />
        )}
        <Pagination
          page={page}
          last={q.data?.last_page ?? 1}
          total={q.data?.total ?? 0}
          onPage={setPage}
        />
      </section>
      {deleting && (
        <DeleteDialog
          title={`حذف من ${def.title}`}
          name={rowName(deleting)}
          note="يُحذف السجل فقط إن لم يكن مستخدماً؛ السجل المستخدم يمكن إيقافه بدلاً من حذفه."
          endpoint={`${kind}/${encodeURIComponent(String(deleting.id))}`}
          onClose={() => setDeleting(undefined)}
        />
      )}
      {editing !== undefined && (
        <MasterEditor
          key={kind + String(editing?.id ?? "new")}
          kind={kind}
          definition={def}
          row={editing}
          onClose={() => setEditing(undefined)}
          onSaved={async () => {
            await client.invalidateQueries({ queryKey: ["master"] });
            setEditing(undefined);
          }}
        />
      )}
    </>
  );
}
function rowName(r: MasterRow): string {
  return String(r.name_ar ?? r.name ?? r.code ?? r.id ?? "");
}
function MasterEditor({
  kind,
  definition,
  row,
  onClose,
  onSaved,
}: {
  kind: string;
  definition: MasterDefinition;
  row: MasterRow | null;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [values, setValues] = useState<Record<string, MasterValue>>(() =>
    Object.fromEntries(
      definition.fields.map((f) => [
        f.key,
        row?.[f.key] ?? f.default ?? (f.type === "boolean" ? false : ""),
      ]),
    ),
  );
  const save = useMutation({
    mutationFn: () =>
      api(row ? `${kind}/${row.id ?? row.code}` : kind, {
        method: row ? "PUT" : "POST",
        body: Object.fromEntries(
          definition.fields.map((f) => [
            f.key,
            values[f.key] === "" && !f.required ? null : values[f.key],
          ]),
        ),
      }),
    onSuccess: onSaved,
  });
  return (
    <Modal
      title={(row ? "تعديل: " : "إضافة: ") + definition.title}
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
          {definition.fields.map((f) => (
            <MasterInput
              key={f.key}
              field={f}
              value={values[f.key]}
              onChange={(value) => setValues((v) => ({ ...v, [f.key]: value }))}
            />
          ))}
        </div>
        <ErrorNotice error={save.error} />
        <footer className="form-actions">
          <button className="button primary" disabled={save.isPending}>
            {save.isPending ? "جارٍ الحفظ…" : "حفظ السجل"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}
export function MasterInput({
  field: f,
  value,
  onChange,
}: {
  field: MasterField;
  value: MasterValue;
  onChange: (v: MasterValue) => void;
}) {
  const ref = useQuery({
    queryKey: ["master-options", f.source],
    queryFn: ({ signal }) => allPages<MasterRow>(f.source!, signal),
    enabled: !!f.source,
  });
  if (f.type === "boolean")
    return (
      <label className="checkbox-inline">
        <input
          type="checkbox"
          checked={!!value}
          onChange={(e) => onChange(e.target.checked)}
        />
        {f.label}
      </label>
    );
  if (f.type === "select")
    return (
      <label className="field">
        <span>{f.label}</span>
        <SearchableSelect
          required={f.required}
          value={String(value ?? "")}
          onChange={(e) =>
            onChange(
              f.key.endsWith("_id") && e.target.value
                ? Number(e.target.value)
                : e.target.value,
            )
          }
        >
          <option value="">اختر…</option>
          {(
            f.options ??
            ref.data?.map((r) => ({
              value: String(
                r.code && f.source === "currencies" ? r.code : r.id,
              ),
              label: String(r.name_ar ?? r.name ?? r.code),
            })) ??
            []
          ).map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </SearchableSelect>
        <ErrorNotice error={ref.error} />
      </label>
    );
  return (
    <Field
      label={f.label}
      type={
        f.type === "number" ? "number" : f.type === "date" ? "date" : "text"
      }
      inputMode={f.type === "money" ? "decimal" : undefined}
      required={f.required}
      value={String(value ?? "")}
      min={f.min}
      max={f.max}
      onChange={(e) =>
        onChange(f.type === "number" ? Number(e.target.value) : e.target.value)
      }
    />
  );
}
