import { createContext, useContext } from "react";
import type { PermissionName, User } from "../../types/identity";
export type Auth = {
  user: User | null;
  loading: boolean;
  error: Error | null;
  can: (permission?: PermissionName) => boolean;
  refresh: () => Promise<void>;
};
export const AuthContext = createContext<Auth | null>(null);
export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error("AuthProvider required");
  return context;
}
