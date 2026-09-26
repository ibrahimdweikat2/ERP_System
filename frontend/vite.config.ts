import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    host: "127.0.0.1",
    port: Number(process.env.ERP_UI_PORT ?? 5173),
    strictPort: true,
    proxy: {
      "/api": process.env.ERP_API_URL ?? "http://127.0.0.1:8188",
      "/sanctum": process.env.ERP_API_URL ?? "http://127.0.0.1:8188",
      "/storage": process.env.ERP_API_URL ?? "http://127.0.0.1:8188",
    },
  },
});
