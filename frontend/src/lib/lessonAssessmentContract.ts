export type LessonAssessmentRoute = {
  readonly subjectSlug: string;
  readonly courseSlug: string;
  readonly lessonSlug: string;
};

export type LessonAssessmentSection = {
  readonly id: string;
  readonly title: string;
};

export type LessonAssessmentOption = {
  readonly id: string;
  readonly label: string;
};

export type LessonAssessmentPart = {
  readonly id: string;
  readonly prompt: string;
  readonly optionIds: ReadonlyArray<string>;
};

export type LessonAssessmentTextPart = {
  readonly id: string;
  readonly prompt: string;
};

export type LessonAssessmentCallout = {
  readonly targetId?: string;
  readonly partId: string;
  readonly x: number;
  readonly y: number;
  readonly labelX?: number;
  readonly labelY?: number;
  readonly labelSide?: "left" | "right" | "top" | "bottom";
  readonly labelWidth?: number;
  readonly leaderStyle?: "elbow" | "straight";
  readonly targetMarker?: "point" | "arrow";
};

type LessonAssessmentQuestionBase = {
  readonly id: string;
  readonly sectionId: string;
  readonly gradingMode: "automatic" | "manual";
  readonly prompt: string;
};

type LessonAssessmentChoiceQuestionBase = LessonAssessmentQuestionBase & {
  readonly options: ReadonlyArray<LessonAssessmentOption>;
};

export type SingleChoiceQuestion = LessonAssessmentChoiceQuestionBase & {
  readonly type: "single_choice";
};

export type MultipleChoiceQuestion = LessonAssessmentChoiceQuestionBase & {
  readonly type: "multiple_choice";
  readonly orderMatters: false;
};

export type SlotChoiceQuestion = LessonAssessmentChoiceQuestionBase & {
  readonly type: "slot_choice";
  readonly parts: ReadonlyArray<LessonAssessmentPart>;
};

export type OrderedChoiceQuestion = LessonAssessmentChoiceQuestionBase & {
  readonly type: "ordered_choice";
};

export type DiagramLabelsQuestion = LessonAssessmentChoiceQuestionBase & {
  readonly type: "diagram_labels";
  readonly parts: ReadonlyArray<LessonAssessmentPart>;
  readonly assetPath: string;
  readonly width: number;
  readonly height: number;
  readonly callouts: ReadonlyArray<LessonAssessmentCallout>;
};

export type TextFieldsQuestion = LessonAssessmentQuestionBase & {
  readonly type: "text_fields";
  readonly parts: ReadonlyArray<LessonAssessmentTextPart>;
};

export type DiagramTextQuestion = LessonAssessmentQuestionBase & {
  readonly type: "diagram_text";
  readonly parts: ReadonlyArray<LessonAssessmentTextPart>;
  readonly assetPath: string;
  readonly width: number;
  readonly height: number;
  readonly callouts: ReadonlyArray<LessonAssessmentCallout>;
};

export type LongTextQuestion = LessonAssessmentQuestionBase & {
  readonly type: "long_text";
  readonly rows: number;
};

export type LessonAssessmentQuestion =
  | SingleChoiceQuestion
  | MultipleChoiceQuestion
  | SlotChoiceQuestion
  | OrderedChoiceQuestion
  | DiagramLabelsQuestion
  | TextFieldsQuestion
  | DiagramTextQuestion
  | LongTextQuestion;

export type LessonAssessmentResponse =
  | { readonly selectedOptionId: string }
  | { readonly selectedOptionIds: ReadonlyArray<string> }
  | { readonly selections: Readonly<Record<string, string>> }
  | { readonly texts: Readonly<Record<string, string>> }
  | { readonly text: string };

export type LessonAssessmentResponseEntry = {
  readonly questionId: string;
  readonly response: LessonAssessmentResponse;
};

export type LessonAssessmentRatioScore = {
  readonly numerator: number;
  readonly denominator: number;
  readonly percentBps: number;
  readonly requiredBps: 8000 | 6000;
  readonly passed: boolean;
};

export type LessonAssessmentSectionScore = LessonAssessmentRatioScore & {
  readonly sectionId: string;
  readonly requiredBps: 6000;
};

export type LessonAssessmentScore = {
  readonly checked: true;
  readonly passed: boolean;
  readonly mastered: boolean;
  readonly overall: LessonAssessmentRatioScore & { readonly requiredBps: 8000 };
  readonly components: {
    readonly automaticBps: number;
    readonly manualBps: number;
    readonly automaticWeightBps: 7000;
    readonly manualWeightBps: 3000;
  };
};

export type LessonAssessmentScoringPolicy = {
  readonly schemaVersion: 2;
  readonly contentVersion: number;
  readonly mode: "hybrid";
  readonly automaticWeightBps: 7000;
  readonly manualWeightBps: 3000;
  readonly automaticGateOverallRequiredBps: 8000;
  readonly automaticGateSectionRequiredBps: 6000;
  readonly manualRequiredBps: 6000;
  readonly finalRequiredBps: 8000;
  readonly automaticQuestionCount: number;
  readonly manualQuestionCount: number;
};

export type LessonAssessmentAutomaticGate = {
  readonly passed: boolean;
  readonly overall: LessonAssessmentRatioScore & { readonly requiredBps: 8000 };
  readonly sections: ReadonlyArray<LessonAssessmentSectionScore>;
  readonly correctCount: number;
  readonly questionResults: ReadonlyArray<LessonAssessmentQuestionResult>;
};

export type LessonAssessmentManualResult = {
  readonly questionId: string;
  readonly scoreBps: number;
  readonly comment: string | null;
};

type LessonAssessmentAttemptBase = {
  readonly id: number;
  readonly revision: number;
  readonly submissionCount: number;
  readonly answeredCount: number;
  readonly correctCount: number;
  readonly lastQuestionId: string | null;
  readonly submittedAt: string | null;
  readonly finalizedAt: string | null;
  readonly passedAt: string | null;
  readonly failureReason: "automatic_threshold_not_met" | "manual_threshold_not_met" | "combined_threshold_not_met" | null;
  readonly pendingManualQuestionCount: number;
  readonly reviewedManualQuestionCount: number;
  readonly reviewedManualQuestionIds: ReadonlyArray<string>;
  readonly responses: ReadonlyArray<LessonAssessmentResponseEntry>;
  readonly lockedQuestionIds: ReadonlyArray<string>;
};

export type LessonAssessmentInProgressAttempt = LessonAssessmentAttemptBase & {
  readonly status: "in_progress";
  readonly submittedAt: null;
  readonly finalizedAt: null;
  readonly passedAt: null;
  readonly failureReason: null;
  readonly pendingManualQuestionCount: 0;
  readonly reviewedManualQuestionCount: 0;
};

export type LessonAssessmentAutomaticFailedAttempt = LessonAssessmentAttemptBase & {
  readonly status: "failed";
  readonly submittedAt: string;
  readonly finalizedAt: null;
  readonly passedAt: null;
  readonly failureReason: "automatic_threshold_not_met";
  readonly pendingManualQuestionCount: 0;
  readonly reviewedManualQuestionCount: 0;
  readonly automaticGate: LessonAssessmentAutomaticGate & { readonly passed: false };
};

export type LessonAssessmentPendingReviewAttempt = LessonAssessmentAttemptBase & {
  readonly status: "pending_review";
  readonly submittedAt: string;
  readonly finalizedAt: null;
  readonly passedAt: null;
  readonly failureReason: null;
  readonly manualProgressBps: number;
  readonly automaticGate: LessonAssessmentAutomaticGate & { readonly passed: true };
};

export type LessonAssessmentFinalizedAttempt = LessonAssessmentAttemptBase & {
  readonly status: "failed" | "passed";
  readonly submittedAt: string;
  readonly finalizedAt: string;
  readonly passedAt: string | null;
  readonly failureReason: "manual_threshold_not_met" | "combined_threshold_not_met" | null;
  readonly automaticGate: LessonAssessmentAutomaticGate & { readonly passed: true };
  readonly score: LessonAssessmentScore;
  readonly manualResults: ReadonlyArray<LessonAssessmentManualResult>;
};

export type LessonAssessmentAttempt =
  | LessonAssessmentInProgressAttempt
  | LessonAssessmentAutomaticFailedAttempt
  | LessonAssessmentPendingReviewAttempt
  | LessonAssessmentFinalizedAttempt;

export type LessonAssessment = {
  readonly id: number;
  readonly contentVersion: number;
  readonly scoringPolicy: LessonAssessmentScoringPolicy;
  readonly title: string;
  readonly totalCount: number;
  readonly retakeAvailable: boolean;
  readonly sections: ReadonlyArray<LessonAssessmentSection>;
  readonly questions: ReadonlyArray<LessonAssessmentQuestion>;
};

export type LessonAssessmentData = {
  readonly assessment: LessonAssessment;
  readonly attempt: LessonAssessmentAttempt | null;
};

export type LessonAssessmentQuestionResult = {
  readonly questionId: string;
  readonly correct: boolean;
  readonly earnedParts: number;
  readonly maxParts: number;
  readonly incorrectPartIds: ReadonlyArray<string>;
};

export type LessonAssessmentSubmission = {
  readonly number: number;
  readonly automaticGate: LessonAssessmentAutomaticGate;
};

export type LessonAssessmentSubmissionData = {
  readonly assessment: LessonAssessment;
  readonly attempt: LessonAssessmentAttempt;
  readonly submission: LessonAssessmentSubmission;
};

export type LessonAssessmentSaveInput = {
  readonly revision: number;
  readonly lastQuestionId: string | null;
  readonly responses: ReadonlyArray<LessonAssessmentResponseEntry>;
  readonly lockQuestionIds?: ReadonlyArray<string>;
};

type JsonRecord = Record<string, unknown>;

const IDENTIFIER_PATTERN = /^[a-z0-9][a-z0-9-]{1,63}$/;
const ROUTE_SLUG_PATTERN = /^[a-z0-9][a-z0-9-]{0,127}$/;
const BACKEND_TIMESTAMP_PATTERN =
  /^([1-9][0-9]{3})-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])T([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])(?:\.([0-9]{1,6}))?(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])$/;
const FORBIDDEN_KEYS = new Set<string>([
  "answer",
  "answerkey",
  "assetkey",
  "author",
  "authoredby",
  "authors",
  "correctanswer",
  "correctness",
  "correctoptionid",
  "correctoptionids",
  "correctresponse",
  "cropbox",
  "disk",
  "distractoroptionids",
  "iscorrect",
  "mimetype",
  "objectkey",
  "sha256",
  "solution",
  "source",
  "sourcepage",
  "sourcepath",
  "sources",
  "storage",
  "audit",
  "auditevent",
  "audittrail",
  "reviewer",
  "reviewerid",
  "reviewedby",
  "rubric",
  "rubrics",
  "expectedanswer",
  "expectedanswers",
  "responsehash",
  "requestfingerprint",
  "idempotencykeyhash",
]);

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function hasExactKeys(record: JsonRecord, required: ReadonlyArray<string>): boolean {
  const allowed = new Set(required);
  const keys = Object.keys(record);

  return required.every((key) => Object.hasOwn(record, key)) && keys.every((key) => allowed.has(key));
}

function containsForbiddenKey(value: unknown, visited: WeakSet<object> = new WeakSet<object>()): boolean {
  if (typeof value !== "object" || value === null) {
    return false;
  }
  if (visited.has(value)) {
    return true;
  }
  visited.add(value);

  if (Array.isArray(value)) {
    return value.some((entry) => containsForbiddenKey(entry, visited));
  }

  for (const [key, nestedValue] of Object.entries(value)) {
    const normalizedKey = key.toLowerCase().replace(/[_-]/g, "");
    if (FORBIDDEN_KEYS.has(normalizedKey) || containsForbiddenKey(nestedValue, visited)) {
      return true;
    }
  }

  return false;
}

function isIdentifier(value: unknown): value is string {
  return typeof value === "string" && IDENTIFIER_PATTERN.test(value);
}

function isNonEmptyString(value: unknown): value is string {
  return typeof value === "string" && value.trim() !== "";
}

function isPositiveInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isSafeInteger(value) && value > 0;
}

function isNonNegativeInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isSafeInteger(value) && value >= 0;
}

function isBackendTimestamp(value: unknown): value is string {
  if (typeof value !== "string") {
    return false;
  }
  const match = BACKEND_TIMESTAMP_PATTERN.exec(value);
  if (
    match === null ||
    /[+-]14:(?!00$)/.test(value) ||
    !Number.isFinite(Date.parse(value))
  ) {
    return false;
  }

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const calendarDate = new Date(Date.UTC(year, month - 1, day));
  return (
    calendarDate.getUTCFullYear() === year &&
    calendarDate.getUTCMonth() === month - 1 &&
    calendarDate.getUTCDate() === day
  );
}

function isNullableBackendTimestamp(value: unknown): value is string | null {
  return value === null || isBackendTimestamp(value);
}

function uniqueStrings(values: ReadonlyArray<string>): boolean {
  return new Set(values).size === values.length;
}

export function lessonAssessmentPath(route: LessonAssessmentRoute): string | null {
  if (
    !ROUTE_SLUG_PATTERN.test(route.subjectSlug) ||
    !ROUTE_SLUG_PATTERN.test(route.courseSlug) ||
    !ROUTE_SLUG_PATTERN.test(route.lessonSlug)
  ) {
    return null;
  }

  return [
    "/api/v1/learning/subjects",
    encodeURIComponent(route.subjectSlug),
    "courses",
    encodeURIComponent(route.courseSlug),
    "lessons",
    encodeURIComponent(route.lessonSlug),
    "assessment",
  ].join("/");
}

function parseOptions(value: unknown): ReadonlyArray<LessonAssessmentOption> | null {
  if (!Array.isArray(value)) {
    return null;
  }

  const options: Array<LessonAssessmentOption> = [];
  const ids = new Set<string>();
  for (const option of value) {
    if (
      !isRecord(option) ||
      !hasExactKeys(option, ["id", "label"]) ||
      !isIdentifier(option.id) ||
      !isNonEmptyString(option.label) ||
      ids.has(option.id)
    ) {
      return null;
    }
    ids.add(option.id);
    options.push({ id: option.id, label: option.label });
  }

  return options;
}

function parseParts(
  value: unknown,
  optionIds: ReadonlySet<string>,
): ReadonlyArray<LessonAssessmentPart> | null {
  if (!Array.isArray(value) || value.length === 0) {
    return null;
  }

  const parts: Array<LessonAssessmentPart> = [];
  const partIds = new Set<string>();
  for (const part of value) {
    if (
      !isRecord(part) ||
      !hasExactKeys(part, ["id", "prompt", "optionIds"]) ||
      !isIdentifier(part.id) ||
      !isNonEmptyString(part.prompt) ||
      !Array.isArray(part.optionIds) ||
      partIds.has(part.id)
    ) {
      return null;
    }

    const referencedOptionIds: Array<string> = [];
    for (const optionId of part.optionIds) {
      if (!isIdentifier(optionId) || !optionIds.has(optionId)) {
        return null;
      }
      referencedOptionIds.push(optionId);
    }
    if (!uniqueStrings(referencedOptionIds)) {
      return null;
    }

    partIds.add(part.id);
    parts.push({ id: part.id, prompt: part.prompt, optionIds: referencedOptionIds });
  }

  return parts;
}

function parseTextParts(value: unknown): ReadonlyArray<LessonAssessmentTextPart> | null {
  if (!Array.isArray(value) || value.length === 0) {
    return null;
  }

  const parts: Array<LessonAssessmentTextPart> = [];
  const partIds = new Set<string>();
  for (const part of value) {
    if (
      !isRecord(part) ||
      !hasExactKeys(part, ["id", "prompt"]) ||
      !isIdentifier(part.id) ||
      !isNonEmptyString(part.prompt) ||
      partIds.has(part.id)
    ) {
      return null;
    }
    partIds.add(part.id);
    parts.push({ id: part.id, prompt: part.prompt });
  }

  return parts;
}

function parseCallouts(
  value: unknown,
  parts: ReadonlyArray<{ readonly id: string }>,
): ReadonlyArray<LessonAssessmentCallout> | null {
  if (!Array.isArray(value)) {
    return null;
  }

  const partIds = new Set(parts.map((part) => part.id));
  const referencedPartIds = new Set<string>();
  const referencedTargetIds = new Set<string>();
  const callouts: Array<LessonAssessmentCallout> = [];
  for (const callout of value) {
    const hasTargetId = isRecord(callout) && Object.hasOwn(callout, "targetId");
    const baseKeys = hasTargetId ? ["targetId", "partId", "x", "y"] : ["partId", "x", "y"];
    const hasLeaderStyle = isRecord(callout) && Object.hasOwn(callout, "leaderStyle");
    const hasTargetMarker = isRecord(callout) && Object.hasOwn(callout, "targetMarker");
    const hasInlineLabel = isRecord(callout) &&
      Object.hasOwn(callout, "labelX") && Object.hasOwn(callout, "labelY");
    const hasCustomLabelPlacement = hasInlineLabel &&
      Object.hasOwn(callout, "labelSide") && Object.hasOwn(callout, "labelWidth");
    const exactKeys = [
      ...baseKeys,
      ...(hasInlineLabel ? ["labelX", "labelY"] : []),
      ...(hasCustomLabelPlacement ? ["labelSide", "labelWidth"] : []),
      ...(hasLeaderStyle ? ["leaderStyle"] : []),
      ...(hasTargetMarker ? ["targetMarker"] : []),
    ];
    if (
      !isRecord(callout) ||
      !hasExactKeys(callout, exactKeys) ||
      (hasTargetId && !isIdentifier(callout.targetId)) ||
      !isIdentifier(callout.partId) ||
      !partIds.has(callout.partId) ||
      typeof callout.x !== "number" ||
      !Number.isFinite(callout.x) ||
      callout.x < 0 ||
      callout.x > 1 ||
      typeof callout.y !== "number" ||
      !Number.isFinite(callout.y) ||
      callout.y < 0 ||
      callout.y > 1
    ) {
      return null;
    }

    const targetId = hasTargetId ? callout.targetId as string : callout.partId;
    if (referencedTargetIds.has(targetId)) {
      return null;
    }

    if (
      (hasInlineLabel || hasCustomLabelPlacement) &&
      (typeof callout.labelX !== "number" ||
        !Number.isFinite(callout.labelX) ||
        callout.labelX < 0 ||
        callout.labelX > 1 ||
        typeof callout.labelY !== "number" ||
        !Number.isFinite(callout.labelY) ||
        callout.labelY < 0 ||
        callout.labelY > 1)
    ) {
      return null;
    }

    if (
      hasCustomLabelPlacement &&
      ((callout.labelSide !== "left" &&
        callout.labelSide !== "right" &&
        callout.labelSide !== "top" &&
        callout.labelSide !== "bottom") ||
        typeof callout.labelWidth !== "number" ||
        !Number.isFinite(callout.labelWidth) ||
        callout.labelWidth <= 0 ||
        callout.labelWidth > 1 ||
        (callout.labelSide === "left" && callout.labelWidth > (callout.labelX as number)) ||
        (callout.labelSide === "right" && (callout.labelX as number) + callout.labelWidth > 1) ||
        ((callout.labelSide === "top" || callout.labelSide === "bottom") &&
          ((callout.labelX as number) - callout.labelWidth / 2 < 0 ||
            (callout.labelX as number) + callout.labelWidth / 2 > 1)))
    ) {
      return null;
    }

    if (
      hasLeaderStyle &&
      ((!hasInlineLabel && !hasCustomLabelPlacement) ||
        (callout.leaderStyle !== "elbow" && callout.leaderStyle !== "straight"))
    ) {
      return null;
    }
    if (
      hasTargetMarker &&
      callout.targetMarker !== "point" &&
      callout.targetMarker !== "arrow"
    ) {
      return null;
    }

    referencedPartIds.add(callout.partId);
    referencedTargetIds.add(targetId);
    const parsedCallout: LessonAssessmentCallout = {
      ...(hasTargetId ? { targetId } : {}),
      partId: callout.partId,
      x: callout.x,
      y: callout.y,
      ...(hasInlineLabel || hasCustomLabelPlacement
        ? { labelX: callout.labelX as number, labelY: callout.labelY as number }
        : {}),
      ...(hasCustomLabelPlacement
        ? {
            labelSide: callout.labelSide as "left" | "right" | "top" | "bottom",
            labelWidth: callout.labelWidth as number,
          }
        : {}),
      ...(hasLeaderStyle
        ? { leaderStyle: callout.leaderStyle as "elbow" | "straight" }
        : {}),
      ...(hasTargetMarker
        ? { targetMarker: callout.targetMarker as "point" | "arrow" }
        : {}),
    };
    callouts.push(parsedCallout);
  }

  if (parts.some((part) => part.id !== "name" && !referencedPartIds.has(part.id))) {
    return null;
  }

  for (const part of parts) {
    const partCallouts = callouts.filter((callout) => callout.partId === part.id);
    if (
      (part.id === "name" && partCallouts.length > 1) ||
      (partCallouts.length > 1 && partCallouts.some((callout) => callout.targetId === undefined)) ||
      partCallouts.filter((callout) => callout.labelX !== undefined).length > 1
    ) {
      return null;
    }
  }

  return callouts;
}

function isCanonicalAssetPath(value: unknown, route: LessonAssessmentRoute): value is string {
  const assessmentPath = lessonAssessmentPath(route);
  if (typeof value !== "string" || assessmentPath === null) {
    return false;
  }

  const prefix = `${assessmentPath}/assets/`;
  if (!value.startsWith(prefix)) {
    return false;
  }

  const assetKey = value.slice(prefix.length);
  return isIdentifier(assetKey) && value === `${prefix}${encodeURIComponent(assetKey)}`;
}

function parseQuestion(value: unknown, route: LessonAssessmentRoute): LessonAssessmentQuestion | null {
  if (
    !isRecord(value) || !isIdentifier(value.id) || !isIdentifier(value.sectionId) ||
    (value.gradingMode !== "automatic" && value.gradingMode !== "manual") || !isNonEmptyString(value.prompt)
  ) {
    return null;
  }

  if (value.type === "text_fields") {
    const parts = parseTextParts(value.parts);
    return hasExactKeys(value, ["id", "sectionId", "type", "gradingMode", "prompt", "parts"]) && parts !== null
      ? { id: value.id, sectionId: value.sectionId, type: value.type, gradingMode: value.gradingMode, prompt: value.prompt, parts }
      : null;
  }

  if (value.type === "long_text") {
    return hasExactKeys(value, ["id", "sectionId", "type", "gradingMode", "prompt", "rows"]) &&
      isPositiveInteger(value.rows) &&
      value.rows <= 12
      ? { id: value.id, sectionId: value.sectionId, type: value.type, gradingMode: value.gradingMode, prompt: value.prompt, rows: value.rows }
      : null;
  }

  if (value.type === "diagram_text") {
    const parts = parseTextParts(value.parts);
    if (
      !hasExactKeys(value, [
        "id",
        "sectionId",
        "type",
        "gradingMode",
        "prompt",
        "parts",
        "assetPath",
        "width",
        "height",
        "callouts",
      ]) ||
      parts === null ||
      !isCanonicalAssetPath(value.assetPath, route) ||
      !isPositiveInteger(value.width) ||
      !isPositiveInteger(value.height)
    ) {
      return null;
    }
    const callouts = parseCallouts(value.callouts, parts);
    return callouts === null
      ? null
      : {
          id: value.id,
          sectionId: value.sectionId,
          type: value.type,
          gradingMode: value.gradingMode,
          prompt: value.prompt,
          parts,
          assetPath: value.assetPath,
          width: value.width,
          height: value.height,
          callouts,
        };
  }

  const options = parseOptions(value.options);
  if (options === null) {
    return null;
  }
  const optionIds = new Set(options.map((option) => option.id));

  if (value.type === "single_choice") {
    return hasExactKeys(value, ["id", "sectionId", "type", "gradingMode", "prompt", "options"])
      ? { id: value.id, sectionId: value.sectionId, type: value.type, gradingMode: value.gradingMode, prompt: value.prompt, options }
      : null;
  }

  if (value.type === "multiple_choice") {
    return hasExactKeys(value, ["id", "sectionId", "type", "gradingMode", "prompt", "options", "orderMatters"]) &&
      value.orderMatters === false
      ? {
          id: value.id,
          sectionId: value.sectionId,
          type: value.type,
          gradingMode: value.gradingMode,
          prompt: value.prompt,
          options,
          orderMatters: false,
        }
      : null;
  }

  if (value.type === "ordered_choice") {
    return hasExactKeys(value, ["id", "sectionId", "type", "gradingMode", "prompt", "options"])
      ? { id: value.id, sectionId: value.sectionId, type: value.type, gradingMode: value.gradingMode, prompt: value.prompt, options }
      : null;
  }

  if (value.type === "slot_choice") {
    const parts = parseParts(value.parts, optionIds);
    return hasExactKeys(value, ["id", "sectionId", "type", "gradingMode", "prompt", "options", "parts"]) && parts !== null
      ? { id: value.id, sectionId: value.sectionId, type: value.type, gradingMode: value.gradingMode, prompt: value.prompt, options, parts }
      : null;
  }

  if (value.type === "diagram_labels") {
    const parts = parseParts(value.parts, optionIds);
    if (
      !hasExactKeys(value, [
        "id",
        "sectionId",
        "type",
        "gradingMode",
        "prompt",
        "options",
        "parts",
        "assetPath",
        "width",
        "height",
        "callouts",
      ]) ||
      parts === null ||
      !isCanonicalAssetPath(value.assetPath, route) ||
      !isPositiveInteger(value.width) ||
      !isPositiveInteger(value.height)
    ) {
      return null;
    }
    const callouts = parseCallouts(value.callouts, parts);
    return callouts === null
      ? null
      : {
          id: value.id,
          sectionId: value.sectionId,
          type: value.type,
          gradingMode: value.gradingMode,
          prompt: value.prompt,
          options,
          parts,
          assetPath: value.assetPath,
          width: value.width,
          height: value.height,
          callouts,
        };
  }

  return null;
}

function parseScoringPolicy(value: unknown, contentVersion: number, questions: ReadonlyArray<LessonAssessmentQuestion>): LessonAssessmentScoringPolicy | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["schemaVersion", "contentVersion", "mode", "automaticWeightBps", "manualWeightBps", "automaticGateOverallRequiredBps", "automaticGateSectionRequiredBps", "manualRequiredBps", "finalRequiredBps", "automaticQuestionCount", "manualQuestionCount"]) ||
    value.schemaVersion !== 2 || value.contentVersion !== contentVersion || value.mode !== "hybrid" ||
    value.automaticWeightBps !== 7000 || value.manualWeightBps !== 3000 ||
    value.automaticGateOverallRequiredBps !== 8000 || value.automaticGateSectionRequiredBps !== 6000 ||
    value.manualRequiredBps !== 6000 || value.finalRequiredBps !== 8000 ||
    !isNonNegativeInteger(value.automaticQuestionCount) || !isNonNegativeInteger(value.manualQuestionCount) ||
    value.automaticQuestionCount + value.manualQuestionCount !== questions.length ||
    value.automaticQuestionCount !== questions.filter((question) => question.gradingMode === "automatic").length ||
    value.manualQuestionCount !== questions.filter((question) => question.gradingMode === "manual").length ||
    value.automaticQuestionCount === 0 || value.manualQuestionCount === 0
  ) {
    return null;
  }

  return value as LessonAssessmentScoringPolicy;
}

function parseAssessment(value: unknown, route: LessonAssessmentRoute): LessonAssessment | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["id", "contentVersion", "scoringPolicy", "title", "totalCount", "retakeAvailable", "sections", "questions"]) ||
    !isPositiveInteger(value.id) ||
    !isPositiveInteger(value.contentVersion) ||
    !isNonEmptyString(value.title) ||
    !isNonNegativeInteger(value.totalCount) ||
    typeof value.retakeAvailable !== "boolean" ||
    !Array.isArray(value.sections) ||
    !Array.isArray(value.questions) ||
    value.totalCount !== value.questions.length
  ) {
    return null;
  }

  const sections: Array<LessonAssessmentSection> = [];
  const sectionIds = new Set<string>();
  for (const section of value.sections) {
    if (
      !isRecord(section) ||
      !hasExactKeys(section, ["id", "title"]) ||
      !isIdentifier(section.id) ||
      !isNonEmptyString(section.title) ||
      sectionIds.has(section.id)
    ) {
      return null;
    }
    sectionIds.add(section.id);
    sections.push({ id: section.id, title: section.title });
  }

  const questions: Array<LessonAssessmentQuestion> = [];
  const questionIds = new Set<string>();
  for (const rawQuestion of value.questions) {
    const question = parseQuestion(rawQuestion, route);
    if (question === null || questionIds.has(question.id) || !sectionIds.has(question.sectionId)) {
      return null;
    }
    questionIds.add(question.id);
    questions.push(question);
  }

  const scoringPolicy = parseScoringPolicy(value.scoringPolicy, value.contentVersion, questions);
  if (scoringPolicy === null) {
    return null;
  }

  return {
    id: value.id,
    contentVersion: value.contentVersion,
    scoringPolicy,
    title: value.title,
    totalCount: value.totalCount,
    retakeAvailable: value.retakeAvailable,
    sections,
    questions,
  };
}

function questionParts(question: LessonAssessmentQuestion): ReadonlyArray<{ readonly id: string }> {
  if (question.type === "long_text") {
    return [{ id: "answer" }];
  }

  return question.type === "slot_choice" ||
    question.type === "diagram_labels" ||
    question.type === "text_fields" ||
    question.type === "diagram_text"
    ? question.parts
    : [];
}

function parseResponse(
  value: unknown,
  question: LessonAssessmentQuestion,
): LessonAssessmentResponse | null {
  if (!isRecord(value)) {
    return null;
  }

  if (question.type === "text_fields" || question.type === "diagram_text") {
    if (!hasExactKeys(value, ["texts"]) || !isRecord(value.texts)) {
      return null;
    }
    const partIds = new Set(question.parts.map((part) => part.id));
    const texts: Record<string, string> = {};
    for (const [partId, text] of Object.entries(value.texts)) {
      if (!partIds.has(partId) || typeof text !== "string" || text.length > 500) {
        return null;
      }
      texts[partId] = text;
    }
    return { texts };
  }

  if (question.type === "long_text") {
    return hasExactKeys(value, ["text"]) && typeof value.text === "string" && value.text.length <= 10000
      ? { text: value.text }
      : null;
  }

  const optionIds = new Set(question.options.map((option) => option.id));
  if (question.type === "single_choice") {
    return hasExactKeys(value, ["selectedOptionId"]) &&
      isIdentifier(value.selectedOptionId) &&
      optionIds.has(value.selectedOptionId)
      ? { selectedOptionId: value.selectedOptionId }
      : null;
  }

  if (question.type === "multiple_choice" || question.type === "ordered_choice") {
    if (!hasExactKeys(value, ["selectedOptionIds"]) || !Array.isArray(value.selectedOptionIds)) {
      return null;
    }
    const selectedOptionIds: Array<string> = [];
    for (const optionId of value.selectedOptionIds) {
      if (!isIdentifier(optionId) || !optionIds.has(optionId)) {
        return null;
      }
      selectedOptionIds.push(optionId);
    }
    return uniqueStrings(selectedOptionIds) ? { selectedOptionIds } : null;
  }

  if (!hasExactKeys(value, ["selections"]) || !isRecord(value.selections)) {
    return null;
  }
  const parts = new Map(question.parts.map((part) => [part.id, new Set(part.optionIds)]));
  const selections: Record<string, string> = {};
  for (const [partId, optionId] of Object.entries(value.selections)) {
    const allowedOptions = parts.get(partId);
    if (allowedOptions === undefined || !isIdentifier(optionId) || !allowedOptions.has(optionId)) {
      return null;
    }
    selections[partId] = optionId;
  }

  return { selections };
}

function gcd(left: number, right: number): number {
  let currentLeft = left;
  let currentRight = right;
  while (currentRight !== 0) {
    [currentLeft, currentRight] = [currentRight, currentLeft % currentRight];
  }

  return Math.abs(currentLeft);
}

function ratioMeetsThreshold(numerator: number, denominator: number, requiredBps: number): boolean {
  return BigInt(numerator) * BigInt(10000) >= BigInt(denominator) * BigInt(requiredBps);
}

function expectedPercentBps(numerator: number, denominator: number): number {
  return Number(
    (BigInt(numerator) * BigInt(20000) + BigInt(denominator)) /
      (BigInt(denominator) * BigInt(2)),
  );
}

function parseRatioScore(value: unknown, requiredBps: 8000 | 6000): LessonAssessmentRatioScore | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["numerator", "denominator", "percentBps", "requiredBps", "passed"]) ||
    !isNonNegativeInteger(value.numerator) ||
    !isPositiveInteger(value.denominator) ||
    value.numerator > value.denominator ||
    !isNonNegativeInteger(value.percentBps) || value.percentBps > 10000 ||
    value.requiredBps !== requiredBps ||
    typeof value.passed !== "boolean" ||
    (value.numerator === 0 ? value.denominator !== 1 : gcd(value.numerator, value.denominator) !== 1) ||
    value.percentBps !== expectedPercentBps(value.numerator, value.denominator) ||
    value.passed !== ratioMeetsThreshold(value.numerator, value.denominator, requiredBps)
  ) {
    return null;
  }

  return { numerator: value.numerator, denominator: value.denominator, percentBps: value.percentBps, requiredBps, passed: value.passed };
}

function parseFinalOverall(value: unknown): LessonAssessmentRatioScore | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["numerator", "denominator", "percentBps", "requiredBps", "passed"]) ||
    !isNonNegativeInteger(value.numerator) || value.numerator > 10000 ||
    value.denominator !== 10000 || value.percentBps !== value.numerator ||
    value.requiredBps !== 8000 || typeof value.passed !== "boolean" ||
    value.passed !== (value.numerator >= 8000)
  ) {
    return null;
  }

  return { numerator: value.numerator, denominator: 10000, percentBps: value.percentBps, requiredBps: 8000, passed: value.passed };
}

function parseQuestionResults(
  value: unknown,
  questions: ReadonlyArray<LessonAssessmentQuestion>,
): ReadonlyArray<LessonAssessmentQuestionResult> | null {
  if (!Array.isArray(value) || value.length !== questions.length) {
    return null;
  }

  const questionsById = new Map(questions.map((question) => [question.id, question]));
  const seenQuestionIds = new Set<string>();
  const results: Array<LessonAssessmentQuestionResult> = [];
  for (const item of value) {
    if (
      !isRecord(item) ||
      !hasExactKeys(item, ["questionId", "correct", "earnedParts", "maxParts", "incorrectPartIds"]) ||
      !isIdentifier(item.questionId) || typeof item.correct !== "boolean" ||
      !isNonNegativeInteger(item.earnedParts) || !isPositiveInteger(item.maxParts) ||
      item.earnedParts > item.maxParts || !Array.isArray(item.incorrectPartIds) ||
      seenQuestionIds.has(item.questionId)
    ) {
      return null;
    }
    const question = questionsById.get(item.questionId);
    if (question === undefined) {
      return null;
    }
    const partIds = new Set(questionParts(question).map((part) => part.id));
    const maxParts = partIds.size > 0 ? partIds.size : 1;
    const incorrectPartIds: Array<string> = [];
    for (const partId of item.incorrectPartIds) {
      if (!isIdentifier(partId) || !partIds.has(partId)) {
        return null;
      }
      incorrectPartIds.push(partId);
    }
    if (
      item.maxParts !== maxParts || item.correct !== (item.earnedParts === maxParts) ||
      !uniqueStrings(incorrectPartIds) ||
      (item.correct && incorrectPartIds.length !== 0) ||
      (!item.correct && partIds.size > 0 && incorrectPartIds.length !== maxParts - item.earnedParts)
    ) {
      return null;
    }
    seenQuestionIds.add(item.questionId);
    results.push({ questionId: item.questionId, correct: item.correct, earnedParts: item.earnedParts, maxParts, incorrectPartIds });
  }

  return results;
}

type ExactFraction = readonly [numerator: bigint, denominator: bigint];

function bigintGcd(left: bigint, right: bigint): bigint {
  let dividend = left;
  let divisor = right;
  while (divisor !== BigInt(0)) {
    [dividend, divisor] = [divisor, dividend % divisor];
  }
  return dividend;
}

function reduceFraction(numerator: bigint, denominator: bigint): ExactFraction {
  if (numerator === BigInt(0)) {
    return [BigInt(0), BigInt(1)];
  }
  const divisor = bigintGcd(numerator, denominator);
  return [numerator / divisor, denominator / divisor];
}

function aggregateResults(results: ReadonlyArray<LessonAssessmentQuestionResult>): ExactFraction | null {
  if (results.length === 0) {
    return null;
  }
  let numerator = BigInt(0);
  let denominator = BigInt(1);
  for (const result of results) {
    const fraction = reduceFraction(BigInt(result.earnedParts), BigInt(result.maxParts));
    const divisor = bigintGcd(denominator, fraction[1]);
    const leftMultiplier = fraction[1] / divisor;
    const rightMultiplier = denominator / divisor;
    [numerator, denominator] = reduceFraction(
      numerator * leftMultiplier + fraction[0] * rightMultiplier,
      denominator * leftMultiplier,
    );
  }
  return reduceFraction(numerator, denominator * BigInt(results.length));
}

function ratioMatchesResults(score: LessonAssessmentRatioScore, results: ReadonlyArray<LessonAssessmentQuestionResult>): boolean {
  const fraction = aggregateResults(results);
  return fraction !== null && BigInt(score.numerator) === fraction[0] && BigInt(score.denominator) === fraction[1];
}

function parseAutomaticGate(value: unknown, assessment: LessonAssessment): LessonAssessmentAutomaticGate | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["passed", "overall", "sections", "correctCount", "questionResults"]) ||
    typeof value.passed !== "boolean" || !isNonNegativeInteger(value.correctCount) || !Array.isArray(value.sections)
  ) {
    return null;
  }
  const automaticQuestions = assessment.questions.filter((question) => question.gradingMode === "automatic");
  const results = parseQuestionResults(value.questionResults, automaticQuestions);
  const overall = parseRatioScore(value.overall, 8000);
  if (results === null || overall === null || !ratioMatchesResults(overall, results)) {
    return null;
  }
  const sectionIds = assessment.sections
    .filter((section) => automaticQuestions.some((question) => question.sectionId === section.id))
    .map((section) => section.id);
  if (value.sections.length !== sectionIds.length || value.correctCount !== results.filter((result) => result.correct).length) {
    return null;
  }
  const sections: Array<LessonAssessmentSectionScore> = [];
  for (const [index, raw] of value.sections.entries()) {
    if (
      !isRecord(raw) ||
      !hasExactKeys(raw, ["sectionId", "numerator", "denominator", "percentBps", "requiredBps", "passed"]) ||
      raw.sectionId !== sectionIds[index]
    ) {
      return null;
    }
    const section = parseRatioScore({
      numerator: raw.numerator,
      denominator: raw.denominator,
      percentBps: raw.percentBps,
      requiredBps: raw.requiredBps,
      passed: raw.passed,
    }, 6000);
    const sectionResults = results.filter((result) => {
      const question = automaticQuestions.find((candidate) => candidate.id === result.questionId);
      return question?.sectionId === raw.sectionId;
    });
    if (section === null || !ratioMatchesResults(section, sectionResults)) {
      return null;
    }
    sections.push({ sectionId: raw.sectionId, ...section, requiredBps: 6000 });
  }
  return value.passed === (overall.passed && sections.every((section) => section.passed))
    ? { passed: value.passed, overall: { ...overall, requiredBps: 8000 }, sections, correctCount: value.correctCount, questionResults: results }
    : null;
}

function parseManualResults(value: unknown, assessment: LessonAssessment): ReadonlyArray<LessonAssessmentManualResult> | null {
  const manualQuestionIds = assessment.questions
    .filter((question) => question.gradingMode === "manual")
    .map((question) => question.id);
  if (!Array.isArray(value) || value.length !== manualQuestionIds.length) {
    return null;
  }
  const remaining = new Set(manualQuestionIds);
  const results: Array<LessonAssessmentManualResult> = [];
  for (const item of value) {
    if (
      !isRecord(item) || !hasExactKeys(item, ["questionId", "scoreBps", "comment"]) ||
      !isIdentifier(item.questionId) || !remaining.delete(item.questionId) ||
      !isNonNegativeInteger(item.scoreBps) || item.scoreBps > 10000 ||
      (item.comment !== null && (typeof item.comment !== "string" || item.comment.length > 2000))
    ) {
      return null;
    }
    results.push({ questionId: item.questionId, scoreBps: item.scoreBps, comment: item.comment });
  }
  return remaining.size === 0 ? results : null;
}

function halfUp(numerator: number, denominator: number): number {
  return Math.floor((numerator + Math.floor(denominator / 2)) / denominator);
}

function parseFinalScore(
  value: unknown,
  assessment: LessonAssessment,
  gate: LessonAssessmentAutomaticGate,
  manualResults: ReadonlyArray<LessonAssessmentManualResult>,
): LessonAssessmentScore | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["checked", "passed", "mastered", "overall", "components"]) ||
    value.checked !== true || typeof value.passed !== "boolean" || typeof value.mastered !== "boolean" || !isRecord(value.components)
  ) {
    return null;
  }
  const overall = parseFinalOverall(value.overall);
  const components = value.components;
  if (
    overall === null ||
    !hasExactKeys(components, ["automaticBps", "manualBps", "automaticWeightBps", "manualWeightBps"]) ||
    !isNonNegativeInteger(components.automaticBps) || components.automaticBps > 10000 ||
    !isNonNegativeInteger(components.manualBps) || components.manualBps > 10000 ||
    components.automaticWeightBps !== assessment.scoringPolicy.automaticWeightBps ||
    components.manualWeightBps !== assessment.scoringPolicy.manualWeightBps
  ) {
    return null;
  }
  const expectedManualBps = halfUp(
    manualResults.reduce((sum, result) => sum + result.scoreBps, 0),
    manualResults.length,
  );
  const expectedFinalBps = halfUp(
    components.automaticBps * components.automaticWeightBps + components.manualBps * components.manualWeightBps,
    10000,
  );
  const passed = components.manualBps >= assessment.scoringPolicy.manualRequiredBps &&
    expectedFinalBps >= assessment.scoringPolicy.finalRequiredBps;
  if (
    components.automaticBps !== gate.overall.percentBps || components.manualBps !== expectedManualBps ||
    overall.percentBps !== expectedFinalBps || value.passed !== passed ||
    value.mastered !== (expectedFinalBps === 10000)
  ) {
    return null;
  }
  return {
    checked: true,
    passed,
    mastered: value.mastered,
    overall: { ...overall, requiredBps: 8000 },
    components: {
      automaticBps: components.automaticBps,
      manualBps: components.manualBps,
      automaticWeightBps: assessment.scoringPolicy.automaticWeightBps,
      manualWeightBps: assessment.scoringPolicy.manualWeightBps,
    },
  };
}

type ParsedAttemptCommon = Omit<LessonAssessmentAttemptBase, "submittedAt" | "finalizedAt" | "passedAt" | "failureReason" | "pendingManualQuestionCount" | "reviewedManualQuestionCount">;

const ATTEMPT_COMMON_KEYS = [
  "id", "status", "revision", "submissionCount", "answeredCount", "correctCount", "lastQuestionId",
  "submittedAt", "finalizedAt", "passedAt", "failureReason", "pendingManualQuestionCount",
  "reviewedManualQuestionCount", "reviewedManualQuestionIds", "responses", "lockedQuestionIds",
] as const;

function parseAttemptCommon(value: JsonRecord, assessment: LessonAssessment): ParsedAttemptCommon | null {
  if (
    !isPositiveInteger(value.id) || !isNonNegativeInteger(value.revision) ||
    !isNonNegativeInteger(value.submissionCount) || !isNonNegativeInteger(value.answeredCount) ||
    !isNonNegativeInteger(value.correctCount) || value.answeredCount > assessment.totalCount ||
    value.correctCount > assessment.scoringPolicy.automaticQuestionCount ||
    (value.lastQuestionId !== null && !isIdentifier(value.lastQuestionId)) ||
    !Array.isArray(value.reviewedManualQuestionIds) || !Array.isArray(value.responses) || !Array.isArray(value.lockedQuestionIds)
  ) {
    return null;
  }
  const questionsById = new Map(assessment.questions.map((question) => [question.id, question]));
  if (value.lastQuestionId !== null && !questionsById.has(value.lastQuestionId)) {
    return null;
  }
  const responses: Array<LessonAssessmentResponseEntry> = [];
  for (const item of value.responses) {
    if (!isRecord(item) || !hasExactKeys(item, ["questionId", "response"]) || !isIdentifier(item.questionId) || responses.some((entry) => entry.questionId === item.questionId)) {
      return null;
    }
    const question = questionsById.get(item.questionId);
    const response = question === undefined ? null : parseResponse(item.response, question);
    if (response === null) {
      return null;
    }
    responses.push({ questionId: item.questionId, response });
  }
  const lockedQuestionIds: Array<string> = [];
  for (const questionId of value.lockedQuestionIds) {
    if (!isIdentifier(questionId) || !questionsById.has(questionId) || lockedQuestionIds.includes(questionId)) {
      return null;
    }
    lockedQuestionIds.push(questionId);
  }
  const reviewedManualQuestionIds: Array<string> = [];
  for (const questionId of value.reviewedManualQuestionIds) {
    const question = isIdentifier(questionId) ? questionsById.get(questionId) : undefined;
    if (question?.gradingMode !== "manual" || reviewedManualQuestionIds.includes(questionId)) {
      return null;
    }
    reviewedManualQuestionIds.push(questionId);
  }
  if (responses.length > assessment.totalCount || value.answeredCount > responses.length) {
    return null;
  }
  return { id: value.id, revision: value.revision, submissionCount: value.submissionCount, answeredCount: value.answeredCount, correctCount: value.correctCount, lastQuestionId: value.lastQuestionId, reviewedManualQuestionIds, responses, lockedQuestionIds };
}

function responseIsComplete(
  question: LessonAssessmentQuestion,
  response: LessonAssessmentResponse,
): boolean {
  if (question.type === "single_choice") {
    return "selectedOptionId" in response;
  }
  if (question.type === "multiple_choice") {
    return "selectedOptionIds" in response && response.selectedOptionIds.length > 0;
  }
  if (question.type === "ordered_choice") {
    return "selectedOptionIds" in response && response.selectedOptionIds.length === question.options.length;
  }
  if (question.type === "slot_choice" || question.type === "diagram_labels") {
    return "selections" in response && question.parts.every((part) => response.selections[part.id] !== undefined);
  }
  if (question.type === "text_fields" || question.type === "diagram_text") {
    return "texts" in response && question.parts.every((part) => (response.texts[part.id]?.trim() ?? "") !== "");
  }

  return "text" in response && response.text.trim() !== "";
}

function terminalResponsesAreLocked(common: ParsedAttemptCommon, assessment: LessonAssessment): boolean {
  const questionIds = assessment.questions.map((question) => question.id);
  return common.answeredCount === assessment.totalCount && common.responses.length === assessment.totalCount &&
    common.lockedQuestionIds.length === assessment.totalCount &&
    questionIds.every((questionId) => common.lockedQuestionIds.includes(questionId)) &&
    assessment.questions.every((question) => {
      const entry = common.responses.find((response) => response.questionId === question.id);
      return entry !== undefined && responseIsComplete(question, entry.response);
    });
}

function inProgressLocksAreComplete(common: ParsedAttemptCommon, assessment: LessonAssessment): boolean {
  if (common.lockedQuestionIds.length === 0) {
    return true;
  }

  const questionsById = new Map(assessment.questions.map((question) => [question.id, question]));
  const responsesByQuestionId = new Map(common.responses.map((response) => [response.questionId, response.response]));

  return common.lockedQuestionIds.every((questionId) => {
    const question = questionsById.get(questionId);
    const response = responsesByQuestionId.get(questionId);

    return question !== undefined && response !== undefined && responseIsComplete(question, response);
  });
}

function parseHybridAttempt(value: unknown, assessment: LessonAssessment): LessonAssessmentAttempt | null {
  if (value === null || !isRecord(value) || typeof value.status !== "string") {
    return null;
  }
  const stateKeys = value.status === "in_progress"
    ? ATTEMPT_COMMON_KEYS
    : value.status === "pending_review"
      ? [...ATTEMPT_COMMON_KEYS, "automaticGate", "manualProgressBps"]
      : value.status === "failed" && value.finalizedAt === null
      ? [...ATTEMPT_COMMON_KEYS, "automaticGate"]
      : value.status === "passed" || value.status === "failed"
        ? [...ATTEMPT_COMMON_KEYS, "automaticGate", "score", "manualResults"]
        : [];
  if (!hasExactKeys(value, stateKeys)) {
    return null;
  }
  const common = parseAttemptCommon(value, assessment);
  if (common === null || !isNullableBackendTimestamp(value.submittedAt) || !isNullableBackendTimestamp(value.finalizedAt) || !isNullableBackendTimestamp(value.passedAt)) {
    return null;
  }
  if (value.status === "in_progress") {
    return common.submissionCount === 0 && common.correctCount === 0 && inProgressLocksAreComplete(common, assessment) &&
      value.submittedAt === null && value.finalizedAt === null && value.passedAt === null && value.failureReason === null &&
      value.pendingManualQuestionCount === 0 && value.reviewedManualQuestionCount === 0 && common.reviewedManualQuestionIds.length === 0
      ? { ...common, status: "in_progress", submittedAt: null, finalizedAt: null, passedAt: null, failureReason: null, pendingManualQuestionCount: 0, reviewedManualQuestionCount: 0 }
      : null;
  }
  const gate = parseAutomaticGate(value.automaticGate, assessment);
  if (gate === null || common.submissionCount === 0 || common.correctCount !== gate.correctCount || !terminalResponsesAreLocked(common, assessment)) {
    return null;
  }
  if (value.status === "failed" && !gate.passed) {
    return value.submittedAt !== null && value.finalizedAt === null && value.passedAt === null &&
      value.failureReason === "automatic_threshold_not_met" && value.pendingManualQuestionCount === 0 && value.reviewedManualQuestionCount === 0
      && common.reviewedManualQuestionIds.length === 0
      ? { ...common, status: "failed", submittedAt: value.submittedAt, finalizedAt: null, passedAt: null, failureReason: "automatic_threshold_not_met", pendingManualQuestionCount: 0, reviewedManualQuestionCount: 0, automaticGate: { ...gate, passed: false } }
      : null;
  }
  if (value.status === "pending_review") {
    const manualCount = assessment.scoringPolicy.manualQuestionCount;
    if (
      !isNonNegativeInteger(value.pendingManualQuestionCount) ||
      !isNonNegativeInteger(value.reviewedManualQuestionCount) ||
      !isNonNegativeInteger(value.manualProgressBps)
    ) {
      return null;
    }
    const maximumProgressBps = Math.floor(
      (value.reviewedManualQuestionCount * 10000 + Math.floor(manualCount / 2)) / manualCount,
    );
    return gate.passed && value.submittedAt !== null && value.finalizedAt === null && value.passedAt === null &&
      value.failureReason === null && value.manualProgressBps <= maximumProgressBps &&
      common.reviewedManualQuestionIds.length === value.reviewedManualQuestionCount &&
      value.pendingManualQuestionCount + value.reviewedManualQuestionCount === manualCount
      ? { ...common, status: "pending_review", submittedAt: value.submittedAt, finalizedAt: null, passedAt: null, failureReason: null, pendingManualQuestionCount: value.pendingManualQuestionCount, reviewedManualQuestionCount: value.reviewedManualQuestionCount, manualProgressBps: value.manualProgressBps, automaticGate: { ...gate, passed: true } }
      : null;
  }
  if (value.status !== "failed" && value.status !== "passed") {
    return null;
  }
  const manualResults = parseManualResults(value.manualResults, assessment);
  const score = manualResults === null ? null : parseFinalScore(value.score, assessment, gate, manualResults);
  if (
    !gate.passed || score === null || value.submittedAt === null || value.finalizedAt === null ||
    value.pendingManualQuestionCount !== 0 || value.reviewedManualQuestionCount !== assessment.scoringPolicy.manualQuestionCount ||
    common.reviewedManualQuestionIds.length !== value.reviewedManualQuestionCount ||
    (value.status === "passed") !== score.passed || (value.status === "passed" ? value.passedAt === null : value.passedAt !== null)
  ) {
    return null;
  }
  const expectedFailureReason = score.passed
    ? null
    : score.components.manualBps < assessment.scoringPolicy.manualRequiredBps
      ? "manual_threshold_not_met"
      : "combined_threshold_not_met";
  if (value.failureReason !== expectedFailureReason) {
    return null;
  }
  return { ...common, status: value.status, submittedAt: value.submittedAt, finalizedAt: value.finalizedAt, passedAt: value.passedAt, failureReason: expectedFailureReason, pendingManualQuestionCount: 0, reviewedManualQuestionCount: value.reviewedManualQuestionCount, automaticGate: { ...gate, passed: true }, score, manualResults: manualResults as ReadonlyArray<LessonAssessmentManualResult> };
}

function parseData(value: unknown, route: LessonAssessmentRoute): LessonAssessmentData | null {
  if (!isRecord(value) || !hasExactKeys(value, ["assessment", "attempt"])) {
    return null;
  }
  const assessment = parseAssessment(value.assessment, route);
  if (assessment === null) {
    return null;
  }
  const attempt = parseHybridAttempt(value.attempt, assessment);
  return attempt === null && value.attempt !== null ? null : { assessment, attempt };
}

export function parseLessonAssessmentPayload(value: unknown, route: LessonAssessmentRoute): LessonAssessmentData | null {
  return containsForbiddenKey(value) || !isRecord(value) || !hasExactKeys(value, ["data"])
    ? null
    : parseData(value.data, route);
}

export function parseLessonAssessmentSubmissionPayload(
  value: unknown,
  route: LessonAssessmentRoute,
): LessonAssessmentSubmissionData | null {
  if (containsForbiddenKey(value) || !isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data) || !hasExactKeys(value.data, ["assessment", "attempt", "submission"])) {
    return null;
  }
  const data = parseData({ assessment: value.data.assessment, attempt: value.data.attempt }, route);
  if (data === null || data.attempt === null || !isRecord(value.data.submission) || !hasExactKeys(value.data.submission, ["number", "automaticGate"]) || !isPositiveInteger(value.data.submission.number)) {
    return null;
  }
  if (!("automaticGate" in data.attempt) || data.attempt.submissionCount !== value.data.submission.number) {
    return null;
  }
  const gate = parseAutomaticGate(value.data.submission.automaticGate, data.assessment);
  if (gate === null || JSON.stringify(gate) !== JSON.stringify(data.attempt.automaticGate)) {
    return null;
  }
  return { assessment: data.assessment, attempt: data.attempt, submission: { number: value.data.submission.number, automaticGate: gate } };
}
