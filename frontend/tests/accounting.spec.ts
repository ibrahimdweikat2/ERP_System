import { test, expect } from "@playwright/test";
import { credentialsFor } from "./credentials";
test("store setup, fiscal periods and exact journal reversal work through the UI", async ({
  page,
}) => {
  test.skip(
    !process.env.ERP_E2E_CREDENTIALS,
    "Financial browser scenarios require the isolated browser-test environment.",
  );
  const creds = credentialsFor("accounting");
  await page.goto("/login");
  await page.getByLabel("البريد الإلكتروني", { exact: true }).fill(creds.email);
  await page.getByLabel("كلمة المرور", { exact: true }).fill(creds.password);
  await page.getByRole("button", { name: "تسجيل الدخول", exact: true }).click();
  await expect(
    page.getByRole("navigation", { name: "التنقل الرئيسي" }),
  ).toBeVisible();
  await page.goto("/admin/setup");
  await page.getByLabel("الاسم التجاري للمتجر").fill("متجر اختبار معزول");
  await page.getByRole("button", { name: "حفظ إعدادات المتجر" }).click();
  await expect(page.getByRole("status")).toContainText("تم حفظ إعدادات المتجر");
  await page.goto("/accounting/masters/fiscal-years");
  await page.getByRole("button", { name: "إضافة سجل" }).click();
  await page
    .getByLabel("الاسم", { exact: true })
    .fill("السنة المالية لاختبار المتصفح");
  await page.getByLabel("بداية السنة").fill("2026-01-01");
  await page.getByLabel("نهاية السنة").fill("2026-12-31");
  await page.getByRole("button", { name: "حفظ السجل" }).click();
  await expect(
    page.getByRole("cell", {
      name: "السنة المالية لاختبار المتصفح",
      exact: true,
    }),
  ).toBeVisible();
  await page.goto("/accounting/journals");
  await page.getByRole("button", { name: "قيد يدوي جديد" }).click();
  await page.getByLabel("تاريخ القيد").fill("2026-09-06");
  await page
    .getByLabel("بيان القيد")
    .fill("اختبار محاسبي معزول عن بيانات المتجر");
  await page
    .getByRole("combobox", { name: "الحساب 1", exact: true })
    .selectOption({ label: "1100 — النقد في الصندوق" });
  await page
    .getByRole("combobox", { name: "الحساب 2", exact: true })
    .selectOption({ label: "3100 — رأس مال المالك" });
  await page.getByLabel("مدين", { exact: true }).nth(0).fill("1000.0001");
  await page.getByLabel("دائن", { exact: true }).nth(1).fill("1000.0001");
  await page.getByRole("button", { name: "حفظ المسودة" }).click();
  await expect(page.getByRole("button", { name: "ترحيل القيد" })).toBeVisible();
  await page.getByRole("button", { name: "ترحيل القيد" }).click();
  await expect(
    page.getByRole("button", { name: "عكس القيد", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "JE/2026/000001" }).last(),
  ).toBeVisible();
  await page.screenshot({
    path: "test-results/accounting-posted.png",
    fullPage: true,
  });
  await page.getByRole("button", { name: "عكس القيد", exact: true }).click();
  await page.getByLabel("تاريخ العكس").fill("2026-10-01");
  await page
    .getByLabel("سبب العملية")
    .fill("إلغاء قيد الاختبار مع حفظ التاريخ");
  await page.getByRole("button", { name: "تأكيد العملية" }).click();
  await expect(
    page.getByRole("button", { name: "عرض القيد الأصلي" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "JE/2026/000002" }).last(),
  ).toBeVisible();
  await page.getByRole("button", { name: "إغلاق", exact: true }).click();
  await page.goto("/accounting/periods");
  const row = page.getByRole("row").filter({
    has: page.getByRole("cell", { name: "2026-09-01", exact: true }),
  });
  await row.getByRole("button", { name: "إقفال نهائي" }).click();
  await page.getByLabel("سبب العملية").fill("إقفال فترة اختبار المحاسبة");
  await page.getByRole("button", { name: "تأكيد العملية" }).click();
  await expect(row.getByText("مقفلة نهائياً")).toBeVisible();
});
