import { test, expect } from "@playwright/test";
import { credentialsFor } from "./credentials";
test("catalog saves appliance details and searches normalized barcode", async ({
  page,
}) => {
  test.skip(!process.env.ERP_E2E_CREDENTIALS, "Requires isolated test store.");
  const creds = credentialsFor("catalog");
  await page.goto("/login");
  await page.getByLabel("البريد الإلكتروني", { exact: true }).fill(creds.email);
  await page.getByLabel("كلمة المرور", { exact: true }).fill(creds.password);
  await page.getByRole("button", { name: "تسجيل الدخول", exact: true }).click();
  await expect(
    page.getByRole("navigation", { name: "التنقل الرئيسي" }),
  ).toBeVisible();
  const suffix = Date.now().toString();
  await page.goto("/catalog/masters/brands");
  await page.getByRole("button", { name: "إضافة سجل" }).click();
  await page
    .getByLabel("الاسم بالعربية", { exact: true })
    .fill("علامة اختبار " + suffix);
  await page.getByRole("button", { name: "حفظ السجل" }).click();
  await expect(
    page.getByRole("cell", { name: "علامة اختبار " + suffix, exact: true }),
  ).toBeVisible();
  await page.goto("/catalog/products");
  await page.getByRole("button", { name: "إضافة منتج" }).click();
  await page.getByLabel("رمز المنتج SKU").fill("wash-" + suffix);
  await page.getByLabel("اسم المنتج بالعربية").fill("غسالة اختبار " + suffix);
  await page
    .getByRole("combobox", { name: "العلامة التجارية", exact: true })
    .selectOption({ label: "علامة اختبار " + suffix });
  await page.getByLabel("سعر البيع النقدي", { exact: true }).fill("1500.0001");
  await page.getByLabel("سعر البيع بالتقسيط", { exact: true }).fill("1800");
  await page.getByLabel("الحد الأدنى للبيع").fill("1400");
  await page.getByLabel("التكلفة المرجعية", { exact: true }).fill("1000.0001");
  await page.getByLabel("الباركود — رمز واحد في كل سطر").fill("bc-" + suffix);
  await page.getByRole("button", { name: "إضافة مواصفة" }).click();
  await page.getByLabel("المواصفة 1", { exact: true }).fill("السعة");
  await page.getByLabel("القيمة 1", { exact: true }).fill("8 كيلو");
  await page.getByRole("button", { name: "حفظ المنتج" }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByRole("cell", { name: "1500.0001", exact: true }),
  ).toBeVisible();
  await page.getByRole("textbox", { name: "بحث" }).fill("bc-" + suffix);
  await expect(
    page.getByRole("cell", { name: "غسالة اختبار " + suffix, exact: false }),
  ).toBeVisible();
  await page.getByRole("button", { name: "عرض / تعديل" }).click();
  await expect(page.getByLabel("القيمة 1", { exact: true })).toHaveValue(
    "8 كيلو",
  );
  await expect(
    page.getByLabel("سعر البيع النقدي", { exact: true }),
  ).toHaveValue("1500.0001");
  await page.screenshot({
    path: "test-results/catalog-product.png",
    fullPage: true,
  });
});
