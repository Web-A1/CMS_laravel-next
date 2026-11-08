import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const componentUrl = new URL("./LessonAssessment.tsx", import.meta.url);
const resultUrl = new URL("./LessonAssessmentResult.tsx", import.meta.url);
const questionUrl = new URL("./LessonAssessmentQuestion.tsx", import.meta.url);
const diagramUrl = new URL("./LessonAssessmentDiagram.tsx", import.meta.url);
const diagramCalibrationPanelUrl = new URL("./LessonAssessmentDiagramCalibrationPanel.tsx", import.meta.url);
const diagramCalibrationHookUrl = new URL("./useLessonAssessmentDiagramCalibration.ts", import.meta.url);
const sidebarUrl = new URL("./LessonAssessmentSidebar.tsx", import.meta.url);
const sidebarServerPageUrl = new URL(
  "../../app/(site)/lk/learning/@sidebar/[subjectSlug]/[courseSlug]/[lessonSlug]/exam/page.tsx",
  import.meta.url,
);
const sentenceCompletionUrl = new URL("./LessonAssessmentSentenceCompletion.tsx", import.meta.url);
const textInputUrl = new URL("./LessonAssessmentTextInput.tsx", import.meta.url);
const stylesUrl = new URL("./lesson-assessment.module.css", import.meta.url);
const learningLayoutStylesUrl = new URL("./learning-layout.css", import.meta.url);
const controllerModulePath = "./lessonAssessmentController.ts";
const controllerModule: typeof import("./lessonAssessmentController") = await import(controllerModulePath);

test("renders choice, typed and expanded question types with native accessible controls", async () => {
  const questionSource = await readFile(questionUrl, "utf8");
  const singleChoiceSource = questionSource.match(
    /if \(question\.type === "single_choice"\)[\s\S]*?if \(question\.type === "multiple_choice"\)/,
  )?.[0] ?? "";

  assert.match(questionSource, /<fieldset/);
  assert.match(questionSource, /<legend/);
  assert.match(questionSource, /type="radio"/);
  assert.match(questionSource, /type="checkbox"/);
  assert.match(questionSource, /compactOptions/);
  assert.match(questionSource, /md:grid-cols-2/);
  assert.match(questionSource, /<select/);
  assert.match(questionSource, /aria-describedby=/);
  assert.match(questionSource, /Ответы зафиксированы/);
  assert.match(questionSource, /Ожидает ручной проверки/);
  assert.doesNotMatch(questionSource, /Задание зачтено/);
  assert.match(questionSource, /const dimDisabledFieldset = !locked && !reviewMode/);
  assert.match(questionSource, /dimDisabledFieldset \? "disabled:opacity-75" : ""/);
  assert.match(questionSource, /const answerBlockClassName = locked && !reviewMode \? "mt-5 grid gap-3" : "mt-8 grid gap-3"/);
  assert.match(questionSource, /const answerControlsClassName = locked && !reviewMode \? "opacity-60" : undefined/);
  assert.match(questionSource, /function fitLongTextAreaToContent/);
  assert.match(questionSource, /element\.style\.overflowY = "hidden"/);
  assert.match(questionSource, /const longTextAutoFitEnabled = question\.type === "long_text" && \(locked \|\| reviewMode\) && longTextValue\.trim\(\) !== ""/);
  assert.match(questionSource, /ref=\{longTextAreaRef\}/);
  assert.match(questionSource, /className="ui-text-brand text-sm font-normal leading-5"/);
  assert.doesNotMatch(questionSource, /ui-text-brand text-sm font-semibold/);
  assert.match(questionSource, /<div className=\{answerControlsClassName\}>/);
  assert.match(questionSource, /<textarea/);
  assert.match(questionSource, /LessonAssessmentTextInput/);
  assert.match(singleChoiceSource, /className="grid w-fit gap-1"/);
  assert.match(singleChoiceSource, /min-h-9 w-fit items-center/);
  assert.match(singleChoiceSource, /reviewMode \? "cursor-default" : "cursor-pointer ui-text-emphasis-hover"/);
  assert.doesNotMatch(singleChoiceSource, /border-\[var\(--color-border-subtle\)\]/);
  assert.doesNotMatch(singleChoiceSource, /bg-white/);
  assert.doesNotMatch(questionSource, /draggable=/);
  assert.doesNotMatch(questionSource, /dangerouslySetInnerHTML/);
});

test("typed blanks keep automatic suggestions separate from manual-answer controls", async () => {
  const [componentSource, questionSource, diagramSource, sentenceCompletionSource, textInputSource] = await Promise.all([
    readFile(componentUrl, "utf8"),
    readFile(questionUrl, "utf8"),
    readFile(diagramUrl, "utf8"),
    readFile(sentenceCompletionUrl, "utf8"),
    readFile(textInputUrl, "utf8"),
  ]);

  assert.doesNotMatch(questionSource, /Введите первые три символа/);
  assert.doesNotMatch(diagramSource, /Введите первые три символа/);
  assert.match(questionSource, /<label className="sr-only"/);
  assert.match(questionSource, /<ol className="grid max-w-2xl list-none gap-1 p-0"/);
  assert.match(questionSource, /\{index \+ 1\}\./);
  assert.match(questionSource, /grid-cols-\[1rem_minmax\(0,1fr\)\] items-center gap-1/);
  assert.match(questionSource, /groupLessonAssessmentTextParts/);
  assert.match(questionSource, /aria-label="Группы классификации"/);
  assert.match(questionSource, /md:grid-cols-\[minmax\(0,0\.9fr\)_minmax\(0,1\.05fr\)_minmax\(0,1\.2fr\)\]/);
  assert.match(questionSource, /\["а\)", "б\)", "в\)"\]/);
  assert.match(questionSource, /whitespace-nowrap text-sm font-normal/);
  assert.match(questionSource, /\$\{partIndex \+ 1\}\./);
  assert.doesNotMatch(questionSource, /translate-y-/);
  assert.doesNotMatch(questionSource, /renderTextPart\(part, "•"\)/);
  assert.match(textInputSource, /<span/);
  assert.match(textInputSource, /variant\?: "box" \| "inline" \| "line"/);
  assert.match(textInputSource, /fitContent\?: boolean/);
  assert.match(textInputSource, /fitContent = false/);
  assert.match(textInputSource, /showGuide\?: boolean/);
  assert.match(textInputSource, /placeholder\?: string/);
  assert.match(textInputSource, /invalid && !isEmpty \? "ui-text-danger" : "ui-text-answer"/);
  assert.match(textInputSource, /border-0 bg-transparent/);
  assert.match(textInputSource, /"bottom-1\.5"/);
  assert.match(textInputSource, /border-b/);
  assert.doesNotMatch(textInputSource, /group-focus-within:opacity-0/);
  assert.match(textInputSource, /min-h-8 w-full resize-none overflow-hidden/);
  assert.match(textInputSource, /function fitLineTextArea/);
  assert.match(textInputSource, /const LINE_MIN_CHARACTER_WIDTH = 18/);
  assert.match(textInputSource, /const LINE_WIDTH_PADDING_CHARACTERS = 2/);
  assert.match(textInputSource, /const BOX_HEIGHT_PX = 32/);
  assert.match(textInputSource, /const BOX_FIT_CONTENT_MIN_WIDTH_PX = 96/);
  assert.match(textInputSource, /const BOX_FIT_CONTENT_MAX_WIDTH_PX = 512/);
  assert.match(textInputSource, /const BOX_FIT_CONTENT_EXTRA_WIDTH_PX = 2/);
  assert.match(textInputSource, /function measureBoxFitContentWidth/);
  assert.match(textInputSource, /window\.getComputedStyle\(element, "::placeholder"\)/);
  assert.match(textInputSource, /measuredTextWidths\.push\(context\.measureText\(trimmedValue\)\.width\)/);
  assert.match(textInputSource, /measuredTextWidths\.push\(context\.measureText\(placeholder\)\.width\)/);
  assert.match(textInputSource, /Math\.max\(\.\.\.measuredTextWidths\) \+ horizontalChromeWidth/);
  assert.match(textInputSource, /const \[fitContentWidthPixels, setFitContentWidthPixels\] = useState<number \| null>\(null\)/);
  assert.match(textInputSource, /const widthMeasurementElement = selectedAnswerAccepted[\s\S]*selectedAnswerButtonRef\.current[\s\S]*textAreaRef\.current/);
  assert.match(textInputSource, /selectedAnswerAccepted \? undefined : placeholder/);
  assert.match(textInputSource, /isBox && fitContent && fitContentWidthPixels !== null[\s\S]*width: `min\(100%, \$\{fitContentWidthPixels\}px\)`/);
  assert.doesNotMatch(textInputSource, /Math\.min\(wrapperRect\.width, availableRight\)/);
  assert.match(textInputSource, /return Math\.min\(availableRight, SELECTED_BOX_ANSWER_MAX_WIDTH_PX\)/);
  assert.match(textInputSource, /width: `min\(100%, \$\{lineCharacterWidth\}ch\)`/);
  assert.match(textInputSource, /isLine \? "relative grid min-h-8 max-w-full items-center"/);
  assert.match(textInputSource, /suggestionsSizeClassName/);
  assert.match(textInputSource, /const suggestionOptionTypographyClassName = isBox \? "text-xs leading-4" : "text-sm leading-5"/);
  assert.match(textInputSource, /isLine \? "w-max min-w-20 max-w-\[min\(32rem,calc\(100vw-2rem\)\)\]" : ""/);
  assert.match(textInputSource, /isInline \? \(/);
  assert.doesNotMatch(textInputSource, /<datalist/);
  assert.match(textInputSource, /<textarea/);
  assert.match(textInputSource, /BOX_HEIGHT_PX/);
  assert.match(textInputSource, /element\.style\.removeProperty\("font-size"\)/);
  assert.match(textInputSource, /element\.style\.removeProperty\("line-height"\)/);
  assert.match(textInputSource, /element\.style\.height = "0px"/);
  assert.match(textInputSource, /Math\.max\(BOX_HEIGHT_PX, element\.scrollHeight\)/);
  assert.doesNotMatch(textInputSource, /BOX_FONT_SIZES_PX/);
  assert.doesNotMatch(textInputSource, /BOX_COMPACT_LINE_HEIGHT_PX/);
  assert.doesNotMatch(textInputSource, /BOX_COMPACT_PADDING_BLOCK_PX/);
  assert.doesNotMatch(textInputSource, /BOX_TWO_LINE_HEIGHT_PX/);
  assert.match(textInputSource, /ResizeObserver/);
  assert.match(textInputSource, /role="listbox"/);
  assert.match(textInputSource, /role="option"/);
  assert.match(textInputSource, /\$\{suggestionOptionTypographyClassName\} ui-text-secondary/);
  assert.match(textInputSource, /className=\{`block w-full cursor-pointer/);
  assert.match(textInputSource, /ui-text-secondary/);
  assert.match(textInputSource, /ui-surface-neutral-hover/);
  assert.match(textInputSource, /ui-text-primary-hover/);
  assert.match(textInputSource, /ui-surface-pressed-active/);
  assert.match(textInputSource, /aria-selected:text-zinc-800/);
  assert.match(textInputSource, /rounded-\[0\.625rem\]/);
  assert.match(textInputSource, /shadow-\[0_8px_22px_rgb\(26_29_31_/);
  assert.match(textInputSource, /aria-selected:font-medium/);
  assert.doesNotMatch(textInputSource, /shadow-md/);
  assert.doesNotMatch(textInputSource, /aria-selected:bg-white/);
  assert.doesNotMatch(textInputSource, /aria-selected:bg-\[var\(--color-brand-surface\)\]/);
  assert.doesNotMatch(textInputSource, /aria-selected:bg-\[var\(--color-surface-subtle\)\]/);
  assert.doesNotMatch(textInputSource, /aria-selected:ring-/);
  assert.doesNotMatch(textInputSource, /aria-selected:shadow-\[/);
  assert.match(textInputSource, /suggestionsOpen && suggestions\.length > 0/);
  assert.match(textInputSource, /onFocus=\{handleFocus\}/);
  assert.match(textInputSource, /onBlur=\{handleBlur\}/);
  assert.match(textInputSource, /scrollIntoView\(\{ block: "nearest" \}\)/);
  assert.match(textInputSource, /readonly suggestionsEnabled\?: boolean/);
  assert.match(textInputSource, /suggestionsEnabled = true/);
  assert.match(textInputSource, /if \(!suggestionsEnabled \|\| disabled \|\| \[\.\.\.value\.trim\(\)\]\.length < 3\)/);
  assert.match(textInputSource, /role=\{hasCombobox && !selectedAnswerAccepted \? "combobox" : undefined\}/);
  assert.match(textInputSource, /aria-autocomplete=\{hasCombobox \? "list" : undefined\}/);
  assert.match(textInputSource, /hasCombobox && suggestionsOpen && suggestions\.length > 0/);
  assert.match(textInputSource, /void suggestLessonAssessmentAnswer/);
  assert.match(questionSource, /suggestionsEnabled=\{question\.gradingMode === "automatic"\}/);
  assert.match(diagramSource, /suggestionsEnabled=\{question\.gradingMode === "automatic"\}/);
  assert.match(sentenceCompletionSource, /suggestionsEnabled=\{question\.gradingMode === "automatic"\}/);
  assert.doesNotMatch(questionSource, /attemptId=\{question\.gradingMode === "manual" \? 0 : attemptId\}/);
  assert.match(textInputSource, /event\.target\.value\.replace/);
  assert.match(textInputSource, /styles\.diagramAnswerTextArea/);
  assert.match(textInputSource, /placeholder=\{placeholder\}/);
  assert.match(textInputSource, /isBox[\s\S]*ui-input[\s\S]*h-8[\s\S]*text-xs leading-4[\s\S]*placeholder:text-xs/);
  assert.match(textInputSource, /placeholder:opacity-\[0\.55\]/);
  assert.match(textInputSource, /const inlineWrapperWidth = selectedAnswerAccepted \? "max-content" : `\$\{inlineCharacterWidth\}ch`/);
  assert.match(textInputSource, /isInline\s*\?\s*\{ width: inlineWrapperWidth \}/);
  assert.match(componentSource, /className=\{`grid gap-8/);
  assert.match(componentSource, /currentQuestion\.type === "single_choice"/);
  assert.match(componentSource, /<div className="flex flex-wrap justify-start gap-3">/);
  assert.match(questionSource, /<legend className="p-0/);
  assert.match(questionSource, /<div className=\{answerBlockClassName\}>/);
});

test("in-progress exams use contextual suggestion coaching without onboarding or grouped navigation", async () => {
  const [
    componentSource,
    sidebarSource,
    sidebarServerSource,
    textInputSource,
  ] = await Promise.all([
    readFile(componentUrl, "utf8"),
    readFile(sidebarUrl, "utf8"),
    readFile(sidebarServerPageUrl, "utf8"),
    readFile(textInputUrl, "utf8"),
  ]);

  assert.doesNotMatch(componentSource, /LessonAssessmentOnboardingDialog/);
  assert.doesNotMatch(componentSource, /onboardingEnabled|onboardingPreviewMode/);
  assert.doesNotMatch(componentSource, /LESSON_ASSESSMENT_ONBOARDING|question=__guide__/);
  assert.doesNotMatch(componentSource, /showModal\(\)|localStorage\.setItem/);

  assert.match(sidebarServerSource, /gradingMode: question\.gradingMode/);
  assert.doesNotMatch(sidebarSource, /groupNavigationItems|CHECK_GROUP_ORDER/);
  assert.doesNotMatch(sidebarSource, /LESSON_ASSESSMENT_CHECK_GROUP_COPY/);
  assert.doesNotMatch(sidebarSource, /<optgroup|data-check-group|sidebar\.group/);
  assert.doesNotMatch(sidebarSource, /Проверка системой|Проверка преподавателем/);
  assert.doesNotMatch(sidebarSource, /groupMarkerClass|bg-sky-400|h-2 w-2 rounded-full|h-1\.5 w-1\.5 shrink-0 rounded-full/);
  assert.doesNotMatch(sidebarSource, /LESSON_ASSESSMENT_GUIDE_STEP_ID|LESSON_ASSESSMENT_GUIDE_TITLE|sidebar\.guide|0\./);
  assert.match(sidebarSource, /navigationItems\.map\(\(item\) =>/);
  assert.match(sidebarSource, /<option key=\{item\.id\} value=\{item\.id\}>/);

  assert.doesNotMatch(textInputSource, /readonly onSuggestionSelect\?: \(suggestion: string\) => void/);
  assert.doesNotMatch(textInputSource, /readonly controlIdPrefix\?: string/);
  assert.doesNotMatch(textInputSource, /readonly dataUi\?: string/);
  assert.doesNotMatch(textInputSource, /controlIdPrefix = "assessment"/);
  assert.doesNotMatch(textInputSource, /onSuggestionSelect\?\.\(suggestion\)/);
  assert.match(textInputSource, /const controlId = `assessment-\$\{questionId\}-\$\{partId\}`/);
  assert.doesNotMatch(textInputSource, /SUGGESTION_SELECTION_WARNING/);
  assert.match(textInputSource, /suggestionSelectionPromptRequested/);
  assert.match(textInputSource, /suggestionSelectionWarningVisible/);
  assert.match(textInputSource, /acceptedSuggestionValue/);
  assert.match(textInputSource, /suggestionSelectionPending/);
  assert.match(textInputSource, /function handleBlur/);
  assert.match(textInputSource, /setSuggestionSelectionPromptRequested\(true\)/);
  assert.match(textInputSource, /suggestionSelectionWarningId/);
  assert.match(textInputSource, /suggestionSelectionWarningVisible \? suggestionSelectionWarningId/);
  assert.match(textInputSource, /role="status"/);
  assert.match(textInputSource, /aria-live="polite"/);
  assert.match(textInputSource, /suggestionWarning/);
  assert.doesNotMatch(textInputSource, /Вернитесь в предыдущее поле и/);
  assert.match(textInputSource, /className="font-semibold ui-text-tertiary">Выберите ответ из всплывающей подсказки/);
  assert.match(textInputSource, /<\/span>\s*<br \/>/);
  assert.match(textInputSource, /только так он будет засчитан/);
  assert.doesNotMatch(textInputSource, /только так он будет засчитан\./);
  assert.match(textInputSource, /absolute left-0 top-full/);
  assert.match(textInputSource, /w-max max-w-\[min\(24rem,calc\(100vw-2rem\)\)\]/);
  assert.match(textInputSource, /SUGGESTION_WARNING_ARROW_OFFSET_PX = 32/);
  assert.match(textInputSource, /suggestionWarningStyle/);
  assert.match(textInputSource, /inlineOffsetPixels - SUGGESTION_WARNING_ARROW_OFFSET_PX/);
  assert.match(textInputSource, /mt-1/);
  assert.match(textInputSource, /before:-top-1\.5/);
  assert.match(textInputSource, /before:left-5/);
  assert.match(textInputSource, /border-\[var\(--color-border-subtle\)\]/);
  assert.match(textInputSource, /bg-white/);
  assert.match(textInputSource, /ui-text-secondary/);
  assert.doesNotMatch(textInputSource, /left-3 top-full/);
  assert.doesNotMatch(textInputSource, /suggestionWarningUsesInlineOffset/);
  assert.doesNotMatch(textInputSource, /border-amber-200/);
  assert.doesNotMatch(textInputSource, /border-red-200/);
  assert.doesNotMatch(textInputSource, /bg-red-50\/95/);
  assert.match(textInputSource, /selectedAnswerAccepted/);
  assert.match(textInputSource, /selectedAnswerClassName/);
  assert.match(textInputSource, /handleSelectedAnswerEdit/);
  assert.match(textInputSource, /Изменить выбранный ответ/);
  assert.match(textInputSource, /selectedAnswer/);
  assert.match(textInputSource, /acceptedSuggestionValue\?: string \| null/);
  assert.match(textInputSource, /onSuggestionAcceptedChange\?: \(value: string \| null\) => void/);
  assert.match(textInputSource, /localAcceptedSuggestionValue/);
  assert.match(textInputSource, /resolvedAcceptedSuggestionValue === value/);
  assert.match(textInputSource, /rounded-full/);
  assert.match(textInputSource, /bg-\[var\(--color-surface-raised\)\]/);
  assert.match(textInputSource, /px-2\.5 py-0\.5/);
  assert.match(textInputSource, /selectedAnswerClassName = \[[\s\S]*text-xs leading-4 ui-text-tertiary/);
  assert.match(textInputSource, /isBox \? "w-max max-w-none" : "max-w-full truncate"/);
  assert.match(textInputSource, /isBox \? "min-h-8 justify-start" : "w-fit"/);
  assert.match(textInputSource, /const selectedAnswerTextClassName = isBox \? "min-w-0 whitespace-normal break-words text-left" : "truncate"/);
  assert.match(textInputSource, /SELECTED_BOX_ANSWER_MAX_WIDTH_PX = 512/);
  assert.match(textInputSource, /selectedAnswerButtonRef/);
  assert.match(textInputSource, /applySelectedAnswerMaxWidth/);
  assert.match(textInputSource, /selectedAnswerButton\.style\.maxWidth = `\$\{maxWidth\}px`/);
  assert.match(textInputSource, /ref=\{selectedAnswerButtonRef\}/);
  assert.match(textInputSource, /function viewportClientWidth\(\): number/);
  assert.match(textInputSource, /contentBoundaryRight - wrapperRect\.left - SUGGESTION_VIEWPORT_GUTTER_PX/);
  assert.doesNotMatch(textInputSource, /Math\.min\(wrapperRect\.width, availableRight\)/);
  assert.match(textInputSource, /return Math\.min\(availableRight, SELECTED_BOX_ANSWER_MAX_WIDTH_PX\)/);
  assert.match(textInputSource, /window\.addEventListener\("resize", applySelectedAnswerMaxWidth\)/);
  assert.doesNotMatch(textInputSource, /isLine \? "min-h-8 w-fit" : "w-fit"/);
  assert.match(textInputSource, /ui-surface-pressed-hover/);
  assert.match(textInputSource, /focus-visible:ring-2/);
  assert.doesNotMatch(textInputSource, /font-semibold leading-5 text-zinc-700/);
  assert.doesNotMatch(textInputSource, /hover:border-\[var\(--color-brand\)\]/);
  assert.doesNotMatch(textInputSource, />\s*✓\s*</);
  assert.match(textInputSource, /window\.requestAnimationFrame\(focusEditableControl\)/);
  assert.match(textInputSource, /suggestionsLayout\.placement === "right"/);
  assert.match(textInputSource, /"top-0"/);
  assert.match(textInputSource, /suggestionsLayout\.blockPlacement === "up"/);
  assert.match(textInputSource, /isInline\s*\?\s*"left-0 top-6 mt-0"/);
  assert.match(textInputSource, /\(isLine \|\| isBox\) && suggestionsLayout\.placement === "right"/);
  assert.match(textInputSource, /isLine \? "left-0 top-7 mt-0" : "left-0 top-full mt-1"/);
  assert.match(textInputSource, /isBox \? "w-max min-w-44 max-w-\[min\(32rem,calc\(100vw-2rem\)\)\]"/);
  assert.match(textInputSource, /SUGGESTION_ANCHOR_GAP_PX = 4/);
  assert.doesNotMatch(textInputSource, /SUGGESTION_SIDE_MIN_SPACE_PX/);
  assert.match(textInputSource, /SUGGESTION_MAX_HEIGHT_PX = 240/);
  assert.match(textInputSource, /SUGGESTION_MIN_VISIBLE_HEIGHT_PX = 56/);
  assert.match(textInputSource, /SUGGESTION_COLLISION_GAP_PX = 4/);
  assert.match(textInputSource, /function suggestionContentBoundaryRight/);
  assert.match(textInputSource, /\[data-ui="lk\.learning\.assessmentPage"\]/);
  assert.match(textInputSource, /function suggestionPeerRects/);
  assert.match(textInputSource, /\[data-ui\$="\.diagram\.image"\]/);
  assert.match(textInputSource, /querySelectorAll<HTMLElement>\("\[data-assessment-text-input-wrapper\]"\)/);
  assert.match(textInputSource, /function createSuggestionLayoutCandidate/);
  assert.match(textInputSource, /function pickSuggestionLayoutCandidate/);
  assert.match(textInputSource, /usableCandidates\.find\(\(candidate\) => candidate\.collisionScore === 0\)/);
  assert.match(textInputSource, /blockPlacement: "down"[\s\S]*placement: "right"/);
  assert.match(textInputSource, /blockPlacement: "up"[\s\S]*placement: "right"/);
  assert.match(textInputSource, /blockPlacement: "up"[\s\S]*placement: "bottom"/);
  assert.match(textInputSource, /blockPlacement: "down"[\s\S]*placement: "bottom"/);
  assert.match(textInputSource, /const suggestionBoundaryRight = viewportClientWidth\(\)/);
  assert.match(textInputSource, /suggestionBoundaryRight - wrapperRect\.left - inlineOffsetPixels/);
  assert.match(textInputSource, /availableRight >= suggestionsMinimumWidth/);
  assert.match(textInputSource, /pickSuggestionLayoutCandidate\(candidates, suggestionsMinimumWidth\)/);
  assert.doesNotMatch(textInputSource, /resolveSuggestionsLayout\(\)/);
  assert.match(textInputSource, /maxHeight: `\$\{suggestionsLayout\.maxHeight\}px`/);
  assert.match(textInputSource, /overflow-y-auto overscroll-contain/);
  assert.doesNotMatch(textInputSource, /max-h-40 overflow-y-auto/);
  assert.match(textInputSource, /measureSuggestionAnchorOffset/);
  assert.match(textInputSource, /inlineStartPadding \+ context\.measureText\(trimmedValue\)\.width \+ SUGGESTION_ANCHOR_GAP_PX/);
  assert.match(textInputSource, /includeInlineStartPadding: isBox/);
  assert.match(textInputSource, /left: `\$\{suggestionsLayout\.inlineOffsetPixels\}px`/);
  assert.match(textInputSource, /data-assessment-text-input-wrapper=\{isBox \? true : undefined\}/);
  assert.match(textInputSource, /onFocus=\{handleFocus\}/);
  assert.match(textInputSource, /onBlur=\{handleBlur\}/);
  assert.match(textInputSource, /setAcceptedSuggestionValue\(suggestion\)/);
  assert.match(textInputSource, /resolvedAcceptedSuggestionValue !== value/);
});

test("assessment navigation uses its scrollbar axis as the right outline", async () => {
  const [sidebarSource, sidebarServerPageSource, contentStylesSource, assessmentStylesSource] = await Promise.all([
    readFile(sidebarUrl, "utf8"),
    readFile(sidebarServerPageUrl, "utf8"),
    readFile(learningLayoutStylesUrl, "utf8"),
    readFile(stylesUrl, "utf8"),
  ]);

  assert.doesNotMatch(sidebarSource, /Последовательность вопросов/);
  assert.match(sidebarSource, />\s*Задания\s*<\/h2>/);
  assert.match(sidebarSource, /styles\.questionLink/);
  assert.match(sidebarSource, /readonly lockedQuestionIds\?: ReadonlyArray<string>/);
  assert.match(sidebarSource, /lockedQuestionIds = \[\]/);
  assert.match(sidebarSource, /const lockedQuestionIdSet = new Set\(lockedQuestionIds\)/);
  assert.match(sidebarSource, /data-completion-state=\{itemCompletionState\}/);
  assert.match(sidebarSource, />Ответ зафиксирован<\/span>/);
  assert.match(sidebarSource, /styles\.questionLinkNumber/);
  assert.match(sidebarSource, /styles\.questionLinkLabel/);
  assert.doesNotMatch(sidebarSource, /w-5 shrink-0 tabular-nums/);
  assert.doesNotMatch(sidebarSource, /w-5 shrink-0 opacity-60/);
  assert.match(sidebarServerPageSource, /lockedQuestionIds=\{state\.data\.attempt\?\.lockedQuestionIds \?\? \[\]\}/);
  assert.match(sidebarSource, /getComputedStyle\(list\)\.paddingBottom/);
  assert.match(sidebarSource, /const visibleBottom = listRect\.bottom - paddingBottom/);
  assert.match(sidebarSource, /activeContextBottom > visibleBottom/);
  assert.match(sidebarSource, /aria-labelledby="lesson-assessment-navigation-title"/);
  assert.match(sidebarSource, /hidden grid-cols-1 gap-1 overflow-y-auto/);
  assert.ok(sidebarSource.indexOf("Задания") < sidebarSource.indexOf('data-ui="lk.learning.assessment.sidebar.questionList"'));
  assert.match(contentStylesSource, /grid-template-columns:\s*18rem minmax\(0, 1fr\)/);
  assert.doesNotMatch(contentStylesSource, /border-inline-end/);
  assert.doesNotMatch(sidebarSource, /lg:pr-3/);
  assert.match(
    assessmentStylesSource,
    /--assessment-sidebar-scrollbar-width:\s*0\.75rem/,
  );
  assert.match(
    assessmentStylesSource,
    /--assessment-sidebar-scrollbar-axis-inset:\s*0\.375rem/,
  );
  assert.match(
    assessmentStylesSource,
    /--assessment-sidebar-outline-width:\s*1px/,
  );
  assert.match(
    assessmentStylesSource,
    /--assessment-sidebar-outline-half-width:\s*0\.5px/,
  );
  assert.match(
    assessmentStylesSource,
    /--assessment-sidebar-outline-color:\s*rgb\(161 161 170 \/ 42%\)/,
  );
  assert.match(
    assessmentStylesSource,
    /--assessment-sidebar-outline-radius:\s*1rem/,
  );
  assert.match(
    assessmentStylesSource,
    /\.assessmentSidebar::before\s*\{[\s\S]*?inset-inline-end:\s*calc\([\s\S]*?var\(--assessment-sidebar-scrollbar-axis-inset\)[\s\S]*?var\(--assessment-sidebar-outline-half-width\)[\s\S]*?border:\s*var\(--assessment-sidebar-outline-width\) solid var\(--assessment-sidebar-outline-color\)/,
  );
  assert.match(
    assessmentStylesSource,
    /\.questionList::\-webkit-scrollbar-track\s*\{[\s\S]*?background:\s*transparent[\s\S]*?margin-block-end:\s*var\(--assessment-sidebar-outline-radius, 1rem\)/,
  );
  assert.doesNotMatch(
    assessmentStylesSource,
    /\.questionList::\-webkit-scrollbar-track\s*\{[\s\S]*?linear-gradient/,
  );
  assert.match(assessmentStylesSource, /\.questionList \.questionLink\s*\{[\s\S]*gap:\s*0/);
  assert.match(
    assessmentStylesSource,
    /\.questionList\s*\{[\s\S]*padding-block-start:\s*0;[\s\S]*padding-block-end:\s*0\.375rem/,
  );
  assert.match(
    assessmentStylesSource,
    /\.questionList \.questionLink\s*\{[\s\S]*width:\s*calc\(100% \+ 0\.375rem\)[\s\S]*margin-inline:\s*-0\.1875rem/,
  );
  assert.match(
    assessmentStylesSource,
    /\.questionList \.questionLink\s*\{[\s\S]*min-height:\s*1\.5rem[\s\S]*padding-block:\s*0/,
  );
  assert.match(
    assessmentStylesSource,
    /\.questionLinkContent\s*\{[\s\S]*display:\s*grid;[\s\S]*grid-template-columns:\s*2rem minmax\(0, 1fr\);[\s\S]*column-gap:\s*0\.375rem/,
  );
  assert.match(
    assessmentStylesSource,
    /\.questionLinkNumber\s*\{[\s\S]*font-variant-numeric:\s*tabular-nums;[\s\S]*text-align:\s*end/,
  );
  assert.match(assessmentStylesSource, /\.questionLinkLabel\s*\{[\s\S]*min-width:\s*0/);
  assert.match(
    assessmentStylesSource,
    /\.questionList \.questionLink\[data-completion-state="completed"\]:not\(\[data-state="active"\]\)\s*\{[\s\S]*color:\s*var\(--color-text-soft\)/,
  );
  assert.match(
    assessmentStylesSource,
    /\.questionList \.questionLink\[data-completion-state="completed"\]:not\(\[data-state="active"\]\):hover\s*\{[\s\S]*color:\s*var\(--color-text-muted\)/,
  );
  assert.doesNotMatch(assessmentStylesSource, /padding-inline-start:\s*0(?:;|\s)/);
  assert.doesNotMatch(assessmentStylesSource, /scrollbar-color\s*:/);
  assert.doesNotMatch(assessmentStylesSource, /(^|[;{]\s*)scrollbar-width\s*:/m);
  assert.match(
    assessmentStylesSource,
    /\.questionList::\-webkit-scrollbar\s*\{[\s\S]*?width:\s*var\(--assessment-sidebar-scrollbar-width, 0\.75rem\)/,
  );
  assert.match(assessmentStylesSource, /border-block:\s*0/);
  assert.match(assessmentStylesSource, /border-inline:\s*0\.125rem solid transparent/);
  assert.doesNotMatch(assessmentStylesSource, /\.sidebarRoot/);
});

test("keeps desktop assessment navigation inside the exam viewport", async () => {
  const [sidebarSource, assessmentStylesSource] = await Promise.all([
    readFile(sidebarUrl, "utf8"),
    readFile(stylesUrl, "utf8"),
  ]);

  assert.match(sidebarSource, /useEffect/);
  assert.match(sidebarSource, /questionListRef/);
  assert.match(sidebarSource, /activeLinkRef/);
  assert.match(sidebarSource, /list\.scrollTop/);
  assert.doesNotMatch(sidebarSource, /scrollIntoView/);
  assert.match(sidebarSource, /styles\.assessmentSidebar/);
  assert.match(sidebarSource, /styles\.assessmentSidebarViewport/);
  assert.doesNotMatch(sidebarSource, /lg:max-h-\[calc\(100dvh-8rem\)\]/);
  assert.match(
    assessmentStylesSource,
    /\.assessmentSidebar \{[\s\S]*?height:\s*calc\(\s*100dvh - var\(--assessment-viewport-offset\) - var\(--page-content-bottom\) -\s*var\(--assessment-footer-reveal\)\s*\);[\s\S]*?grid-template-rows:\s*auto minmax\(0, 1fr\);[\s\S]*?overflow:\s*hidden;/,
  );
  assert.match(
    assessmentStylesSource,
    /\.assessmentSidebarViewport \{[\s\S]*?min-height:\s*0;[\s\S]*?overflow:\s*hidden;/,
  );
  assert.match(assessmentStylesSource, /\.questionList \{[\s\S]*?min-height:\s*0;/);
});

test("result breakpoint keeps the sidebar desktop at 64rem and moves the two-column result layout to 80rem", async () => {
  const stylesSource = await readFile(stylesUrl, "utf8");
  const narrowDesktopBlock = stylesSource.match(
    /@media \(min-width: 64rem\) \{([\s\S]*?)\n\}\n\n@media \(min-width: 80rem\)/,
  )?.[1] ?? "";

  assert.match(stylesSource, /@media \(min-width: 64rem\)[\s\S]*\.assessmentSidebar/);
  assert.doesNotMatch(narrowDesktopBlock, /\.assessmentResultRequirements[\s\S]*?white-space:\s*nowrap/);
  assert.doesNotMatch(narrowDesktopBlock, /\.assessmentResultAutomaticScores[\s\S]*?grid-template-columns/);
  assert.match(stylesSource, /container-name:\s*assessment-result-scores/);
  assert.match(
    stylesSource,
    /@container assessment-result-scores \(min-width: 30rem\)[\s\S]*?\.assessmentResultAutomaticScores[\s\S]*?grid-template-columns:[\s\S]*?\.assessmentResultAutomaticScoreItem:not\(:last-child\)[\s\S]*?border-block-end:\s*0;[\s\S]*?border-inline-end/,
  );
  assert.match(
    narrowDesktopBlock,
    /\.assessmentResultHeadline \{[\s\S]*?grid-template-columns:\s*minmax\(0, 1fr\) max-content/,
  );
  assert.match(
    stylesSource,
    /@media \(min-width: 80rem\)[\s\S]*\.assessmentResultMain \{[\s\S]*grid-template-areas:[\s\S]*"hero person"/,
  );
});

test("pending review progress uses the raw manual-question count from the scoring policy", async () => {
  const resultSource = await readFile(resultUrl, "utf8");

  assert.match(
    resultSource,
    /<span className=\{styles\.assessmentResultBrandText\}>[\s\S]*?Преподаватель проверил: \{reviewedManualQuestionCount\} из \{assessment\.scoringPolicy\.manualQuestionCount\}/,
  );
  assert.doesNotMatch(resultSource, /ui-text-brand/);
});

test("sentence-completion questions share one page while retaining separate answer inputs", async () => {
  const [componentSource, sentenceCompletionSource, sidebarSource] = await Promise.all([
    readFile(componentUrl, "utf8"),
    readFile(sentenceCompletionUrl, "utf8"),
    readFile(sidebarUrl, "utf8"),
  ]);

  assert.match(componentSource, /<LessonAssessmentSentenceCompletion/);
  assert.match(componentSource, /sentenceCompletionQuestionsForNavigationItem/);
  assert.match(
    componentSource,
    /const questionsToConfirm = isSentenceCompletionQuestion\(currentQuestion\)[\s\S]*?sentenceCompletionQuestionsForNavigationItem\([\s\S]*?current\.currentQuestionId/,
  );
  assert.match(
    componentSource,
    /const sentenceCompletionQuestions = sentenceCompletionQuestionsForNavigationItem\([\s\S]*?state\.currentQuestionId/,
  );
  assert.match(componentSource, /sentenceCompletionQuestions\.map/);
  assert.match(sentenceCompletionSource, /entries\.map/);
  assert.match(sentenceCompletionSource, /variant="inline"/);
  assert.match(sentenceCompletionSource, /text-sm font-normal leading-6/);
  assert.match(sentenceCompletionSource, /Предложение \{questionIndex \+ 1\}/);
  assert.match(sidebarSource, /lessonAssessmentNavigationItems/);
});

test("diagram keeps the accessible numbered fallback and supports inline leader-line answers", async () => {
  const [diagramSource, stylesSource] = await Promise.all([
    readFile(diagramUrl, "utf8"),
    readFile(stylesUrl, "utf8"),
  ]);
  const inlineBranch = diagramSource.match(/if \(hasInlineLabels\) \{[\s\S]*?\n  \}\n\n  return \(/)?.[0] ?? "";

  assert.match(diagramSource, /<ol/);
  assert.match(diagramSource, /<label/);
  assert.match(diagramSource, /<select/);
  assert.match(diagramSource, /aria-describedby=/);
  assert.match(diagramSource, /data-ui=/);
  assert.match(diagramSource, /Ошибка в этой подписи/);
  assert.match(diagramSource, /focus-visible/);
  assert.doesNotMatch(diagramSource, /dangerouslySetInnerHTML/);
  assert.match(inlineBranch, /<svg/);
  assert.match(inlineBranch, /markerEnd=/);
  assert.match(inlineBranch, /targetMarker === "arrow"/);
  assert.match(inlineBranch, /interactiveDiagramArrow/);
  assert.match(inlineBranch, /interactiveDiagramPoint/);
  assert.match(inlineBranch, /<circle/);
  assert.match(inlineBranch, /interactiveDiagramField/);
  assert.match(inlineBranch, /diagramLeaderPath/);
  assert.doesNotMatch(inlineBranch, /shelfOuterX|includeShelf/);
  assert.match(inlineBranch, /leaderStyle \?\? "elbow"/);
  assert.match(inlineBranch, /renderControl\(part, errorId, "box", true\)/);
  assert.match(diagramSource, /DEVELOPMENT_DIAGRAM_CALIBRATION_ENABLED/);
  assert.match(diagramSource, /searchParams\.get\("calibrate"\) === "1"/);
  assert.match(diagramSource, /enabled: diagramCalibrationEnabled/);
  assert.match(diagramSource, /--diagram-stage-top-clearance/);
  assert.match(diagramSource, /EDGE_LABEL_CLEARANCE/);
  assert.match(diagramSource, /hasInlineNameLabel/);
  assert.match(diagramSource, /inlineDiagramParts/);
  assert.match(diagramSource, /lessonAssessmentDiagramStageScale/);
  assert.match(diagramSource, /DIAGRAM_STAGE_TRIM_BY_QUESTION/);
  assert.match(diagramSource, /DIAGRAM_NAME_PLACEHOLDER = "Что изображено в целом на схеме\?"/);
  assert.match(diagramSource, /DIAGRAM_LATIN_NAME_PLACEHOLDER = "Что изображено в целом на схеме\? \(на латыни\)"/);
  assert.match(diagramSource, /DIAGRAM_LABEL_PLACEHOLDER = "Что изображено тут\?"/);
  assert.match(diagramSource, /DIAGRAM_LATIN_LABEL_PLACEHOLDER = "Термин на латыни"/);
  assert.match(diagramSource, /LATIN_DIAGRAM_NAME_QUESTION_IDS: ReadonlySet<string>/);
  assert.match(diagramSource, /"diagram-04"[\s\S]*"diagram-06"/);
  assert.match(diagramSource, /LATIN_DIAGRAM_QUESTION_IDS: ReadonlySet<string>/);
  assert.match(diagramSource, /"diagram-02"[\s\S]*"diagram-09"/);
  assert.match(diagramSource, /fitContent = false/);
  assert.match(diagramSource, /fitContent=\{fitContent\}/);
  assert.match(diagramSource, /diagramInlineLabelAvailableWidth\(callout\)/);
  assert.match(diagramSource, /diagramInlineLabelCssWidth\([\s\S]*labelWidth,[\s\S]*availableLabelWidth/);
  assert.match(diagramSource, /return `min\(\$\{availableCssWidth\}, \$\{DIAGRAM_INLINE_LABEL_MAX_WIDTH\}, max\(\$\{baseWidth\}, \$\{DIAGRAM_INLINE_LABEL_MIN_WIDTH\}\)\)`/);
  assert.match(diagramSource, /diagramInlineFieldStyle\(\{[\s\S]*labelSide[\s\S]*width: fieldWidth/);
  assert.match(diagramSource, /labelSide === "left"[\s\S]*right: `\$\{\(1 - fieldLeft - labelWidth\) \* 100}%`/);
  assert.match(diagramSource, /labelSide === "top" \|\| labelSide === "bottom"[\s\S]*left: `\$\{\(fieldLeft \+ labelWidth \/ 2\) \* 100}%`/);
  assert.match(diagramSource, /diagramStageCroppedHeight/);
  assert.match(diagramSource, /--diagram-stage-shift-y/);
  assert.match(inlineBranch, /diagramInlineLabelWidth\(callout, diagramStageScale\)/);
  assert.match(diagramSource, /hasInlineLabels \? "max-w-full" : ""/);
  assert.match(diagramSource, /hasInlineLabels \? "box" : undefined/);
  assert.match(diagramSource, /hasInlineLabels \? "box" : undefined,[\s\S]*hasInlineLabels/);
  assert.doesNotMatch(diagramSource, /labelSide !== "left"/);
  assert.match(diagramSource, /part\.id === "name"[\s\S]*LATIN_DIAGRAM_NAME_QUESTION_IDS\.has\(question\.id\)[\s\S]*DIAGRAM_LATIN_NAME_PLACEHOLDER[\s\S]*DIAGRAM_NAME_PLACEHOLDER[\s\S]*LATIN_DIAGRAM_QUESTION_IDS\.has\(question\.id\)[\s\S]*DIAGRAM_LATIN_LABEL_PLACEHOLDER[\s\S]*DIAGRAM_LABEL_PLACEHOLDER/);
  assert.match(diagramSource, /showGuide=\{!hasInlineLabels \|\| \(part\.id === "name" && !hasInlineNameLabel\)\}/);
  assert.doesNotMatch(inlineBranch, /<ol/);
  assert.doesNotMatch(inlineBranch, /styles\.marker/);
  assert.match(stylesSource, /aspect-ratio/);
  assert.match(stylesSource, /\.interactiveDiagramStage:has\(\[role="listbox"\]\),[\s\S]*\.interactiveDiagramStage:has\(\[data-ui\$="\.suggestionWarning"\]\)[\s\S]*z-index: 20/);
  assert.match(stylesSource, /\.interactiveDiagramField:focus-within[\s\S]*z-index: 40/);
  assert.match(stylesSource, /\.interactiveDiagramField:has\(\[data-ui\$="\.suggestionWarning"\]\)[\s\S]*z-index: 60/);
  assert.match(stylesSource, /\.interactiveDiagramField\s*\{[\s\S]*display:\s*grid;[\s\S]*align-items:\s*center;[\s\S]*justify-items:\s*start/);
  assert.match(stylesSource, /\.interactiveDiagramField\[data-label-side="left"\]\s*\{[\s\S]*justify-items:\s*end/);
  assert.match(stylesSource, /\.interactiveDiagramField\[data-label-side="bottom"\],[\s\S]*\.interactiveDiagramField\[data-label-side="top"\]\s*\{[\s\S]*justify-items:\s*center/);
  assert.match(stylesSource, /\.interactiveDiagramField\[data-label-side="left"\]\s*>\s*span\s*>\s*textarea\s*\{[\s\S]*justify-self:\s*stretch;[\s\S]*text-align:\s*right/);
  assert.match(stylesSource, /\.interactiveDiagramField\[data-label-side="left"\]\s*>\s*span\s*>\s*button\s*\{[\s\S]*justify-content:\s*flex-end/);
  assert.match(stylesSource, /\.interactiveDiagramField\[data-label-side="left"\]\s*>\s*span\s*>\s*button\s*>\s*span\s*\{[\s\S]*text-align:\s*right/);
  assert.match(stylesSource, /\.interactiveDiagramField\[data-label-side="bottom"\][\s\S]*transform:\s*translateX\(-50%\)/);
  assert.match(stylesSource, /\.interactiveDiagramField\[data-label-side="top"\][\s\S]*transform:\s*translate\(-50%, -100%\)/);
  assert.match(stylesSource, /position:\s*absolute/);
  assert.match(stylesSource, /\.interactiveDiagramStage/);
  assert.match(stylesSource, /\.interactiveDiagramCrop/);
  assert.match(stylesSource, /aspect-ratio:\s*var\(--diagram-crop-aspect\)/);
  assert.match(stylesSource, /transform:\s*translateY\(var\(--diagram-stage-shift-y\)\)/);
  assert.match(stylesSource, /width:\s*calc\(100% \* var\(--diagram-stage-scale\)\)/);
  assert.match(stylesSource, /margin-inline:\s*auto/);
  assert.match(stylesSource, /\.interactiveDiagramLine/);
  assert.match(stylesSource, /\.interactiveDiagramLineHalo\s*\{[\s\S]*?stroke-width:\s*2px/);
  assert.match(stylesSource, /\.interactiveDiagramLine\s*\{[\s\S]*?stroke-width:\s*1px/);
  assert.match(stylesSource, /\.diagramAnswerTextArea\s*\{[\s\S]*?padding-block:\s*0\.375rem/);
  assert.match(stylesSource, /\.diagramAnswerTextArea:focus::placeholder\s*\{[\s\S]*?opacity:\s*0/);
  assert.match(stylesSource, /@media\s*\(min-width:/);
  assert.match(stylesSource, /prefers-reduced-motion/);
});

test("local diagram calibration stays compact and supports target type, movement, persistence and export", async () => {
  const [diagramSource, panelSource, hookSource, stylesSource] = await Promise.all([
    readFile(diagramUrl, "utf8"),
    readFile(diagramCalibrationPanelUrl, "utf8"),
    readFile(diagramCalibrationHookUrl, "utf8"),
    readFile(stylesUrl, "utf8"),
  ]);

  assert.match(diagramSource, /process\.env\.NODE_ENV !== "production"/);
  assert.match(diagramSource, /LessonAssessmentDiagramCalibrationPanel/);
  assert.match(diagramSource, /onFocusCapture=\{\(\) => calibration\.selectPart\(part\.id\)\}/);
  assert.match(diagramSource, /diagramCalibrationTargetSelection/);
  assert.match(panelSource, /Калибровка указателей/);
  assert.match(panelSource, /Подпись \$\{partNumber\}/);
  assert.match(panelSource, /answerTerms\[callout\.partId\]/);
  assert.match(panelSource, /Точка/);
  assert.match(panelSource, /Стрелка/);
  assert.match(panelSource, /\[1, 5, 20\]/);
  assert.match(panelSource, /Сдвинуть влево/);
  assert.match(panelSource, /Сдвинуть вправо/);
  assert.match(panelSource, /Сдвинуть вверх/);
  assert.match(panelSource, /Сдвинуть вниз/);
  assert.match(panelSource, /Зафиксировать/);
  assert.match(panelSource, /Выровнять поле по цели/);
  assert.match(panelSource, /Экспортировать настройки/);
  assert.match(panelSource, /Сбросить схему/);
  assert.match(panelSource, /Настройки калибровки/);
  assert.match(hookSource, /window\.localStorage\.getItem/);
  assert.match(hookSource, /window\.localStorage\.setItem/);
  assert.match(hookSource, /window\.localStorage\.removeItem/);
  assert.match(hookSource, /moveDiagramCalibrationTarget/);
  assert.match(hookSource, /alignDiagramCalibrationLabelToTarget/);
  assert.match(hookSource, /setDiagramCalibrationTargetMarker/);
  assert.match(stylesSource, /\.diagramCalibrationPanel/);
  assert.match(stylesSource, /\.diagramCalibrationControls/);
  assert.doesNotMatch(stylesSource, /\.diagramCalibrationPanel[\s\S]{0,200}position:\s*fixed/);
});

test("protected diagram reveal waits for decode and commits image with overlays atomically", async () => {
  const [componentSource, diagramSource] = await Promise.all([
    readFile(componentUrl, "utf8"),
    readFile(diagramUrl, "utf8"),
  ]);
  const loading = controllerModule.createLessonAssessmentDiagramState("diagram-01");
  const ready = controllerModule.reduceLessonAssessmentDiagramState(loading, {
    type: "loaded",
    questionId: "diagram-01",
    revision: 0,
  });
  const unavailable = new Set(["diagram-01"]);
  const recordAvailability = (questionId: string, available: boolean): void => {
    if (available) {
      unavailable.delete(questionId);
    } else {
      unavailable.add(questionId);
    }
  };

  recordAvailability("diagram-01", ready.status === "ready");

  assert.equal(ready.status, "ready");
  assert.equal(unavailable.has("diagram-01"), false);
  assert.match(diagramSource, /registry\.preloadAround\(asset/);
  assert.match(diagramSource, /registry\.load\(asset\)/);
  assert.match(diagramSource, /useSyncExternalStore/);
  assert.match(diagramSource, /loadedImage\.generation === registrySnapshot/);
  assert.match(diagramSource, /effectiveImageStatus === "ready" && objectUrl !== null/);
  assert.match(diagramSource, /image\.decode\(\)/);
  assert.match(diagramSource, /requestAnimationFrame\(\(\) =>/);
  assert.match(diagramSource, /atomicVisibilityClass/);
  assert.match(diagramSource, /renderImagePlaceholder\(\)/);
  assert.match(
    componentSource,
    /initialData\.assessment\.questions[\s\S]*question\.type === "diagram_labels"[\s\S]*question\.type === "diagram_text"[\s\S]*\.map\(\(question\) => question\.id\)/,
  );
  assert.match(
    componentSource,
    /targetQuestion\?\.type === "diagram_labels"[\s\S]*targetQuestion\?\.type === "diagram_text"[\s\S]*new Set\(current\)\.add\(questionId\)/,
  );
  assert.match(componentSource, /key=\{currentQuestion\.id\}/);
  assert.ok(
    diagramSource.indexOf("const request = imageState.revision") <
      diagramSource.indexOf("registry.preloadAround(asset"),
  );
});

test("protected image registry is active for editing and submitted-answer review, then disposed outside review", async () => {
  const componentSource = await readFile(componentUrl, "utf8");

  assert.match(
    componentSource,
    /assessmentAttemptId === null \|\| \(assessmentAttemptStatus !== "in_progress" && !reviewMode\)/,
  );
  assert.match(componentSource, /onAccessDenied: handleProtectedAssetAccessDenied/);
  assert.match(componentSource, /protectedDiagramQuestionIdsFingerprint/);
  assert.match(
    componentSource,
    /useEffect\(\(\) => \(\) => protectedImageRegistry\?\.dispose\(\), \[protectedImageRegistry\]\)/,
  );
  assert.match(componentSource, /assessmentAttemptStatus !== null && assessmentAttemptStatus !== "in_progress"/);
  assert.match(componentSource, /submittedAttempt && searchParams\.get\("review"\) === "answers"/);
});

test("sidebar query navigation changes the current question even when no answer is pending", async () => {
  const componentSource = await readFile(componentUrl, "utf8");
  const queryNavigationEffectStart = componentSource.indexOf("useEffect(() => {\n    if (navigationQuestionId === null)");
  const queryNavigationEffectEnd =
    queryNavigationEffectStart === -1
      ? -1
      : componentSource.indexOf("  const handleRetake", queryNavigationEffectStart);
  const queryNavigationEffect =
    queryNavigationEffectStart === -1 || queryNavigationEffectEnd === -1
      ? ""
      : componentSource.slice(queryNavigationEffectStart, queryNavigationEffectEnd);

  assert.match(
    componentSource,
    /const navigationQuestionId = searchParams\.get\("question"\) \?\? requestedQuestionId/,
  );
  assert.match(queryNavigationEffect, /questionIdsForNavigationItem/);
  assert.match(queryNavigationEffect, /targetQuestionIds\.length === 0/);
  assert.match(queryNavigationEffect, /navigationContext\.router\.replace\(/);
  assert.match(queryNavigationEffect, /navigationContext\.navigateWithoutSaving\(navigationQuestionId\)/);
  assert.doesNotMatch(queryNavigationEffect, /displayQuestion\(navigationQuestionId\)/);
  assert.doesNotMatch(queryNavigationEffect, /performSave|saveCoordinator/);
});

test("explicit save requests lock inputs and discard navigation until the PATCH settles", async () => {
  const componentSource = await readFile(componentUrl, "utf8");
  const navigateWithoutSavingBlock = componentSource.match(
    /const navigateWithoutSaving = useCallback\(\(questionId: string\): boolean => \{[\s\S]*?\n  \}, \[[\s\S]*?\]\);/,
  )?.[0] ?? "";
  const handleSubmitBlock = componentSource.match(
    /const handleSubmit = useCallback\(async \(\): Promise<void> => \{[\s\S]*?\n  \}, \[[\s\S]*?\]\);/,
  )?.[0] ?? "";

  assert.match(componentSource, /lessonAssessmentInteractionLocked/);
  assert.match(componentSource, /const explicitSaveInFlight = saveRequestInFlight \|\| saveRequestGate\.isRunning\(\)/);
  assert.match(componentSource, /const interactionLocked = lessonAssessmentInteractionLocked\(/);
  assert.match(componentSource, /<LessonAssessmentSentenceCompletion[\s\S]*disabled=\{reviewMode \|\| interactionLocked\}/);
  assert.match(componentSource, /<LessonAssessmentQuestion[\s\S]*disabled=\{interactionLocked\}/);
  assert.match(navigateWithoutSavingBlock, /saveRequestGate\.isRunning\(\)\s*\|\|\s*saveRequestInFlight/);
  assert.match(handleSubmitBlock, /navigationOperationRef\.current \|\| saveRequestGate\.isRunning\(\) \|\| saveRequestInFlight/);
});

test("unmounted diagrams do not block final submission after reload", async () => {
  const source = await readFile(componentUrl, "utf8");
  const submitBody = source.slice(
    source.indexOf("const handleSubmit"),
    source.indexOf("const handleSave"),
  );

  assert.doesNotMatch(submitBody, /unavailableDiagramQuestionIds|hasUnavailableDiagram/);
  assert.match(source, /const currentNavigationHasUnavailableDiagram = currentNavigationQuestionIds\.some/);
  assert.match(source, /state\.submissionStatus === "submitting" \|\|\s*currentNavigationHasUnavailableDiagram/);
});

test("retrySubmitButton uses direct handleSubmit retry action", async () => {
  const componentSource = await readFile(componentUrl, "utf8");

  assert.match(componentSource, /data-ui="lk\.learning\.assessment\.retrySubmitButton"/);
  assert.match(componentSource, /onClick=\{\(\) => void handleSubmit\(\)\}/);
  assert.match(componentSource, />\s*Повторить отправку\s*<\/Button>/);
});

test("submission error recovers from conflict or unavailable without an automatic second POST", async () => {
  const componentSource = await readFile(componentUrl, "utf8");
  const handleSubmitBlock = componentSource.match(
    /const handleSubmit = useCallback\(async \(\): Promise<void> => \{[\s\S]*?\n  \}, \[[\s\S]*?\]\);/,
  )?.[0] ?? "";

  assert.match(
    handleSubmitBlock,
    /if \(result\.status === "conflict" \|\| result\.status === "unavailable"\) \{[\s\S]*reloadLessonAssessment\(apiBaseUrl, stableRoute, controller\.signal\)/,
  );
  assert.equal((handleSubmitBlock.match(/router\.replace\(pathname\);/g) ?? []).length, 2);
  assert.doesNotMatch(handleSubmitBlock, /router\.replace\(pathname, \{ scroll: false \}\)/);
  assert.match(handleSubmitBlock, /const terminalAttempt = reloaded\.data\.attempt !== null &&[\s\S]*status !== "in_progress"/);
  assert.match(handleSubmitBlock, /const synchronized = applyConflictReload\(latest, reloaded\.data, submissionSnapshot\)/);
  assert.match(handleSubmitBlock, /return terminalAttempt \? synchronized : applySubmissionError\(synchronized\);/);
  assert.match(handleSubmitBlock, /if \(terminalAttempt\) \{[\s\S]*router\.replace\(pathname\);[\s\S]*router\.refresh\(\);/);
});

test("accepted exam transitions use App Router's scroll reset without changing manual or failed paths", async () => {
  const [componentSource, sidebarSource] = await Promise.all([
    readFile(componentUrl, "utf8"),
    readFile(sidebarUrl, "utf8"),
  ]);
  const handleSubmitBlock = componentSource.match(
    /const handleSubmit = useCallback\(async \(\): Promise<void> => \{[\s\S]*?\n  \}, \[[\s\S]*?\]\);/,
  )?.[0] ?? "";
  const commitAnswerBlock = componentSource.match(
    /const commitAnswer = useCallback\(async \(\): Promise<void> => \{[\s\S]*?\n  \}, \[[\s\S]*?\]\);/,
  )?.[0] ?? "";

  assert.match(
    commitAnswerBlock,
    /if \(!\(await performSave\(currentQuestionIds, \{ force: true, lock: true \}\)\) \|\| !mountedRef\.current\) \{\s*return;/,
  );
  assert.match(
    commitAnswerBlock,
    /if \(action\.type === "navigate"\) \{[\s\S]*router\.replace\(`\$\{pathname\}\?\$\{nextSearchParams\.toString\(\)\}`\);/,
  );
  assert.doesNotMatch(commitAnswerBlock, /scroll:\s*false/);
  assert.match(handleSubmitBlock, /if \(result\.status === "ready"\) \{[\s\S]*router\.replace\(pathname\);/);
  assert.match(handleSubmitBlock, /if \(terminalAttempt\) \{[\s\S]*router\.replace\(pathname\);/);
  assert.doesNotMatch(handleSubmitBlock, /scroll:\s*false/);
  assert.match(sidebarSource, /router\.replace\([\s\S]*\{ scroll: false \},/);
  assert.equal((sidebarSource.match(/scroll=\{false\}/g) ?? []).length, 2);
  assert.match(componentSource, /if \(currentQuestionIds\.length === 0\) \{\s*return;\s*\}[\s\S]*performSave\(currentQuestionIds, \{ force: false, lock: false \}\);/);
});

test("latin diagram fields use concise copy while remaining bounded by their actual side space", async () => {
  const diagramSource = await readFile(diagramUrl, "utf8");
  const widthFunction = diagramSource.match(
    /function diagramInlineLabelCssWidth\([\s\S]*?\n\}/,
  )?.[0] ?? "";

  assert.match(diagramSource, /DIAGRAM_LATIN_LABEL_PLACEHOLDER = "Термин на латыни"/);
  assert.doesNotMatch(diagramSource, /DIAGRAM_LATIN_INLINE_LABEL_MIN_WIDTH/);
  assert.match(widthFunction, /const availableCssWidth = `\$\{availableWidth \* 100\}%`/);
  assert.match(
    widthFunction,
    /return `min\(\$\{availableCssWidth\}, \$\{DIAGRAM_INLINE_LABEL_MAX_WIDTH\}, max\(\$\{baseWidth\}, \$\{DIAGRAM_INLINE_LABEL_MIN_WIDTH\}\)\)`/,
  );
});

test("orchestrator declares explicit save and answer actions without implicit persistence", async () => {
  const [componentSource, resultSource] = await Promise.all([
    readFile(componentUrl, "utf8"),
    readFile(resultUrl, "utf8"),
  ]);
  const routerRefreshMatches = componentSource.match(/router\.refresh\(\)/g) ?? [];
  const controllerSource = await readFile(new URL("./lessonAssessmentController.ts", import.meta.url), "utf8");
  const handleSaveBlock = componentSource.match(
    /const handleSave = useCallback\(async \(\): Promise<void> => \{[\s\S]*?\n  \}, \[[\s\S]*?\]\);/,
  )?.[0] ?? "";
  const handleAnswerBlock = componentSource.match(
    /const handleAnswer = useCallback\(async \(\): Promise<void> => \{[\s\S]*?\n  \}, \[[\s\S]*?\]\);/,
  )?.[0] ?? "";
  const commitAnswerBlock = componentSource.match(
    /const commitAnswer = useCallback\(async \(\): Promise<void> => \{[\s\S]*?\n  \}, \[[\s\S]*?\]\);/,
  )?.[0] ?? "";

  assert.match(componentSource, /data-ui="lk\.learning\.assessment\.saveButton"/);
  assert.match(componentSource, />\s*Сохранить\s*</);
  assert.match(componentSource, /data-ui="lk\.learning\.assessment\.answerButton"/);
  assert.match(componentSource, /const handleSave = useCallback/);
  assert.match(componentSource, /const handleAnswer = useCallback/);
  assert.match(componentSource, /const commitAnswer = useCallback/);
  assert.match(componentSource, /saveRequestInFlight/);
  assert.match(componentSource, /createLessonAssessmentSingleFlightRequestGate/);
  assert.match(componentSource, /resolveLessonAssessmentAnswerAction/);
  assert.match(componentSource, /createLessonAssessmentSaveSnapshot\(current,\s*questionIds\)/);
  assert.match(componentSource, /lockQuestionIds: options\.lock \? questionIds : \[\]/);
  assert.match(componentSource, /discardLessonAssessmentChanges/);
  assert.match(componentSource, /updateState/);
  assert.match(componentSource, /stateRef\.current/);
  assert.match(componentSource, /aria-live="polite"/);
  assert.match(componentSource, /data-ui=/);
  assert.doesNotMatch(componentSource, /data-ui="lk\.learning\.assessment\.saveStatus"/);
  assert.doesNotMatch(componentSource, /data-ui="lk\.learning\.assessment\.saveRetryButton"/);
  assert.match(controllerSource, /Ответить и отправить/);
  assert.doesNotMatch(componentSource, /ANSWER_CONFIRMATION_TITLE/);
  assert.doesNotMatch(componentSource, /lesson-assessment-answer-confirmation-title/);
  assert.match(componentSource, /ANSWER_CONFIRMATION_PRIMARY_COPY/);
  assert.match(componentSource, /ANSWER_CONFIRMATION_SECONDARY_COPY/);
  assert.match(componentSource, /ANSWER_CONFIRMATION_STORAGE_PREFIX/);
  assert.match(componentSource, /ANSWER_CONFIRMATION_STORAGE_VALUE = "shown"/);
  assert.match(componentSource, /function answerConfirmationStorageKey\(route: LessonAssessmentRoute\): string/);
  assert.match(componentSource, /window\.sessionStorage\.getItem\(storageKey\) === ANSWER_CONFIRMATION_STORAGE_VALUE/);
  assert.match(componentSource, /window\.sessionStorage\.setItem\(storageKey, ANSWER_CONFIRMATION_STORAGE_VALUE\)/);
  assert.match(componentSource, /answerConfirmationAcknowledged/);
  assert.match(componentSource, /answerConfirmationVisible/);
  assert.match(componentSource, /answerButtonWrapperRef/);
  assert.match(componentSource, /data-ui="lk\.learning\.assessment\.answerConfirmation"/);
  assert.match(componentSource, /role="status"/);
  assert.match(componentSource, /absolute left-0 top-full/);
  assert.match(componentSource, /before:-top-1\.5/);
  assert.match(componentSource, /aria-expanded=\{answerConfirmationVisible \? true : undefined\}/);
  assert.doesNotMatch(componentSource, /data-ui="lk\.learning\.assessment\.answerConfirmation\.confirm"/);
  assert.doesNotMatch(componentSource, /role="dialog"/);
  assert.doesNotMatch(componentSource, /aria-modal="true"/);
  assert.doesNotMatch(componentSource, /fixed inset-0/);
  assert.doesNotMatch(componentSource, /bg-black\/40/);
  assert.doesNotMatch(componentSource, /answerConfirmationCancelButtonRef/);
  assert.match(componentSource, /bg-white/);
  assert.match(componentSource, /text-sm leading-6 ui-text-tertiary/);
  assert.match(componentSource, /text-xs leading-5 ui-text-muted-ink/);
  assert.doesNotMatch(componentSource, /border-amber-200/);
  assert.doesNotMatch(componentSource, /border-red-200/);
  assert.doesNotMatch(componentSource, /bg-red-50\/80/);
  assert.match(componentSource, /нажмите «Ответить» ещё раз/);
  assert.match(componentSource, /Это действие отменить невозможно/);
  assert.match(componentSource, /нажмите «Сохранить»/);
  assert.match(componentSource, /ответы зафиксированы не будут/);
  assert.match(componentSource, /const currentNavigationLocked = currentNavigationQuestionIds\.length > 0/);
  assert.match(componentSource, /currentNavigationLocked/);
  assert.match(handleSaveBlock, /setAnswerConfirmationVisible\(false\)/);
  assert.match(handleSaveBlock, /performSave\(currentQuestionIds,\s*\{\s*force:\s*false,\s*lock:\s*false\s*\}\)/);
  assert.doesNotMatch(handleSaveBlock, /navigateWithoutSaving|handleSubmit/);
  assert.match(handleAnswerBlock, /if \(!answerConfirmationAcknowledged\)/);
  assert.match(handleAnswerBlock, /writeAnswerConfirmationAcknowledged\(confirmationStorageKey\)/);
  assert.match(handleAnswerBlock, /setAnswerConfirmationAcknowledged\(true\)/);
  assert.match(handleAnswerBlock, /setAnswerConfirmationVisible\(true\)/);
  assert.match(handleAnswerBlock, /setAnswerConfirmationVisible\(false\)/);
  assert.match(handleAnswerBlock, /await commitAnswer\(\)/);
  assert.doesNotMatch(handleAnswerBlock, /if \(answerConfirmationVisible\)/);
  assert.match(commitAnswerBlock, /performSave\(currentQuestionIds,\s*\{\s*force:\s*true,\s*lock:\s*true\s*\}\)/);
  assert.match(commitAnswerBlock, /resolveLessonAssessmentAnswerAction/);
  assert.match(commitAnswerBlock, /action\.type === "navigate"/);
  assert.match(commitAnswerBlock, /router\.replace\(`\$\{pathname\}\?\$\{nextSearchParams\.toString\(\)\}`\);/);
  assert.doesNotMatch(commitAnswerBlock, /router\.replace\(`\$\{pathname\}\?\$\{nextSearchParams\.toString\(\)\}`, \{ scroll: false \}\)/);
  assert.match(commitAnswerBlock, /await handleSubmit\(\)/);
  assert.match(componentSource, /if \(saveResult\.status === "conflict"\)[\s\S]*return false;/);
  assert.doesNotMatch(componentSource, /data-ui="lk\.learning\.assessment\.checkActions"/);
  assert.doesNotMatch(componentSource, /lesson-assessment-check-hint/);
  assert.doesNotMatch(
    componentSource,
    /AUTOSAVE_DELAY_MS|createLessonAssessmentSaveCoordinator|saveCoordinator\.schedule|beforeunload|localStorage/,
  );
  assert.match(componentSource, /saveStatus === "error"/);
  assert.match(componentSource, /role="alert"/);
  assert.match(componentSource, /data-ui="lk\.learning\.assessment\.saveError"/);
  assert.match(componentSource, /Не удалось сохранить ответы\./);
  assert.match(componentSource, /if \(mountedRef\.current\)/);
  assert.doesNotMatch(componentSource, /document\.addEventListener\(\s*["']click["']/);
  assert.doesNotMatch(componentSource, /window\.location\.assign/);
  assert.match(componentSource, /LessonAssessmentResult/);
  assert.match(
    componentSource,
    /state\.attempt\.status === "passed"[\s\S]*?<LessonAssessmentReviewResult[\s\S]*?finalScore=\{state\.attempt\.score\}/,
  );
  assert.match(resultSource, /export function LessonAssessmentReviewResult/);
  assert.match(resultSource, /reviewComplete \? "reviewed" : "pending"/);
  assert.match(resultSource, /проверены"} преподавателем/);
  assert.match(resultSource, /Экзамен сдан/);
  assert.match(resultSource, /Все задания выполнены верно — отличный результат/);
  assert.match(resultSource, /Общий результат:/);
  assert.doesNotMatch(resultSource, /Что помешало сдать экзамен/);
  assert.match(resultSource, /ui-text-danger/);
  assert.match(resultSource, /ниже порога/);
  assert.match(resultSource, /score\.components/);
  assert.doesNotMatch(componentSource, /state\.attempt\.correctCount\}\s*из/);
  assert.match(componentSource, /Сдать экзамен ещё раз/);
  assert.match(componentSource, /Подготавливаем экзамен/);
  assert.match(componentSource, /Доступ к экзамену закрыт/);
  assert.match(componentSource, /Не удалось начать экзамен/);
  assert.match(componentSource, /Не удалось отправить экзамен/);
  assert.match(componentSource, /Не удалось отправить экзамен\. Ответы сохранены\. Повторите отправку\./);
  assert.match(componentSource, /data-ui="lk\.learning\.assessment\.submissionError"/);
  assert.match(componentSource, /retakeLessonAssessment/);
  assert.match(componentSource, /data-ui="lk\.learning\.assessment\.retakeButton"/);
  assert.doesNotMatch(componentSource, /Вернуться к уроку/);
  assert.doesNotMatch(componentSource, /data-ui="lk\.learning\.assessment\.lessonLink"/);
  assert.match(componentSource, /process\.env\.NODE_ENV === "development"/);
  assert.match(componentSource, /resetLessonAssessmentDevelopmentState/);
  assert.match(componentSource, /data-ui="lk\.learning\.assessment\.developmentResetButton"/);
  assert.match(componentSource, /Сбросить все ответы/);
  assert.match(componentSource, /window\.location\.reload\(\)/);
  assert.match(componentSource, /Не удалось начать повторное прохождение/);
  assert.match(componentSource, /AbortController/);
  assert.match(componentSource, /router\.refresh\(\)/);
  assert.ok(routerRefreshMatches.length >= 2);
  assert.doesNotMatch(componentSource, /Посмотреть ответы/);
  assert.doesNotMatch(`${componentSource}\n${resultSource}`, /dangerouslySetInnerHTML/);
});

test("terminal review is read-only and marks only the learner's own answers", async () => {
  const [
    componentSource,
    questionSource,
    sentenceCompletionSource,
    sidebarSource,
    textInputSource,
    diagramSource,
    stylesSource,
  ] = await Promise.all([
    readFile(componentUrl, "utf8"),
    readFile(questionUrl, "utf8"),
    readFile(sentenceCompletionUrl, "utf8"),
    readFile(sidebarUrl, "utf8"),
    readFile(textInputUrl, "utf8"),
    readFile(diagramUrl, "utf8"),
    readFile(stylesUrl, "utf8"),
  ]);

  assert.match(componentSource, /const submittedAttempt = assessmentAttemptStatus !== null && assessmentAttemptStatus !== "in_progress"/);
  assert.match(componentSource, /submittedAttempt && searchParams\.get\("review"\) === "answers"/);
  assert.match(componentSource, /if \(state\.attempt\.status === "pending_review" && !reviewMode\)/);
  assert.match(componentSource, /if \(submittedAttempt && !reviewMode && "score" in state\.attempt\)/);
  assert.match(componentSource, /!reviewMode \? <div className="flex flex-wrap justify-start gap-3">/);
  assert.match(componentSource, /!reviewMode && assessmentComplete && currentNavigationHasUnavailableDiagram/);
  assert.doesNotMatch(componentSource, /reviewAnswersButton|backToResultButton|reviewRetakeButton/);
  assert.match(questionSource, /const controlsDisabled = disabled \|\| locked \|\| reviewMode/);
  assert.match(componentSource, /activeAttempt\.manualResults\.find\(\(result\) => result\.questionId === currentQuestion\.id\)/);
  assert.match(componentSource, /manualReviewResult=\{reviewMode \? manualReviewResult : undefined\}/);
  assert.match(questionSource, /Результат проверки:/);
  assert.match(questionSource, /manualReviewResult\.scoreBps \/ 100/);
  assert.match(questionSource, /Комментарий преподавателя/);
  assert.match(questionSource, /manualReviewComment \?/);
  assert.match(questionSource, /whitespace-pre-wrap/);
  assert.match(questionSource, /const optionReviewState = selected \? wholeQuestionReviewState : undefined/);
  assert.match(questionSource, /reviewMode && selections\[part\.id\] !== undefined/);
  assert.match(questionSource, /reviewMode && texts\[part\.id\]\?\.trim\(\) !== ""/);
  assert.match(sentenceCompletionSource, /reviewMode && texts\[segment\.part\.id\]\?\.trim\(\) !== ""/);
  assert.match(sidebarSource, /results\.every\(\(result\) => result\.correct\) \? "correct" : "incorrect"/);
  assert.match(sidebarSource, /questionIds\.every\(\(questionId\) => reviewedManualQuestionIdSet\.has\(questionId\)\)/);
  assert.match(sidebarSource, /nextSearchParams\.set\("review", "answers"\)/);
  assert.match(sidebarSource, /nextSearchParams\.set\("question", questionId\)/);
  assert.match(sidebarSource, /nextSearchParams\.delete\("review"\)/);
  assert.match(sidebarSource, /nextSearchParams\.delete\("question"\)/);
  assert.match(sidebarSource, /Результаты экзамена/);
  assert.match(sidebarSource, /reviewMode = false/);
  assert.match(sidebarSource, /submittedAttempt = false/);
  assert.match(sidebarSource, /className=\{styles\.resultNavigationItem\}/);
  assert.doesNotMatch(sidebarSource, /navigationItems\.length \+ 1/);
  assert.match(sidebarSource, /activeLink\.parentElement\?\.nextElementSibling/);
  assert.match(sidebarSource, /activeContextBottom - visibleBottom/);
  assert.match(sidebarSource, /data-review-state=\{itemReviewState\}/);
  assert.match(sidebarSource, /<span className="sr-only">/);
  assert.doesNotMatch(sidebarSource, /itemReviewState === "correct" \? "✓" : "×"/);
  assert.match(sidebarSource, /!text-zinc-700 data-\[state=active\]:!text-zinc-950/);
  assert.match(sidebarSource, /className=\{styles\.questionLinkContent\}/);
  assert.match(stylesSource, /\.questionList \.questionLink \{\s*align-items: center;/);
  assert.match(stylesSource, /\.questionLinkContent \{[\s\S]*align-items: flex-start;/);
  assert.match(questionSource, /className="sr-only"[\s\S]*\.reviewStatus/);
  assert.doesNotMatch(questionSource, /reviewStatusCorrect/);
  assert.match(stylesSource, /\.reviewAnswerCorrect/);
  assert.match(stylesSource, /\.reviewAnswerIncorrect/);
  assert.match(stylesSource, /data-review-state="reviewed"/);
  assert.match(stylesSource, /\.assessmentResultScoreItem \{[\s\S]*grid-template-columns: max-content max-content;/);
  assert.match(stylesSource, /\.assessmentResultScoreItem > dt,[\s\S]*white-space: nowrap;/);
  assert.match(stylesSource, /--assessment-result-divider-gap: 1rem/);
  assert.match(stylesSource, /inset-inline-end: var\(--assessment-result-divider-gap\)/);
  assert.match(stylesSource, /inset-inline-start: var\(--assessment-result-divider-gap\)/);
  assert.match(stylesSource, /grid-template-columns: 10rem max-content/);
  assert.match(stylesSource, /grid-template-columns: 11\.75rem max-content/);
  assert.match(stylesSource, /\.assessmentResultMain \{\s*align-content: start;/);
  assert.match(questionSource, /review-status/);
  assert.match(componentSource, /manualReviewPending=\{reviewMode && state\.attempt\.status === "pending_review" && currentQuestion\.gradingMode === "manual" && !state\.attempt\.reviewedManualQuestionIds\.includes\(currentQuestion\.id\)\}/);
  assert.match(componentSource, /manualReviewReviewed=\{reviewMode && state\.attempt\.status === "pending_review" && currentQuestion\.gradingMode === "manual" && state\.attempt\.reviewedManualQuestionIds\.includes\(currentQuestion\.id\)\}/);
  assert.match(questionSource, /manualReviewPending \? \(/);
  assert.match(questionSource, /manualReviewReviewed \? \(/);
  assert.match(questionSource, /Проверено преподавателем/);
  assert.match(questionSource, /data-state=\{wholeQuestionReviewState \?\? \(manualReviewResult \|\| manualReviewReviewed \? "reviewed" : manualReviewPending \? "pending-review"/);
  assert.match(questionSource, /Верный ответ/);
  assert.match(textInputSource, /accessibleDescription/);
  assert.match(textInputSource, /Верный ответ/);
  assert.match(textInputSource, /Неверный ответ/);
  assert.match(diagramSource, /aria-describedby=\{describedBy\}/);
  assert.match(diagramSource, /Верный ответ/);
});

test("automatic failure uses the shared result layout and the same retake feedback contract", async () => {
  const [componentSource, resultSource] = await Promise.all([
    readFile(componentUrl, "utf8"),
    readFile(resultUrl, "utf8"),
  ]);
  const automaticFailureBranch = componentSource.slice(
    componentSource.indexOf('if (state.attempt.status === "failed" && state.attempt.failureReason === "automatic_threshold_not_met" && !reviewMode)'),
    componentSource.indexOf('if (submittedAttempt && !reviewMode && "score" in state.attempt)'),
  );

  assert.match(resultSource, /export function LessonAssessmentAutomaticFailureResult/);
  assert.match(automaticFailureBranch, /<LessonAssessmentAutomaticFailureResult/);
  assert.match(componentSource, /data-ui="lk\.learning\.assessment\.retakeError"/);
  assert.doesNotMatch(automaticFailureBranch, /<section className="grid gap-4"/);
});
