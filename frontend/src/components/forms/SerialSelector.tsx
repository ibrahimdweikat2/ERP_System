export type SerialOption = { id: number; serial_no: string; status: string };
export function SerialSelector({
  options,
  value,
  onChange,
  allowedStatuses = ["in_stock"],
}: {
  options: SerialOption[];
  value: number[];
  onChange: (ids: number[]) => void;
  allowedStatuses?: string[];
}) {
  return (
    <fieldset className="serial-selector">
      <legend>الأرقام التسلسلية المتاحة</legend>
      {options
        .filter((s) => allowedStatuses.includes(s.status))
        .map((s) => (
          <label key={s.id}>
            <input
              type="checkbox"
              checked={value.includes(s.id)}
              onChange={(e) =>
                onChange(
                  e.target.checked
                    ? [...value, s.id]
                    : value.filter((id) => id !== s.id),
                )
              }
            />
            <bdi>{s.serial_no}</bdi>
          </label>
        ))}
    </fieldset>
  );
}
