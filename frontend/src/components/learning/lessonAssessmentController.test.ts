import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const modulePath = "./lessonAssessmentController.ts";
const controllerModule: typeof import("./lessonAssessmentController") = await import(modulePath);
const controllerUrl = new URL("./lessonAssessmentController.ts", import.meta.url);

type Deferred<T> = {
  readonly promise: Promise<T>;
  readonly resolve: (value: T) => void;
};

function deferred<T>(): Deferred<T> {
  let resolvePromise: ((value: T) => void) | undefined;
  const promise = new Promise<T>((resolve) => {
    resolvePromise = resolve;
  });
  return {
    promise,
    resolve: (value) => resolvePromise?.(value),
  };
}

test("controller keeps the start coordinator and no autosave coordinator implementation", async () => {
  const source = await readFile(controllerUrl, "utf8");

  assert.match(source, /createLessonAssessmentStartCoordinator/);
  assert.match(source, /createLessonAssessmentSingleFlightRequestGate/);
  assert.match(source, /resolveLessonAssessmentAnswerAction/);
  assert.doesNotMatch(source, /createLessonAssessmentSaveCoordinator/);
  assert.doesNotMatch(source, /scheduleTimeout/);
  assert.doesNotMatch(source, /cancelTimeout/);
  assert.doesNotMatch(source, /flushPending/);
});

test("single-flight request gate rejects a second save while the first is unresolved", async () => {
  let calls = 0;
  const first = deferred<boolean>();
  const gate = controllerModule.createLessonAssessmentSingleFlightRequestGate<boolean>();

  const started = gate.run(async () => {
    calls += 1;
    return first.promise;
  });
  const rejected = await gate.run(async () => {
    calls += 1;
    return true;
  });

  assert.equal(gate.isRunning(), true);
  assert.equal(rejected, null);
  assert.equal(calls, 1);

  first.resolve(true);
  assert.equal(await started, true);
  assert.equal(gate.isRunning(), false);

  assert.equal(
    await gate.run(async () => {
      calls += 1;
      return false;
    }),
    false,
  );
  assert.equal(calls, 2);
});

test("interaction lock treats an accepted explicit save as a hard editing and navigation stop", () => {
  assert.equal(
    controllerModule.lessonAssessmentInteractionLocked({
      explicitSaveRunning: true,
      navigating: false,
      submitting: false,
      resetting: false,
    }),
    true,
  );
  assert.equal(
    controllerModule.lessonAssessmentInteractionLocked({
      explicitSaveRunning: false,
      navigating: false,
      submitting: false,
      resetting: false,
    }),
    false,
  );
  assert.equal(
    controllerModule.lessonAssessmentInteractionLocked({
      explicitSaveRunning: false,
      navigating: true,
      submitting: false,
      resetting: false,
    }),
    true,
  );
});

test("answer action uses next visible navigation item to decide label and post-save step", () => {
  assert.deepEqual(
    controllerModule.resolveLessonAssessmentAnswerAction({
      nextQuestionId: "ordered-01",
    }),
    {
      type: "navigate",
      questionId: "ordered-01",
      label: "Ответить",
    },
  );
  assert.deepEqual(
    controllerModule.resolveLessonAssessmentAnswerAction({
      nextQuestionId: null,
    }),
    {
      type: "submit",
      label: "Ответить и отправить",
    },
  );
  assert.deepEqual(
    controllerModule.resolveLessonAssessmentAnswerAction({
      nextQuestionId: null,
    }),
    {
      type: "submit",
      label: "Ответить и отправить",
    },
  );
});

test("start coordinator shares one request and aborts after the last lease releases", async () => {
  let starts = 0;
  const pending = deferred<string>();
  const coordinator = controllerModule.createLessonAssessmentStartCoordinator<string>();
  const first = coordinator.acquire((signal) => {
    starts += 1;
    assert.equal(signal.aborted, false);
    return pending.promise;
  });
  const second = coordinator.acquire(() => {
    starts += 1;
    return pending.promise;
  });

  assert.equal(starts, 1);
  assert.strictEqual(first.promise, second.promise);
  first.release();
  assert.equal(second.signal.aborted, false);
  second.release();
  await Promise.resolve();
  assert.equal(second.signal.aborted, true);
  pending.resolve("aborted");
});

test("start coordinator creates one new request after a settled retry", async () => {
  let starts = 0;
  const coordinator = controllerModule.createLessonAssessmentStartCoordinator<string>();
  const first = coordinator.acquire(async () => {
    starts += 1;
    return "failed";
  });
  assert.equal(await first.promise, "failed");
  first.release();

  const retry = coordinator.acquire(async () => {
    starts += 1;
    return "ready";
  });
  assert.equal(await retry.promise, "ready");
  assert.equal(starts, 2);
  retry.release();
});

test("diagram state is unavailable while loading and resets for another question", () => {
  const loading = controllerModule.createLessonAssessmentDiagramState("diagram-01");
  const ready = controllerModule.reduceLessonAssessmentDiagramState(loading, {
    type: "loaded",
    questionId: "diagram-01",
    revision: 0,
  });
  const failed = controllerModule.reduceLessonAssessmentDiagramState(ready, {
    type: "failed",
    questionId: "diagram-01",
    revision: 0,
  });
  const retrying = controllerModule.reduceLessonAssessmentDiagramState(failed, {
    type: "retry",
    questionId: "diagram-01",
  });
  const nextQuestion = controllerModule.reduceLessonAssessmentDiagramState(retrying, {
    type: "question_changed",
    questionId: "diagram-02",
  });
  const staleReady = controllerModule.reduceLessonAssessmentDiagramState(retrying, {
    type: "loaded",
    questionId: "diagram-01",
    revision: 0,
  });

  assert.deepEqual(loading, { questionId: "diagram-01", status: "loading", revision: 0 });
  assert.equal(ready.status, "ready");
  assert.equal(failed.status, "error");
  assert.deepEqual(retrying, { questionId: "diagram-01", status: "loading", revision: 1 });
  assert.strictEqual(staleReady, retrying);
  assert.deepEqual(nextQuestion, { questionId: "diagram-02", status: "loading", revision: 0 });
});

test("submission availability always explains why the native button is disabled", () => {
  const incomplete = controllerModule.lessonAssessmentSubmissionAvailability({
    complete: false,
    diagramUnavailable: false,
    saving: false,
    submitting: false,
  });
  const diagram = controllerModule.lessonAssessmentSubmissionAvailability({
    complete: true,
    diagramUnavailable: true,
    saving: false,
    submitting: false,
  });

  assert.deepEqual(incomplete, {
    available: false,
    description: "Ответьте на все доступные задания, чтобы отправить экзамен.",
  });
  assert.equal(diagram.available, false);
  assert.match(diagram.description, /схем/);
});
