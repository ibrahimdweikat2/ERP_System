import type { Location } from "../inventory/types";
export type ReceiptLine = {
  id: number;
  product_id: number;
  product_snapshot: {
    sku: string;
    name_ar: string;
    manufacturer_model: string | null;
    serial_tracked: boolean;
    unit_name: string;
  };
  purchase_order_line_id: number | null;
  location_id: number;
  location: Location;
  condition: string;
  condition_notes: string | null;
  quantity: string;
  serials: string[];
  unit_cost: string;
  foreign_value: string;
  base_value: string;
  posted_movement_id: number | null;
};
export type GoodsReceipt = {
  id: number;
  document_no: string | null;
  document_date: string;
  supplier_id: number;
  supplier_snapshot: {
    legal_name: string;
    trade_name: string | null;
    address: string | null;
    tax_number: string | null;
  };
  purchase_order_id: number | null;
  purchase_order: { id: number; document_no: string } | null;
  delivery_reference: string;
  status: string;
  version: number;
  currency: string;
  base_currency: string;
  exchange_rate: string;
  exchange_rate_date: string;
  exchange_rate_source: string;
  foreign_total: string;
  base_total: string;
  notes: string | null;
  posted_journal_entry_id: number | null;
  lines: ReceiptLine[];
};
export const conditionLabels: Record<string, string> = {
  new: "جديد وسليم",
  open_box: "مفتوح / يحتاج فحص",
  damaged: "تالف",
};
