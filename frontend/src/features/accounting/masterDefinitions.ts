export type MasterValue = string | number | boolean | null | undefined;
export type MasterRow = {
  id?: number | string;
  code?: string;
  [key: string]: MasterValue;
};
export type MasterField = {
  key: string;
  label: string;
  type?: "text" | "number" | "date" | "boolean" | "select" | "money";
  required?: boolean;
  options?: { value: string; label: string }[];
  source?: string;
  default?: MasterValue;
  min?: number;
  max?: number;
};
export type MasterDefinition = {
  title: string;
  description: string;
  fields: MasterField[];
  columns: string[];
  readonly?: boolean;
};
const name: MasterField = { key: "name", label: "الاسم", required: true };
const arabic: MasterField = {
  key: "name_ar",
  label: "الاسم بالعربية",
  required: true,
};
const active: MasterField = {
  key: "active",
  label: "نشط",
  type: "boolean",
  default: true,
};
const currency: MasterField = {
  key: "currency_code",
  label: "العملة",
  type: "select",
  source: "currencies",
  required: true,
  default: "ILS",
};
const account: MasterField = {
  key: "account_id",
  label: "حساب الأستاذ",
  type: "select",
  source: "accounts",
  required: true,
};
export const masterDefinitions: Record<string, MasterDefinition> = {
  categories: {
    title: "الفئات",
    description: "تنظيم المنتجات ضمن فئات رئيسية وفرعية.",
    columns: ["name_ar", "slug", "active"],
    fields: [
      arabic,
      { key: "name_en", label: "الاسم بالإنجليزية" },
      { key: "slug", label: "الرمز المختصر", required: true },
      {
        key: "parent_id",
        label: "الفئة الرئيسية",
        type: "select",
        source: "categories",
      },
      {
        key: "sort_order",
        label: "ترتيب العرض",
        type: "number",
        default: 0,
        required: true,
        min: 0,
      },
      active,
    ],
  },
  brands: {
    title: "العلامات التجارية",
    description: "العلامات التجارية للأجهزة والمنتجات.",
    columns: ["name_ar", "name_en", "active"],
    fields: [arabic, { key: "name_en", label: "الاسم بالإنجليزية" }, active],
  },
  units: {
    title: "وحدات القياس",
    description: "دقة الكمية لكل وحدة. الأجهزة المسلسلة تُعد بوحدات صحيحة.",
    columns: ["code", "name_ar", "decimal_places"],
    fields: [
      { key: "code", label: "رمز الوحدة", required: true },
      arabic,
      {
        key: "decimal_places",
        label: "الخانات العشرية",
        type: "number",
        default: 0,
        required: true,
        min: 0,
        max: 4,
      },
    ],
  },
  "warranty-policies": {
    title: "سياسات الضمان",
    description: "مدة الضمان والجهة المسؤولة وشروط الخدمة.",
    columns: [
      "name_ar",
      "duration_value",
      "duration_unit",
      "provider_type",
      "active",
    ],
    fields: [
      arabic,
      {
        key: "duration_value",
        label: "المدة",
        type: "number",
        required: true,
        min: 1,
        max: 3650,
        default: 12,
      },
      {
        key: "duration_unit",
        label: "وحدة المدة",
        type: "select",
        required: true,
        default: "month",
        options: [
          { value: "day", label: "يوم" },
          { value: "month", label: "شهر" },
          { value: "year", label: "سنة" },
        ],
      },
      {
        key: "provider_type",
        label: "مقدم الضمان",
        type: "select",
        required: true,
        default: "manufacturer",
        options: [
          { value: "store", label: "المتجر" },
          { value: "supplier", label: "المورد" },
          { value: "manufacturer", label: "المصنّع" },
        ],
      },
      { key: "terms", label: "شروط الضمان" },
      active,
    ],
  },
  "stock-locations": {
    title: "المواقع الداخلية",
    description: "مواقع العرض والتخزين والفحص داخل المتجر.",
    columns: ["code", "name_ar", "purpose", "sellable", "active"],
    fields: [
      { key: "code", label: "رمز الموقع", required: true },
      arabic,
      {
        key: "purpose",
        label: "الاستخدام",
        type: "select",
        required: true,
        default: "warehouse",
        options: [
          { value: "showroom", label: "معرض" },
          { value: "warehouse", label: "مستودع" },
          { value: "reserved", label: "محجوز" },
          { value: "returns", label: "فحص المرتجعات" },
          { value: "damaged", label: "تالف" },
          { value: "warranty", label: "صيانة وضمان" },
        ],
      },
      { key: "sellable", label: "متاح للبيع", type: "boolean", default: true },
      active,
    ],
  },
  accounts: {
    title: "دليل الحسابات",
    description:
      "حسابات الأستاذ وروابطها الرقابية. لا تتغير البنية المالية لحساب مستخدم.",
    columns: [
      "code",
      "name_ar",
      "account_type",
      "normal_balance",
      "is_control_account",
      "active",
    ],
    fields: [
      { key: "code", label: "رمز الحساب", required: true },
      arabic,
      { key: "name_en", label: "الاسم بالإنجليزية" },
      {
        key: "parent_id",
        label: "الحساب الأب",
        type: "select",
        source: "accounts",
      },
      {
        key: "account_type",
        label: "نوع الحساب",
        required: true,
        type: "select",
        default: "asset",
        options: [
          { value: "asset", label: "أصول" },
          { value: "liability", label: "التزامات" },
          { value: "equity", label: "حقوق ملكية" },
          { value: "revenue", label: "إيرادات" },
          { value: "expense", label: "مصروفات" },
        ],
      },
      {
        key: "normal_balance",
        label: "طبيعة الرصيد",
        type: "select",
        required: true,
        default: "debit",
        options: [
          { value: "debit", label: "مدين" },
          { value: "credit", label: "دائن" },
        ],
      },
      {
        key: "is_control_account",
        label: "حساب رقابي",
        type: "boolean",
        default: false,
      },
      {
        key: "allow_manual_posting",
        label: "يسمح بالقيد اليدوي",
        type: "boolean",
        default: true,
      },
      active,
    ],
  },
  journals: {
    title: "دفاتر اليومية",
    description: "دفاتر تصنّف القيود حسب مصدرها.",
    columns: ["code", "name_ar", "active"],
    fields: [{ key: "code", label: "الرمز", required: true }, arabic, active],
  },
  currencies: {
    title: "العملات",
    description: "دقة عرض مبالغ العملات وحالة تفعيلها.",
    columns: ["code", "name", "decimal_places", "is_active"],
    fields: [
      { key: "code", label: "رمز العملة", required: true },
      name,
      {
        key: "decimal_places",
        label: "الخانات العشرية",
        type: "number",
        required: true,
        min: 0,
        max: 4,
        default: 2,
      },
      { key: "is_active", label: "نشطة", type: "boolean", default: true },
    ],
  },
  "exchange-rates": {
    title: "أسعار الصرف",
    description:
      "قيمة وحدة من العملة الأجنبية بالعملة الأساسية. الأسعار المحفوظة لا تُعدّل.",
    columns: ["currency_code", "rate_date", "rate_to_base", "source"],
    readonly: true,
    fields: [
      currency,
      { key: "rate_date", label: "تاريخ السعر", type: "date", required: true },
      { key: "rate_to_base", label: "سعر الصرف", required: true },
      { key: "source", label: "مصدر السعر", required: true },
    ],
  },
  "tax-codes": {
    title: "الرموز الضريبية",
    description:
      "أدخل النسبة وتواريخ السريان المعتمدة للمتجر. كل تغيير في النسبة يحتاج إصداراً جديداً.",
    columns: [
      "code",
      "name_ar",
      "category",
      "rate",
      "effective_from",
      "effective_to",
    ],
    fields: [
      { key: "code", label: "الرمز", required: true },
      arabic,
      {
        key: "category",
        label: "الفئة",
        required: true,
        type: "select",
        default: "standard",
        options: [
          { value: "standard", label: "خاضع للضريبة" },
          { value: "zero", label: "نسبة صفرية" },
          { value: "exempt", label: "معفى" },
        ],
      },
      { key: "rate", label: "النسبة المئوية", type: "money", required: true },
      { key: "effective_from", label: "ساري من", type: "date", required: true },
      { key: "effective_to", label: "ساري حتى (اختياري)", type: "date" },
      {
        key: "input_account_id",
        label: "حساب ضريبة المدخلات",
        required: true,
        type: "select",
        source: "accounts",
      },
      {
        key: "output_account_id",
        label: "حساب ضريبة المخرجات",
        required: true,
        type: "select",
        source: "accounts",
      },
    ],
  },
  cashboxes: {
    title: "الصناديق",
    description: "اربط كل صندوق نقدي بحساب أستاذ مستقل وعملة.",
    columns: ["name", "account_id", "currency_code", "active"],
    fields: [name, account, currency, active],
  },
  "bank-accounts": {
    title: "الحسابات البنكية",
    description: "بيانات الحساب البنكي ورابطه بدفتر الأستاذ.",
    columns: ["name", "bank_name", "currency_code", "active"],
    fields: [
      name,
      { key: "bank_name", label: "البنك", required: true },
      { key: "account_number", label: "رقم الحساب" },
      { key: "iban", label: "IBAN" },
      account,
      currency,
      active,
    ],
  },
  "fiscal-years": {
    title: "السنوات المالية",
    description: "تُنشأ الفترات الشهرية تلقائياً ضمن التواريخ المختارة.",
    columns: ["name", "starts_on", "ends_on", "status"],
    readonly: true,
    fields: [
      name,
      { key: "starts_on", label: "بداية السنة", type: "date", required: true },
      { key: "ends_on", label: "نهاية السنة", type: "date", required: true },
    ],
  },
};
export const valueLabels: Record<string, string> = {
  day: "يوم",
  month: "شهر",
  year: "سنة",
  store: "المتجر",
  supplier: "المورد",
  manufacturer: "المصنّع",
  showroom: "معرض",
  warehouse: "مستودع",
  reserved: "محجوز",
  returns: "فحص المرتجعات",
  damaged: "تالف",
  warranty: "صيانة وضمان",
  asset: "أصول",
  liability: "التزامات",
  equity: "حقوق ملكية",
  revenue: "إيرادات",
  expense: "مصروفات",
  debit: "مدين",
  credit: "دائن",
  standard: "خاضع للضريبة",
  zero: "نسبة صفرية",
  exempt: "معفى",
  open: "مفتوحة",
  locked: "مقفلة",
  soft_closed: "إغلاق أولي",
  draft: "مسودة",
  posted: "مرحّل",
};
