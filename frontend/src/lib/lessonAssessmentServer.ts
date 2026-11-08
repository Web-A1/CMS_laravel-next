import "server-only";

import { accountBackendRequest } from "@/lib/accountAuth";
import { rethrowNextDynamicServerError } from "@/lib/apiErrors";
import {
  lessonAssessmentPath,
  parseLessonAssessmentPayload,
  type LessonAssessmentData,
  type LessonAssessmentRoute,
} from "@/lib/lessonAssessmentContract";

export type LessonAssessmentState =
  | { readonly status: "ready"; readonly data: LessonAssessmentData }
  | { readonly status: "denied" }
  | { readonly status: "not_found" }
  | { readonly status: "unavailable" };

export async function loadLessonAssessment(
  apiBaseUrl: string,
  refererPath: string,
  route: LessonAssessmentRoute,
): Promise<LessonAssessmentState> {
  const assessmentPath = lessonAssessmentPath(route);
  if (assessmentPath === null) {
    return { status: "unavailable" };
  }

  try {
    const accountRequest = await accountBackendRequest(apiBaseUrl, refererPath);
    const response = await fetch(`${accountRequest.resolvedApiBaseUrl}${assessmentPath}`, {
      headers: accountRequest.headers,
      cache: "no-store",
    });

    if (response.status === 401 || response.status === 403) {
      return { status: "denied" };
    }
    if (response.status === 404) {
      return { status: "not_found" };
    }
    if (response.status !== 200) {
      return { status: "unavailable" };
    }

    const payload: unknown = await response.json().catch(() => null);
    const data = parseLessonAssessmentPayload(payload, route);
    return data === null ? { status: "unavailable" } : { status: "ready", data };
  } catch (error) {
    rethrowNextDynamicServerError(error);

    return { status: "unavailable" };
  }
}
