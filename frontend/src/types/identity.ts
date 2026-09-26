export type PermissionName =
  `${"sales" | "installments" | "checks" | "inventory" | "purchasing" | "accounting" | "reports" | "catalog" | "customers" | "payments" | "cashbank" | "expenses" | "approvals" | "audit" | "users" | "roles" | "settings"}.${string}`;
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
  mfa_required?: boolean;
  mfa_enabled?: boolean;
  last_login_at: string | null;
};
