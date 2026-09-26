export type OrderLine = {
  received_quantity?: string;
  remaining_quantity?: string;
  id: number;
  product_id: number;
  product_snapshot: { sku: string; name_ar: string; unit_name: string };
  quantity: string;
  unit_price: string;
  discount_amount: string;
  tax_code_id: number | null;
  tax_inclusive: boolean;
  tax_rate: string;
  taxable_base: string;
  tax_amount: string;
  total: string;
  tax_snapshot: { name_ar: string } | null;
};
export type PurchaseOrder = {
  id: number;
  document_no: string | null;
  document_date: string;
  expected_on: string | null;
  supplier_id: number;
  supplier_snapshot: {
    code: string;
    legal_name: string;
    trade_name: string | null;
    tax_number: string | null;
    address: string | null;
  };
  supplier_reference: string | null;
  status: string;
  version: number;
  currency: string;
  base_currency: string;
  exchange_rate: string;
  exchange_rate_date: string;
  exchange_rate_source: string;
  subtotal: string;
  tax_total: string;
  total: string;
  base_total: string;
  notes: string | null;
  lines: OrderLine[];
  approval_id: number | null;
  approval: { decision_reason: string | null } | null;
};
export const orderStatus: Record<string, string> = {
  draft: "مسودة",
  pending: "بانتظار الموافقة",
  approved: "معتمد",
  rejected: "مرفوض",
  issued: "صادر",
};
