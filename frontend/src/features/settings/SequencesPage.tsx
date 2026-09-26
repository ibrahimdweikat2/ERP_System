import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { DataTable } from "../../components/data-table/DataTable";
import {
  ErrorNotice,
  Field,
  Loading,
  Modal,
} from "../../components/ui/Primitives";
type Sequence = {
  id: number;
  document_type: string;
  prefix: string;
  padding: number;
  next_number: number;
  reset_policy: "yearly" | "never";
};
export function SequencesPage() {
  const q = useQuery({
    queryKey: ["sequences"],
    queryFn: () => api<ApiEnvelope<Sequence[]>>("document-sequences"),
  });
  const [editing, setEditing] = useState<Sequence | null>(null);
  const client = useQueryClient();
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">الإدارة / المستندات</p>
          <h1>تسلسل المستندات</h1>
          <p>
            أرقام دائمة وفريدة للمستندات. يصبح التنسيق ثابتاً بعد إصدار أول رقم.
          </p>
        </div>
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
                key: "type",
                label: "نوع المستند",
                render: (s) => <bdi>{s.document_type}</bdi>,
              },
              {
                key: "prefix",
                label: "البادئة",
                render: (s) => <bdi className="mono">{s.prefix}</bdi>,
              },
              {
                key: "format",
                label: "التنسيق",
                render: (s) => (
                  <bdi className="mono">
                    {s.prefix}
                    {s.reset_policy === "yearly" ? "YYYY/" : ""}
                    {"N".repeat(s.padding)}
                  </bdi>
                ),
              },
              {
                key: "reset",
                label: "دورة الترقيم",
                render: (s) =>
                  s.reset_policy === "yearly" ? "سنوي" : "متواصل",
              },
              {
                key: "edit",
                label: "الإجراءات",
                render: (s) => (
                  <button className="text-link" onClick={() => setEditing(s)}>
                    تعديل التنسيق
                  </button>
                ),
              },
            ]}
          />
        )}
      </section>
      {editing && (
        <SequenceEditor
          sequence={editing}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            await client.invalidateQueries({ queryKey: ["sequences"] });
            setEditing(null);
          }}
        />
      )}
    </>
  );
}
function SequenceEditor({
  sequence,
  onClose,
  onSaved,
}: {
  sequence: Sequence;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [prefix, setPrefix] = useState(sequence.prefix);
  const [padding, setPadding] = useState(sequence.padding);
  const [start, setStart] = useState(sequence.next_number);
  const [reset, setReset] = useState(sequence.reset_policy);
  const save = useMutation({
    mutationFn: () =>
      api(`document-sequences/${sequence.id}`, {
        method: "PUT",
        body: { prefix, padding, next_number: start, reset_policy: reset },
      }),
    onSuccess: onSaved,
  });
  return (
    <Modal
      title="تنسيق رقم المستند"
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
        <Field
          label="البادئة"
          value={prefix}
          dir="ltr"
          required
          onChange={(e) => setPrefix(e.target.value)}
        />
        <div className="form-grid">
          <Field
            label="عدد خانات الرقم"
            type="number"
            min={3}
            max={12}
            required
            value={padding}
            onChange={(e) => setPadding(Number(e.target.value))}
          />
          <Field
            label="بداية الترقيم"
            type="number"
            min={1}
            max={999999999999}
            required
            value={start}
            onChange={(e) => setStart(Number(e.target.value))}
          />
        </div>
        <label className="field">
          <span>دورة الترقيم</span>
          <SearchableSelect
            value={reset}
            onChange={(e) =>
              setReset(e.target.value as Sequence["reset_policy"])
            }
          >
            <option value="yearly">سنوي، مع السنة في الرقم</option>
            <option value="never">متواصل</option>
          </SearchableSelect>
        </label>
        <ErrorNotice error={save.error} />
        <footer className="form-actions">
          <button className="button primary" disabled={save.isPending}>
            حفظ التنسيق
          </button>
        </footer>
      </form>
    </Modal>
  );
}
