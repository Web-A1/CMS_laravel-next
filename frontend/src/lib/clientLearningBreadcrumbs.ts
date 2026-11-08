import { clientAccountApiBaseUrl } from "@/lib/clientAccount";

export type LearningBreadcrumbLabelOverrides = Readonly<Record<string, string>>;

type ClientFetch = typeof fetch;

type LearningBreadcrumbOptions = {
  readonly apiBaseUrl?: string;
  readonly fetchLearning?: ClientFetch;
  readonly signal?: AbortSignal;
};

type NamedLearningEntry = {
  readonly slug: string;
  readonly title: string;
  readonly canonicalPath?: string;
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isPathSegment(value: unknown): value is string {
  return typeof value === "string" && value !== "" && value !== "." && value !== ".." && !/[/?#]/.test(value);
}

function isTitle(value: unknown): value is string {
  return typeof value === "string" && value.trim() !== "";
}

function isLearningPath(value: unknown): value is string {
  return typeof value === "string" && value.startsWith("/lk/learning/");
}

function readNamedLearningEntry(value: unknown): NamedLearningEntry | null {
  if (!isRecord(value) || !isPathSegment(value.slug) || !isTitle(value.title)) {
    return null;
  }

  return {
    slug: value.slug,
    title: value.title.trim(),
    canonicalPath: isLearningPath(value.canonicalPath) ? value.canonicalPath : undefined,
  };
}

function addLabel(labels: Record<string, string>, path: string, entry: NamedLearningEntry | null): void {
  if (!entry) return;

  labels[entry.canonicalPath ?? path] = entry.title;
}

function learningPathParts(parts: readonly string[]): { courseSlug?: string; lessonSlug?: string; subjectSlug: string } | null {
  if (
    parts[0] !== "lk" ||
    parts[1] !== "learning" ||
    !isPathSegment(parts[2]) ||
    parts.length > 6 ||
    (parts[5] !== undefined && parts[5] !== "exam")
  ) {
    return null;
  }

  if (parts[3] !== undefined && !isPathSegment(parts[3])) {
    return null;
  }

  if (parts[4] !== undefined && !isPathSegment(parts[4])) {
    return null;
  }

  return {
    subjectSlug: parts[2],
    courseSlug: parts[3],
    lessonSlug: parts[4],
  };
}

async function fetchLearningJson(
  path: string,
  { apiBaseUrl = clientAccountApiBaseUrl(), fetchLearning = fetch, signal }: LearningBreadcrumbOptions,
): Promise<unknown> {
  const response = await fetchLearning(`${apiBaseUrl.replace(/\/+$/, "")}${path}`, {
    credentials: "include",
    headers: { Accept: "application/json" },
    signal,
  });

  if (!response.ok) {
    return null;
  }

  return response.json().catch(() => null);
}

function readPayloadData(payload: unknown): Record<string, unknown> | null {
  return isRecord(payload) && isRecord(payload.data) ? payload.data : null;
}

export async function loadLearningBreadcrumbLabelOverrides(
  parts: readonly string[],
  options: LearningBreadcrumbOptions = {},
): Promise<LearningBreadcrumbLabelOverrides> {
  try {
    const route = learningPathParts(parts);
    if (!route) {
      return {};
    }

    const subjectPath = `/lk/learning/${route.subjectSlug}`;
    const labels: Record<string, string> = {};

    if (!route.courseSlug) {
      const data = readPayloadData(
        await fetchLearningJson(`/api/v1/learning/subjects/${encodeURIComponent(route.subjectSlug)}`, options),
      );
      addLabel(labels, subjectPath, readNamedLearningEntry(data?.subject));

      return labels;
    }

    const coursePath = `${subjectPath}/${route.courseSlug}`;
    const data = readPayloadData(
      await fetchLearningJson(
        `/api/v1/learning/subjects/${encodeURIComponent(route.subjectSlug)}/courses/${encodeURIComponent(route.courseSlug)}`,
        options,
      ),
    );

    addLabel(labels, subjectPath, readNamedLearningEntry(data?.subject));
    addLabel(labels, coursePath, readNamedLearningEntry(data?.course));

    if (!route.lessonSlug || !Array.isArray(data?.lessons)) {
      return labels;
    }

    const lesson = data.lessons.map(readNamedLearningEntry).find((entry) => entry?.slug === route.lessonSlug) ?? null;
    addLabel(labels, `${coursePath}/${route.lessonSlug}`, lesson);

    return labels;
  } catch {
    return {};
  }
}
