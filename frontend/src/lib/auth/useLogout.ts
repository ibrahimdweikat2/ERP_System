import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQueryClient } from "@tanstack/react-query";
import { api } from "../api/client";

/** Signs out and drops every cached query, so the next account starts clean. */
export function useLogout() {
  const client = useQueryClient();
  const navigate = useNavigate();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>();
  const logout = async () => {
    setPending(true);
    try {
      await api("auth/logout", { method: "POST" });
      await client.cancelQueries();
      client.setQueryData(["me"], null);
      client.removeQueries({ predicate: (query) => query.queryKey[0] !== "me" });
      navigate("/login", { replace: true });
    } catch (e) {
      setError(e);
    } finally {
      setPending(false);
    }
  };
  return { logout, pending, error };
}
