import { useQuery } from "@tanstack/react-query";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { Badge, ErrorNotice, Loading } from "../../components/ui/Primitives";
type Health = {
  database: { engine: string; version: string; connected: boolean };
  queue: {
    connection: string;
    pending: number;
    failed: number;
    last_probe: { completed_at: string } | null;
  };
  timezone: string;
  phase: number;
};
export function HealthPage() {
  const q = useQuery({
    queryKey: ["health"],
    queryFn: () => api<ApiEnvelope<Health>>("platform/system/health"),
    refetchInterval: 30_000,
  });
  const h = q.data?.data;
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المنصة / التشغيل</p>
          <h1>حالة النظام</h1>
          <p>معلومات الاتصال والمهام الخلفية. يتم التحديث كل 30 ثانية.</p>
        </div>
        <button className="button" onClick={() => void q.refetch()}>
          تحديث الحالة
        </button>
      </header>
      <ErrorNotice error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : (
        h && (
          <section className="panel">
            <div className="panel-heading">
              <h2>البنية التشغيلية</h2>
              <Badge tone="good">متصل</Badge>
            </div>
            <table>
              <tbody>
                <tr>
                  <th>قاعدة البيانات</th>
                  <td>
                    <bdi>
                      {h.database.engine} {h.database.version}
                    </bdi>
                  </td>
                </tr>
                <tr>
                  <th>طابور المهام</th>
                  <td>
                    <bdi>{h.queue.connection}</bdi>
                  </td>
                </tr>
                <tr>
                  <th>مهام قيد الانتظار</th>
                  <td>{h.queue.pending}</td>
                </tr>
                <tr>
                  <th>مهام فاشلة</th>
                  <td>
                    <Badge tone={h.queue.failed ? "danger" : "good"}>
                      {h.queue.failed}
                    </Badge>
                  </td>
                </tr>
                <tr>
                  <th>آخر اختبار ناجح للعامل</th>
                  <td>
                    {h.queue.last_probe
                      ? new Date(
                          h.queue.last_probe.completed_at,
                        ).toLocaleString("ar-PS")
                      : "لم يكتمل اختبار بعد"}
                  </td>
                </tr>
                <tr>
                  <th>المنطقة الزمنية</th>
                  <td>
                    <bdi>{h.timezone}</bdi>
                  </td>
                </tr>
              </tbody>
            </table>
          </section>
        )
      )}
    </>
  );
}
