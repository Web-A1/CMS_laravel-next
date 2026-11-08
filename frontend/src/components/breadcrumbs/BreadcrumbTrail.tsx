import Link from "next/link";

import type { BreadcrumbItem } from "./breadcrumbLabels";
import { compactBreadcrumbItems } from "./compactBreadcrumbs";

export default function BreadcrumbTrail({ items }: { readonly items: ReadonlyArray<BreadcrumbItem> }) {
  const visibleCrumbs = compactBreadcrumbItems(items);

  return (
    <nav aria-label="Хлебные крошки" className="breadcrumbs page-gutter">
      <ol className="breadcrumbs__list text-sm font-normal leading-5 sm:text-xs">
        {visibleCrumbs.map((entry, index) => {
          const separator = index > 0 ? (
            <span aria-hidden="true" className="breadcrumbs__sep">/</span>
          ) : null;

          if (entry.kind === "ellipsis") {
            return (
              <li key="ellipsis" className="breadcrumbs__item min-w-0 max-w-full">
                {separator}
                <span className="breadcrumbs__ellipsis">
                  <span className="sr-only">Скрытые разделы: {entry.hiddenItems.map((item) => item.label).join(", ")}</span>
                  <span aria-hidden="true">…</span>
                </span>
              </li>
            );
          }

          const crumb = entry.item;
          const isCurrent = entry.sourceIndex === items.length - 1;

          return (
            <li key={`${crumb.href}-${crumb.label}-${entry.sourceIndex}`} className="breadcrumbs__item min-w-0 max-w-full">
              {separator}
              {isCurrent ? (
                <span aria-current="page" className="breadcrumbs__current min-w-0 break-words">{crumb.label}</span>
              ) : (
                <Link href={crumb.href} className="breadcrumbs__link">{crumb.label}</Link>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
