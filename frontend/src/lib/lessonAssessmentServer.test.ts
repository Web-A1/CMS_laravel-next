import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import ts from "typescript";

type ServerModule = Pick<typeof import("./lessonAssessmentServer"), "loadLessonAssessment">;

const route = {
  subjectSlug: "anatomiya",
  courseSlug: "artrologiya",
  lessonSlug: "lesson-01",
} as const;

function validPayload(): unknown {
  return {
    data: {
      assessment: {
        id: 1,
        contentVersion: 37,
        scoringPolicy: {
          schemaVersion: 2,
          contentVersion: 37,
          mode: "hybrid",
          automaticWeightBps: 7000,
          manualWeightBps: 3000,
          automaticGateOverallRequiredBps: 8000,
          automaticGateSectionRequiredBps: 6000,
          manualRequiredBps: 6000,
          finalRequiredBps: 8000,
          automaticQuestionCount: 1,
          manualQuestionCount: 1,
        },
        title: "Экзамен",
        totalCount: 2,
        retakeAvailable: true,
        sections: [{ id: "main", title: "Основное" }],
        questions: [
          {
            id: "question-01",
            sectionId: "main",
            gradingMode: "automatic",
            type: "single_choice",
            prompt: "Выберите ответ",
            options: [{ id: "o1", label: "Ответ" }],
          },
          {
            id: "manual-01",
            sectionId: "main",
            gradingMode: "manual",
            type: "long_text",
            prompt: "Объясните ответ",
            rows: 5,
          },
        ],
      },
      attempt: null,
    },
  };
}

async function loadServerModule(): Promise<ServerModule> {
  const source = await readFile(new URL("./lessonAssessmentServer.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, {
    compilerOptions: {
      esModuleInterop: true,
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const contractPath = "./lessonAssessmentContract.ts";
  const contract: typeof import("./lessonAssessmentContract") = await import(contractPath);
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "server-only") return {};
    if (specifier === "@/lib/accountAuth") {
      return {
        accountBackendRequest: async (apiBaseUrl: string, refererPath: string) => ({
          resolvedApiBaseUrl: apiBaseUrl,
          headers: { Referer: refererPath },
        }),
      };
    }
    if (specifier === "@/lib/apiErrors") return { rethrowNextDynamicServerError: () => undefined };
    if (specifier === "@/lib/lessonAssessmentContract") return contract;
    throw new Error(`Unexpected module in lesson assessment server test: ${specifier}`);
  };

  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as ServerModule;
}

const server = await loadServerModule();

async function withFetch(response: Response, run: () => Promise<void>): Promise<void> {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => response;
  try {
    await run();
  } finally {
    globalThis.fetch = originalFetch;
  }
}

function jsonResponse(payload: unknown, status: number): Response {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { "content-type": "application/json" },
  });
}

test("server loader returns ready only for an exact valid 200 and uses no-store", async () => {
  const originalFetch = globalThis.fetch;
  let capturedUrl = "";
  let capturedInit: RequestInit | undefined;
  globalThis.fetch = async (input, init) => {
    capturedUrl = String(input);
    capturedInit = init;
    return jsonResponse(validPayload(), 200);
  };

  try {
    const result = await server.loadLessonAssessment("https://backend.test", "/lk/lesson", route);
    assert.equal(result.status, "ready");
    assert.equal(
      capturedUrl,
      "https://backend.test/api/v1/learning/subjects/anatomiya/courses/artrologiya/lessons/lesson-01/assessment",
    );
    assert.equal(capturedInit?.cache, "no-store");
    assert.equal(new Headers(capturedInit?.headers).get("Referer"), "/lk/lesson");
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test("server loader rejects malformed 200 and every non-200 success status", async () => {
  await withFetch(jsonResponse({ data: { assessment: {}, attempt: null } }, 200), async () => {
    assert.deepEqual(await server.loadLessonAssessment("https://backend.test", "/lk/lesson", route), {
      status: "unavailable",
    });
  });

  for (const status of [201, 204, 206]) {
    const response = status === 204 ? new Response(null, { status }) : jsonResponse(validPayload(), status);
    await withFetch(response, async () => {
      assert.deepEqual(await server.loadLessonAssessment("https://backend.test", "/lk/lesson", route), {
        status: "unavailable",
      });
    });
  }
});

test("server loader maps only 401/403, 404 and 5xx to their exact states", async () => {
  for (const status of [401, 403]) {
    await withFetch(jsonResponse({ error: { code: "DENIED", message: "denied" } }, status), async () => {
      assert.deepEqual(await server.loadLessonAssessment("https://backend.test", "/lk/lesson", route), {
        status: "denied",
      });
    });
  }

  await withFetch(jsonResponse({ error: { code: "MISSING", message: "missing" } }, 404), async () => {
    assert.deepEqual(await server.loadLessonAssessment("https://backend.test", "/lk/lesson", route), {
      status: "not_found",
    });
  });

  for (const status of [500, 502, 503]) {
    await withFetch(jsonResponse({ error: { code: "FAILED", message: "failed" } }, status), async () => {
      assert.deepEqual(await server.loadLessonAssessment("https://backend.test", "/lk/lesson", route), {
        status: "unavailable",
      });
    });
  }
});
