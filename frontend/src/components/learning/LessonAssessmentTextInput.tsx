"use client";

import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type CSSProperties,
  type KeyboardEvent as ReactKeyboardEvent,
} from "react";

import { suggestLessonAssessmentAnswer } from "@/lib/lessonAssessmentApi";
import type { LessonAssessmentRoute } from "@/lib/lessonAssessmentContract";

import styles from "./lesson-assessment.module.css";

type LessonAssessmentTextInputProps = {
  readonly apiBaseUrl: string;
  readonly acceptedSuggestionValue?: string | null;
  readonly attemptId: number;
  readonly describedBy?: string;
  readonly disabled: boolean;
  readonly fitContent?: boolean;
  readonly invalid: boolean;
  readonly onChange: (value: string) => void;
  readonly onSuggestionAcceptedChange?: (value: string | null) => void;
  readonly partId: string;
  readonly placeholder?: string;
  readonly questionId: string;
  readonly reviewState?: "correct" | "incorrect";
  readonly route: LessonAssessmentRoute;
  readonly showGuide?: boolean;
  readonly suggestionsEnabled?: boolean;
  readonly value: string;
  readonly variant?: "box" | "inline" | "line";
};

const SUGGESTION_DELAY_MS = 250;
const BOX_HEIGHT_PX = 32;
const BOX_FIT_CONTENT_MIN_WIDTH_PX = 96;
const BOX_FIT_CONTENT_MAX_WIDTH_PX = 512;
const BOX_FIT_CONTENT_EXTRA_WIDTH_PX = 2;
const LINE_MIN_HEIGHT_PX = 32;
const LINE_MIN_CHARACTER_WIDTH = 18;
const LINE_WIDTH_PADDING_CHARACTERS = 2;
const SUGGESTION_ANCHOR_GAP_PX = 4;
const SUGGESTION_WARNING_ARROW_OFFSET_PX = 32;
const SUGGESTION_MAX_WIDTH_PX = 384;
const SUGGESTION_MAX_HEIGHT_PX = 240;
const SUGGESTION_OPTION_HEIGHT_PX = 28;
const SUGGESTION_LIST_CHROME_HEIGHT_PX = 4;
const SUGGESTION_MIN_VISIBLE_HEIGHT_PX = 56;
const SUGGESTION_COLLISION_GAP_PX = 4;
const SUGGESTION_LINE_MIN_WIDTH_PX = 80;
const SUGGESTION_BOX_MIN_WIDTH_PX = 176;
const SUGGESTION_VIEWPORT_GUTTER_PX = 16;
const SELECTED_BOX_ANSWER_MAX_WIDTH_PX = 512;

type SuggestionBlockPlacement = "down" | "up";

type SuggestionsLayout = {
  readonly blockPlacement: SuggestionBlockPlacement;
  readonly inlineOffsetPixels: number | null;
  readonly maxHeight: number | null;
  readonly maxWidth: number | null;
  readonly placement: SuggestionListPlacement;
};

type SuggestionListPlacement = "bottom" | "right";

const DEFAULT_SUGGESTIONS_LAYOUT: SuggestionsLayout = {
  blockPlacement: "down",
  inlineOffsetPixels: null,
  maxHeight: null,
  maxWidth: null,
  placement: "bottom",
};

type RectEdges = {
  readonly bottom: number;
  readonly left: number;
  readonly right: number;
  readonly top: number;
};

type SuggestionLayoutCandidate = {
  readonly blockPlacement: SuggestionBlockPlacement;
  readonly collisionScore: number;
  readonly inlineOffsetPixels: number | null;
  readonly maxHeight: number;
  readonly maxWidth: number;
  readonly order: number;
  readonly placement: SuggestionListPlacement;
};

let suggestionMeasurementCanvas: HTMLCanvasElement | null = null;

function fontFromComputedStyle(computedStyle: CSSStyleDeclaration): string {
  return [
    computedStyle.fontStyle || "normal",
    computedStyle.fontVariant || "normal",
    computedStyle.fontWeight || "400",
    computedStyle.fontSize || "14px",
    computedStyle.fontFamily || "sans-serif",
  ].join(" ");
}

function fitBoxedTextArea(element: HTMLTextAreaElement): void {
  element.style.removeProperty("font-size");
  element.style.removeProperty("line-height");
  element.style.removeProperty("padding-block");
  element.style.height = "0px";
  element.style.height = `${Math.max(BOX_HEIGHT_PX, element.scrollHeight)}px`;
}

function fitLineTextArea(element: HTMLTextAreaElement): void {
  element.style.height = "0px";
  element.style.height = `${Math.max(LINE_MIN_HEIGHT_PX, element.scrollHeight)}px`;
}

function cssPixelValue(value: string): number {
  const parsedValue = Number.parseFloat(value);

  return Number.isFinite(parsedValue) ? parsedValue : 0;
}

function viewportClientWidth(): number {
  if (typeof document === "undefined") {
    return typeof window === "undefined" ? 0 : window.innerWidth;
  }

  return document.documentElement.clientWidth || window.innerWidth;
}

function viewportClientHeight(): number {
  if (typeof document === "undefined") {
    return typeof window === "undefined" ? 0 : window.innerHeight;
  }

  return document.documentElement.clientHeight || window.innerHeight;
}

function suggestionContentBoundaryRight(element: HTMLElement | null): number {
  const viewportRight = viewportClientWidth();
  const assessmentPage = element?.closest<HTMLElement>('[data-ui="lk.learning.assessmentPage"]') ?? null;
  if (assessmentPage === null) {
    return viewportRight;
  }

  return Math.min(viewportRight, assessmentPage.getBoundingClientRect().right);
}

function suggestionListDownTop(
  wrapperRect: DOMRect,
  placement: SuggestionListPlacement,
  isLine: boolean,
): number {
  if (placement === "right") {
    return wrapperRect.top;
  }

  return isLine ? wrapperRect.top + 28 : wrapperRect.bottom + SUGGESTION_ANCHOR_GAP_PX;
}

function suggestionListUpBottom(wrapperRect: DOMRect, placement: SuggestionListPlacement): number {
  if (placement === "right") {
    return wrapperRect.bottom;
  }

  return wrapperRect.top - SUGGESTION_ANCHOR_GAP_PX;
}

function boundedSuggestionWidth(availableWidth: number): number {
  return Math.max(0, Math.min(Math.floor(availableWidth), SUGGESTION_MAX_WIDTH_PX));
}

function availableSuggestionHeight(
  wrapperRect: DOMRect,
  placement: SuggestionListPlacement,
  blockPlacement: SuggestionBlockPlacement,
  isLine: boolean,
): number {
  return blockPlacement === "up"
    ? suggestionListUpBottom(wrapperRect, placement) - SUGGESTION_VIEWPORT_GUTTER_PX
    : viewportClientHeight() -
      suggestionListDownTop(wrapperRect, placement, isLine) -
      SUGGESTION_VIEWPORT_GUTTER_PX;
}

function boundedSuggestionHeight(
  wrapperRect: DOMRect,
  placement: SuggestionListPlacement,
  blockPlacement: SuggestionBlockPlacement,
  isLine: boolean,
): number {
  const availableHeight = availableSuggestionHeight(wrapperRect, placement, blockPlacement, isLine);
  return Math.max(0, Math.min(Math.floor(availableHeight), SUGGESTION_MAX_HEIGHT_PX));
}

function estimatedSuggestionListHeight(suggestionCount: number): number {
  return Math.min(
    SUGGESTION_MAX_HEIGHT_PX,
    Math.max(1, suggestionCount) * SUGGESTION_OPTION_HEIGHT_PX + SUGGESTION_LIST_CHROME_HEIGHT_PX,
  );
}

function suggestionPeerRects(element: HTMLElement): ReadonlyArray<DOMRect> {
  const diagramStage = element.closest<HTMLElement>('[data-ui$=".diagram.image"]');
  if (diagramStage === null) {
    return [];
  }

  const rects: DOMRect[] = [];
  for (const candidate of diagramStage.querySelectorAll<HTMLElement>("[data-assessment-text-input-wrapper]")) {
    if (candidate === element) {
      continue;
    }

    const rect = candidate.getBoundingClientRect();
    if (rect.width > 0 && rect.height > 0) {
      rects.push(rect);
    }
  }

  return rects;
}

function expandedRect(rect: RectEdges, pixels: number): RectEdges {
  return {
    bottom: rect.bottom + pixels,
    left: rect.left - pixels,
    right: rect.right + pixels,
    top: rect.top - pixels,
  };
}

function rectIntersectionArea(first: RectEdges, second: RectEdges): number {
  const width = Math.max(0, Math.min(first.right, second.right) - Math.max(first.left, second.left));
  const height = Math.max(0, Math.min(first.bottom, second.bottom) - Math.max(first.top, second.top));

  return width * height;
}

function suggestionCandidateRect({
  blockPlacement,
  height,
  inlineOffsetPixels,
  isLine,
  maxWidth,
  placement,
  wrapperRect,
}: {
  readonly blockPlacement: SuggestionBlockPlacement;
  readonly height: number;
  readonly inlineOffsetPixels: number | null;
  readonly isLine: boolean;
  readonly maxWidth: number;
  readonly placement: SuggestionListPlacement;
  readonly wrapperRect: DOMRect;
}): RectEdges {
  const top = blockPlacement === "up"
    ? suggestionListUpBottom(wrapperRect, placement) - height
    : suggestionListDownTop(wrapperRect, placement, isLine);
  const left = placement === "right" && inlineOffsetPixels !== null
    ? wrapperRect.left + inlineOffsetPixels
    : wrapperRect.left;

  return {
    bottom: top + height,
    left,
    right: left + maxWidth,
    top,
  };
}

function suggestionCollisionScore(rect: RectEdges, peerRects: ReadonlyArray<DOMRect>): number {
  return peerRects.reduce(
    (score, peerRect) =>
      score + rectIntersectionArea(rect, expandedRect(peerRect, SUGGESTION_COLLISION_GAP_PX)),
    0,
  );
}

function createSuggestionLayoutCandidate({
  blockPlacement,
  estimatedHeight,
  inlineOffsetPixels,
  isLine,
  maxWidth,
  order,
  peerRects,
  placement,
  wrapperRect,
}: {
  readonly blockPlacement: SuggestionBlockPlacement;
  readonly estimatedHeight: number;
  readonly inlineOffsetPixels: number | null;
  readonly isLine: boolean;
  readonly maxWidth: number;
  readonly order: number;
  readonly peerRects: ReadonlyArray<DOMRect>;
  readonly placement: SuggestionListPlacement;
  readonly wrapperRect: DOMRect;
}): SuggestionLayoutCandidate {
  const maxHeight = boundedSuggestionHeight(wrapperRect, placement, blockPlacement, isLine);
  const collisionRect = suggestionCandidateRect({
    blockPlacement,
    height: Math.min(estimatedHeight, maxHeight),
    inlineOffsetPixels,
    isLine,
    maxWidth,
    placement,
    wrapperRect,
  });

  return {
    blockPlacement,
    collisionScore: suggestionCollisionScore(collisionRect, peerRects),
    inlineOffsetPixels,
    maxHeight,
    maxWidth,
    order,
    placement,
  };
}

function suggestionCandidateIsUsable(candidate: SuggestionLayoutCandidate, minWidth: number): boolean {
  return candidate.maxWidth >= minWidth && candidate.maxHeight >= SUGGESTION_MIN_VISIBLE_HEIGHT_PX;
}

function pickSuggestionLayoutCandidate(
  candidates: ReadonlyArray<SuggestionLayoutCandidate>,
  minWidth: number,
): SuggestionLayoutCandidate | null {
  const usableCandidates = candidates.filter((candidate) => suggestionCandidateIsUsable(candidate, minWidth));
  const nonCollidingCandidate = usableCandidates.find((candidate) => candidate.collisionScore === 0);
  if (nonCollidingCandidate !== undefined) {
    return nonCollidingCandidate;
  }

  const fallbackCandidates = usableCandidates.length > 0 ? usableCandidates : candidates;
  return fallbackCandidates.toSorted((first, second) =>
    first.collisionScore - second.collisionScore ||
    second.maxHeight - first.maxHeight ||
    second.maxWidth - first.maxWidth ||
    first.order - second.order,
  )[0] ?? null;
}

function suggestionsLayoutFromCandidate(candidate: SuggestionLayoutCandidate | null): SuggestionsLayout {
  if (candidate === null) {
    return DEFAULT_SUGGESTIONS_LAYOUT;
  }

  return {
    blockPlacement: candidate.blockPlacement,
    inlineOffsetPixels: candidate.inlineOffsetPixels,
    maxHeight: candidate.maxHeight,
    maxWidth: candidate.maxWidth,
    placement: candidate.placement,
  };
}

function measureSuggestionAnchorOffset(
  element: HTMLElement | null,
  value: string,
  options: { readonly includeInlineStartPadding?: boolean } = {},
): number {
  const trimmedValue = value.trim();
  if (element === null || trimmedValue === "") {
    return 0;
  }

  const canvas = suggestionMeasurementCanvas ?? document.createElement("canvas");
  suggestionMeasurementCanvas = canvas;

  const context = canvas.getContext("2d");
  if (context === null) {
    return 0;
  }

  const computedStyle = window.getComputedStyle(element);
  context.font = fontFromComputedStyle(computedStyle);

  const inlineStartPadding = options.includeInlineStartPadding === true
    ? cssPixelValue(computedStyle.paddingInlineStart)
    : 0;

  return Math.ceil(inlineStartPadding + context.measureText(trimmedValue).width + SUGGESTION_ANCHOR_GAP_PX);
}

function measureBoxFitContentWidth(
  element: HTMLElement | null,
  value: string,
  placeholder: string | undefined,
): number | null {
  if (element === null) {
    return null;
  }

  const trimmedValue = value.trim();
  const canvas = suggestionMeasurementCanvas ?? document.createElement("canvas");
  suggestionMeasurementCanvas = canvas;

  const context = canvas.getContext("2d");
  if (context === null) {
    return null;
  }

  const computedStyle = window.getComputedStyle(element);
  const measuredTextWidths: number[] = [];
  if (trimmedValue !== "") {
    context.font = fontFromComputedStyle(computedStyle);
    measuredTextWidths.push(context.measureText(trimmedValue).width);
  }
  if (placeholder !== undefined && placeholder.trim() !== "") {
    const placeholderStyle = window.getComputedStyle(element, "::placeholder");
    context.font = fontFromComputedStyle(placeholderStyle);
    measuredTextWidths.push(context.measureText(placeholder).width);
  }

  if (measuredTextWidths.length === 0) {
    return BOX_FIT_CONTENT_MIN_WIDTH_PX;
  }

  const horizontalChromeWidth =
    cssPixelValue(computedStyle.paddingInlineStart) +
    cssPixelValue(computedStyle.paddingInlineEnd) +
    cssPixelValue(computedStyle.borderInlineStartWidth) +
    cssPixelValue(computedStyle.borderInlineEndWidth) +
    BOX_FIT_CONTENT_EXTRA_WIDTH_PX;
  const measuredWidth = Math.ceil(Math.max(...measuredTextWidths) + horizontalChromeWidth);

  return Math.min(
    Math.max(measuredWidth, BOX_FIT_CONTENT_MIN_WIDTH_PX),
    BOX_FIT_CONTENT_MAX_WIDTH_PX,
  );
}

export function LessonAssessmentTextInput({
  apiBaseUrl,
  acceptedSuggestionValue,
  attemptId,
  describedBy,
  disabled,
  fitContent = false,
  invalid,
  onChange,
  onSuggestionAcceptedChange,
  partId,
  placeholder,
  questionId,
  reviewState,
  route,
  showGuide = true,
  suggestionsEnabled = true,
  value,
  variant = "line",
}: LessonAssessmentTextInputProps) {
  const wrapperRef = useRef<HTMLSpanElement>(null);
  const textAreaRef = useRef<HTMLTextAreaElement>(null);
  const selectedAnswerButtonRef = useRef<HTMLButtonElement>(null);
  const [suggestionState, setSuggestionState] = useState<{
    readonly prefix: string;
    readonly values: ReadonlyArray<string>;
  }>({ prefix: "", values: [] });
  const [suggestionsLayout, setSuggestionsLayout] = useState<SuggestionsLayout>(DEFAULT_SUGGESTIONS_LAYOUT);
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const [suggestionSelectionPromptRequested, setSuggestionSelectionPromptRequested] = useState(false);
  const [localAcceptedSuggestionValue, setLocalAcceptedSuggestionValue] = useState<string | null>(null);
  const [activeSuggestionIndex, setActiveSuggestionIndex] = useState(0);
  const [fitContentWidthPixels, setFitContentWidthPixels] = useState<number | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const controlId = `assessment-${questionId}-${partId}`;
  const listId = `${controlId}-suggestions`;
  const reviewStatusId = reviewState === undefined
    ? undefined
    : `${controlId}-review-status`;
  const suggestionSelectionWarningId = `${controlId}-suggestion-selection-warning`;
  const suggestions = suggestionsEnabled && suggestionState.prefix === value ? suggestionState.values : [];
  const isEmpty = value.trim() === "";
  const isBox = variant === "box";
  const hasCombobox = suggestionsEnabled;
  const resolvedAcceptedSuggestionValue = acceptedSuggestionValue ?? localAcceptedSuggestionValue;
  const suggestionSelectionPending = hasCombobox &&
    !disabled &&
    !isEmpty &&
    suggestions.length > 0 &&
    resolvedAcceptedSuggestionValue !== value;
  const suggestionSelectionWarningVisible =
    suggestionSelectionPromptRequested && suggestionSelectionPending;
  const selectedAnswerAccepted = hasCombobox &&
    !isEmpty &&
    resolvedAcceptedSuggestionValue === value &&
    reviewState === undefined;
  const accessibleDescription = [
    describedBy,
    reviewStatusId,
    suggestionSelectionWarningVisible ? suggestionSelectionWarningId : undefined,
  ].filter(Boolean).join(" ") || undefined;
  const isInline = variant === "inline";
  const isLine = variant === "line";
  const inlineCharacterWidth = Math.min(Math.max([...value.trim()].length + 1, 8), 30);
  const inlineWrapperWidth = selectedAnswerAccepted ? "max-content" : `${inlineCharacterWidth}ch`;
  const lineCharacterWidth = Math.max(
    [...value.trim()].length + LINE_WIDTH_PADDING_CHARACTERS,
    LINE_MIN_CHARACTER_WIDTH,
  );
  const wrapperClassName = isInline
    ? "relative mx-1 inline-block max-w-full align-baseline"
    : isLine ? "relative grid min-h-8 max-w-full items-center" : "relative block max-w-full";
  const wrapperStyle = isInline
    ? { width: inlineWrapperWidth }
    : isLine ? { width: `min(100%, ${lineCharacterWidth}ch)` }
      : isBox && fitContent && fitContentWidthPixels !== null
        ? { width: `min(100%, ${fitContentWidthPixels}px)` }
      : undefined;
  const suggestionsPositionClassName = isInline
    ? "left-0 top-6 mt-0"
    : (isLine || isBox) && suggestionsLayout.placement === "right"
      ? suggestionsLayout.blockPlacement === "up" ? "bottom-0" : "top-0"
      : suggestionsLayout.blockPlacement === "up"
        ? "left-0 bottom-full mb-1"
        : isLine ? "left-0 top-7 mt-0" : "left-0 top-full mt-1";
  const suggestionsSizeClassName = isInline
    ? "w-max min-w-44 max-w-[min(24rem,calc(100vw-2rem))]"
    : isBox ? "w-max min-w-44 max-w-[min(32rem,calc(100vw-2rem))]"
      : isLine ? "w-max min-w-20 max-w-[min(32rem,calc(100vw-2rem))]" : "";
  const suggestionOptionTypographyClassName = isBox ? "text-xs leading-4" : "text-sm leading-5";
  const suggestionsMinimumWidth = isLine ? SUGGESTION_LINE_MIN_WIDTH_PX : SUGGESTION_BOX_MIN_WIDTH_PX;
  const suggestionsListStyle: CSSProperties | undefined = (isLine || isBox) &&
    suggestionsLayout.maxHeight !== null &&
    suggestionsLayout.maxWidth !== null
      ? {
          ...(suggestionsLayout.placement === "right" && suggestionsLayout.inlineOffsetPixels !== null
            ? { left: `${suggestionsLayout.inlineOffsetPixels}px` }
            : {}),
          maxHeight: `${suggestionsLayout.maxHeight}px`,
          maxWidth: `${suggestionsLayout.maxWidth}px`,
          minWidth: `${Math.min(suggestionsMinimumWidth, suggestionsLayout.maxWidth)}px`,
        }
      : undefined;
  const suggestionWarningStyle = isLine && suggestionsLayout.inlineOffsetPixels !== null
    ? {
        left: `${Math.max(0, suggestionsLayout.inlineOffsetPixels - SUGGESTION_WARNING_ARROW_OFFSET_PX)}px`,
      }
    : undefined;
  const controlClassName = [
    isBox
      ? "ui-input relative z-10 block h-8 w-full resize-none overflow-hidden rounded-[var(--radius-control)] border border-[var(--color-border-control)] bg-white px-2.5 text-xs leading-4 outline-none transition-colors placeholder:text-xs placeholder:ui-text-placeholder placeholder:opacity-[0.55] focus:border-[var(--color-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)]"
      : isInline
        ? "relative z-10 h-7 w-full border-0 bg-transparent px-0 text-sm font-medium leading-5 outline-none"
        : "relative z-10 block min-h-8 w-full resize-none overflow-hidden border-0 bg-transparent px-0 py-1 text-sm leading-6 outline-none",
    isBox ? "" : "focus-visible:outline-none",
    isBox ? styles.diagramAnswerTextArea : "",
    reviewState === undefined ? "disabled:cursor-not-allowed disabled:opacity-60" : "disabled:cursor-default disabled:opacity-100",
    isBox && invalid ? "border-[var(--color-danger)]" : "",
    reviewState === "correct"
      ? styles.reviewAnswerCorrect
      : reviewState === "incorrect"
        ? styles.reviewAnswerIncorrect
        : invalid && !isEmpty ? "ui-text-danger" : "ui-text-answer",
  ].join(" ");
  const selectedAnswerClassName = [
    "relative z-10 inline-flex items-center rounded-full border border-transparent bg-[var(--color-surface-raised)] px-2.5 py-0.5 text-xs leading-4 ui-text-tertiary transition",
    isBox ? "w-max max-w-none" : "max-w-full truncate",
    disabled
      ? "cursor-default opacity-100"
      : "cursor-pointer outline-none ui-surface-pressed-hover ui-text-primary-hover focus-visible:ring-2 focus-visible:ring-[var(--color-brand-focus)]",
    isBox ? "min-h-8 justify-start" : "w-fit",
  ].join(" ");
  const selectedAnswerTextClassName = isBox ? "min-w-0 whitespace-normal break-words text-left" : "truncate";
  const resolveSelectedAnswerMaxWidth = useCallback((): number | null => {
    if (!isBox || wrapperRef.current === null) {
      return null;
    }

    const wrapperRect = wrapperRef.current.getBoundingClientRect();
    const contentBoundaryRight = suggestionContentBoundaryRight(wrapperRef.current);
    const availableRight = Math.floor(
      contentBoundaryRight - wrapperRect.left - SUGGESTION_VIEWPORT_GUTTER_PX,
    );
    if (availableRight <= 0) {
      return null;
    }

    return Math.min(availableRight, SELECTED_BOX_ANSWER_MAX_WIDTH_PX);
  }, [isBox]);
  const applySelectedAnswerMaxWidth = useCallback((): void => {
    const selectedAnswerButton = selectedAnswerButtonRef.current;
    if (!isBox || selectedAnswerButton === null) {
      return;
    }

    const maxWidth = resolveSelectedAnswerMaxWidth();
    if (maxWidth === null) {
      selectedAnswerButton.style.removeProperty("max-width");
      return;
    }

    selectedAnswerButton.style.maxWidth = `${maxWidth}px`;
  }, [isBox, resolveSelectedAnswerMaxWidth]);
  const resolveSuggestionsLayout = useCallback((suggestionCount: number): SuggestionsLayout => {
    if ((!isLine && !isBox) || wrapperRef.current === null) {
      return DEFAULT_SUGGESTIONS_LAYOUT;
    }

    const wrapperRect = wrapperRef.current.getBoundingClientRect();
    // The popover may use viewport whitespace beyond the assessment content column.
    const suggestionBoundaryRight = viewportClientWidth();
    const peerRects = suggestionPeerRects(wrapperRef.current);
    const estimatedHeight = estimatedSuggestionListHeight(suggestionCount);
    const inlineOffsetPixels = Math.min(
      wrapperRect.width,
      measureSuggestionAnchorOffset(textAreaRef.current, value, {
        includeInlineStartPadding: isBox,
      }),
    );
    const availableRight = suggestionBoundaryRight - wrapperRect.left - inlineOffsetPixels -
      SUGGESTION_VIEWPORT_GUTTER_PX;
    const bottomMaxWidth = boundedSuggestionWidth(
      suggestionBoundaryRight - wrapperRect.left - SUGGESTION_VIEWPORT_GUTTER_PX,
    );
    const candidates: SuggestionLayoutCandidate[] = [];
    let order = 0;

    if (availableRight >= suggestionsMinimumWidth) {
      const rightMaxWidth = boundedSuggestionWidth(availableRight);
      candidates.push(
        createSuggestionLayoutCandidate({
          blockPlacement: "down",
          estimatedHeight,
          inlineOffsetPixels,
          isLine,
          maxWidth: rightMaxWidth,
          order,
          peerRects,
          placement: "right",
          wrapperRect,
        }),
      );
      order += 1;
      candidates.push(
        createSuggestionLayoutCandidate({
          blockPlacement: "up",
          estimatedHeight,
          inlineOffsetPixels,
          isLine,
          maxWidth: rightMaxWidth,
          order,
          peerRects,
          placement: "right",
          wrapperRect,
        }),
      );
      order += 1;
    }

    candidates.push(
      createSuggestionLayoutCandidate({
        blockPlacement: "up",
        estimatedHeight,
        inlineOffsetPixels: null,
        isLine,
        maxWidth: bottomMaxWidth,
        order,
        peerRects,
        placement: "bottom",
        wrapperRect,
      }),
    );
    order += 1;
    candidates.push(
      createSuggestionLayoutCandidate({
        blockPlacement: "down",
        estimatedHeight,
        inlineOffsetPixels: null,
        isLine,
        maxWidth: bottomMaxWidth,
        order,
        peerRects,
        placement: "bottom",
        wrapperRect,
      }),
    );

    return suggestionsLayoutFromCandidate(
      pickSuggestionLayoutCandidate(candidates, suggestionsMinimumWidth),
    );
  }, [isBox, isLine, suggestionsMinimumWidth, value]);
  const openSuggestions = useCallback((): void => {
    setSuggestionsLayout(resolveSuggestionsLayout(suggestions.length));
    setSuggestionsOpen(true);
  }, [resolveSuggestionsLayout, suggestions.length]);

  useEffect(() => {
    if (!suggestionsEnabled || disabled || [...value.trim()].length < 3) {
      return;
    }

    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      void suggestLessonAssessmentAnswer(
        apiBaseUrl,
        route,
        attemptId,
        questionId,
        partId,
        value,
        { signal: controller.signal },
      ).then((result) => {
        if (!controller.signal.aborted) {
          const resultSuggestions = result.status === "ready" ? result.suggestions : [];
          setActiveSuggestionIndex(0);
          setSuggestionsLayout(resolveSuggestionsLayout(resultSuggestions.length));
          setSuggestionState({
            prefix: value,
            values: resultSuggestions,
          });
        }
      });
    }, SUGGESTION_DELAY_MS);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [apiBaseUrl, attemptId, disabled, partId, questionId, resolveSuggestionsLayout, route, suggestionsEnabled, value]);

  useLayoutEffect(() => {
    if (isBox) {
      const editableTextArea = textAreaRef.current;
      if (editableTextArea !== null) {
        fitBoxedTextArea(editableTextArea);
      }
      const widthMeasurementElement = selectedAnswerAccepted
        ? selectedAnswerButtonRef.current
        : editableTextArea;
      setFitContentWidthPixels(
        fitContent ? measureBoxFitContentWidth(
          widthMeasurementElement,
          value,
          selectedAnswerAccepted ? undefined : placeholder,
        ) : null,
      );
    } else if (isLine && textAreaRef.current !== null) {
      fitLineTextArea(textAreaRef.current);
      setFitContentWidthPixels(null);
    }
  }, [fitContent, isBox, isLine, placeholder, selectedAnswerAccepted, value]);

  useEffect(() => {
    if (isInline || textAreaRef.current === null) {
      return;
    }

    const textArea = textAreaRef.current;
    let previousWidth = textArea.clientWidth;
    const resizeObserver = new ResizeObserver(() => {
      const currentWidth = textArea.clientWidth;
      if (currentWidth !== previousWidth) {
        previousWidth = currentWidth;
        if (isBox) {
          fitBoxedTextArea(textArea);
        } else {
          fitLineTextArea(textArea);
        }
      }
    });
    resizeObserver.observe(textArea);

    return () => resizeObserver.disconnect();
  }, [isBox, isInline]);

  useEffect(() => {
    if (!suggestionsEnabled || !suggestionsOpen || suggestions.length === 0) {
      return;
    }

    document.getElementById(`${listId}-${activeSuggestionIndex}`)?.scrollIntoView({ block: "nearest" });
  }, [activeSuggestionIndex, listId, suggestions.length, suggestionsEnabled, suggestionsOpen]);

  useLayoutEffect(() => {
    if (!selectedAnswerAccepted || !isBox) {
      return;
    }

    applySelectedAnswerMaxWidth();
    window.addEventListener("resize", applySelectedAnswerMaxWidth);

    return () => window.removeEventListener("resize", applySelectedAnswerMaxWidth);
  }, [applySelectedAnswerMaxWidth, isBox, selectedAnswerAccepted, value]);

  function setAcceptedSuggestionValue(nextValue: string | null): void {
    setLocalAcceptedSuggestionValue(nextValue);
    onSuggestionAcceptedChange?.(nextValue);
  }

  function selectSuggestion(suggestion: string): void {
    setAcceptedSuggestionValue(suggestion);
    setSuggestionSelectionPromptRequested(false);
    onChange(suggestion);
    setSuggestionsOpen(false);
  }

  function focusEditableControl(): void {
    const control = isInline ? inputRef.current : textAreaRef.current;
    control?.focus();
    control?.setSelectionRange(value.length, value.length);
  }

  function handleSelectedAnswerEdit(): void {
    if (disabled) {
      return;
    }

    setAcceptedSuggestionValue(null);
    setSuggestionSelectionPromptRequested(false);
    window.requestAnimationFrame(focusEditableControl);
  }

  function handleFocus(): void {
    if (!hasCombobox) {
      return;
    }

    setSuggestionSelectionPromptRequested(false);
    openSuggestions();
  }

  function handleBlur(): void {
    if (!hasCombobox) {
      return;
    }

    setSuggestionsLayout(resolveSuggestionsLayout(suggestions.length));
    setSuggestionsOpen(false);
    if (!isEmpty && resolvedAcceptedSuggestionValue !== value) {
      setSuggestionSelectionPromptRequested(true);
    }
  }

  function handleValueChange(nextValue: string): void {
    setActiveSuggestionIndex(0);
    if (hasCombobox) {
      openSuggestions();
    }
    setSuggestionSelectionPromptRequested(false);
    if (resolvedAcceptedSuggestionValue !== nextValue) {
      setAcceptedSuggestionValue(null);
    }
    onChange(nextValue);
  }

  function handleSuggestionKeyDown(
    event: ReactKeyboardEvent<HTMLInputElement | HTMLTextAreaElement>,
  ): void {
    if (!suggestionsEnabled) {
      return;
    }

    if (event.key === "Enter") {
      const suggestion = suggestions[activeSuggestionIndex];
      if (suggestionsOpen && suggestion !== undefined) {
        event.preventDefault();
        selectSuggestion(suggestion);
      } else if (!isInline) {
        event.preventDefault();
      }
      return;
    }
    if (!suggestionsOpen || suggestions.length === 0) {
      return;
    }
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setActiveSuggestionIndex((index) => (index + 1) % suggestions.length);
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setActiveSuggestionIndex((index) => (index - 1 + suggestions.length) % suggestions.length);
    } else if (event.key === "Escape") {
      event.preventDefault();
      setSuggestionsOpen(false);
    }
  }

  return (
    <span
      ref={wrapperRef}
      className={wrapperClassName}
      style={wrapperStyle}
      role={hasCombobox && !selectedAnswerAccepted ? "combobox" : undefined}
      aria-controls={hasCombobox && suggestionsOpen && suggestions.length > 0 ? listId : undefined}
      aria-expanded={hasCombobox ? suggestionsOpen && suggestions.length > 0 : undefined}
      aria-haspopup={hasCombobox ? "listbox" : undefined}
      data-assessment-text-input-wrapper={isBox ? true : undefined}
    >
      {selectedAnswerAccepted ? (
        <button
          ref={selectedAnswerButtonRef}
          type="button"
          className={selectedAnswerClassName}
          disabled={disabled}
          aria-label={`Изменить выбранный ответ: ${value}`}
          onClick={handleSelectedAnswerEdit}
          data-ui={`lk.learning.assessment.question.${questionId}.part.${partId}.selectedAnswer`}
        >
          <span className={selectedAnswerTextClassName}>{value}</span>
        </button>
      ) : !isInline ? (
        <textarea
          ref={textAreaRef}
          id={controlId}
          value={value}
          rows={1}
          wrap="soft"
          disabled={disabled}
          autoComplete="off"
          spellCheck={false}
          maxLength={500}
          placeholder={placeholder}
          aria-invalid={invalid ? true : undefined}
          aria-describedby={accessibleDescription}
          aria-autocomplete={hasCombobox ? "list" : undefined}
          aria-activedescendant={hasCombobox && suggestionsOpen && suggestions.length > 0
            ? `${listId}-${activeSuggestionIndex}`
            : undefined}
          className={controlClassName}
          onFocus={handleFocus}
          onBlur={handleBlur}
          onKeyDown={handleSuggestionKeyDown}
          onChange={(event) => {
            handleValueChange(event.target.value.replace(/\s*\n+\s*/g, " "));
          }}
          data-ui={`lk.learning.assessment.question.${questionId}.part.${partId}.input`}
          data-review-state={reviewState}
        />
      ) : (
        <input
          ref={inputRef}
          id={controlId}
          type="text"
          value={value}
          disabled={disabled}
          autoComplete="off"
          spellCheck={false}
          maxLength={500}
          placeholder={placeholder}
          aria-invalid={invalid ? true : undefined}
          aria-describedby={accessibleDescription}
          aria-autocomplete={hasCombobox ? "list" : undefined}
          aria-activedescendant={hasCombobox && suggestionsOpen && suggestions.length > 0
            ? `${listId}-${activeSuggestionIndex}`
            : undefined}
          className={controlClassName}
          onFocus={handleFocus}
          onBlur={handleBlur}
          onKeyDown={handleSuggestionKeyDown}
          onChange={(event) => {
            handleValueChange(event.target.value);
          }}
          data-ui={`lk.learning.assessment.question.${questionId}.part.${partId}.input`}
          data-review-state={reviewState}
        />
      )}
      {isEmpty && showGuide && !isBox ? (
        <span
          className={`pointer-events-none absolute inset-x-0 ${isInline ? "bottom-0.5" : "bottom-1.5"} border-b ${
            invalid ? "border-[var(--color-danger)]" : "border-[var(--color-border-strong)]"
          }`}
          aria-hidden="true"
        />
      ) : null}
      {reviewStatusId ? (
        <span id={reviewStatusId} className="sr-only">
          {reviewState === "correct" ? "Верный ответ" : "Неверный ответ"}
        </span>
      ) : null}
      {hasCombobox && suggestionsOpen && suggestions.length > 0 ? (
        <ul
          id={listId}
          role="listbox"
          className={`absolute z-30 overflow-y-auto overscroll-contain rounded-[0.625rem] border border-[var(--color-border-subtle)] bg-white p-0.5 shadow-[0_8px_22px_rgb(26_29_31_/_0.04)] ${suggestionsPositionClassName} ${suggestionsSizeClassName}`}
          style={suggestionsListStyle}
        >
          {suggestions.map((suggestion, index) => (
            <li key={suggestion} role="presentation">
              <button
                id={`${listId}-${index}`}
                type="button"
                role="option"
                aria-selected={index === activeSuggestionIndex}
                className={`block w-full cursor-pointer rounded-md px-2.5 py-1 text-left ${suggestionOptionTypographyClassName} ui-text-secondary transition ui-surface-neutral-hover ui-text-primary-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-brand)] ui-surface-pressed-active aria-selected:font-medium aria-selected:text-zinc-800`}
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => selectSuggestion(suggestion)}
              >
                {suggestion}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      {suggestionSelectionWarningVisible ? (
        <span
          id={suggestionSelectionWarningId}
          className="absolute left-0 top-full z-20 mt-1 w-max max-w-[min(24rem,calc(100vw-2rem))] rounded-[var(--radius-card)] border border-[var(--color-border-subtle)] bg-white px-3 py-2 text-xs leading-5 ui-text-secondary shadow-[0_16px_44px_rgb(24_28_32_/_0.10)] before:absolute before:-top-1.5 before:left-5 before:h-3 before:w-3 before:rotate-45 before:border-l before:border-t before:border-[var(--color-border-subtle)] before:bg-white before:content-['']"
          style={suggestionWarningStyle}
          role="status"
          aria-live="polite"
          data-ui={`lk.learning.assessment.question.${questionId}.part.${partId}.suggestionWarning`}
        >
          <span className="font-semibold ui-text-tertiary">Выберите ответ из всплывающей подсказки</span>
          <br />
          — только так он будет засчитан
        </span>
      ) : null}
    </span>
  );
}
