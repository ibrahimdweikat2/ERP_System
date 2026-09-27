import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { Trash2 } from "lucide-react";
import { api } from "../../lib/api/client";
import { ErrorNotice, Modal } from "./Primitives";

/**
 * Confirmation dialog for a DELETE request. The server decides what may go: unused records
 * and never-posted drafts. Otherwise it answers with a reason, which is shown here.
 */
export function DeleteDialog({
  title,
  name,
  note,
  endpoint,
  onClose,
  onDeleted,
}: {
  title: string;
  name: string;
  note?: string;
  endpoint: string;
  onClose: () => void;
  onDeleted?: () => void;
}) {
  const client = useQueryClient();
  const remove = useMutation({
    mutationFn: () => api(endpoint, { method: "DELETE" }),
    onSuccess: async () => {
      await client.invalidateQueries();
      onClose();
      onDeleted?.();
    },
  });
  return (
    <Modal title={title} onClose={() => !remove.isPending && onClose()}>
      <div className="padded-form">
        <p>
          سيُحذف <strong>{name}</strong> نهائياً. لا يمكن التراجع عن هذه
          العملية، لكن نسخة منه تبقى في سجل التدقيق.
        </p>
        {note && <p className="muted">{note}</p>}
        <ErrorNotice error={remove.error} />
        <footer className="form-actions">
          <button
            type="button"
            className="button"
            disabled={remove.isPending}
            onClick={onClose}
          >
            إلغاء
          </button>
          <button
            type="button"
            className="button danger"
            disabled={remove.isPending}
            onClick={() => remove.mutate()}
          >
            {remove.isPending ? "جارٍ الحذف…" : "تأكيد الحذف"}
          </button>
        </footer>
      </div>
    </Modal>
  );
}

/** Row icon for list tables. */
export function DeleteIconButton({
  label,
  onClick,
}: {
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      className="icon-button danger"
      title="حذف"
      aria-label={`حذف ${label}`}
      onClick={onClick}
    >
      <Trash2 size={16} />
    </button>
  );
}

/** "Delete draft" button for a document page; render it only while the document is a draft. */
export function DeleteDraftButton({
  endpoint,
  name,
  backTo,
}: {
  endpoint: string;
  name: string;
  /** The list page to return to once the draft is gone. */
  backTo: string;
}) {
  const [open, setOpen] = useState(false);
  const navigate = useNavigate();
  return (
    <>
      <button
        type="button"
        className="button danger-outline"
        onClick={() => setOpen(true)}
      >
        <Trash2 size={16} />
        حذف المسودة
      </button>
      {open && (
        <DeleteDialog
          title="حذف المسودة"
          name={name}
          note="تُحذف المسودات فقط قبل ترحيلها؛ المستندات المرحّلة تُصحَّح بالعكس أو المرتجع."
          endpoint={endpoint}
          onClose={() => setOpen(false)}
          onDeleted={() => navigate(backTo, { replace: true })}
        />
      )}
    </>
  );
}
