import { test, expect } from "@playwright/test";
import { credentialsFor } from "./credentials";
test("supplier contacts, exact terms and private banking persist through the UI", async ({
  page,
}) => {
  test.skip(
    !process.env.ERP_E2E_CREDENTIALS,
    "Requires isolated browser test store.",
  );
  test.setTimeout(90000);
  const credentials = credentialsFor("suppliers");
  await page.goto("/login");
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
  await page.goto("/purchasing/suppliers");
  await page.getByRole("button", { name: "إضافة مورد" }).click();
  const code = "SUP-" + Date.now();
  await page.getByLabel("رمز المورد", { exact: true }).fill(code);
  await page
    .getByLabel("الاسم القانوني", { exact: true })
    .fill("شركة الموردين للاختبار");
  await page
    .getByLabel("الاسم التجاري", { exact: true })
    .fill("مورد متصفح " + code);
  await page.getByLabel("الرقم الضريبي", { exact: true }).fill("TEST-" + code);
  await page.getByLabel("أجل الدفع بالأيام").fill("45");
  await page.getByLabel("حد الائتمان لدى المورد").fill("12500.0001");
  await page.getByRole("button", { name: "إضافة جهة اتصال" }).click();
  await page.getByLabel("اسم جهة الاتصال 1").fill("مندوب اختبار");
  await page.getByLabel("الهاتف 1").fill("000000000");
  await page.getByLabel("البريد الإلكتروني 1").fill("supplier@example.test");
  await page.getByLabel("IBAN", { exact: true }).fill("TEST-PRIVATE-" + code);
  await page.getByRole("button", { name: "حفظ المورد" }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await page
    .getByRole("textbox", { name: "ابحث في السجلات", exact: true })
    .fill(code);
  await expect(
    page.getByRole("cell", { name: "12500.0001 ILS", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "تعديل", exact: true }).click();
  await expect(page.getByLabel("اسم جهة الاتصال 1")).toHaveValue(
    "مندوب اختبار",
  );
  await expect(page.getByLabel("IBAN", { exact: true })).toHaveValue(
    "TEST-PRIVATE-" + code,
  );
  await page.getByLabel("عنوان المورد").fill("عنوان المورد المحدث");
  await page.getByRole("button", { name: "حفظ المورد" }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await page
    .getByRole("button", { name: new RegExp("مورد متصفح " + code) })
    .click();
  await expect(page.getByRole("dialog")).toContainText("عنوان المورد المحدث");
  await page.screenshot({
    path: "test-results/supplier-details.png",
    fullPage: true,
  });
});
