import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./compactBreadcrumbs.ts";
const { compactBreadcrumbItems }: typeof import("./compactBreadcrumbs") = await import(modulePath);

const items = [
  { href: "/", label: "Главная" },
  { href: "/lk", label: "Личный кабинет" },
  { href: "/lk/learning", label: "Обучение" },
  { href: "/lk/learning/gistologiya", label: "Гистология" },
  { href: "/lk/learning/gistologiya/tsitologiya", label: "Цитология" },
  { label: "Клеточная мембрана" },
] as const;

test("keeps short breadcrumb chains unchanged", () => {
  const visible = compactBreadcrumbItems(items.slice(0, 3));

  assert.deepEqual(
    visible.map((entry) => entry.kind),
    ["item", "item", "item"],
  );
});

test("collapses the middle of long breadcrumb chains", () => {
  const visible = compactBreadcrumbItems(items);

  assert.deepEqual(
    visible.map((entry) => (entry.kind === "item" ? entry.item.label : "…")),
    ["Главная", "…", "Цитология", "Клеточная мембрана"],
  );
});

test("keeps hidden breadcrumb labels available for accessible context", () => {
  const ellipsis = compactBreadcrumbItems(items).find((entry) => entry.kind === "ellipsis");

  assert.deepEqual(
    ellipsis?.kind === "ellipsis" ? ellipsis.hiddenItems.map((item) => item.label) : [],
    ["Личный кабинет", "Обучение", "Гистология"],
  );
});
