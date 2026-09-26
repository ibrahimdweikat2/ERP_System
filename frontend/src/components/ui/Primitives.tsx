import {
  useEffect,
  useRef,
  useId,
  type ReactNode,
  type InputHTMLAttributes,
} from "react";
import { AlertCircle, Inbox, LoaderCircle, X } from "lucide-react";
import { ApiError } from "../../lib/api/client";
export function Loading() {
  return (
    <div className="state">
      <LoaderCircle className="spin" size={24} />
      <span>جارٍ تحميل البيانات…</span>
    </div>
  );
}
export function ErrorNotice({ error }: { error: unknown }) {
  if (!error) return null;
  return (
    <div className="notice error" role="alert">
      <AlertCircle size={18} />
      <div>
        {error instanceof Error ? error.message : "تعذر إتمام العملية."}
        {error instanceof ApiError &&
          Object.entries(error.errors).map(([key, messages]) => (
            <p key={key}>{messages.join(" ")}</p>
          ))}
      </div>
    </div>
  );
}
export function Empty({
  title = "لا توجد سجلات بعد",
  children,
}: {
  title?: string;
  children?: ReactNode;
}) {
  return (
    <div className="state">
      <Inbox size={30} />
      <h3>{title}</h3>
      {children && <p>{children}</p>}
    </div>
  );
}
export function Badge({
  children,
  tone = "neutral",
}: {
  children: ReactNode;
  tone?: "neutral" | "good" | "warning" | "danger";
}) {
  return <span className={"badge " + tone}>{children}</span>;
}
export function Field({
  label,
  error,
  icon,
  trailing,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  error?: string;
  icon?: ReactNode;
  trailing?: ReactNode;
}) {
  const id = useId();
  const input = (
    <input
      id={id}
      aria-invalid={!!error}
      aria-describedby={error ? id + "-error" : undefined}
      {...props}
    />
  );
  return (
    <label className="field" htmlFor={id}>
      <span>{label}</span>
      {icon || trailing ? (
        <div className="field-control">
          {icon && <span className="field-icon" aria-hidden="true">{icon}</span>}
          {input}
          {trailing}
        </div>
      ) : (
        input
      )}
      {error && <small id={id + "-error"}>{error}</small>}
    </label>
  );
}
export function Modal({
  title,
  children,
  onClose,
}: {
  title: string;
  children: ReactNode;
  onClose: () => void;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const id = useId();
  useEffect(() => {
    const el = ref.current;
    el?.showModal();
    return () => el?.close();
  }, []);
  return (
    <dialog ref={ref} onCancel={onClose} aria-labelledby={id}>
      <header className="dialog-header">
        <h2 id={id}>{title}</h2>
        <button className="icon-button" onClick={onClose} aria-label="إغلاق">
          <X size={20} />
        </button>
      </header>
      {children}
    </dialog>
  );
}
