import { useEffect } from "react";

// Mirrors the store's display name and logo into the browser tab.
export function useBrandHead(name: string, logoUrl?: string | null) {
  useEffect(() => {
    document.title = name + " | إدارة متجر الأجهزة";
    let icon = document.querySelector<HTMLLinkElement>("link[rel='icon']");
    if (!logoUrl) {
      icon?.remove();
      return;
    }
    if (!icon) {
      icon = document.createElement("link");
      icon.rel = "icon";
      document.head.appendChild(icon);
    }
    icon.href = logoUrl;
  }, [name, logoUrl]);
}
