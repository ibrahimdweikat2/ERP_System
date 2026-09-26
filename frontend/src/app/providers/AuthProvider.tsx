import { type ReactNode } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { api, ApiError, type ApiEnvelope } from "../../lib/api/client";
import type { User } from "../../types/identity";
import { AuthContext as Context } from "../../lib/auth/context";
export function AuthProvider({ children }: { children: ReactNode }) {
  const client = useQueryClient();
  const query = useQuery({
    queryKey: ["me"],
    queryFn: async () => {
      try {
        return (await api<ApiEnvelope<User>>("auth/me")).data;
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) return null;
        throw error;
      }
    },
    retry: false,
    staleTime: 30_000,
  });
  const user = query.data ?? null;
  return (
    <Context.Provider
      value={{
        user,
        loading: query.isPending,
        error: query.error,
        can: (p) =>
          !p || (!!user && (user.is_owner || user.permissions.includes(p))),
        refresh: async () => {
          await client.invalidateQueries({ queryKey: ["me"] });
        },
      }}
    >
      {children}
    </Context.Provider>
  );
}
