import { useEffect, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { Modal } from "../ui/Primitives";
export function PrintDialog({
  children,
  onClose,
}: {
  children: ReactNode;
  onClose: () => void;
}) {
  useEffect(() => {
    document.body.classList.add("printing-document");
    return () => document.body.classList.remove("printing-document");
  }, []);
  return createPortal(
    <div className="print-overlay">
      <Modal title="معاينة الطباعة" onClose={onClose}>
        {children}
      </Modal>
    </div>,
    document.body,
  );
}
