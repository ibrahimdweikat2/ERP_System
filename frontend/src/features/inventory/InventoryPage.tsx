import { SearchableSelect } from "../../components/ui/SearchableSelect";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useParams } from "react-router-dom";
import { api, allPages, type Page } from "../../lib/api/client";
import { useAuth } from "../../lib/auth/context";
import {
  DataTable,
  Filters,
  Pagination,
} from "../../components/data-table/DataTable";
import {
  Badge,
  ErrorNotice,
  Loading,
  Modal,
} from "../../components/ui/Primitives";
import {
  type Balance,
  type Movement,
  type Serial,
  type Location,
  stockLabels,
  documentPath,
} from "./types";
export function InventoryPage() {
  const { view = "balances" } = useParams();
  const [search, setSearch] = useState("");
  const [location, setLocation] = useState("");
  const [page, setPage] = useState(1);
  const [history, setHistory] = useState<Serial | null>(null);
  const { can } = useAuth();
  const endpoint =
    view === "low-stock" || view === "quarantine" ? "balances" : view;
  const q = useQuery({
    queryKey: ["inventory", view, search, location, page],
    queryFn: ({ signal }) =>
      api<Page<Balance | Movement | Serial>>(
        `inventory/${endpoint}?search=${encodeURIComponent(search)}&page=${page}${location ? "&location_id=" + location : ""}${view === "quarantine" ? "&quarantine=1" : ""}`,
        { signal },
      ),
    enabled: ["balances", "movements", "serials"].includes(endpoint),
  });
  const loc = useQuery({
    queryKey: ["stock-location-options"],
    queryFn: ({ signal }) => allPages<Location>("stock-locations", signal),
  });
  const settings = useQuery({
    queryKey: ["inventory-settings"],
    queryFn: () =>
      api<{ data: { base_currency: string } }>("inventory/settings"),
  });
  const title = (
    {
      balances: "أرصدة المخزون",
      movements: "دفتر حركات المخزون",
      serials: "الأرقام التسلسلية",
      "low-stock": "المخزون المنخفض",
      quarantine: "الفحص والتالف والضمان",
    } as Record<string, string>
  )[view];
  const filteredLocations =
    view === "quarantine" ? loc.data?.filter((l) => !l.sellable) : loc.data;
  const data = q.data?.data ?? [];
  return (
    <>
      <header className="page-heading">
        <div>
          <p className="eyebrow">المخزون</p>
          <h1>{title ?? "المخزون"}</h1>
          <p>
            {endpoint === "balances"
              ? "الكميات الفعلية والمحجوزة والمتاحة للبيع بحسب الموقع الداخلي."
              : endpoint === "serials"
                ? "حالة كل جهاز وموقعه وتاريخ حركاته."
                : "حركات الكمية والقيمة وروابط مستنداتها."}{" "}
            {can("inventory.view_cost") &&
              settings.data &&
              `القيم بـ ${settings.data.data.base_currency}.`}
          </p>
        </div>
        <div className="heading-actions">
          <Link className="button" to="/inventory/documents/stock-transfers">
            التحويلات
          </Link>
          <Link className="button" to="/inventory/documents/stock-counts">
            الجرد
          </Link>
        </div>
      </header>
      <section className="panel">
        <Filters
          value={search}
          onChange={(v) => {
            setSearch(v);
            setPage(1);
          }}
        >
          <SearchableSelect
            aria-label="الموقع الداخلي"
            value={location}
            onChange={(e) => {
              setLocation(e.target.value);
              setPage(1);
            }}
          >
            <option value="">كل المواقع</option>
            {filteredLocations?.map((l) => (
              <option value={l.id} key={l.id}>
                {l.name_ar}
              </option>
            ))}
          </SearchableSelect>
        </Filters>
        <ErrorNotice error={q.error ?? loc.error} />
        {q.isPending ? (
          <Loading />
        ) : endpoint === "balances" ? (
          <DataTable
            rows={data as Balance[]}
            columns={[
              {
                key: "product",
                label: "المنتج",
                render: (r) => (
                  <div>
                    <strong>{r.product.name_ar}</strong>
                    <small className="cell-sub">
                      <bdi>{r.product.sku}</bdi>
                    </small>
                  </div>
                ),
              },
              {
                key: "location",
                label: "الموقع",
                render: (r) => r.location.name_ar,
              },
              {
                key: "qty_on_hand",
                label: "الفعلية",
                render: (r) => <bdi>{r.qty_on_hand}</bdi>,
              },
              {
                key: "reserved",
                label: "المحجوزة",
                render: (r) => <bdi>{r.qty_reserved}</bdi>,
              },
              {
                key: "available",
                label: "المتاحة للبيع",
                render: (r) => <bdi>{r.sellable_quantity}</bdi>,
              },
              ...(can("inventory.view_cost")
                ? [
                    {
                      key: "value",
                      label: "قيمة المخزون",
                      render: (r: Balance) => <bdi>{r.inventory_value}</bdi>,
                    },
                    {
                      key: "average",
                      label: "متوسط التكلفة",
                      render: (r: Balance) => <bdi>{r.average_cost}</bdi>,
                    },
                  ]
                : []),
            ]}
          />
        ) : endpoint === "movements" ? (
          <DataTable
            rows={data as Movement[]}
            columns={[
              {
                key: "date",
                label: "التاريخ",
                render: (r) => <bdi>{r.movement_date}</bdi>,
              },
              {
                key: "product",
                label: "المنتج",
                render: (r) => r.product.name_ar,
              },
              {
                key: "location",
                label: "الموقع",
                render: (r) => r.location.name_ar,
              },
              {
                key: "type",
                label: "الحركة",
                render: (r) => stockLabels[r.movement_type] ?? r.movement_type,
              },
              {
                key: "quantity",
                label: "الكمية",
                render: (r) => (
                  <Badge tone={r.direction === "in" ? "good" : "neutral"}>
                    <bdi>
                      {r.direction === "in" ? "+" : "−"}
                      {r.quantity}
                    </bdi>
                  </Badge>
                ),
              },
              ...(can("inventory.view_cost")
                ? [
                    {
                      key: "value",
                      label: "القيمة",
                      render: (r: Movement) => <bdi>{r.total_cost}</bdi>,
                    },
                  ]
                : []),
              {
                key: "source",
                label: "المستند",
                render: (r) =>
                  documentPath(r.source_type, r.source_id) ? (
                    <Link
                      className="text-link"
                      to={documentPath(r.source_type, r.source_id)}
                    >
                      عرض المستند #{r.source_id}
                    </Link>
                  ) : (
                    "—"
                  ),
              },
            ]}
          />
        ) : (
          <DataTable
            rows={data as Serial[]}
            columns={[
              {
                key: "serial",
                label: "الرقم التسلسلي",
                render: (r) => <bdi>{r.serial_no}</bdi>,
              },
              {
                key: "product",
                label: "المنتج",
                render: (r) => r.product.name_ar,
              },
              {
                key: "location",
                label: "الموقع",
                render: (r) => r.location?.name_ar ?? "—",
              },
              {
                key: "status",
                label: "الحالة",
                render: (r) => (
                  <Badge tone={r.status === "in_stock" ? "good" : "neutral"}>
                    {stockLabels[r.status] ?? r.status}
                  </Badge>
                ),
              },
              ...(can("inventory.view_cost")
                ? [
                    {
                      key: "cost",
                      label: "تكلفة الاستلام",
                      render: (r: Serial) => <bdi>{r.acquisition_cost}</bdi>,
                    },
                  ]
                : []),
              {
                key: "history",
                label: "الإجراءات",
                render: (r) => (
                  <button className="text-link" onClick={() => setHistory(r)}>
                    سجل الجهاز
                  </button>
                ),
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
      {history && (
        <SerialHistory serial={history} onClose={() => setHistory(null)} />
      )}
    </>
  );
}
type SerialEvent = {
  id: number;
  from_status: string | null;
  to_status: string;
  from_location: string | null;
  to_location: string | null;
  movement_date: string;
  source_type: string;
  source_id: number;
};
function SerialHistory({
  serial,
  onClose,
}: {
  serial: Serial;
  onClose: () => void;
}) {
  const [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["serial-history", serial.id, page],
    queryFn: () =>
      api<Page<SerialEvent>>(
        `inventory/serials/${serial.id}/history?page=${page}`,
      ),
  });
  return (
    <Modal title={`سجل الجهاز — ${serial.serial_no}`} onClose={onClose}>
      <ErrorNotice error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : (
        <DataTable
          rows={q.data?.data ?? []}
          columns={[
            {
              key: "date",
              label: "التاريخ",
              render: (r) => <bdi>{r.movement_date}</bdi>,
            },
            {
              key: "from",
              label: "من",
              render: (r) => r.from_location ?? "استلام جديد",
            },
            {
              key: "to",
              label: "إلى",
              render: (r) => r.to_location ?? "خارج المخزون",
            },
            {
              key: "status",
              label: "الحالة",
              render: (r) => stockLabels[r.to_status] ?? r.to_status,
            },
            {
              key: "source",
              label: "المرجع",
              render: (r) => (
                <Link
                  className="text-link"
                  to={documentPath(r.source_type, r.source_id)}
                >
                  المستند #{r.source_id}
                </Link>
              ),
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
    </Modal>
  );
}
