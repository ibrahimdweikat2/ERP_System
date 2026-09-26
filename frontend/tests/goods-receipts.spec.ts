import { test, expect } from "@playwright/test";
import { credentialsFor } from "./credentials";
test("receiving a PO separates healthy and damaged serials, posts and prints", async ({
  page,
}) => {
  test.skip(
    !process.env.ERP_E2E_CREDENTIALS,
    "Requires isolated browser test store.",
  );
  test.setTimeout(120000);
  const credentials = credentialsFor("goods-receipts");
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
  const create = async (path: string, data: object) => {
    const response = await page.request.post("/api/v1/" + path, {
      headers: {
        ...headers,
        "Idempotency-Key": path.replaceAll("/", "-") + "-" + suffix,
      },
      data,
    });
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()).data;
  };
  const years = (await (
    await page.request.get("/api/v1/fiscal-years")
  ).json()) as { data: { starts_on: string }[] };
  if (!years.data.some((y) => y.starts_on === "2026-01-01"))
    await create("fiscal-years", {
      name: "سنة اختبار الاستلام",
      starts_on: "2026-01-01",
      ends_on: "2026-12-31",
    });
  const units = (await (await page.request.get("/api/v1/units")).json()) as {
    data: { id: number; code: string }[];
  };
  const p = await create("products", {
    sku: "GR-" + suffix,
    name_ar: "جهاز استلام " + suffix,
    unit_id: units.data.find((u) => u.code === "piece")!.id,
    serial_tracked: true,
    active: true,
    cash_price: "200",
    installment_price: "250",
    minimum_price: "150",
    reorder_level: "1",
    barcodes: [],
  });
  const s = await create("suppliers", {
    code: "GS-" + suffix,
    legal_name: "مورد استلام " + suffix,
    contacts: [],
    currency: "ILS",
    payment_terms_days: 30,
    credit_limit: "10000",
    active: true,
  });
  const po = await create("purchase-orders", {
    supplier_id: s.id,
    document_date: "2026-08-01",
    currency: "ILS",
    lines: [
      {
        product_id: p.id,
        quantity: "2",
        unit_price: "100.0001",
        discount_amount: "0",
        tax_code_id: null,
        tax_inclusive: false,
      },
    ],
  });
  await create(`purchase-orders/${po.id}/submit`, { version: 1 });
  await create(`purchase-orders/${po.id}/decide`, {
    version: 1,
    decision: "approved",
    reason: "مراجعة أمر شراء لاختبار الاستلام",
  });
  await create(`purchase-orders/${po.id}/issue`, { version: 1 });
  await page.goto("/purchasing/receipts");
  await page
    .getByRole("button", { name: "سند استلام جديد", exact: true })
    .click();
  await page
    .getByLabel("أمر الشراء المعتمد والصادر")
    .selectOption(String(po.id));
  await page.getByLabel("تاريخ استلام البضاعة").fill("2026-08-15");
  await page.getByLabel("رقم سند تسليم المورد").fill("DEL-" + suffix);
  await page
    .getByLabel("بند أمر الشراء 1")
    .selectOption(String(po.lines[0].id));
  await page
    .getByLabel("أرقام الأجهزة المستلمة 1 — رقم في كل سطر")
    .fill("GOOD-" + suffix + "\n");
  await page
    .getByRole("button", { name: "إضافة بند استلام", exact: true })
    .click();
  await page
    .getByLabel("بند أمر الشراء 2")
    .selectOption(String(po.lines[0].id));
  await page
    .getByRole("combobox", { name: "حالة البضاعة 2" })
    .selectOption("damaged");
  await page
    .getByLabel("وصف حالة البضاعة 2")
    .fill("ضرر خارجي مسجل عند التسليم");
  await page
    .getByLabel("أرقام الأجهزة المستلمة 2 — رقم في كل سطر")
    .fill("DAMAGED-" + suffix);
  await page
    .getByRole("button", { name: "حفظ سند الاستلام", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await page
    .getByRole("button", { name: "ترحيل الاستلام", exact: true })
    .click();
  await expect(page.getByRole("heading", { name: /GR\/2026\// })).toBeVisible();
  await expect(page.locator(".order-totals")).toContainText("200.0002 ILS");
  await expect(
    page.getByRole("button", { name: "تعديل الاستلام", exact: true }),
  ).toHaveCount(0);
  await page
    .getByRole("button", { name: "معاينة الطباعة", exact: true })
    .click();
  await expect(page.locator(".print-document")).toContainText(
    "DAMAGED-" + suffix,
  );
  await page.emulateMedia({ media: "print" });
  await page.setViewportSize({ width: 794, height: 1123 });
  await expect
    .poll(() =>
      page
        .locator(".print-document")
        .evaluate((el) => el.scrollWidth <= el.clientWidth),
    )
    .toBe(true);
  await page.pdf({
    path: "test-results/goods-receipt.pdf",
    printBackground: true,
    preferCSSPageSize: true,
  });
  await page.screenshot({
    path: "test-results/goods-receipt-print.png",
    fullPage: true,
  });
  const result = await (
    await page.request.get("/api/v1/purchase-orders/" + po.id)
  ).json();
  expect(result.data.lines[0].remaining_quantity).toBe("0.0000");
  const serial = await (
    await page.request.get("/api/v1/inventory/serials?search=DAMAGED-" + suffix)
  ).json();
  expect(serial.data[0].status).toBe("damaged");
});
