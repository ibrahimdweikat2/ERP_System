export type PermissionName =
  `${"sales" | "installments" | "checks" | "inventory" | "purchasing" | "accounting" | "reports" | "catalog" | "customers" | "payments" | "cashbank" | "expenses" | "approvals" | "audit" | "users" | "roles" | "settings" | "backups"}.${string}`;
export type Role = {
  id: number;
  name: string;
  label: string;
  permissions?: { id: number; name: PermissionName }[];
};
export type User = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  status: "active" | "disabled";
  roles: Role[];
  permissions: PermissionName[];
  is_owner: boolean;
  // The platform superadmin manages companies and belongs to none of them.
  is_platform_admin: boolean;
  // The company holds a valid license; without one users reach only the dashboard.
  licensed: boolean;
  company: { id: number; name: string; status: "active" | "suspended" } | null;
  mfa_required?: boolean;
  mfa_enabled?: boolean;
  last_login_at: string | null;
};
