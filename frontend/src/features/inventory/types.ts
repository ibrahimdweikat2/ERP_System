export type Location = {
  id: number;
  code: string;
  name_ar: string;
  purpose: string;
  sellable: boolean;
  active: boolean;
};
export type StockProduct = {
  id: number;
  sku: string;
  name_ar: string;
  serial_tracked: boolean;
  active?: boolean;
  unit_id?: number;
};
export type Balance = {
  id: number;
  product_id: number;
  location_id: number;
  product: StockProduct;
  location: Location;
  qty_on_hand: string;
  qty_reserved: string;
  qty_available: string;
  sellable_quantity: string;
  inventory_value?: string;
  average_cost?: string;
};
export type Movement = {
  id: number;
  product: StockProduct;
  location: Location;
  direction: "in" | "out";
  quantity: string;
  unit_cost?: string;
  total_cost?: string;
  movement_type: string;
  source_type: string;
  source_id: number;
  movement_date: string;
};
export type Serial = {
  id: number;
  serial_no: string;
  status: string;
  product: StockProduct;
  location: Location | null;
  acquisition_cost?: string;
};
export type StockKind =
  "stock-transfers" | "stock-adjustments" | "stock-counts";
export type Approval = {
  id: number;
  status: string;
  amount?: string;
  currency: string;
  requested_by: number;
  decided_by: number | null;
  decision_reason: string | null;
  source_type: string;
  source_id: number;
  source_version: number;
  payload_json?: {
    reason: string;
    document_date: string;
    effects: {
      product_id: number;
      quantity: string;
      value: string;
      direction: string;
      serials: string[];
    }[];
  };
};
export type StockLine = {
  id: number;
  product_id: number;
  product: StockProduct;
  quantity?: string;
  unit_cost?: string | null;
  posted_value?: string;
  serials?: string[];
  expected_quantity?: string;
  counted_quantity?: string | null;
  expected_serials?: string[];
  counted_serials?: string[];
};
export type StockDocument = {
  id: number;
  document_no: string | null;
  document_date: string;
  reason: string;
  status: string;
  version: number;
  location_id: number;
  location: Location;
  destination_id?: number;
  destination?: Location;
  adjustment_kind?: "opening" | "gain" | "loss" | "write_off";
  snapshot_at?: string;
  gross_value?: string;
  posted_journal_entry_id: number | null;
  approval: Approval | null;
  approval_id: number | null;
  lines: StockLine[];
};
export const stockTitles: Record<StockKind, string> = {
  "stock-transfers": "التحويلات الداخلية",
  "stock-adjustments": "التسويات والافتتاحي",
  "stock-counts": "الجرد",
};
export const stockLabels: Record<string, string> = {
  draft: "مسودة",
  pending: "بانتظار الموافقة",
  approved: "معتمد",
  rejected: "مرفوض",
  superseded: "نسخة مستبدلة",
  posted: "مرحّل",
  in_stock: "في المخزون",
  reserved: "محجوز",
  sold: "مباع",
  returned_pending_inspection: "بانتظار الفحص",
  damaged: "تالف",
  warranty_service: "صيانة وضمان",
  supplier_returned: "مرتجع للمورد",
  retired: "مستبعد",
  opening: "رصيد افتتاحي",
  gain: "زيادة",
  loss: "نقص",
  write_off: "شطب تالف",
  opening_balance: "رصيد افتتاحي",
  adjustment_gain: "تسوية زيادة",
  adjustment_loss: "تسوية نقص",
  internal_transfer: "تحويل داخلي",
  count_gain: "زيادة جرد",
  count_loss: "نقص جرد",
  damaged_write_off: "شطب تالف",
  purchase_receipt: "استلام مشتريات",
};
export const documentPath = (source: string, id: number) =>
  source === "goods_receipt"
    ? `/purchasing/receipts/${id}`
    : {
          stock_transfer: "stock-transfers",
          stock_adjustment: "stock-adjustments",
          stock_count: "stock-counts",
        }[source]
      ? `/inventory/documents/${{ stock_transfer: "stock-transfers", stock_adjustment: "stock-adjustments", stock_count: "stock-counts" }[source]}/${id}`
      : "";
