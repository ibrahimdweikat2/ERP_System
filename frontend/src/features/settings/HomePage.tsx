import { Link, Navigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { api, type ApiEnvelope } from "../../lib/api/client";
import {
  ArrowLeft,
  BookOpenCheck,
  Users,
  ListChecks,
  LockKeyhole,
} from "lucide-react";
import { useAuth } from "../../lib/auth/context";
import { Badge, Loading, ErrorNotice } from "../../components/ui/Primitives";
export function HomePage() {
  const { user, can } = useAuth();
  const settings = useQuery({
    queryKey: ["store-settings"],
    queryFn: () => api<ApiEnvelope<{ trade_name: string }>>("store/settings"),
    enabled: can("settings.manage"),
  });
  if (can("settings.manage") && settings.isPending) return <Loading />;
  if (settings.error) return <ErrorNotice error={settings.error} />;
  if (can("settings.manage") && settings.data && !settings.data.data.trade_name)
    return <Navigate to="/admin/setup" replace />;
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">مساحة العمل</p>
          <h1>مرحباً، {user?.name}</h1>
          <p>إدارة الوصول ومتابعة سجل المتجر من مكان واحد.</p>
        </div>
        <Badge tone="good">جلسة آمنة</Badge>
      </header>
      <section className="welcome-panel">
        <div className="welcome-symbol">
          <BookOpenCheck size={37} />
        </div>
        <div>
          <h2>لنبدأ بسجل منظم</h2>
          <p>حسابك جاهز. أضف فريق المتجر وحدد صلاحياته قبل بدء العمليات.</p>
        </div>
        <span className="line-pattern" aria-hidden="true" />
      </section>
      <div className="section-heading">
        <h2>أدوات الإدارة</h2>
        <span>الوصول حسب صلاحيات حسابك</span>
      </div>
      <div className="quick-actions">
        {[
          {
            p: "users.manage" as const,
            to: "/admin/users",
            title: "فريق المتجر",
            desc: "إضافة المستخدمين وتحديد أدوار العمل.",
            icon: Users,
          },
          {
            p: "roles.manage" as const,
            to: "/admin/roles",
            title: "الأدوار والصلاحيات",
            desc: "تحديد العمليات المتاحة لكل دور.",
            icon: LockKeyhole,
          },
          {
            p: "audit.view" as const,
            to: "/admin/audit",
            title: "سجل التدقيق",
            desc: "تتبع من نفّذ العملية ومتى.",
            icon: ListChecks,
          },
        ]
          .filter((x) => can(x.p))
          .map((x) => (
            <Link key={x.to} to={x.to} className="quick-action">
              <x.icon size={22} />
              <div>
                <h3>{x.title}</h3>
                <p>{x.desc}</p>
              </div>
              <ArrowLeft size={18} />
            </Link>
          ))}
      </div>
      <section className="panel">
        <div className="panel-heading">
          <h2>العمليات المالية</h2>
          <Badge tone="warning">غير مفعّلة بعد</Badge>
        </div>
        <div className="operational-note">
          <p>
            ستظهر بيانات المبيعات والمخزون والأقساط بعد اكتمال إعداد المتجر
            وتفعيل العمليات.
          </p>
          <p className="muted">
            لا توجد أرقام مالية أو أرصدة افتتاحية مسجلة في هذه المرحلة.
          </p>
        </div>
      </section>
    </>
  );
}
