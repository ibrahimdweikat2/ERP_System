import type { ReactNode } from "react";
export function PrintView({
  title,
  number,
  children,
}: {
  title: string;
  number: string;
  children: ReactNode;
}) {
  return (
    <article className="print-document" dir="rtl">
      <header>
        <h1>{title}</h1>
        <bdi>{number}</bdi>
      </header>
      {children}
      <button className="button no-print" onClick={() => window.print()}>
        طباعة
      </button>
    </article>
  );
}
