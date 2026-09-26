import { useState } from "react";
import { Modal, ErrorNotice } from "../ui/Primitives";
export function ApprovalDialog({
  title,
  onConfirm,
  onClose,
  children,
}: {
  title: string;
  onConfirm: (reason: string) => Promise<void>;
  onClose: () => void;
  children: React.ReactNode;
}) {
  const [reason, setReason] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>();
  return (
    <Modal
      title={title}
      onClose={() => {
        if (!pending) onClose();
      }}
    >
      <form
        onSubmit={async (e) => {
          e.preventDefault();
          setPending(true);
          try {
            await onConfirm(reason);
            onClose();
          } catch (err) {
            setError(err);
          } finally {
            setPending(false);
          }
        }}
      >
        {children}
        <label className="field">
          <span>سبب العملية</span>
          <textarea
            required
            minLength={5}
            maxLength={1000}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </label>
        <ErrorNotice error={error} />
        <footer className="form-actions">
          <button className="button primary" disabled={pending}>
            {pending ? "جارٍ التنفيذ…" : "تأكيد العملية"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}
