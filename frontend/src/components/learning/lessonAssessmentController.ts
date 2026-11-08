type LessonAssessmentStartEntry<T> = {
  readonly controller: AbortController;
  readonly promise: Promise<T>;
  leases: number;
  settled: boolean;
};

export type LessonAssessmentSingleFlightRequestGate<T> = {
  readonly isRunning: () => boolean;
  readonly run: (request: () => Promise<T>) => Promise<T | null>;
};

export function createLessonAssessmentSingleFlightRequestGate<T>(): LessonAssessmentSingleFlightRequestGate<T> {
  let inFlight: Promise<T> | null = null;

  return {
    isRunning: () => inFlight !== null,
    run: async (request) => {
      if (inFlight !== null) {
        return null;
      }

      const accepted = request();
      inFlight = accepted;
      try {
        return await accepted;
      } finally {
        if (inFlight === accepted) {
          inFlight = null;
        }
      }
    },
  };
}

export type LessonAssessmentAnswerAction =
  | { readonly type: "navigate"; readonly questionId: string; readonly label: "Ответить" }
  | { readonly type: "submit"; readonly label: "Ответить и отправить" };

export function lessonAssessmentInteractionLocked({
  explicitSaveRunning,
  navigating,
  submitting,
  resetting,
}: {
  readonly explicitSaveRunning: boolean;
  readonly navigating: boolean;
  readonly submitting: boolean;
  readonly resetting: boolean;
}): boolean {
  return explicitSaveRunning || navigating || submitting || resetting;
}

export function resolveLessonAssessmentAnswerAction({
  nextQuestionId,
}: {
  readonly nextQuestionId: string | null;
}): LessonAssessmentAnswerAction {
  if (nextQuestionId !== null) {
    return {
      type: "navigate",
      questionId: nextQuestionId,
      label: "Ответить",
    };
  }

  return {
    type: "submit",
    label: "Ответить и отправить",
  };
}

export type LessonAssessmentStartLease<T> = {
  readonly promise: Promise<T>;
  readonly signal: AbortSignal;
  readonly release: () => void;
};

export type LessonAssessmentStartCoordinator<T> = {
  readonly acquire: (start: (signal: AbortSignal) => Promise<T>) => LessonAssessmentStartLease<T>;
};

export function createLessonAssessmentStartCoordinator<T>(): LessonAssessmentStartCoordinator<T> {
  let current: LessonAssessmentStartEntry<T> | null = null;

  function acquire(start: (signal: AbortSignal) => Promise<T>): LessonAssessmentStartLease<T> {
    if (current === null || current.settled) {
      const controller = new AbortController();
      const entry: LessonAssessmentStartEntry<T> = {
        controller,
        promise: start(controller.signal),
        leases: 0,
        settled: false,
      };
      current = entry;
      void entry.promise.then(
        () => {
          entry.settled = true;
          if (entry.leases === 0 && current === entry) {
            current = null;
          }
        },
        () => {
          entry.settled = true;
          if (entry.leases === 0 && current === entry) {
            current = null;
          }
        },
      );
    }

    const entry = current;
    entry.leases += 1;
    let released = false;
    return {
      promise: entry.promise,
      signal: entry.controller.signal,
      release: () => {
        if (released) {
          return;
        }
        released = true;
        entry.leases -= 1;
        queueMicrotask(() => {
          if (entry.leases !== 0) {
            return;
          }
          if (!entry.settled) {
            entry.controller.abort();
          }
          if (entry.settled && current === entry) {
            current = null;
          }
        });
      },
    };
  }

  return { acquire };
}

export type LessonAssessmentDiagramState = {
  readonly questionId: string;
  readonly status: "loading" | "ready" | "error";
  readonly revision: number;
};

export type LessonAssessmentDiagramEvent =
  | { readonly type: "loaded" | "failed"; readonly questionId: string; readonly revision: number }
  | { readonly type: "retry" | "question_changed"; readonly questionId: string };

export function createLessonAssessmentDiagramState(questionId: string): LessonAssessmentDiagramState {
  return { questionId, status: "loading", revision: 0 };
}

export function reduceLessonAssessmentDiagramState(
  state: LessonAssessmentDiagramState,
  event: LessonAssessmentDiagramEvent,
): LessonAssessmentDiagramState {
  if (event.type === "question_changed" || event.questionId !== state.questionId) {
    return createLessonAssessmentDiagramState(event.questionId);
  }
  if ((event.type === "loaded" || event.type === "failed") && event.revision !== state.revision) {
    return state;
  }
  if (event.type === "loaded") {
    return { ...state, status: "ready" };
  }
  if (event.type === "failed") {
    return { ...state, status: "error" };
  }

  return { ...state, status: "loading", revision: state.revision + 1 };
}

export type LessonAssessmentSubmissionAvailability = {
  readonly available: boolean;
  readonly description: string;
};

export function lessonAssessmentSubmissionAvailability({
  complete,
  diagramUnavailable,
  saving,
  submitting,
}: {
  readonly complete: boolean;
  readonly diagramUnavailable: boolean;
  readonly saving: boolean;
  readonly submitting: boolean;
}): LessonAssessmentSubmissionAvailability {
  if (submitting) {
    return { available: false, description: "Отправляем ответы." };
  }
  if (saving) {
    return { available: false, description: "Дождитесь сохранения ответов перед отправкой." };
  }
  if (diagramUnavailable) {
    return { available: false, description: "Загрузите отмеченные схемы, прежде чем отправлять экзамен." };
  }
  if (!complete) {
    return { available: false, description: "Ответьте на все доступные задания, чтобы отправить экзамен." };
  }

  return { available: true, description: "Все доступные задания заполнены. Экзамен можно отправить." };
}
