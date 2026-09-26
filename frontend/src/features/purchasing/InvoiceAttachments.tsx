import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { api, type ApiEnvelope } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import { ErrorNotice, Loading } from "../../components/ui/Primitives";
type Attachment = {
  id: number;
  original_name: string;
  size: number;
  mime_type: string;
  created_at: string;
};
export function InvoiceAttachments({
  id,
  required,
}: {
  id: number;
  required: boolean;
}) {
  const { can } = useAuth();
  const client = useQueryClient();
  const [file, setFile] = useState<File | null>(null);
  const path = `supplier-invoices/${id}/attachments`;
  const q = useQuery({
    queryKey: ["invoice-attachments", id],
    queryFn: () => api<ApiEnvelope<Attachment[]>>(path),
  });
  const upload = useMutation({
    mutationFn: () => {
      const body = new FormData();
      body.append("file", file!);
      return api(path, { method: "POST", body });
    },
    onSuccess: async () => {
      setFile(null);
      await client.invalidateQueries({ queryKey: ["invoice-attachments", id] });
    },
  });
  return (
    <section className="panel">
      <h2>مرفقات الفاتورة</h2>
      <p>
        {required
          ? "تتطلب السياسة إرفاق نسخة الفاتورة قبل الترحيل."
          : "أرفق نسخة فاتورة المورد والمستندات المؤيدة."}{" "}
        يمكن تنزيل الملفات وفق صلاحيات عرض الفاتورة.
      </p>
      {q.isPending ? (
        <Loading />
      ) : (
        <ul>
          {q.data?.data.map((a) => (
            <li key={a.id}>
              <a
                className="text-link"
                href={`/api/v1/${path}/${a.id}/download`}
              >
                {a.original_name}
              </a>{" "}
              <small>({Math.ceil(a.size / 1024)} كيلوبايت)</small>
            </li>
          ))}
        </ul>
      )}
      {can("purchasing.invoice") && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (file) upload.mutate();
          }}
          className="form-actions"
        >
          <label className="field">
            <span>نسخة فاتورة المورد</span>
            <input
              key={q.dataUpdatedAt}
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
          </label>
          <button className="button" disabled={!file || upload.isPending}>
            إرفاق الملف
          </button>
          <small>PDF أو JPEG أو PNG؛ الحد الأقصى 10 ميغابايت.</small>
        </form>
      )}
      <ErrorNotice error={q.error || upload.error} />
    </section>
  );
}
