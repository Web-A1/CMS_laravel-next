import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import ts from "typescript";

type BreadcrumbLabelsModule = typeof import("./breadcrumbLabels");

async function loadBreadcrumbLabelsModule(): Promise<BreadcrumbLabelsModule> {
  const source = await readFile(new URL("./breadcrumbLabels.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };

  Function("module", "exports", output)(sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as BreadcrumbLabelsModule;
}

const breadcrumbLabels = await loadBreadcrumbLabelsModule();

test("offer breadcrumbs skip the removed public catalog", () => {
  assert.deepEqual(breadcrumbLabels.buildBreadcrumbs(["offers", "legacy-product-318"]), [
    { href: "/", label: "Главная" },
    { href: "/offers/legacy-product-318", label: "Предложение" },
  ]);
});

test("uses readable labels for known subject and course slugs", () => {
  const crumbs = breadcrumbLabels.buildBreadcrumbs(["subjects", "anatomiya", "artrologiya"]);

  assert.deepEqual(
    crumbs.map(({ label }) => label),
    ["Главная", "Предметы", "Анатомия", "Артрология"],
  );
  assert.equal(crumbs.at(-1)?.href, "/subjects/anatomiya/artrologiya");
});

test("labels the lesson assessment route", () => {
  const crumbs = breadcrumbLabels.buildBreadcrumbs(["lk", "learning", "anatomiya", "artrologiya", "lesson", "exam"]);

  assert.equal(crumbs.at(-1)?.label, "Экзамен");
});

test("keeps canonical hrefs while hiding unknown dynamic slugs", () => {
  const subjectCrumbs = breadcrumbLabels.buildBreadcrumbs(["subjects", "new-subject", "new-course"]);
  const articleCrumbs = breadcrumbLabels.buildBreadcrumbs(["articles", "new-article"]);

  assert.deepEqual(
    subjectCrumbs.map(({ label }) => label),
    ["Главная", "Предметы", "Предмет", "Курс"],
  );
  assert.equal(subjectCrumbs.at(-1)?.href, "/subjects/new-subject/new-course");
  assert.equal(articleCrumbs.at(-1)?.label, "Статья");
  assert.equal(articleCrumbs.at(-1)?.href, "/articles/new-article");
});
