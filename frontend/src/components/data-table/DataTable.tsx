import { useEffect, useState, type ReactNode } from "react";
import { useDebounce } from "../../lib/hooks/useDebounce";
import { ChevronLeft, ChevronRight, Search } from "lucide-react";
import { Empty } from "../ui/Primitives";
export type Column<T> = {
  key: string;
  label: string;
  render: (row: T) => ReactNode;
};
export function DataTable<T extends { id: number | string }>({
  rows,
  columns,
  empty,
}: {
  rows: T[];
  columns: Column<T>[];
  empty?: ReactNode;
}) {
  return (
    <div className="table-scroll">
      <table>
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.key} scope="col">
                {c.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id}>
              {columns.map((c) => (
                <td key={c.key}>{c.render(row)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      {!rows.length && (empty ?? <Empty />)}
    </div>
  );
}
export function Filters({
  value,
  onChange,
  placeholder = "ابحث في السجلات",
  children,
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  children?: ReactNode;
}) {
  // The box updates on every keystroke; the parent (and its request) only once
  // typing pauses.
  const [text, setText] = useState(value);
  const settled = useDebounce(text);
  useEffect(() => {
    if (settled !== value) onChange(settled);
  }, [settled, value, onChange]);
  return (
    <div className="filters">
      <label className="search">
        <Search size={18} />
        <input
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder={placeholder}
          aria-label={placeholder}
        />
      </label>
      {children}
    </div>
  );
}
export function Pagination({
  page,
  last,
  total,
  onPage,
}: {
  page: number;
  last: number;
  total: number;
  onPage: (page: number) => void;
}) {
  return (
    <div className="pagination">
      <span>
        {total} سجل · الصفحة {page} من {Math.max(last, 1)}
      </span>
      <div>
        <button
          className="icon-button"
          aria-label="الصفحة السابقة"
          disabled={page <= 1}
          onClick={() => onPage(page - 1)}
        >
          <ChevronRight size={18} />
        </button>
        <button
          className="icon-button"
          aria-label="الصفحة التالية"
          disabled={page >= last}
          onClick={() => onPage(page + 1)}
        >
          <ChevronLeft size={18} />
        </button>
      </div>
    </div>
  );
}
