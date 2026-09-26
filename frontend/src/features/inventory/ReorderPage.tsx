import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { api, type Page } from "../../lib/api/client";
import {
  DataTable,
  Filters,
  Pagination,
} from "../../components/data-table/DataTable";
import { ErrorNotice, Loading } from "../../components/ui/Primitives";
type ReorderRow = {
  id: number;
  sku: string;
  name_ar: string;
  available: string;
  reorder_level: string;
  suggested_quantity: string;
};
export function ReorderPage() {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["inventory", "reorder", search, page],
    queryFn: () =>
      api<Page<ReorderRow>>(
        `inventory/reorder?search=${encodeURIComponent(search)}&page=${page}`,
      ),
  });
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المخزون</p>
          <h1>المخزون المنخفض</h1>
          <p>
            المنتجات التي يقل مخزونها المتاح للبيع عن حد إعادة الطلب، بما فيها
            المنتجات دون رصيد. الكميات المقترحة تحتاج مراجعة قبل الشراء.
          </p>
        </div>
      </header>
      <section className="panel">
        <Filters
          value={search}
          onChange={(v) => {
            setSearch(v);
            setPage(1);
          }}
        />
        <ErrorNotice error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : (
          <DataTable
            rows={q.data?.data ?? []}
            columns={[
              {
                key: "product",
                label: "المنتج",
                render: (r) => (
                  <div>
                    {r.name_ar}
                    <small className="cell-sub">
                      <bdi>{r.sku}</bdi>
                    </small>
                  </div>
                ),
              },
              {
                key: "available",
                label: "المتاح للبيع",
                render: (r) => <bdi>{r.available}</bdi>,
              },
              {
                key: "level",
                label: "حد إعادة الطلب",
                render: (r) => <bdi>{r.reorder_level}</bdi>,
              },
              {
                key: "suggested",
                label: "الكمية المقترحة",
                render: (r) => <bdi>{r.suggested_quantity}</bdi>,
              },
            ]}
          />
        )}
        <Pagination
          page={page}
          last={q.data?.last_page ?? 1}
          total={q.data?.total ?? 0}
          onPage={setPage}
        />
      </section>
    </>
  );
}
