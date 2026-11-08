import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import ts from "typescript";

async function loadAssessmentApiModule(): Promise<typeof import("./lessonAssessmentApi")> {
  const source = await readFile(new URL("./lessonAssessmentApi.ts", import.meta.url), "utf8");
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
    if (specifier === "./lessonAssessmentContract") {
      return contract;
    }

    throw new Error(`Unexpected module in lesson assessment API test: ${specifier}`);
  };

  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as typeof import("./lessonAssessmentApi");
}

const assessmentApi = await loadAssessmentApiModule();

const apiBaseUrl = "https://backend.example.test";
const route = {
  subjectSlug: "anatomiya",
  courseSlug: "artrologiya",
  lessonSlug: "lesson-01",
} as const;
const xsrfCookie = "session=one; XSRF-TOKEN=csrf%20token%2Bvalue";

function readyPayload(revision = 0) {
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
      attempt: {
        id: 9,
        status: "in_progress",
        revision,
        submissionCount: 0,
        answeredCount: 0,
        correctCount: 0,
        lastQuestionId: null,
        submittedAt: null,
        finalizedAt: null,
        passedAt: null,
        failureReason: null,
        pendingManualQuestionCount: 0,
        reviewedManualQuestionCount: 0,
        reviewedManualQuestionIds: [],
        responses: [],
        lockedQuestionIds: [],
      },
    },
  };
}

function jsonResponse(payload: unknown, status = 200): Response {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { "content-type": "application/json" },
  });
}

test("starts through Sanctum CSRF with a decoded XSRF header", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const responses = [new Response(null, { status: 204 }), jsonResponse(readyPayload(), 201)];

  const result = await assessmentApi.startLessonAssessment(apiBaseUrl, route, {
    cookie: xsrfCookie,
    fetcher: async (input, init) => {
      calls.push({ url: String(input), init });
      return responses[calls.length - 1] ?? new Response(null, { status: 500 });
    },
  });

  assert.equal(result.status, "ready");
  assert.equal(calls[0]?.url, `${apiBaseUrl}/sanctum/csrf-cookie`);
  assert.equal(calls[0]?.init?.credentials, "include");
  assert.equal(calls[1]?.init?.method, "POST");
  assert.equal(calls[1]?.init?.credentials, "include");
  assert.equal(new Headers(calls[1]?.init?.headers).get("Accept"), "application/json");
  assert.equal(new Headers(calls[1]?.init?.headers).get("Content-Type"), "application/json");
  assert.equal(new Headers(calls[1]?.init?.headers).get("X-XSRF-TOKEN"), "csrf token+value");
  assert.deepEqual(JSON.parse(String(calls[1]?.init?.body)), {});
});

test("starts a retake with an explicit strict mutation payload", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const responses = [new Response(null, { status: 204 }), jsonResponse(readyPayload(), 201)];

  const result = await assessmentApi.retakeLessonAssessment(apiBaseUrl, route, {
    cookie: xsrfCookie,
    fetcher: async (input, init) => {
      calls.push({ url: String(input), init });
      return responses[calls.length - 1] ?? new Response(null, { status: 500 });
    },
  });

  assert.equal(result.status, "ready");
  assert.match(calls[1]?.url ?? "", /\/assessment\/attempts$/);
  assert.equal(calls[1]?.init?.method, "POST");
  assert.deepEqual(JSON.parse(String(calls[1]?.init?.body)), { retake: true });
});

test("resets local development assessment state through Sanctum CSRF", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const responses = [
    new Response(null, { status: 204 }),
    jsonResponse({ data: { status: "reset" } }),
  ];

  const result = await assessmentApi.resetLessonAssessmentDevelopmentState(apiBaseUrl, route, {
    cookie: xsrfCookie,
    fetcher: async (input, init) => {
      calls.push({ url: String(input), init });
      return responses[calls.length - 1] ?? new Response(null, { status: 500 });
    },
  });

  assert.deepEqual(result, { status: "ready" });
  assert.match(calls[1]?.url ?? "", /\/assessment\/development-state$/);
  assert.equal(calls[1]?.init?.method, "DELETE");
  assert.deepEqual(JSON.parse(String(calls[1]?.init?.body)), {});
});

test("rejects malformed development reset responses", async () => {
  const result = await assessmentApi.resetLessonAssessmentDevelopmentState(apiBaseUrl, route, {
    cookie: xsrfCookie,
    fetcher: async (input) => String(input).endsWith("/sanctum/csrf-cookie")
      ? new Response(null, { status: 204 })
      : jsonResponse({ data: { status: "almost-reset" } }),
  });

  assert.deepEqual(result, { status: "unavailable" });
});

test("loads only strict local development calibration terms without a csrf mutation", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const result = await assessmentApi.loadLessonAssessmentDevelopmentCalibrationTerms(
    apiBaseUrl,
    route,
    "diagram-01",
    {
      fetcher: async (input, init) => {
        calls.push({ url: String(input), init });
        return jsonResponse({
          data: {
            questionId: "diagram-01",
            terms: { name: "строение сустава", p01: "кость" },
          },
        });
      },
    },
  );

  assert.deepEqual(result, {
    status: "ready",
    terms: { name: "строение сустава", p01: "кость" },
  });
  assert.equal(calls.length, 1);
  assert.match(calls[0]?.url ?? "", /development-calibration-key\?questionId=diagram-01$/);
  assert.equal(calls[0]?.init?.method, "GET");
  assert.equal(calls[0]?.init?.credentials, "include");
});

test("rejects malformed or over-broad development calibration keys", async () => {
  const malformedPayloads = [
    { data: { questionId: "diagram-01", terms: { p01: ["кость"] } } },
    { data: { questionId: "diagram-02", terms: { p01: "кость" } } },
    { data: { questionId: "diagram-01", terms: { p01: "кость" }, answerKey: "hidden" } },
  ];

  for (const payload of malformedPayloads) {
    const result = await assessmentApi.loadLessonAssessmentDevelopmentCalibrationTerms(
      apiBaseUrl,
      route,
      "diagram-01",
      { fetcher: async () => jsonResponse(payload) },
    );
    assert.deepEqual(result, { status: "unavailable" });
  }
});

test("saves the exact revision payload with PATCH", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const result = await assessmentApi.saveLessonAssessment(
    apiBaseUrl,
    route,
    9,
    {
      revision: 3,
      lastQuestionId: "question-01",
      responses: [{ questionId: "question-01", response: { selectedOptionId: "o1" } }],
    },
    {
      cookie: xsrfCookie,
      fetcher: async (input, init) => {
        calls.push({ url: String(input), init });
        return calls.length === 1 ? new Response(null, { status: 204 }) : jsonResponse(readyPayload(4));
      },
    },
  );

  assert.equal(result.status, "ready");
  assert.match(calls[1]?.url ?? "", /\/assessment\/attempts\/9$/);
  assert.equal(calls[1]?.init?.method, "PATCH");
  assert.deepEqual(JSON.parse(String(calls[1]?.init?.body)), {
    revision: 3,
    lastQuestionId: "question-01",
    responses: [{ questionId: "question-01", response: { selectedOptionId: "o1" } }],
  });
});

test("sends locked question ids only when the caller confirms an answer", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const result = await assessmentApi.saveLessonAssessment(
    apiBaseUrl,
    route,
    9,
    {
      revision: 3,
      lastQuestionId: "question-01",
      responses: [{ questionId: "question-01", response: { selectedOptionId: "o1" } }],
      lockQuestionIds: ["question-01"],
    },
    {
      cookie: xsrfCookie,
      fetcher: async (input, init) => {
        calls.push({ url: String(input), init });
        return calls.length === 1 ? new Response(null, { status: 204 }) : jsonResponse(readyPayload(4));
      },
    },
  );

  assert.equal(result.status, "ready");
  assert.deepEqual(JSON.parse(String(calls[1]?.init?.body)), {
    revision: 3,
    lastQuestionId: "question-01",
    responses: [{ questionId: "question-01", response: { selectedOptionId: "o1" } }],
    lockQuestionIds: ["question-01"],
  });
});

test("accepts a saved in-progress response without leaking result fields", async () => {
  const base = readyPayload(4);
  const edited = {
    data: {
      ...base.data,
      attempt: {
        ...base.data.attempt,
        answeredCount: 1,
        lastQuestionId: "question-01",
        responses: [{ questionId: "question-01", response: { selectedOptionId: "o1" } }],
      },
    },
  };

  const result = await assessmentApi.saveLessonAssessment(
    apiBaseUrl,
    route,
    9,
    {
      revision: 3,
      lastQuestionId: "question-01",
      responses: [{ questionId: "question-01", response: { selectedOptionId: "o1" } }],
    },
    {
      cookie: xsrfCookie,
      fetcher: async (input) => String(input).endsWith("/sanctum/csrf-cookie")
        ? new Response(null, { status: 204 })
        : jsonResponse(edited),
    },
  );

  assert.equal(result.status, "ready");
  assert.equal(result.data.attempt?.submissionCount, 0);
  assert.equal(result.data.attempt?.submittedAt, null);
  assert.equal(result.data.attempt?.status, "in_progress");
  assert.equal(result.data.attempt !== null && "score" in result.data.attempt, false);
});

test("submits with the current revision and parses the exact automatic-failure response", async () => {
  const initial = readyPayload(4);
  const automaticGate = {
    passed: false,
    overall: {
      numerator: 0,
      denominator: 1,
      percentBps: 0,
      requiredBps: 8000,
      passed: false,
    },
    sections: [{
      sectionId: "main",
      numerator: 0,
      denominator: 1,
      percentBps: 0,
      requiredBps: 6000,
      passed: false,
    }],
    correctCount: 0,
    questionResults: [{
      questionId: "question-01",
      correct: false,
      earnedParts: 0,
      maxParts: 1,
      incorrectPartIds: [],
    }],
  };
  const payload = {
    data: {
      ...initial.data,
      attempt: {
        ...initial.data.attempt,
        status: "failed",
        revision: 4,
        submissionCount: 1,
        answeredCount: 2,
        lastQuestionId: "question-01",
        submittedAt: "2026-08-19T12:00:00.000Z",
        failureReason: "automatic_threshold_not_met",
        automaticGate,
        responses: [
          { questionId: "question-01", response: { selectedOptionId: "o1" } },
          { questionId: "manual-01", response: { text: "Развёрнутый ответ" } },
        ],
        lockedQuestionIds: ["question-01", "manual-01"],
      },
      submission: {
        number: 1,
        automaticGate: structuredClone(automaticGate),
      },
    },
  };
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];

  const result = await assessmentApi.submitLessonAssessment(apiBaseUrl, route, 9, 3, {
    cookie: xsrfCookie,
    fetcher: async (input, init) => {
      calls.push({ url: String(input), init });
      return calls.length === 1 ? new Response(null, { status: 204 }) : jsonResponse(payload);
    },
  });

  assert.equal(result.status, "ready");
  assert.match(calls[1]?.url ?? "", /\/assessment\/attempts\/9\/submissions$/);
  assert.deepEqual(JSON.parse(String(calls[1]?.init?.body)), { revision: 3 });
});

test("loads prefix suggestions without exposing the answer key or requesting CSRF", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const result = await assessmentApi.suggestLessonAssessmentAnswer(
    apiBaseUrl,
    route,
    9,
    "fill-01",
    "p01",
    "жел",
    {
      fetcher: async (input, init) => {
        calls.push({ url: String(input), init });
        return jsonResponse({ data: { suggestions: ["Жёлтая связка"] } });
      },
    },
  );

  assert.deepEqual(result, { status: "ready", suggestions: ["Жёлтая связка"] });
  assert.equal(calls.length, 1);
  assert.equal(calls[0]?.init?.method, "GET");
  assert.match(calls[0]?.url ?? "", /\/assessment\/attempts\/9\/suggestions\?/);
  assert.match(calls[0]?.url ?? "", /questionId=fill-01/);
  assert.match(calls[0]?.url ?? "", /partId=p01/);
  assert.match(calls[0]?.url ?? "", /prefix=%D0%B6%D0%B5%D0%BB/);
});

test("accepts a complete finite glossary instead of truncating suggestions to five", async () => {
  const suggestions = Array.from({ length: 23 }, (_, index) => `ligamentum ${index + 1}`);
  const result = await assessmentApi.suggestLessonAssessmentAnswer(
    apiBaseUrl,
    route,
    9,
    "diagram-01",
    "p01",
    "liga",
    { fetcher: async () => jsonResponse({ data: { suggestions } }) },
  );

  assert.deepEqual(result, { status: "ready", suggestions });
});

test("rejects an unbounded suggestion payload", async () => {
  const suggestions = Array.from({ length: 501 }, (_, index) => `term ${index + 1}`);
  const result = await assessmentApi.suggestLessonAssessmentAnswer(
    apiBaseUrl,
    route,
    9,
    "fill-01",
    "p01",
    "term",
    { fetcher: async () => jsonResponse({ data: { suggestions } }) },
  );

  assert.deepEqual(result, { status: "unavailable" });
});

test("does not request suggestions before three characters", async () => {
  let called = false;
  const result = await assessmentApi.suggestLessonAssessmentAnswer(
    apiBaseUrl,
    route,
    9,
    "fill-01",
    "p01",
    "же",
    { fetcher: async () => { called = true; return jsonResponse({ data: { suggestions: [] } }); } },
  );

  assert.deepEqual(result, { status: "unavailable" });
  assert.equal(called, false);
});

test("maps conflict, incomplete and access denials without throwing", async () => {
  async function resultFor(status: number, operation: "save" | "submission"): Promise<string> {
    const options = {
      cookie: xsrfCookie,
      fetcher: async (input: RequestInfo | URL): Promise<Response> =>
        String(input).endsWith("/sanctum/csrf-cookie")
          ? new Response(null, { status: 204 })
          : jsonResponse({ error: { code: "EXPECTED", message: "safe" } }, status),
    };

    if (operation === "save") {
      return (
        await assessmentApi.saveLessonAssessment(
          apiBaseUrl,
          route,
          9,
          { revision: 0, lastQuestionId: null, responses: [] },
          options,
        )
      ).status;
    }

    return (await assessmentApi.submitLessonAssessment(apiBaseUrl, route, 9, 0, options)).status;
  }

  assert.equal(await resultFor(409, "save"), "conflict");
  assert.equal(await resultFor(422, "submission"), "incomplete");
  assert.equal(await resultFor(401, "save"), "denied");
  assert.equal(await resultFor(403, "submission"), "denied");
  assert.equal(await resultFor(422, "save"), "unavailable");
});

test("maps AbortError from either CSRF or mutation fetch to aborted", async () => {
  for (const abortOnCall of [1, 2]) {
    let callCount = 0;
    const result = await assessmentApi.startLessonAssessment(apiBaseUrl, route, {
      cookie: xsrfCookie,
      signal: new AbortController().signal,
      fetcher: async () => {
        callCount += 1;
        if (callCount === abortOnCall) {
          throw new DOMException("The operation was aborted", "AbortError");
        }
        return new Response(null, { status: 204 });
      },
    });

    assert.deepEqual(result, { status: "aborted" });
  }
});

test("maps transport, CSRF, 5xx and malformed success responses to unavailable", async () => {
  const scenarios: ReadonlyArray<ReadonlyArray<Response>> = [
    [new Response(null, { status: 500 })],
    [new Response(null, { status: 204 }), new Response(null, { status: 500 })],
    [new Response(null, { status: 204 }), jsonResponse({ data: { assessment: {}, attempt: null } })],
  ];

  for (const responses of scenarios) {
    let index = 0;
    const result = await assessmentApi.startLessonAssessment(apiBaseUrl, route, {
      cookie: xsrfCookie,
      fetcher: async () => responses[index++] ?? new Response(null, { status: 500 }),
    });
    assert.deepEqual(result, { status: "unavailable" });
  }

  const transport = await assessmentApi.startLessonAssessment(apiBaseUrl, route, {
    cookie: xsrfCookie,
    fetcher: async () => {
      throw new TypeError("network payload must not escape");
    },
  });
  assert.deepEqual(transport, { status: "unavailable" });
});

test("fails closed for missing or invalid percent-encoded XSRF cookies", async () => {
  for (const cookie of ["", "XSRF-TOKEN=%E0%A4%A"]) {
    let callCount = 0;
    const result = await assessmentApi.startLessonAssessment(apiBaseUrl, route, {
      cookie,
      fetcher: async () => {
        callCount += 1;
        return new Response(null, { status: 204 });
      },
    });

    assert.deepEqual(result, { status: "unavailable" });
    assert.equal(callCount, 1);
  }
});
