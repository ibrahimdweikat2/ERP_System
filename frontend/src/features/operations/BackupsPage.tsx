import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Download, Trash2 } from "lucide-react";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { DataTable } from "../../components/data-table/DataTable";
import { ErrorNotice, Loading } from "../../components/ui/Primitives";
import { DeleteDialog } from "../../components/ui/DeleteAction";
import { useAuth } from "../../lib/auth/context";
import { Heading, StateBadge, statusLabels, useCommand } from "./shared";

type Backup = {
  id: number;
  status: "queued" | "processing" | "completed" | "failed";
  file_name: string | null;
  size: number | null;
  checksum: string | null;
  error: string | null;
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
  requested_by_name: string | null;
};

const inProgress = (b: Backup) =>
  b.status === "queued" || b.status === "processing";

function fileSize(bytes: number | null) {
  if (bytes == null) return "—";
  return bytes < 1024 * 1024
    ? `${(bytes / 1024).toFixed(1)} KB`
    : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** The company's own backups: data of this company only, never other companies. */
export function BackupsPage() {
  const { user } = useAuth();
  const command = useCommand();
  const [deleting, setDeleting] = useState<Backup>();
  const list = useQuery({
    queryKey: ["company-backups"],
    queryFn: () => api<ApiEnvelope<Backup[]>>("backups"),
    refetchInterval: (q) =>
      q.state.data?.data.some(inProgress) ? 5000 : false,
  });
  const rows = list.data?.data ?? [];
  const busy = rows.some(inProgress);
  return (
    <>
      <Heading title="النسخ الاحتياطي" section="الإدارة">
        <button
          type="button"
          className="button primary"
          disabled={command.pending || busy}
          onClick={() => command.run("backups")}
        >
          {busy ? "جارٍ إنشاء النسخة…" : "إنشاء نسخة احتياطية الآن"}
        </button>
      </Heading>
      <ErrorNotice error={list.error ?? command.error} />
      <section className="panel ops-panel">
        <h2>نسخ بيانات {user?.company?.name ?? "الشركة"}</h2>
        <p>
          تشمل النسخة بيانات شركتك فقط بصيغة SQL، ولا تشمل بيانات أي شركة أخرى
          ولا المرفقات والصور. يحتوي الملف جميع بيانات الشركة بما فيها حسابات
          المستخدمين، فاحفظه بعد تحميله في مكان آمن.
        </p>
        {list.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={rows}
            empty="لا توجد نسخ احتياطية بعد."
            columns={[
              { key: "id", label: "النسخة", render: (r) => `#${r.id}` },
              { key: "created", label: "تاريخ الطلب", render: (r) => r.created_at },
              { key: "by", label: "بواسطة", render: (r) => r.requested_by_name ?? "—" },
              { key: "status", label: "الحالة", render: (r) => <StateBadge value={r.status} /> },
              { key: "done", label: "الانتهاء", render: (r) => r.completed_at ?? "—" },
              { key: "size", label: "الحجم", render: (r) => fileSize(r.size) },
              { key: "error", label: "ملاحظة", render: (r) => r.error || "—" },
              {
                key: "actions",
                label: "الإجراءات",
                render: (r) => (
                  <span className="row-actions">
                    {r.status === "completed" && (
                      <a
                        className="button secondary"
                        href={`/api/v1/backups/${r.id}/download`}
                      >
                        <Download size={16} />
                        تحميل
                      </a>
                    )}
                    {r.status !== "processing" && (
                      <button
                        type="button"
                        className="button danger-outline"
                        aria-label={`حذف النسخة #${r.id}`}
                        onClick={() => setDeleting(r)}
                      >
                        <Trash2 size={16} />
                        حذف
                      </button>
                    )}
                  </span>
                ),
              },
            ]}
          />
        )}
      </section>
      {deleting && (
        <DeleteDialog
          title={`حذف النسخة الاحتياطية #${deleting.id}`}
          name={deleting.file_name ?? `#${deleting.id}`}
          message={
            <DeleteWarning
              backup={deleting}
              last={
                deleting.status === "completed" &&
                rows.filter((r) => r.status === "completed").length === 1
              }
            />
          }
          endpoint={`backups/${deleting.id}`}
          onClose={() => setDeleting(undefined)}
        />
      )}
    </>
  );
}

/** What deleting this backup means, so the owner decides knowingly. */
function DeleteWarning({ backup, last }: { backup: Backup; last: boolean }) {
  if (backup.status !== "completed") {
    return (
      <div className="notice warning" role="alert">
        <p>
          سيُحذف سجل النسخة <strong>#{backup.id}</strong> (
          {statusLabels[backup.status] ?? backup.status}).
        </p>
        <ul>
          {backup.status === "queued" && <li>سيُلغى طلب إنشاء هذه النسخة.</li>}
          <li>لا يوجد ملف مكتمل لهذه النسخة، فلا تُفقد أي نسخة احتياطية.</li>
          <li>لا تتأثر بيانات الشركة الحالية.</li>
          <li>تُسجَّل عملية الحذف واسم المستخدم في سجل التدقيق.</li>
        </ul>
      </div>
    );
  }
  return (
    <div className="notice warning" role="alert">
      <p>
        سيُحذف ملف النسخة <strong>{backup.file_name}</strong>
        {backup.completed_at && <> (تاريخ {backup.completed_at})</>} نهائياً من
        الخادم، ولا يمكن التراجع عن هذه العملية.
      </p>
      <p>
        <strong>ما يترتب على الحذف:</strong>
      </p>
      <ul>
        <li>
          لن يمكن تحميل هذه النسخة لاحقاً، ولا استعادة بيانات الشركة إلى
          تاريخها.
        </li>
        {last && (
          <li>
            <strong>
              هذه آخر نسخة مكتملة؛ بعد حذفها لن تبقى للشركة أي نسخة احتياطية على
              الخادم. حمّلها أولاً أو أنشئ نسخة جديدة.
            </strong>
          </li>
        )}
        <li>
          لا تتأثر بيانات الشركة الحالية (الفواتير، المخزون، الحسابات، العملاء)
          بالحذف.
        </li>
        <li>أي نسخة سبق تحميلها على جهازك تبقى صالحة.</li>
        <li>تُسجَّل عملية الحذف واسم المستخدم في سجل التدقيق.</li>
      </ul>
    </div>
  );
}
