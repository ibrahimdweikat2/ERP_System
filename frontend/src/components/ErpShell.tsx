import { useState } from "react";
import { useBrandHead } from "../lib/hooks/useBrandHead";
import {
  Link,
  NavLink,
  Outlet,
  useLocation,
  useNavigate,
} from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  LayoutDashboard,
  ShoppingCart,
  CalendarClock,
  Landmark,
  Users,
  Package,
  Warehouse,
  Truck,
  BookOpen,
  ChartNoAxesCombined,
  Settings,
  Refrigerator,
  ChevronDown,
  LogOut,
  Menu,
  PanelRightClose,
} from "lucide-react";
import { useAuth } from "../lib/auth/context";
import { navigation } from "../app/router/navigation";
import { api } from "../lib/api/client";
import { ErrorNotice } from "./ui/Primitives";
const icons = {
  home: LayoutDashboard,
  sales: ShoppingCart,
  installments: CalendarClock,
  checks: Landmark,
  customers: Users,
  products: Package,
  inventory: Warehouse,
  purchasing: Truck,
  accounting: BookOpen,
  reports: ChartNoAxesCombined,
  admin: Settings,
};
export function ErpShell() {
  const { user, can } = useAuth();
  const location = useLocation();
  const client = useQueryClient();
  const store = useQuery({
    queryKey: ["store-context"],
    queryFn: () =>
      api<{
        data: {
          trade_name: string;
          base_currency: string;
          platform_name: string | null;
          logo_url: string | null;
        };
      }>("store/context"),
  });
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  // Accordion: one menu group open at a time, starting with the group of the current page.
  const groupOf = (path: string) =>
    navigation.find((g) =>
      g.items.some(
        (i) =>
          i.path && (i.path === "/" ? path === "/" : path.startsWith(i.path)),
      ),
    )?.label ?? null;
  const [openGroup, setOpenGroup] = useState<string | null>(() =>
    groupOf(location.pathname),
  );
  const [shownPath, setShownPath] = useState(location.pathname);
  if (shownPath !== location.pathname) {
    // Navigating from inside a page (not the menu) reveals that page's group.
    setShownPath(location.pathname);
    const group = groupOf(location.pathname);
    if (group) setOpenGroup(group);
  }
  const platformName = store.data?.data.platform_name || "دفتر";
  const logoUrl = store.data?.data.logo_url;
  useBrandHead(platformName, logoUrl);
  const [error, setError] = useState<unknown>();
  const [loggingOut, setLoggingOut] = useState(false);
  return (
    <div className="erp-layout">
      <aside className={"sidebar " + (open ? "mobile-open" : "")}>
        <div className="sidebar-brand">
          <Link to="/" className="brand">
            <span className={"brand-mark" + (logoUrl ? " has-logo" : "")}>
              {logoUrl ? (
                <img src={logoUrl} alt="" />
              ) : (
                <Refrigerator size={23} />
              )}
            </span>
            <span className="brand-name">
              {platformName}
              <small>إدارة متجر الأجهزة</small>
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
        <nav aria-label="التنقل الرئيسي">
          {navigation.map((g) => {
            const Icon = icons[g.icon as keyof typeof icons];
            const items = g.items.filter(
              (i) =>
                can(i.permission) ||
                (i.permission === "catalog.view" && can("catalog.manage")),
            );
            if (!items.length) return null;
            return (
              <details key={g.label} open={openGroup === g.label}>
                <summary
                  onClick={(e) => {
                    e.preventDefault();
                    setOpenGroup((current) =>
                      current === g.label ? null : g.label,
                    );
                  }}
                >
                  <Icon size={18} />
                  <span>{g.label}</span>
                  <ChevronDown size={14} />
                </summary>
                <div className="nav-items">
                  {items.map((i) =>
                    i.path ? (
                      <NavLink
                        key={i.label}
                        to={i.path}
                        end={i.path === "/"}
                        onClick={() => setOpen(false)}
                        className={({ isActive }) =>
                          isActive ? "nav-item active" : "nav-item"
                        }
                      >
                        {i.label}
                      </NavLink>
                    ) : (
                      <span
                        className="nav-item unavailable"
                        key={i.label}
                        aria-disabled="true"
                      >
                        {i.label}
                        <small>قريباً</small>
                      </span>
                    ),
                  )}
                </div>
              </details>
            );
          })}
        </nav>
        <div className="sidebar-footer">
          <Shield />
          سجل واضح لكل حركة
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
              المتجر <span className="slash">/</span> مساحة العمل
            </span>
          </div>
          <div className="user-controls">
            <div className="user-avatar">{user?.name.slice(0, 1)}</div>
            <span className="user-name">
              {user?.name}
              <small>{user?.roles.map((r) => r.label).join("، ")}</small>
            </span>
            <button
              className="icon-button"
              aria-label="تسجيل الخروج"
              disabled={loggingOut}
              onClick={async () => {
                setLoggingOut(true);
                try {
                  await api("auth/logout", { method: "POST" });
                  await client.cancelQueries();
                  client.setQueryData(["me"], null);
                  client.removeQueries({
                    predicate: (query) => query.queryKey[0] !== "me",
                  });
                  navigate("/login", { replace: true });
                } catch (e) {
                  setError(e);
                } finally {
                  setLoggingOut(false);
                }
              }}
            >
              <LogOut size={18} />
            </button>
          </div>
        </header>
        <main className="page-content">
          <ErrorNotice error={error} />
          {/* Keyed by path: screens that share one component (lists, master data,
              stock documents) start fresh instead of inheriting page and filters. */}
          <Outlet key={location.pathname} />
        </main>
        <footer className="workspace-footer">
          <span>دفتر · إدارة متجر واحد</span>
          <span>
            {store.data && `العملة الأساسية: ${store.data.data.base_currency}`}
          </span>
        </footer>
      </div>
    </div>
  );
}
function Shield() {
  return <BookOpen size={15} />;
}
