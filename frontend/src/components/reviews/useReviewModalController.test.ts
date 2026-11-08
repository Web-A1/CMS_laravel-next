import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

import type { PublicReview, ReviewNavigation, ReviewResult, ReviewsResult } from "../../lib/reviewsApi";

type Controller = ReturnType<typeof import("./useReviewModalController").useReviewModalController>;

test("search survives modal open, next, subject lookup and close", async () => {
  const ui = await harness({ initialSearch: "курс", url: "https://school.test/reviews?q=%D0%BA%D1%83%D1%80%D1%81" });
  ui.render().openReview(review(1), ui.trigger);
  assert.equal(ui.requestFilters[0].search, "курс");
  ui.requests[0].resolve({ data: review(1), navigation: { previousId: null, nextId: 2, position: 1, total: 2, filters: { subject: null, course: null } } });
  await Promise.resolve();
  ui.render().goNext();
  assert.equal(ui.requestFilters[1].search, "курс");
  assert.equal(ui.requestFilters[1].id, 2);
  const lookup = ui.render().selectModalSubject("histology");
  assert.equal(ui.listFilters[0].search, "курс");
  ui.lists[0].resolve({ data: [], meta: { limit: 1, page: 1, perPage: 1, total: 0, lastPage: 1, sort: "latest", totalPublished: 2, filters: { subject: "histology", course: null }, facets: { subjects: [], courses: [] } } });
  await lookup;
  assert.equal(ui.render().reviewError, "По вашему запросу в этом предмете отзывов не найдено. Выберите другой предмет.");
  ui.render().closeSelectedReview();
  const url = new URL(ui.urls.at(-1)!, "https://school.test");
  assert.equal(url.searchParams.get("q"), "курс");
  assert.equal(url.searchParams.has("review"), false);
});

test("history crossing into another search refreshes the collection instead of loading stale navigation", async () => {
  const ui = await harness({ initialSearch: "курс", url: "https://school.test/reviews?q=%D0%BA%D1%83%D1%80%D1%81" });
  ui.render();
  ui.pop("/reviews?q=819&review=819");
  assert.equal(ui.requests.length, 0);
  assert.equal(ui.historyClosed(), 1);
});

test("modal URLs preserve page size and reset page only when subject changes", async () => {
  const ui = await harness({ initialReview: review(1), initialPerPage: 10, url: "https://school.test/reviews?page=2&per_page=10&review=1" });
  ui.render().closeSelectedReview();
  assert.equal(ui.urls.at(-1), "/reviews?page=2&per_page=10");
  ui.render().openReview(review(1), ui.trigger);
  assert.equal(new URL(ui.urls.at(-1)!, "https://school.test").searchParams.get("page"), "2");
  const change = ui.render().selectModalSubject("anatomy");
  ui.requests.at(-1)!.resolve({ data: review(1), navigation: { previousId: null, nextId: null, position: 1, total: 1, filters: { subject: "anatomy", course: null } } });
  await change;
  const url = new URL(ui.urls.at(-1)!, "https://school.test");
  assert.equal(url.searchParams.get("per_page"), "10");
  assert.equal(url.searchParams.get("subject"), "anatomy");
  assert.equal(url.searchParams.has("page"), false);
});

test("history refreshes across page sizes but keeps modal history across page numbers", async () => {
  const ui = await harness({ initialPerPage: 10, url: "https://school.test/reviews?per_page=10&review=1" });
  ui.render();
  ui.pop("/reviews?page=2&per_page=10&review=2");
  assert.equal(ui.requests.length, 1);
  assert.equal(ui.historyClosed(), 0);
  ui.pop("/reviews?per_page=50&review=2");
  assert.equal(ui.requests.length, 1);
  assert.equal(ui.historyClosed(), 1);
});

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}

function review(id: number, slug = "anatomy"): PublicReview {
  return {
    id, displayName: "Студент", authorPhotoUrl: null, text: `Отзыв ${id}`,
    reviewWrittenAt: null, publishedAt: null, source: "admin", importOrigin: "admin",
    course: null, subject: { id, slug, title: slug }, sourceLinks: [], media: [],
  };
}

async function harness(options: { initialReview?: PublicReview; initialReviewNavigation?: ReviewNavigation; initialSubject?: string; initialSearch?: string; initialPerPage?: number; url?: string } = {}) {
  const states: unknown[] = [];
  const refs: Array<{ current: unknown }> = [];
  let stateCursor = 0;
  let refCursor = 0;
  const effects: Array<{ deps: unknown[]; cleanup?: () => void }> = [];
  let effectCursor = 0;
  const listeners = new Map<string, () => void>();
  const requests: Array<ReturnType<typeof deferred<ReviewResult>>> = [];
  const lists: Array<ReturnType<typeof deferred<ReviewsResult>>> = [];
  const urls: string[] = [];
  const requestFilters: Array<{ id: number; subject?: string; search?: string }> = [];
  const listFilters: Array<{ subject?: string; search?: string }> = [];
  let focusRestored = 0;
  let historyClosed = 0;
  const source = await readFile(new URL("./useReviewModalController.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const sandboxModule = { exports: {} as { useReviewModalController: (options: unknown) => Controller } };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "react") return {
      useState: (initial: unknown) => {
        const slot = stateCursor++;
        if (!(slot in states)) states[slot] = initial;
        return [states[slot], (next: unknown) => { states[slot] = next; }];
      },
      useRef: (initial: unknown) => refs[refCursor++] ?? (refs[refCursor - 1] = { current: initial }),
      useCallback: (callback: unknown) => callback,
      useEffect: (effect: () => (() => void), deps: unknown[]) => {
        const slot = effectCursor++;
        if (effects[slot] && deps.every((dep, i) => dep === effects[slot].deps[i])) return;
        effects[slot]?.cleanup?.();
        effects[slot] = { deps, cleanup: effect() };
      },
    };
    if (specifier === "@/lib/reviewsApi") return {
      getReviewResult: (id: number, filters: { subject?: string; search?: string }) => {
        requestFilters.push({ id, ...filters });
        const request = deferred<ReviewResult>();
        requests.push(request);
        return request.promise;
      },
      listReviewsResult: (filters: { subject?: string; search?: string }) => {
        listFilters.push(filters);
        const request = deferred<ReviewsResult>();
        lists.push(request);
        return request.promise;
      },
    };
    if (specifier === "./reviewModalUtils") return {
      reviewSubjectSlug: (value: PublicReview | null) => value?.subject?.slug ?? "",
      isScreenshotSourceMedia: () => false,
      reviewModalMediaShape: () => null,
    };
    throw new Error(`Unexpected module: ${specifier}`);
  };
  let location = new URL(options.url ?? "https://school.test/reviews");
  const fakeWindow = {
    get location() { return location; },
    history: { pushState: (_state: unknown, _unused: unknown, url: string) => { urls.push(url); location = new URL(url, location); } },
    addEventListener: (type: string, handler: () => void) => listeners.set(type, handler),
    removeEventListener: (type: string, handler: () => void) => { if (listeners.get(type) === handler) listeners.delete(type); },
    requestAnimationFrame: (callback: () => void) => callback(),
  };
  Function("require", "module", "exports", "window", output)(requireStub, sandboxModule, sandboxModule.exports, fakeWindow);
  const render = () => {
    stateCursor = 0;
    refCursor = 0;
    effectCursor = 0;
    return sandboxModule.exports.useReviewModalController({
      modalReviewItems: [review(1), review(2)], initialReview: options.initialReview ?? null,
      initialReviewNavigation: options.initialReviewNavigation ?? null, initialReviewId: options.initialReview?.id ?? null,
      initialSubject: options.initialSubject ?? "", closeImageViewer: () => undefined,
      initialSearch: options.initialSearch ?? "",
      initialPerPage: options.initialPerPage ?? 25,
      onHistoryClose: () => { historyClosed++; },
    });
  };
  return {
    render, requests, requestFilters, listFilters, lists, urls,
    pop: (url: string) => { location = new URL(url, location); listeners.get("popstate")?.(); },
    trigger: { focus: () => { focusRestored++; } } as HTMLButtonElement,
    unmount: () => effects.forEach((effect) => effect.cleanup?.()),
    focusRestored: () => focusRestored,
    historyClosed: () => historyClosed,
  };
}

test("a response arriving after close never reopens the modal or restores its URL", async () => {
  const ui = await harness();
  ui.render().openReview(review(1), ui.trigger);
  ui.render().closeSelectedReview();
  const closedUrl = ui.urls.at(-1);
  ui.requests[0].resolve({ data: review(1), navigation: null });
  await Promise.resolve();
  assert.equal(ui.render().selectedReview, null);
  assert.equal(ui.urls.at(-1), closedUrl);
  assert.equal(ui.focusRestored(), 1);
});

test("a late response cannot replace a newer selected review", async () => {
  const ui = await harness();
  ui.render().openReview(review(1), ui.trigger);
  ui.render().openReview(review(2), ui.trigger);
  ui.requests[1].resolve({ data: review(2), navigation: null });
  await Promise.resolve();
  ui.requests[0].resolve({ data: review(1), navigation: null });
  await Promise.resolve();
  assert.equal(ui.render().selectedReview?.id, 2);
  assert.match(ui.urls.at(-1) ?? "", /review=2/);
});

test("failure of an obsolete request does not clear the current loading state", async () => {
  const ui = await harness();
  ui.render().openReview(review(1), ui.trigger);
  ui.render().openReview(review(2), ui.trigger);
  ui.requests[0].reject(new Error("offline"));
  await Promise.resolve();
  assert.equal(ui.render().isReviewLoading, true);
  ui.requests[1].resolve({ data: review(2), navigation: null });
  await Promise.resolve();
  assert.equal(ui.render().isReviewLoading, false);
});

test("closing during subject lookup cancels the follow-up detail request", async () => {
  const ui = await harness();
  ui.render().openReview(review(1), ui.trigger);
  const changingSubject = ui.render().selectModalSubject("histology");
  ui.render().closeSelectedReview();
  ui.lists[0].resolve({
    data: [review(3, "histology")],
    meta: {
      limit: 1, page: 1, perPage: 1, total: 1, lastPage: 1, sort: "latest", totalPublished: 1,
      filters: { subject: "histology", course: null }, facets: { subjects: [], courses: [] },
    },
  });
  await changingSubject;
  assert.equal(ui.requests.length, 1);
  assert.equal(ui.render().selectedReview, null);
  ui.requests[0].resolve({ data: review(1), navigation: null });
});

test("unmount invalidates requests before they can change browser history", async () => {
  const ui = await harness();
  ui.render().openReview(review(1), ui.trigger);
  const previousUrl = ui.urls.at(-1);
  ui.unmount();
  ui.requests[0].resolve({ data: review(1), navigation: null });
  await Promise.resolve();
  assert.equal(ui.urls.at(-1), previousUrl);
});

function navigation(subject: string | null, nextId = 3, position = 1, total = 18): ReviewNavigation {
  return { previousId: position > 1 ? 1 : null, nextId, position, total, filters: { subject, course: null } };
}

test("direct review without subject keeps All and navigates across subjects using global metadata", async () => {
  const ui = await harness({ initialReview: review(2), initialReviewNavigation: navigation(null), url: "https://school.test/reviews?review=2" });
  assert.equal(ui.render().activeModalSubject, "");
  assert.equal(ui.render().selectedModalTotal, 18);
  ui.render().goNext();
  assert.deepEqual(ui.requestFilters[0], { id: 3, subject: undefined, search: undefined });
  ui.requests[0].resolve({ data: review(3, "histology"), navigation: navigation(null, 4, 2) });
  await Promise.resolve();
  assert.equal(ui.render().selectedReview?.id, 3);
  assert.equal(ui.render().activeModalSubject, "");
  assert.equal(ui.render().reviewNavigation?.position, 2);
  assert.equal(ui.urls.at(-1), "/reviews?review=3");
  ui.render().goPrevious();
  assert.equal(ui.requestFilters[1].subject, undefined);
});

test("explicit subject is honored by initial state, next request and URL", async () => {
  const ui = await harness({ initialSubject: "anatomy", initialReview: review(1), initialReviewNavigation: navigation("anatomy", 2, 1, 2), url: "https://school.test/reviews?subject=anatomy&review=1" });
  assert.equal(ui.render().activeModalSubject, "anatomy");
  ui.render().goNext();
  assert.deepEqual(ui.requestFilters[0], { id: 2, subject: "anatomy", search: undefined });
  ui.requests[0].resolve({ data: review(2), navigation: { ...navigation("anatomy", 2, 2, 2), nextId: null } });
  await Promise.resolve();
  assert.equal(ui.render().canGoNext, false);
  assert.equal(ui.render().selectedModalTotal, 2);
  assert.equal(new URL(ui.urls.at(-1)!, "https://school.test").searchParams.get("subject"), "anatomy");
});

test("card opening respects the page filter instead of deriving a filter from the card", async () => {
  const ui = await harness();
  ui.render().openReview(review(1), ui.trigger);
  assert.equal(ui.render().activeModalSubject, "");
  assert.deepEqual(ui.requestFilters[0], { id: 1, subject: undefined, search: undefined });
});

test("a filter change commits review, navigation, filter and URL together", async () => {
  const ui = await harness({ initialReview: review(1), initialReviewNavigation: navigation(null) });
  const changing = ui.render().selectModalSubject("histology");
  assert.equal(ui.render().activeModalSubject, "");
  ui.lists[0].resolve({ data: [review(3, "histology")] } as unknown as ReviewsResult);
  await Promise.resolve();
  ui.requests[0].resolve({ data: review(3, "histology"), navigation: navigation("histology", 4, 1, 5) });
  await changing;
  assert.equal(ui.render().activeModalSubject, "histology");
  assert.equal(ui.render().selectedModalTotal, 5);
  assert.equal(ui.urls.at(-1), "/reviews?review=3&subject=histology");
  const all = ui.render().selectModalSubject("");
  ui.requests[1].resolve({ data: review(3, "histology"), navigation: navigation(null, 4, 2) });
  await all;
  assert.equal(ui.render().activeModalSubject, "");
  assert.equal(ui.urls.at(-1), "/reviews?review=3");
});

test("404 and network failures preserve the visible selection and offer a working retry", async () => {
  const ui = await harness({ initialReview: review(1), initialReviewNavigation: navigation(null) });
  ui.render().goNext();
  ui.requests[0].resolve({ data: null, navigation: null });
  await Promise.resolve();
  assert.ok(ui.render().reviewError);
  assert.equal(ui.render().selectedReview?.id, 1);
  assert.equal(ui.render().canGoNext, false);
  ui.render().retryReview();
  ui.requests[1].reject(new Error("offline"));
  await Promise.resolve();
  assert.match(ui.render().reviewError ?? "", /повторите/);
  ui.render().retryReview();
  ui.requests[2].resolve({ data: review(3), navigation: navigation(null, 4, 2) });
  await Promise.resolve();
  assert.equal(ui.render().reviewError, null);
  assert.equal(ui.render().selectedReview?.id, 3);
});

test("failed subject lookup does not apply a new filter to old navigation", async () => {
  const ui = await harness({ initialReview: review(1), initialReviewNavigation: navigation(null) });
  const changing = ui.render().selectModalSubject("histology");
  ui.lists[0].reject(new Error("offline"));
  await changing;
  assert.ok(ui.render().reviewError);
  assert.equal(ui.render().activeModalSubject, "");
  assert.equal(ui.render().reviewNavigation?.filters.subject, null);
});

test("back and forward restore URL selection without writing new history entries", async () => {
  const ui = await harness({ initialReview: review(1), initialReviewNavigation: navigation(null) });
  ui.render();
  ui.pop("/reviews?subject=histology&review=3");
  assert.deepEqual(ui.requestFilters[0], { id: 3, subject: "histology", search: undefined });
  ui.requests[0].resolve({ data: review(3, "histology"), navigation: navigation("histology", 4, 1, 5) });
  await Promise.resolve();
  assert.equal(ui.render().activeModalSubject, "histology");
  assert.equal(ui.urls.length, 0);
  ui.pop("/reviews");
  assert.equal(ui.render().selectedReview, null);
  assert.equal(ui.historyClosed(), 1);
  ui.pop("/reviews?review=1");
  ui.requests[1].resolve({ data: review(1), navigation: navigation(null) });
  await Promise.resolve();
  assert.equal(ui.render().selectedReview?.id, 1);
  assert.equal(ui.render().activeModalSubject, "");
  assert.equal(ui.urls.length, 0);
});
