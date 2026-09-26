import { Field } from "../ui/Primitives";
export function MoneyInput({
  label,
  value,
  onChange,
  required = true,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
}) {
  return (
    <Field
      label={label}
      inputMode="decimal"
      dir="ltr"
      pattern="[0-9]+([.][0-9]{1,4})?"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      required={required}
    />
  );
}
