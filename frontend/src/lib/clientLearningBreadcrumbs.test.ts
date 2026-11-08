import assert from "node:assert/strict";
import test from "node:test";

import ts from "typescript";
import { readFile } from "node:fs/promises";

type ClientLearningBreadcrumbsModule = typeof import("./clientLearningBreadcrumbs");

async function loadClientLearningBreadcrumbsModule(): Promise<ClientLearningBreadcrumbsModule> {
  const source = await readFile(new URL("./clientLearningBreadcrumbs.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string) => {
    if (specifier === "@/lib/clientAccount") {
      return { clientAccountApiBaseUrl: () => "/backend" };
    }

    throw new Error(`Unexpected import: ${specifier}`);
  };

  Function("module", "exports", "require", output)(sandboxModule, sandboxModule.exports, requireStub);
  return sandboxModule.exports as ClientLearningBreadcrumbsModule;
}

const clientLearningBreadcrumbs = await loadClientLearningBreadcrumbsModule();

function jsonResponse(value: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(value), {
    status: 200,
    headers: { "content-type": "application/json" },
    ...init,
  });
}

test("loads subject and course labels for learning course breadcrumbs", async () => {
  const requests: string[] = [];
  const labels = await clientLearningBreadcrumbs.loadLearningBreadcrumbLabelOverrides(
    ["lk", "learning", "anatomiya", "miologiya"],
    {
      fetchLearning: async (input) => {
        requests.push(String(input));
        return jsonResponse({
          data: {
            subject: { slug: "anatomiya", title: "Анатомия", canonicalPath: "/lk/learning/anatomiya" },
            course: { slug: "miologiya", title: "Миология", canonicalPath: "/lk/learning/anatomiya/miologiya" },
            lessons: [],
          },
        });
      },
    },
  );

  assert.deepEqual(requests, ["/backend/api/v1/learning/subjects/anatomiya/courses/miologiya"]);
  assert.deepEqual(labels, {
    "/lk/learning/anatomiya": "Анатомия",
    "/lk/learning/anatomiya/miologiya": "Миология",
  });
});

test("loads lesson labels from the course navigation payload", async () => {
  const labels = await clientLearningBreadcrumbs.loadLearningBreadcrumbLabelOverrides(
    ["lk", "learning", "anatomiya", "artrologiya", "soedineniya-kostej-verhnej-konechnosti"],
    {
      fetchLearning: async () =>
        jsonResponse({
          data: {
            subject: { slug: "anatomiya", title: "Анатомия", canonicalPath: "/lk/learning/anatomiya" },
            course: { slug: "artrologiya", title: "Артрология", canonicalPath: "/lk/learning/anatomiya/artrologiya" },
            lessons: [
              {
                slug: "soedineniya-kostej-verhnej-konechnosti",
                title: "Соединения костей верхней конечности",
                canonicalPath: "/lk/learning/anatomiya/artrologiya/soedineniya-kostej-verhnej-konechnosti",
              },
            ],
          },
        }),
    },
  );

  assert.equal(
    labels["/lk/learning/anatomiya/artrologiya/soedineniya-kostej-verhnej-konechnosti"],
    "Соединения костей верхней конечности",
  );
});

test("keeps learning labels for the lesson assessment route", async () => {
  const labels = await clientLearningBreadcrumbs.loadLearningBreadcrumbLabelOverrides(
    ["lk", "learning", "anatomiya", "artrologiya", "lesson", "exam"],
    {
      fetchLearning: async () =>
        jsonResponse({
          data: {
            subject: { slug: "anatomiya", title: "Анатомия" },
            course: { slug: "artrologiya", title: "Артрология" },
            lessons: [{ slug: "lesson", title: "Урок" }],
          },
        }),
    },
  );

  assert.deepEqual(labels, {
    "/lk/learning/anatomiya": "Анатомия",
    "/lk/learning/anatomiya/artrologiya": "Артрология",
    "/lk/learning/anatomiya/artrologiya/lesson": "Урок",
  });
});

test("fails softly for non-learning routes, failed responses and malformed payloads", async () => {
  const nonLearning = await clientLearningBreadcrumbs.loadLearningBreadcrumbLabelOverrides(["subjects", "anatomiya"]);
  const failed = await clientLearningBreadcrumbs.loadLearningBreadcrumbLabelOverrides(["lk", "learning", "anatomiya"], {
    fetchLearning: async () => jsonResponse({ error: { code: "NO" } }, { status: 401 }),
  });
  const malformed = await clientLearningBreadcrumbs.loadLearningBreadcrumbLabelOverrides(["lk", "learning", "anatomiya"], {
    fetchLearning: async () => jsonResponse({ data: { subject: { slug: "anatomiya", title: "" } } }),
  });

  assert.deepEqual(nonLearning, {});
  assert.deepEqual(failed, {});
  assert.deepEqual(malformed, {});
});
