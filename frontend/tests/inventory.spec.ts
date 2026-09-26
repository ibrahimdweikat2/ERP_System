import { test, expect } from "@playwright/test";
import { credentialsFor } from "./credentials";
test("opening, serial transfer, approved count and device history work through the UI", async ({
  page,
}) => {
  test.skip(!process.env.ERP_E2E_CREDENTIALS, "Requires isolated test store.");
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on("pageerror", (error) => {
    throw error;
  });
  const creds = credentialsFor("inventory");
  await page.goto("/login");
  await page.getByLabel("البريد الإلكتروني", { exact: true }).fill(creds.email);
  await page.getByLabel("كلمة المرور", { exact: true }).fill(creds.password);
  await page.getByRole("button", { name: "تسجيل الدخول", exact: true }).click();
  await expect(
    page.getByRole("navigation", { name: "التنقل الرئيسي" }),
  ).toBeVisible();
  const years = await page.request.get("/api/v1/fiscal-years");
  const yearData = (await years.json()) as { data: { starts_on: string }[] };
  if (!yearData.data.some((y) => y.starts_on === "2026-01-01")) {
    const token = (await page.context().cookies()).find(
      (c) => c.name === "XSRF-TOKEN",
    )!;
    const response = await page.request.post("/api/v1/fiscal-years", {
      headers: {
        "X-XSRF-TOKEN": decodeURIComponent(token.value),
        Accept: "application/json",
      },
      data: {
        name: "سنة اختبار المخزون",
        starts_on: "2026-01-01",
        ends_on: "2026-12-31",
      },
    });
    expect(response.status()).toBe(201);
  }
  const suffix = Date.now().toString();
  const sku = "INV-" + suffix;
  const name = "جهاز مخزون " + suffix;
  const s1 = "UI-A-" + suffix;
  const s2 = "UI-B-" + suffix;
  await page.goto("/catalog/products");
  await page.getByRole("button", { name: "إضافة منتج" }).click();
  await page.getByLabel("رمز المنتج SKU").fill(sku);
  await page.getByLabel("اسم المنتج بالعربية").fill(name);
  await page.getByRole("button", { name: "حفظ المنتج" }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await page.goto("/inventory/documents/stock-adjustments");
  await page.getByRole("button", { name: "مستند جديد" }).click();
  await page.getByLabel("تاريخ المستند").fill("2026-08-01");
  await page
    .getByRole("combobox", { name: "الموقع الداخلي", exact: true })
    .selectOption({ label: "المستودع الرئيسي" });
  await page.getByLabel("سبب المستند").fill("افتتاحي مخزون اختبار المتصفح");
  await page
    .getByRole("combobox", { name: "المنتج 1", exact: true })
    .selectOption({ label: sku + " — " + name });
  await page.getByLabel("تكلفة الوحدة 1", { exact: true }).fill("100.0001");
  await page
    .getByLabel("الأرقام الجديدة 1 — رقم واحد في كل سطر")
    .fill(s1 + "\n" + s2 + "\n");
  await page.getByRole("button", { name: "حفظ المسودة" }).click();
  await expect(
    page.getByRole("button", { name: "إرسال للموافقة" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "إرسال للموافقة" }).click();
  await page.getByRole("button", { name: "اعتماد المستند" }).click();
  await page
    .getByLabel("سبب العملية")
    .fill("مراجعة الأجهزة والتكلفة الافتتاحية");
  await page.getByRole("button", { name: "تأكيد العملية" }).click();
  await page
    .getByRole("button", { name: "ترحيل المستند", exact: true })
    .click();
  await expect(page.getByRole("heading", { name: /SA\/2026\// })).toBeVisible();
  await page.goto("/inventory/documents/stock-transfers");
  await page.getByRole("button", { name: "مستند جديد" }).click();
  await page.getByLabel("تاريخ المستند").fill("2026-08-02");
  await page
    .getByRole("combobox", { name: "الموقع المصدر", exact: true })
    .selectOption({ label: "المستودع الرئيسي" });
  await page
    .getByRole("combobox", { name: "الموقع الوجهة", exact: true })
    .selectOption({ label: "صالة العرض" });
  await page.getByLabel("سبب المستند").fill("نقل جهاز اختبار إلى صالة العرض");
  await page
    .getByRole("combobox", { name: "المنتج 1", exact: true })
    .selectOption({ label: sku + " — " + name });
  await page.getByRole("checkbox", { name: s1, exact: true }).check();
  await page.getByRole("button", { name: "حفظ المسودة" }).click();
  await page
    .getByRole("button", { name: "ترحيل المستند", exact: true })
    .click();
  await expect(page.getByRole("heading", { name: /ST\/2026\// })).toBeVisible();
  await page.getByRole("button", { name: "معاينة الطباعة" }).click();
  const print = page.locator(".print-document");
  await expect(
    print.getByRole("heading", { name: "سند تحويل مخزون" }),
  ).toBeVisible();
  await expect(print).toContainText(s1);
  await expect(print).toContainText("صالة العرض");
  await page.emulateMedia({ media: "print" });
  await expect(print).toBeVisible();
  await expect(
    page.getByRole("navigation", { name: "التنقل الرئيسي" }),
  ).toBeHidden();
  await expect(
    print.getByRole("button", { name: "طباعة", exact: true }),
  ).toBeHidden();
  await page.pdf({
    path: "test-results/stock-transfer.pdf",
    printBackground: true,
    preferCSSPageSize: true,
  });
  await page.screenshot({
    path: "test-results/stock-transfer-print.png",
    fullPage: true,
  });
  await page.emulateMedia({ media: "screen" });
  await page.getByRole("button", { name: "إغلاق", exact: true }).click();
  await page.goto("/inventory/documents/stock-counts");
  await page.getByRole("button", { name: "بدء جرد جديد" }).click();
  await page.getByLabel("تاريخ المستند").fill("2026-08-03");
  await page
    .getByRole("combobox", { name: "الموقع الداخلي", exact: true })
    .selectOption({ label: "المستودع الرئيسي" });
  await page.getByLabel("سبب المستند").fill("جرد اختبار وإثبات الجهاز المفقود");
  await page
    .getByRole("combobox", { name: "المنتج 1", exact: true })
    .selectOption({ label: sku + " — " + name });
  await page.getByRole("button", { name: "بدء الجرد وحفظ اللقطة" }).click();
  await page.getByRole("button", { name: "تسجيل / تعديل العدّ" }).click();
  await page.getByLabel("الكمية المعدودة 1", { exact: true }).fill("0");
  await page.getByRole("button", { name: "حفظ المسودة" }).click();
  await page.getByRole("button", { name: "إرسال للموافقة" }).click();
  await page.getByRole("button", { name: "اعتماد المستند" }).click();
  await page.getByLabel("سبب العملية").fill("اعتماد فرق الجرد بعد إعادة العد");
  await page.getByRole("button", { name: "تأكيد العملية" }).click();
  await page
    .getByRole("button", { name: "ترحيل المستند", exact: true })
    .click();
  await expect(page.getByRole("heading", { name: /CT\/2026\// })).toBeVisible();
  await page.goto("/inventory/balances");
  await page
    .getByRole("textbox", { name: "ابحث في السجلات", exact: true })
    .fill(sku);
  await expect(
    page.getByRole("cell", { name: "100.0001", exact: true }),
  ).toBeVisible();
  await page.screenshot({
    path: "test-results/inventory-reconciled.png",
    fullPage: true,
  });
  await page.goto("/inventory/serials");
  await page
    .getByRole("textbox", { name: "ابحث في السجلات", exact: true })
    .fill(s2);
  await expect(
    page.getByRole("cell", { name: "مستبعد", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "سجل الجهاز" }).click();
  await expect(
    page.getByRole("cell", { name: "2026-08-03", exact: true }),
  ).toBeVisible();
  await page.screenshot({
    path: "test-results/serial-history.png",
    fullPage: true,
  });
});
