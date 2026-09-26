import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { api, type Page } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import { DataTable } from "../../components/data-table/DataTable";
import { Badge, ErrorNotice, Loading } from "../../components/ui/Primitives";
import { ApprovalDialog } from "../../components/forms/ApprovalDialog";
type Period = {
  id: number;
  period_no: number;
  starts_on: string;
  ends_on: string;
  status: "open" | "soft_closed" | "locked";
};
export function PeriodsPage() {
  const [action, setAction] = useState<{
    period: Period;
    status: Period["status"];
  } | null>(null);
  const client = useQueryClient();
  const { can } = useAuth();
  const q = useQuery({
    queryKey: ["periods"],
    queryFn: () => api<Page<Period>>("accounting-periods"),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المحاسبة / الرقابة</p>
          <h1>الفترات المالية</h1>
          <p>
            الإغلاق الأولي يوقف الترحيل حتى إعادة الفتح. الإقفال النهائي دائم.
          </p>
        </div>
        <Link className="button" to="/accounting/masters/fiscal-years">
          السنوات المالية
        </Link>
      </header>
      <section className="panel">
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            columns={[
              { key: "number", label: "الفترة", render: (p) => p.period_no },
              {
                key: "from",
                label: "من",
                render: (p) => <bdi>{p.starts_on}</bdi>,
              },
              {
                key: "to",
                label: "إلى",
                render: (p) => <bdi>{p.ends_on}</bdi>,
              },
              {
                key: "status",
                label: "الحالة",
                render: (p) => (
                  <Badge
                    tone={
                      p.status === "open"
                        ? "good"
                        : p.status === "locked"
                          ? "danger"
                          : "warning"
                    }
                  >
                    {p.status === "open"
                      ? "مفتوحة"
                      : p.status === "locked"
                        ? "مقفلة نهائياً"
                        : "إغلاق أولي"}
                  </Badge>
                ),
              },
              {
                key: "actions",
                label: "الإجراءات",
                render: (p) =>
                  can("accounting.period_lock") && p.status !== "locked" ? (
                    <div className="inline-actions">
                      {p.status === "soft_closed" ? (
                        <button
                          className="text-link"
                          onClick={() =>
                            setAction({ period: p, status: "open" })
                          }
                        >
                          إعادة الفتح
                        </button>
                      ) : (
                        <button
                          className="text-link"
                          onClick={() =>
                            setAction({ period: p, status: "soft_closed" })
                          }
                        >
                          إغلاق أولي
                        </button>
                      )}
                      <button
                        className="text-link"
                        onClick={() =>
                          setAction({ period: p, status: "locked" })
                        }
                      >
                        إقفال نهائي
                      </button>
                    </div>
                  ) : null,
              },
            ]}
          />
        )}
      </section>
      {action && (
        <ApprovalDialog
          title={
            action.status === "locked"
              ? "إقفال نهائي للفترة"
              : action.status === "open"
                ? "إعادة فتح الفترة"
                : "إغلاق أولي للفترة"
          }
          onClose={() => setAction(null)}
          onConfirm={async (reason) => {
            await api(`accounting-periods/${action.period.id}/lock`, {
              method: "POST",
              body: { status: action.status, reason },
            });
            await client.invalidateQueries({ queryKey: ["periods"] });
          }}
        >
          <p>
            {action.status === "locked"
              ? "لن تقبل هذه الفترة قيوداً جديدة أو عكس قيود داخلها. التصحيح يكون في فترة مفتوحة لاحقة."
              : "سيُسجل التغيير وسببه في سجل التدقيق."}
          </p>
          <p>
            <bdi>
              {action.period.starts_on} — {action.period.ends_on}
            </bdi>
          </p>
        </ApprovalDialog>
      )}
    </>
  );
}
