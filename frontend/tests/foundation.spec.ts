import { test, expect } from "@playwright/test";
import { readFileSync } from "node:fs";
const credentials = JSON.parse(
  readFileSync(
    process.env.ERP_E2E_CREDENTIALS ??
      new URL("../../.runtime/browser-credentials.json", import.meta.url),
    "utf8",
  ),
) as { email: string; password: string };
test("owner signs in through CSRF-protected cookie session and sees RTL administration", async ({
  page,
}) => {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto("/login");
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await expect(
    page.getByRole("heading", { name: "أهلاً بعودتك" }),
  ).toBeVisible();
  await page
    .getByLabel("البريد الإلكتروني", { exact: true })
    .fill(credentials.email);
  await page
    .getByLabel("كلمة المرور", { exact: true })
    .fill(credentials.password);
  await page.getByRole("button", { name: "تسجيل الدخول", exact: true }).click();
  await expect(
    page.getByRole("navigation", { name: "التنقل الرئيسي" }),
  ).toBeVisible();
  await page.getByRole("link", { name: "المستخدمون", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "فريق المتجر", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("cell", { name: credentials.email, exact: true }),
  ).toBeVisible();
  await page.getByRole("link", { name: "سجل التدقيق", exact: true }).click();
  await expect(
    page.getByRole("cell", { name: "تسجيل دخول", exact: true }).first(),
  ).toBeVisible();
  await page.screenshot({
    path: "test-results/foundation-desktop.png",
    fullPage: true,
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole("button", { name: "فتح القائمة" }).click();
  await expect(
    page.getByRole("navigation", { name: "التنقل الرئيسي" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "إغلاق القائمة" }).click();
  await page.getByRole("button", { name: "تسجيل الخروج", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "أهلاً بعودتك" }),
  ).toBeVisible();
  expect(errors).toEqual([]);
});
test("write requests without a CSRF token are rejected", async ({
  request,
}) => {
  const response = await request.post("/api/v1/auth/login", {
    data: credentials,
    headers: { Accept: "application/json" },
  });
  expect(response.status()).toBe(419);
});
