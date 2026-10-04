export type CompanyStatus = "active" | "suspended";
export type Company = {
  id: number;
  name: string;
  status: CompanyStatus;
  created_at: string;
  updated_at: string;
  users_count?: number;
  owners_count?: number;
  roles?: { id: number; name: string; label: string }[];
};
export type CompanyOwner = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  status: "active" | "disabled";
  last_login_at: string | null;
  created_at: string;
  mfa_required: boolean;
  mfa_enabled: boolean;
};
