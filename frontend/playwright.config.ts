import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: "./tests",
  fullyParallel: false,
  workers: 1,
  expect: { timeout: 15000 },
  use: {
    baseURL: process.env.ERP_E2E_URL ?? "http://127.0.0.1:5199",
    browserName: "chromium",
    channel: "msedge",
    headless: true,
    viewport: { width: 1440, height: 1000 },
  },
  reporter: "list",
});
