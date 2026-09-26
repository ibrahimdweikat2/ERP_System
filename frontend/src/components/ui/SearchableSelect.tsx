import {
  Children,
  Fragment,
  isValidElement,
  useEffect,
  useId,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { createPortal } from "react-dom";
import { ChevronDown } from "lucide-react";

/**
 * Drop-in replacement for <select> that filters its options as the user types.
 * It takes the same <option> children and calls onChange with e.target.value, so
 * existing handlers work unchanged. The list is portalled with fixed positioning so
 * table cells never clip it; inside a modal <dialog> it is portalled into that dialog
 * because the dialog sits in the browser top layer above anything in body.
 */
type Option = { value: string; label: string; disabled: boolean };
type ChangeEvent = {
  target: { value: string };
  currentTarget: { value: string };
};

function text(node: ReactNode): string {
  if (node === null || node === undefined || typeof node === "boolean")
    return "";
  if (typeof node === "string" || typeof node === "number") return String(node);
  if (Array.isArray(node)) return node.map(text).join("");
  if (isValidElement<{ children?: ReactNode }>(node))
    return text(node.props.children);
  return "";
}

function collect(children: ReactNode, into: Option[] = []): Option[] {
  Children.forEach(children, (child) => {
    if (!isValidElement<Record<string, unknown>>(child)) return;
    if (child.type === Fragment) {
      collect(child.props.children as ReactNode, into);
    } else if (child.type === "option") {
      const label = text(child.props.children as ReactNode);
      into.push({
        value: String(child.props.value ?? label),
        label,
        disabled: Boolean(child.props.disabled),
      });
    }
  });
  return into;
}

// Case, diacritics and common Arabic letter variants are ignored when matching.
function normalize(value: string): string {
  return value
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[̀-ًͯ-ٰٟ]/g, "")
    .replace(/[أإآ]/g, "ا")
    .replace(/ة/g, "ه")
    .replace(/ى/g, "ي");
}

export function SearchableSelect({
  value,
  onChange,
  children,
  required = false,
  disabled = false,
  "aria-label": ariaLabel,
}: {
  value?: string | number | null;
  onChange?: (event: ChangeEvent) => void;
  children?: ReactNode;
  required?: boolean;
  disabled?: boolean;
  "aria-label"?: string;
}) {
  const options = useMemo(() => collect(children), [children]);
  const current = value === null || value === undefined ? "" : String(value);
  const selected = options.find((o) => o.value === current);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);
  const [box, setBox] = useState<DOMRect>();
  const input = useRef<HTMLInputElement>(null);
  const list = useRef<HTMLUListElement>(null);
  const id = useId();

  const matches = useMemo(() => {
    const words = normalize(query).split(/\s+/).filter(Boolean);
    if (!words.length) return options;
    // The empty "choose…" entry only makes sense before the user starts typing.
    return options.filter((o) => {
      if (o.value === "") return false;
      const label = normalize(o.label);
      return words.every((w) => label.includes(w));
    });
  }, [options, query]);

  const place = () => setBox(input.current?.getBoundingClientRect());
  useLayoutEffect(() => {
    if (!open) return;
    place();
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, true);
    return () => {
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
    };
  }, [open]);
  useEffect(() => {
    list.current
      ?.querySelector<HTMLElement>(`[data-index="${active}"]`)
      ?.scrollIntoView({ block: "nearest" });
  }, [active, open]);

  const show = () => {
    if (disabled) return;
    const index = matches.findIndex((o) => o.value === current);
    setActive(index < 0 ? 0 : index);
    setOpen(true);
  };
  const close = () => {
    setOpen(false);
    setQuery("");
  };
  const choose = (option: Option | undefined) => {
    if (!option || option.disabled) return;
    close();
    if (option.value !== current)
      onChange?.({
        target: { value: option.value },
        currentTarget: { value: option.value },
      });
  };
  const move = (step: number) => {
    if (!open) return show();
    if (!matches.length) return;
    let next = active;
    for (let i = 0; i < matches.length; i++) {
      next = (next + step + matches.length) % matches.length;
      if (!matches[next].disabled) break;
    }
    setActive(next);
  };

  return (
    <span className={"searchable-select" + (disabled ? " disabled" : "")}>
      <input
        ref={input}
        role="combobox"
        aria-label={ariaLabel}
        aria-expanded={open}
        aria-controls={`${id}-list`}
        aria-autocomplete="list"
        aria-activedescendant={open ? `${id}-${active}` : undefined}
        autoComplete="off"
        disabled={disabled}
        className={selected && selected.value !== "" ? "" : "placeholder"}
        value={open ? query : (selected?.label ?? "")}
        placeholder={open ? selected?.label || "اكتب للبحث…" : undefined}
        onClick={() => (open ? close() : show())}
        onChange={(e) => {
          setQuery(e.target.value);
          setActive(0);
          setOpen(true);
        }}
        onBlur={close}
        onKeyDown={(e) => {
          if (e.key === "ArrowDown") {
            e.preventDefault();
            move(1);
          } else if (e.key === "ArrowUp") {
            e.preventDefault();
            move(-1);
          } else if (e.key === "Enter" && open) {
            // Choose the highlighted option instead of submitting the form.
            e.preventDefault();
            choose(matches[active]);
          } else if (e.key === "Escape" && open) {
            e.preventDefault();
            close();
          }
        }}
      />
      <ChevronDown size={16} className="searchable-select-icon" />
      {/* Carries `required` into native form validation. */}
      <input
        className="searchable-select-validation"
        tabIndex={-1}
        aria-hidden="true"
        required={required}
        disabled={disabled}
        value={current}
        onChange={() => undefined}
        onFocus={() => input.current?.focus()}
      />
      {open &&
        box &&
        createPortal(
          <ul
            ref={list}
            id={`${id}-list`}
            role="listbox"
            className="searchable-select-list"
            style={{ top: box.bottom + 4, left: box.left, width: box.width }}
            // Keep focus in the input while clicking an option.
            onMouseDown={(e) => e.preventDefault()}
          >
            {matches.length ? (
              matches.map((o, index) => (
                <li
                  key={o.value + index}
                  id={`${id}-${index}`}
                  data-index={index}
                  role="option"
                  aria-selected={o.value === current}
                  aria-disabled={o.disabled || undefined}
                  className={
                    (index === active ? "active " : "") +
                    (o.value === current ? "selected " : "") +
                    (o.value === "" ? "empty-choice" : "")
                  }
                  onMouseEnter={() => setActive(index)}
                  onClick={() => choose(o)}
                >
                  {o.label}
                </li>
              ))
            ) : (
              <li className="no-match" role="presentation">
                لا توجد نتائج
              </li>
            )}
          </ul>,
          input.current?.closest("dialog") ?? document.body,
        )}
    </span>
  );
}
