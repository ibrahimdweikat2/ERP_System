import { useState } from "react";
import { Link, NavLink, Outlet, useLocation } from "react-router-dom";
import { Building2, LogOut, Menu, PanelRightClose, ShieldCheck } from "lucide-react";
import { useAuth } from "../../lib/auth/context";
import { useLogout } from "../../lib/auth/useLogout";
import { useBrandHead } from "../../lib/hooks/useBrandHead";
import { ErrorNotice } from "../../components/ui/Primitives";

const items = [
  { label: "الشركات", path: "/platform", end: true },
  { label: "المهام والنسخ الاحتياطية", path: "/platform/operations" },
  { label: "حالة النظام", path: "/platform/health" },
  { label: "سجل تدقيق المنصة", path: "/platform/audit" },
  { label: "أمان الحساب", path: "/platform/security" },
];

/** Layout of the superadmin area: platform tools only, no company menus. */
export function PlatformShell() {
  const { user } = useAuth();
  const { logout, pending, error } = useLogout();
  const location = useLocation();
  const [open, setOpen] = useState(false);
  useBrandHead("إدارة المنصة", null);
  return (
    <div className="erp-layout">
      <aside className={"sidebar " + (open ? "mobile-open" : "")}>
        <div className="sidebar-brand">
          <Link to="/platform" className="brand">
            <span className="brand-mark">
              <Building2 size={23} />
            </span>
            <span className="brand-name">
              دفتر
              <small>إدارة المنصة والشركات</small>
            </span>
          </Link>
          <button
            className="icon-button mobile-only"
            aria-label="إغلاق القائمة"
            onClick={() => setOpen(false)}
          >
            <PanelRightClose size={20} />
          </button>
        </div>
        <nav aria-label="تنقل المنصة">
          <div className="nav-items">
            {items.map((i) => (
              <NavLink
                key={i.path}
                to={i.path}
                end={i.end}
                onClick={() => setOpen(false)}
                className={({ isActive }) =>
                  isActive ? "nav-item active" : "nav-item"
                }
              >
                {i.label}
              </NavLink>
            ))}
          </div>
        </nav>
        <div className="sidebar-footer">
          <ShieldCheck size={15} />
          حساب مدير المنصة
        </div>
      </aside>
      <div className="workspace">
        <header className="topbar">
          <div className="topbar-context">
            <button
              className="icon-button mobile-only"
              aria-label="فتح القائمة"
              onClick={() => setOpen(true)}
            >
              <Menu size={21} />
            </button>
            <span>
              المنصة <span className="slash">/</span> الإدارة
            </span>
          </div>
          <div className="user-controls">
            <div className="user-avatar">{user?.name.slice(0, 1)}</div>
            <span className="user-name">
              {user?.name}
              <small>مدير المنصة</small>
            </span>
            <button
              className="icon-button"
              aria-label="تسجيل الخروج"
              disabled={pending}
              onClick={() => void logout()}
            >
              <LogOut size={18} />
            </button>
          </div>
        </header>
        <main className="page-content">
          <ErrorNotice error={error} />
          <Outlet key={location.pathname} />
        </main>
        <footer className="workspace-footer">
          <span>دفتر · منصة متعددة الشركات</span>
        </footer>
      </div>
    </div>
  );
}
