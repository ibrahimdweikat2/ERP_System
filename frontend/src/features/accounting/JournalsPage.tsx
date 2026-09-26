import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Decimal from "decimal.js";
import { Plus, Trash2, Printer } from "lucide-react";
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
  Empty,
  ErrorNotice,
  Field,
  Loading,
  Modal,
} from "../../components/ui/Primitives";
import { ApprovalDialog } from "../../components/forms/ApprovalDialog";
import type { MasterRow } from "./masterDefinitions";
type JournalLine = {
  id: number;
  account_id: number;
  account: { code: string; name_ar: string };
  debit: string;
  credit: string;
  foreign_amount: string;
  memo: string | null;
};
export type Entry = {
  id: number;
  entry_no: string | null;
  entry_date: string;
  description: string;
  status: "draft" | "posted";
  currency: string;
  exchange_rate: string;
  journal_id: number;
  reference_type: string;
  reversal_id: number | null;
  reversal_of_id: number | null;
  lines?: JournalLine[];
};
export function JournalsPage() {
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [editor, setEditor] = useState<Entry | null | undefined>();
  const [selected, setSelected] = useState<number | null>(null);
  const { can } = useAuth();
  const client = useQueryClient();
  const q = useQuery({
    queryKey: ["entries", status, search, page],
    queryFn: () =>
      api<Page<Entry>>(
        `journal-entries?status=${status}&search=${encodeURIComponent(search)}&page=${page}`,
      ),
  });
  const refresh = async () => {
    await client.invalidateQueries({ queryKey: ["entries"] });
    await client.invalidateQueries({ queryKey: ["entry"] });
  };
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المحاسبة / الأستاذ</p>
          <h1>القيود اليومية</h1>
          <p>مسودات قابلة للمراجعة، وقيود مرحّلة محفوظة بعكس مستقل.</p>
        </div>
        {can("accounting.journal_create") && (
          <button className="button primary" onClick={() => setEditor(null)}>
            <Plus size={18} />
            قيد يدوي جديد
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
          placeholder="رقم القيد أو البيان"
        >
          <label className="field compact">
            <span>حالة القيد</span>
            <SearchableSelect
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">كل القيود</option>
              <option value="draft">مسودات</option>
              <option value="posted">مرحّلة</option>
            </SearchableSelect>
          </label>
        </Filters>
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            empty={
              <Empty title="لا توجد قيود بعد">
                ابدأ بقيد يدوي بعد إعداد السنة المالية والحسابات.
              </Empty>
            }
            columns={[
              {
                key: "no",
                label: "رقم القيد",
                render: (e) => (
                  <button
                    className="text-link mono"
                    onClick={() => setSelected(e.id)}
                  >
                    {e.entry_no ?? "مسودة #" + e.id}
                  </button>
                ),
              },
              {
                key: "date",
                label: "التاريخ",
                render: (e) => <bdi>{e.entry_date}</bdi>,
              },
              {
                key: "description",
                label: "البيان",
                render: (e) => e.description,
              },
              { key: "currency", label: "العملة", render: (e) => e.currency },
              {
                key: "status",
                label: "الحالة",
                render: (e) => (
                  <Badge
                    tone={
                      e.reversal_id
                        ? "warning"
                        : e.status === "posted"
                          ? "good"
                          : "neutral"
                    }
                  >
                    {e.reversal_id
                      ? "تم عكسه"
                      : e.reversal_of_id
                        ? "قيد عكس"
                        : e.status === "posted"
                          ? "مرحّل"
                          : "مسودة"}
                  </Badge>
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
      {selected && (
        <EntryDetail
          id={selected}
          onClose={() => setSelected(null)}
          onChange={refresh}
          onEdit={(e) => {
            setSelected(null);
            setEditor(e);
          }}
          onSelect={setSelected}
        />
      )}
      {editor !== undefined && (
        <EntryEditor
          entry={editor}
          onClose={() => setEditor(undefined)}
          onSaved={async (id) => {
            await refresh();
            setEditor(undefined);
            setSelected(id);
          }}
        />
      )}
    </>
  );
}
function EntryEditor({
  entry,
  onClose,
  onSaved,
}: {
  entry: Entry | null;
  onClose: () => void;
  onSaved: (id: number) => Promise<void>;
}) {
  const [date, setDate] = useState(
    entry?.entry_date ?? new Date().toLocaleDateString("en-CA"),
  );
  const [description, setDescription] = useState(entry?.description ?? "");
  const [currency, setCurrency] = useState(entry?.currency ?? "ILS");
  const [key] = useState(() => crypto.randomUUID());
  const [lines, setLines] = useState(
    () =>
      entry?.lines?.map((l) => ({
        key: crypto.randomUUID(),
        account_id: String(l.account_id),
        debit: new Decimal(l.foreign_amount).greaterThan(0)
          ? l.foreign_amount
          : "0",
        credit: new Decimal(l.foreign_amount).lessThan(0)
          ? new Decimal(l.foreign_amount).abs().toFixed(4)
          : "0",
        memo: l.memo ?? "",
      })) ?? [blankLine(), blankLine()],
  );
  const accounts = useQuery({
    queryKey: ["master-options", "accounts"],
    queryFn: () => allPages<MasterRow>("accounts"),
  });
  const currencies = useQuery({
    queryKey: ["master-options", "currencies"],
    queryFn: () => allPages<MasterRow>("currencies"),
  });
  const journals = useQuery({
    queryKey: ["master-options", "journals"],
    queryFn: () => allPages<MasterRow>("journals"),
  });
  const general = journals.data?.find((j) => j.code === "general");
  const save = useMutation({
    mutationFn: () =>
      api<ApiEnvelope<Entry>>(
        entry ? `journal-entries/${entry.id}` : "journal-entries",
        {
          method: entry ? "PUT" : "POST",
          key,
          body: {
            journal_id: Number(general?.id),
            entry_date: date,
            description,
            currency,
            lines: lines.map((l) => ({
              account_id: Number(l.account_id),
              debit: l.debit || "0",
              credit: l.credit || "0",
              memo: l.memo || null,
            })),
          },
        },
      ),
    onSuccess: (r) => onSaved(r.data.id),
  });
  const totals = lines.reduce(
    (sum, l) => {
      try {
        return {
          debit: sum.debit.plus(l.debit || 0),
          credit: sum.credit.plus(l.credit || 0),
        };
      } catch {
        return sum;
      }
    },
    { debit: new Decimal(0), credit: new Decimal(0) },
  );
  return (
    <Modal
      title={entry ? "تعديل مسودة القيد" : "قيد يدوي جديد"}
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
            label="تاريخ القيد"
            type="date"
            required
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
          <label className="field">
            <span>العملة</span>
            <SearchableSelect
              value={currency}
              required
              onChange={(e) => setCurrency(e.target.value)}
            >
              {currencies.data
                ?.filter((c) => c.is_active)
                .map((c) => (
                  <option key={c.code} value={c.code}>
                    {String(c.name)}
                  </option>
                ))}
            </SearchableSelect>
          </label>
        </div>
        <Field
          label="بيان القيد"
          required
          value={description}
          maxLength={1000}
          onChange={(e) => setDescription(e.target.value)}
        />
        <p className="muted small">
          المبالغ بعملة القيد. يُحفظ سعر الصرف حسب التاريخ، ويُشترط التوازن
          بالعملة الأساسية عند الترحيل.
        </p>
        <div className="journal-lines">
          {lines.map((l, i) => (
            <div className="journal-edit-line" key={l.key}>
              <label className="field">
                <span>الحساب {i + 1}</span>
                <SearchableSelect
                  required
                  value={l.account_id}
                  onChange={(e) =>
                    setLines((rows) =>
                      rows.map((r) =>
                        r.key === l.key
                          ? { ...r, account_id: e.target.value }
                          : r,
                      ),
                    )
                  }
                >
                  <option value="">اختر الحساب</option>
                  {accounts.data
                    ?.filter(
                      (a) =>
                        a.active &&
                        !a.is_control_account &&
                        a.allow_manual_posting,
                    )
                    .map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.code} — {String(a.name_ar)}
                      </option>
                    ))}
                </SearchableSelect>
              </label>
              {(["debit", "credit"] as const).map((side) => (
                <Field
                  key={side}
                  label={side === "debit" ? "مدين" : "دائن"}
                  value={l[side]}
                  required
                  dir="ltr"
                  inputMode="decimal"
                  pattern="[0-9]+([.][0-9]{1,4})?"
                  onChange={(e) =>
                    setLines((rows) =>
                      rows.map((r) =>
                        r.key === l.key ? { ...r, [side]: e.target.value } : r,
                      ),
                    )
                  }
                />
              ))}
              <button
                className="icon-button"
                type="button"
                aria-label={"حذف السطر " + (i + 1)}
                disabled={lines.length <= 2}
                onClick={() =>
                  setLines((rows) => rows.filter((r) => r.key !== l.key))
                }
              >
                <Trash2 size={16} />
              </button>
            </div>
          ))}
        </div>
        <button
          className="button"
          type="button"
          disabled={lines.length >= 100}
          onClick={() => setLines((rows) => [...rows, blankLine()])}
        >
          <Plus size={16} />
          إضافة سطر
        </button>
        <div className="journal-totals">
          <span>
            مدين <bdi>{totals.debit.toFixed(4)}</bdi>
          </span>
          <span>
            دائن <bdi>{totals.credit.toFixed(4)}</bdi>
          </span>
          <Badge tone={totals.debit.equals(totals.credit) ? "good" : "warning"}>
            {totals.debit.equals(totals.credit) ? "متوازن" : "غير متوازن"}
          </Badge>
        </div>
        <ErrorNotice
          error={
            save.error || accounts.error || currencies.error || journals.error
          }
        />
        <footer className="form-actions">
          <button
            className="button primary"
            disabled={save.isPending || !general}
          >
            حفظ المسودة
          </button>
        </footer>
      </form>
    </Modal>
  );
}
function blankLine() {
  return {
    key: crypto.randomUUID(),
    account_id: "",
    debit: "0",
    credit: "0",
    memo: "",
  };
}
function EntryDetail({
  id,
  onClose,
  onEdit,
  onChange,
  onSelect,
}: {
  id: number;
  onClose: () => void;
  onEdit: (e: Entry) => void;
  onChange: () => Promise<void>;
  onSelect: (id: number) => void;
}) {
  const q = useQuery({
    queryKey: ["entry", id],
    queryFn: () => api<ApiEnvelope<Entry>>(`journal-entries/${id}`),
  });
  const e = q.data?.data;
  const { can } = useAuth();
  const [reverse, setReverse] = useState(false);
  const [date, setDate] = useState(new Date().toLocaleDateString("en-CA"));
  const post = useMutation({
    mutationFn: () =>
      api(`journal-entries/${id}/post`, {
        method: "POST",
        key: crypto.randomUUID(),
      }),
    onSuccess: onChange,
  });
  return (
    <Modal title={e?.entry_no ?? "مسودة القيد"} onClose={onClose}>
      {q.isPending ? (
        <Loading />
      ) : (
        e && (
          <div className="journal-print-area">
            <div className="entry-meta">
              <h2>{e.entry_no ?? "مسودة #" + e.id}</h2>
              <Badge tone={e.status === "posted" ? "good" : "neutral"}>
                {e.status === "posted" ? "مرحّل" : "مسودة"}
              </Badge>
              <bdi>
                {e.entry_date} · {e.currency}
              </bdi>
              <p>{e.description}</p>
            </div>
            <DataTable
              rows={e.lines ?? []}
              columns={[
                {
                  key: "account",
                  label: "الحساب",
                  render: (l) => l.account.code + " — " + l.account.name_ar,
                },
                {
                  key: "debit",
                  label: "مدين بالعملة الأساسية",
                  render: (l) => <bdi>{l.debit}</bdi>,
                },
                {
                  key: "credit",
                  label: "دائن بالعملة الأساسية",
                  render: (l) => <bdi>{l.credit}</bdi>,
                },
              ]}
            />
            <p className="muted small">
              سعر الصرف المحفوظ: <bdi>{e.exchange_rate}</bdi>
            </p>
            {e.reversal_id && (
              <p className="notice">
                تم عكس هذا القيد.{" "}
                <button
                  className="text-link"
                  onClick={() => onSelect(e.reversal_id!)}
                >
                  عرض قيد العكس
                </button>
              </p>
            )}
            {e.reversal_of_id && (
              <button
                className="text-link"
                onClick={() => onSelect(e.reversal_of_id!)}
              >
                عرض القيد الأصلي
              </button>
            )}
            <ErrorNotice error={q.error || post.error} />
            <footer className="form-actions">
              <button
                className="button"
                onClick={() => {
                  document.body.classList.add("printing-journal");
                  window.print();
                  document.body.classList.remove("printing-journal");
                }}
              >
                <Printer size={16} />
                طباعة
              </button>
              {e.status === "draft" &&
                e.reference_type === "manual" &&
                can("accounting.journal_create") && (
                  <button className="button" onClick={() => onEdit(e)}>
                    تعديل المسودة
                  </button>
                )}
              {e.status === "draft" &&
                e.reference_type === "manual" &&
                can("accounting.post") && (
                  <button
                    className="button primary"
                    disabled={post.isPending}
                    onClick={() => post.mutate()}
                  >
                    {post.isPending ? "جارٍ الترحيل…" : "ترحيل القيد"}
                  </button>
                )}
              {e.status === "posted" &&
                e.reference_type === "manual" &&
                !e.reversal_id &&
                can("accounting.reverse") && (
                  <button className="button" onClick={() => setReverse(true)}>
                    عكس القيد
                  </button>
                )}
            </footer>
          </div>
        )
      )}
      {reverse && (
        <ApprovalDialog
          title="عكس القيد"
          onClose={() => setReverse(false)}
          onConfirm={async (reason) => {
            const result = await api<ApiEnvelope<Entry>>(
              `journal-entries/${id}/reverse`,
              {
                method: "POST",
                key: crypto.randomUUID(),
                body: { date, reason },
              },
            );
            await onChange();
            onSelect(result.data.id);
          }}
        >
          <p className="muted">
            سيُنشأ قيد مقابل ويحفظ القيد الأصلي دون تعديل.
          </p>
          <Field
            label="تاريخ العكس"
            type="date"
            required
            min={e?.entry_date}
            value={date}
            onChange={(event) => setDate(event.target.value)}
          />
        </ApprovalDialog>
      )}
    </Modal>
  );
}
