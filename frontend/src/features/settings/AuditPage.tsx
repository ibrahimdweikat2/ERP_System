import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { api, type Page } from "../../lib/api/client";
import {
  DataTable,
  Filters,
  Pagination,
} from "../../components/data-table/DataTable";
import {
  Badge,
  ErrorNotice,
  Loading,
  Modal,
} from "../../components/ui/Primitives";
type Audit = {
  id: number;
  action: string;
  entity_type: string;
  entity_id: string | null;
  actor: { name: string } | null;
  occurred_at: string;
  before_json: unknown;
  after_json: unknown;
  ip_address: string | null;
};
const labels: Record<string, string> = {
  "auth.login": "تسجيل دخول",
  "auth.logout": "تسجيل خروج",
  "auth.login_failed": "محاولة دخول غير ناجحة",
  "users.created": "إضافة مستخدم",
  "users.updated": "تعديل مستخدم",
  "roles.saved": "حفظ دور",
  "auth.password_reset": "إعادة تعيين كلمة المرور",
  "users.owner_provisioned": "إنشاء حساب المالك",
  "auth.login_blocked": "دخول مرفوض (شركة موقوفة)",
  "company.created": "إنشاء الشركة",
  "platform.company_created": "إنشاء شركة",
  "platform.company_updated": "تعديل شركة",
  "platform.owner_created": "إنشاء مالك لشركة",
  "platform.superadmin_provisioned": "إنشاء مدير المنصة",
};
// The same trail for a company ("audit-logs") or for the platform's own actions ("platform/audit-logs").
export function AuditPage({
  endpoint = "audit-logs",
  section = "الإدارة / الرقابة",
}: {
  endpoint?: string;
  section?: string;
}) {
  const [action, setAction] = useState("");
  const [page, setPage] = useState(1);
  const [detail, setDetail] = useState<Audit | null>(null);
  const q = useQuery({
    queryKey: ["audit", endpoint, action, page],
    queryFn: ({ signal }) =>
      api<Page<Audit>>(
        `${endpoint}?action=${encodeURIComponent(action)}&page=${page}`,
        { signal },
      ),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">{section}</p>
          <h1>سجل التدقيق</h1>
          <p>تاريخ العمليات مع المستخدم والوقت والتغييرات المسجلة.</p>
        </div>
        <Badge>سجل محفوظ لا يقبل التعديل</Badge>
      </header>
      <section className="panel">
        <Filters
          value={action}
          onChange={(v) => {
            setAction(v);
            setPage(1);
          }}
          placeholder="تصفية برمز العملية، مثال: auth.login"
        />
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            columns={[
              {
                key: "time",
                label: "الوقت",
                render: (a) => (
                  <bdi>{new Date(a.occurred_at).toLocaleString("ar-PS")}</bdi>
                ),
              },
              {
                key: "actor",
                label: "المستخدم",
                render: (a) => a.actor?.name ?? "إدارة المنصة / النظام",
              },
              {
                key: "action",
                label: "العملية",
                render: (a) => <span>{labels[a.action] ?? a.action}</span>,
              },
              {
                key: "entity",
                label: "السجل",
                render: (a) => (
                  <bdi className="mono">
                    {a.entity_type} #{a.entity_id ?? "—"}
                  </bdi>
                ),
              },
              {
                key: "details",
                label: "التفاصيل",
                render: (a) => (
                  <button className="text-link" onClick={() => setDetail(a)}>
                    عرض التغييرات
                  </button>
                ),
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
      {detail && (
        <Modal title="تفاصيل العملية" onClose={() => setDetail(null)}>
          <dl className="audit-detail">
            <dt>العملية</dt>
            <dd>
              <bdi>{detail.action}</bdi>
            </dd>
            <dt>عنوان الاتصال</dt>
            <dd>
              <bdi>{detail.ip_address ?? "—"}</bdi>
            </dd>
          </dl>
          <h3>قبل التغيير</h3>
          <pre dir="ltr">{JSON.stringify(detail.before_json, null, 2)}</pre>
          <h3>بعد التغيير</h3>
          <pre dir="ltr">{JSON.stringify(detail.after_json, null, 2)}</pre>
        </Modal>
      )}
    </>
  );
}
