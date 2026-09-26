export type ApiEnvelope<T> = { data: T };
export type Page<T> = {
  data: T[];
  current_page?: number;
  last_page?: number;
  total?: number;
  meta?: { current_page: number; last_page: number; total: number };
};
export async function allPages<T>(
  path: string,
  signal?: AbortSignal,
): Promise<T[]> {
  const rows: T[] = [];
  let page = 1;
  let last = 1;
  do {
    const result = await api<Page<T>>(
      `${path}${path.includes("?") ? "&" : "?"}per_page=100&page=${page}`,
      { signal },
    );
    rows.push(...result.data);
    last = result.meta?.last_page ?? result.last_page ?? 1;
    page++;
  } while (page <= last);
  return rows;
}
export class ApiError extends Error {
  status: number;
  code: string;
  errors: Record<string, string[]>;
  constructor(
    status: number,
    body: {
      message?: string;
      code?: string;
      errors?: Record<string, string[]>;
    },
  ) {
    super(body.message ?? "تعذر الاتصال بالخادم. حاول مرة أخرى.");
    this.status = status;
    this.code = body.code ?? "NETWORK_ERROR";
    this.errors = body.errors ?? {};
  }
}
const cookie = (name: string) =>
  document.cookie
    .split("; ")
    .find((row) => row.startsWith(name + "="))
    ?.split("=")
    .slice(1)
    .join("=");
export async function csrf(): Promise<void> {
  const response = await fetch("/sanctum/csrf-cookie", {
    credentials: "include",
    headers: { Accept: "application/json" },
  });
  if (!response.ok)
    throw new ApiError(response.status, {
      message: "تعذر بدء جلسة آمنة. أعد المحاولة.",
    });
}
/** Rows per page in every paged table. */
export const PAGE_SIZE = 10;
// A paged table request (one with page=) gets the standard size unless it chose its
// own; option lists call allPages, which asks for 100 explicitly.
function withPageSize(path: string, method: string): string {
  if (method !== "GET" || !/[?&]page=/.test(path) || /[?&]per_page=/.test(path))
    return path;
  return `${path}&per_page=${PAGE_SIZE}`;
}
export async function api<T>(
  path: string,
  options: {
    method?: string;
    body?: unknown;
    key?: string;
    signal?: AbortSignal;
  } = {},
): Promise<T> {
  const method = options.method ?? "GET";
  if (method !== "GET" && !cookie("XSRF-TOKEN")) await csrf();
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  const multipart = options.body instanceof FormData;
  if (options.body !== undefined && !multipart)
    headers["Content-Type"] = "application/json";
  const token = cookie("XSRF-TOKEN");
  if (token) headers["X-XSRF-TOKEN"] = decodeURIComponent(token);
  if (options.key) headers["Idempotency-Key"] = options.key;
  const response = await fetch("/api/v1/" + withPageSize(path, method), {
    method,
    credentials: "include",
    headers,
    signal: options.signal,
    body:
      options.body === undefined
        ? undefined
        : multipart
          ? (options.body as FormData)
          : JSON.stringify(options.body),
  });
  const body: unknown = await response.json().catch(() => ({}));
  if (!response.ok)
    throw new ApiError(
      response.status,
      body as ConstructorParameters<typeof ApiError>[1],
    );
  return body as T;
}
