import Decimal from "decimal.js";
export function formatMoney(
  value: string,
  currency = "ILS",
  decimalPlaces = 2,
): string {
  return new Decimal(value).toFixed(decimalPlaces) + " " + currency;
}
