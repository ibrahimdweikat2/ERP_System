import {
  useEffect,
  useRef,
  type ClipboardEvent,
  type KeyboardEvent,
} from "react";

export type OtpStatus = "idle" | "checking" | "success" | "error";

// Segmented code entry: one box per character, paste-aware, and it calls
// onComplete as soon as the last box is filled.
export function OtpInput({
  length,
  value,
  onChange,
  onComplete,
  status = "idle",
  numeric = true,
  label,
  autoFocus,
}: {
  length: number;
  value: string;
  onChange: (value: string) => void;
  onComplete: (value: string) => void;
  status?: OtpStatus;
  numeric?: boolean;
  label: string;
  autoFocus?: boolean;
}) {
  const refs = useRef<(HTMLInputElement | null)[]>([]);
  const locked = status === "checking" || status === "success";
  const clean = (raw: string) =>
    numeric
      ? raw.replace(/\D/g, "")
      : raw.replace(/[^0-9a-z]/gi, "").toUpperCase();

  useEffect(() => {
    if (autoFocus) refs.current[0]?.focus();
  }, [autoFocus]);
  // After a rejected code the boxes are cleared: return focus to the first one.
  useEffect(() => {
    if (status === "error" && value === "") refs.current[0]?.focus();
  }, [status, value]);

  const commit = (next: string, focusAt: number) => {
    const v = next.slice(0, length);
    onChange(v);
    refs.current[Math.min(focusAt, length - 1)]?.focus();
    if (v.length === length) onComplete(v);
  };
  const setAt = (index: number, raw: string) => {
    const chars = clean(raw);
    if (!chars) return;
    const next = (
      value.slice(0, index) +
      chars +
      value.slice(index + chars.length)
    ).slice(0, length);
    commit(next, index + chars.length);
  };
  const onKey = (index: number, e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Backspace") {
      e.preventDefault();
      const at = value[index] ? index : index - 1;
      if (at < 0) return;
      onChange(value.slice(0, at) + value.slice(at + 1));
      refs.current[at]?.focus();
    } else if (e.key === "ArrowLeft") {
      refs.current[Math.min(index + 1, length - 1)]?.focus();
    } else if (e.key === "ArrowRight") {
      refs.current[Math.max(index - 1, 0)]?.focus();
    }
  };
  const onPaste = (e: ClipboardEvent<HTMLInputElement>) => {
    e.preventDefault();
    const chars = clean(e.clipboardData.getData("text"));
    if (chars) commit(chars, chars.length);
  };

  return (
    <div
      className={`otp otp-${status}`}
      role="group"
      aria-label={label}
      dir="ltr"
      style={{ ["--otp-count" as string]: length }}
    >
      {Array.from({ length }, (_, i) => (
        <input
          key={i}
          ref={(el) => {
            refs.current[i] = el;
          }}
          className={"otp-box" + (value[i] ? " filled" : "")}
          style={{ ["--i" as string]: i }}
          value={value[i] ?? ""}
          inputMode={numeric ? "numeric" : "text"}
          autoComplete={i === 0 ? "one-time-code" : "off"}
          autoCapitalize="characters"
          spellCheck={false}
          maxLength={length}
          disabled={locked}
          aria-label={`${label} — الخانة ${i + 1} من ${length}`}
          onFocus={(e) => e.target.select()}
          onChange={(e) => setAt(i, e.target.value)}
          onKeyDown={(e) => onKey(i, e)}
          onPaste={onPaste}
        />
      ))}
    </div>
  );
}
