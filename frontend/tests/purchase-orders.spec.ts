import { test, expect } from "@playwright/test";
import { credentialsFor } from "./credentials";
test("purchase order is approved, issued and printed with exact totals", async ({
  page,
}) => {
  test.skip(
    !process.env.ERP_E2E_CREDENTIALS,
    "Requires isolated browser test store.",
  );
  test.setTimeout(90000);
  const credentials = credentialsFor("purchase-orders");
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
  const token = (await page.context().cookies()).find(
    (c) => c.name === "XSRF-TOKEN",
  )!;
  const headers = {
    "X-XSRF-TOKEN": decodeURIComponent(token.value),
    Accept: "application/json",
  };
  const suffix = Date.now().toString();
  const units = (await (await page.request.get("/api/v1/units")).json()) as {
    data: { id: number; code: string }[];
  };
  const product = await page.request.post("/api/v1/products", {
    headers,
    data: {
      sku: "PO-" + suffix,
      name_ar: "جهاز أمر شراء " + suffix,
      unit_id: units.data.find((u) => u.code === "piece")!.id,
      serial_tracked: true,
      active: true,
      cash_price: "200",
      installment_price: "250",
      minimum_price: "150",
      reorder_level: "1",
      barcodes: [],
    },
  });
  expect(product.status()).toBe(201);
  const supplier = await page.request.post("/api/v1/suppliers", {
    headers: { ...headers, "Idempotency-Key": "supplier-po-" + suffix },
    data: {
      code: "PS-" + suffix,
      legal_name: "مورد طلب " + suffix,
      contacts: [],
      currency: "ILS",
      payment_terms_days: 30,
      credit_limit: "10000",
      active: true,
    },
  });
  expect(supplier.status()).toBe(201);
  const supplierData = (await supplier.json()) as { data: { id: number } };
  await page.goto("/purchasing/orders");
  await page
    .getByRole("button", { name: "أمر شراء جديد", exact: true })
    .click();
  await page
    .getByRole("combobox", { name: "المورد", exact: true })
    .selectOption(String(supplierData.data.id));
  await page.getByLabel("تاريخ أمر الشراء", { exact: true }).fill("2026-09-01");
  await page
    .getByLabel("منتج الشراء 1")
    .selectOption({ label: "PO-" + suffix + " — جهاز أمر شراء " + suffix });
  await page.getByLabel("كمية الشراء 1").fill("3");
  await page.getByLabel("سعر الوحدة 1").fill("100.0001");
  await page.getByLabel("خصم البند 1").fill("0.0001");
  await page
    .getByRole("button", { name: "حفظ أمر الشراء", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByRole("cell", { name: "300.0002", exact: true }),
  ).toHaveCount(2);
  await page
    .getByRole("button", { name: "إرسال للموافقة", exact: true })
    .click();
  await page
    .getByRole("button", { name: "اعتماد أمر الشراء", exact: true })
    .click();
  await page.getByLabel("سبب العملية").fill("مراجعة قيمة وكميات أمر الشراء");
  await page
    .getByRole("button", { name: "تأكيد العملية", exact: true })
    .click();
  await page
    .getByRole("button", { name: "إصدار أمر الشراء", exact: true })
    .click();
  await expect(page.getByRole("heading", { name: /PO\/2026\// })).toBeVisible();
  await expect(
    page.getByRole("button", { name: "تعديل أمر الشراء", exact: true }),
  ).toHaveCount(0);
  await page
    .getByRole("button", { name: "معاينة الطباعة", exact: true })
    .click();
  await expect(page.locator(".print-document")).toContainText(
    "مورد طلب " + suffix,
  );
  await page.emulateMedia({ media: "print" });
  await page.setViewportSize({ width: 794, height: 1123 });
  await expect(page.locator(".print-document")).toBeVisible();
  await expect
    .poll(() =>
      page
        .locator(".print-document")
        .evaluate((el) => el.scrollWidth <= el.clientWidth),
    )
    .toBe(true);
  await page.pdf({
    path: "test-results/purchase-order.pdf",
    printBackground: true,
    preferCSSPageSize: true,
  });
  await page.screenshot({
    path: "test-results/purchase-order-print.png",
    fullPage: true,
  });
});
