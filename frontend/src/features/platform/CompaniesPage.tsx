import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { api, type ApiEnvelope, type Page } from "../../lib/api/client";
import type { Company } from "../../types/platform";
import {
  DataTable,
  Filters,
  Pagination,
} from "../../components/data-table/DataTable";
import {
  Badge,
  ErrorNotice,
  Field,
  Loading,
  Modal,
} from "../../components/ui/Primitives";

export function CompanyStatusBadge({ status }: { status: Company["status"] }) {
  return status === "active" ? (
    <Badge tone="good">نشطة</Badge>
  ) : (
    <Badge tone="danger">موقوفة</Badge>
  );
}

/** Superadmin: every company on the platform, and creating a new one. */
export function CompaniesPage() {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const navigate = useNavigate();
  const q = useQuery({
    queryKey: ["platform-companies", search, page],
    queryFn: ({ signal }) =>
      api<Page<Company>>(
        `platform/companies?search=${encodeURIComponent(search)}&page=${page}`,
        { signal },
      ),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المنصة / الشركات</p>
          <h1>الشركات</h1>
          <p>
            كل شركة تعمل ببياناتها المستقلة، وتبدأ بالأدوار نفسها: المالك،
            المحاسب، المبيعات والصندوق، المخزون والمشتريات، التحصيل.
          </p>
        </div>
        <button className="button primary" onClick={() => setCreating(true)}>
          <Plus size={18} />
          شركة جديدة
        </button>
      </header>
      <section className="panel">
        <Filters
          value={search}
          onChange={(v) => {
            setSearch(v);
            setPage(1);
          }}
          placeholder="ابحث باسم الشركة"
        />
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            empty="لا توجد شركات مطابقة."
            columns={[
              {
                key: "name",
                label: "الشركة",
                render: (c) => (
                  <Link to={`/platform/companies/${c.id}`}>{c.name}</Link>
                ),
              },
              {
                key: "status",
                label: "الحالة",
                render: (c) => <CompanyStatusBadge status={c.status} />,
              },
              {
                key: "owners",
                label: "المالكون",
                render: (c) =>
                  c.owners_count ? (
                    c.owners_count
                  ) : (
                    <Badge tone="warning">بلا مالك</Badge>
                  ),
              },
              { key: "users", label: "المستخدمون", render: (c) => c.users_count ?? 0 },
              {
                key: "created",
                label: "تاريخ الإنشاء",
                render: (c) => (
                  <bdi>{new Date(c.created_at).toLocaleDateString("ar-PS")}</bdi>
                ),
              },
            ]}
          />
        )}
        <Pagination
          page={page}
          last={q.data?.meta?.last_page ?? q.data?.last_page ?? 1}
          total={q.data?.meta?.total ?? q.data?.total ?? 0}
          onPage={setPage}
        />
      </section>
      {creating && (
        <CreateCompany
          onClose={() => setCreating(false)}
          onCreated={(company) => navigate(`/platform/companies/${company.id}`)}
        />
      )}
    </>
  );
}

function CreateCompany({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: (company: Company) => void;
}) {
  const [name, setName] = useState("");
  const client = useQueryClient();
  const save = useMutation({
    mutationFn: () =>
      api<ApiEnvelope<Company>>("platform/companies", {
        method: "POST",
        body: { name: name.trim() },
      }),
    onSuccess: async (result) => {
      await client.invalidateQueries({ queryKey: ["platform-companies"] });
      onCreated(result.data);
    },
  });
  return (
    <Modal
      title="شركة جديدة"
      onClose={() => {
        if (!save.isPending) onClose();
      }}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <Field
          label="اسم الشركة"
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          maxLength={160}
          autoFocus
        />
        <p className="muted small">
          تُنشأ الشركة مع الأدوار القياسية ودليل الحسابات وتسلسل المستندات
          ومواقع المخزون. أضف مالكها بعد الإنشاء.
        </p>
        <ErrorNotice error={save.error} />
        <footer className="form-actions">
          <button
            className="button"
            type="button"
            disabled={save.isPending}
            onClick={onClose}
          >
            إلغاء
          </button>
          <button className="button primary" disabled={save.isPending || !name.trim()}>
            {save.isPending ? "جارٍ الإنشاء…" : "إنشاء الشركة"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}
