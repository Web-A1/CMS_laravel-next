import { backendApiBaseUrl } from "@/lib/apiBaseUrl";
import { serveLegacyReviewImage } from "@/lib/legacyReviewImage";
import { articleMediaRedirect, isArticleMediaPath } from "@/lib/articleMediaRedirect";

type MediaRouteContext = {
  readonly params: Promise<{ directory: string; path: string[] }>;
};

async function serveReviewImage(request: Request, context: MediaRouteContext): Promise<Response> {
  const { directory, path } = await context.params;
  const relativePath = path.join("/");
  if (directory === "reviews") {
    return serveLegacyReviewImage(`/storage/reviews/${relativePath}`, new URL(backendApiBaseUrl()).origin, request.method);
  }
  const articleMedia = directory === "article-media";
  const allowedPath = articleMedia
    ? isArticleMediaPath(relativePath)
    : /^[a-zA-Z0-9_-]+(?:\/[0-9]+)?\.(?:jpe?g|png|webp|avif)$/.test(relativePath);
  if ((!articleMedia && !["review-authors", "review-screenshots"].includes(directory)) || !allowedPath) {
    return new Response(null, { status: 404, headers: { "Cache-Control": "no-store" } });
  }

  const origin = new URL(backendApiBaseUrl()).origin;
  const headers = new Headers();
  for (const name of ["if-none-match", "if-modified-since"]) {
    const value = request.headers.get(name);
    if (value) headers.set(name, value);
  }

  try {
    // Only allowlisted public media are proxied; no cookies or authorization.
    const upstream = await fetch(`${origin}/storage/${directory}/${relativePath}`, {
      method: request.method === "HEAD" ? "HEAD" : "GET", headers,
      cache: "no-store", redirect: articleMedia ? "manual" : "error", signal: AbortSignal.timeout(15_000),
    });
    if (articleMedia && [301, 302, 307, 308].includes(upstream.status)) {
      await upstream.body?.cancel();
      const location = articleMediaRedirect(upstream.headers.get("location"), relativePath, process.env.ARTICLE_MEDIA_PUBLIC_URL);
      return location
        ? new Response(null, { status: 302, headers: { Location: location, "Cache-Control": "public, max-age=300" } })
        : new Response(null, { status: 502, headers: { "Cache-Control": "no-store" } });
    }
    if (!upstream.ok && upstream.status !== 304) {
      await upstream.body?.cancel();
      return new Response(null, { status: upstream.status === 404 ? 404 : 502, headers: { "Cache-Control": "no-store" } });
    }
    const contentType = upstream.headers.get("content-type");
    const allowedType = articleMedia ? /^(?:image\/(?:jpeg|png|webp|avif|gif)|application\/pdf)(?:;|$)/i : /^image\/(?:jpeg|png|webp|avif)(?:;|$)/i;
    if (upstream.status !== 304 && !allowedType.test(contentType ?? "")) {
      await upstream.body?.cancel();
      return new Response(null, { status: 502, headers: { "Cache-Control": "no-store" } });
    }
    const responseHeaders = new Headers({
      "Cache-Control": "public, max-age=31536000, immutable",
      "X-Content-Type-Options": "nosniff",
    });
    for (const name of ["content-type", "etag", "last-modified"]) {
      const value = upstream.headers.get(name);
      if (value) responseHeaders.set(name, value);
    }
    return new Response(upstream.body, { status: upstream.status, headers: responseHeaders });
  } catch {
    return new Response(null, { status: 502, headers: { "Cache-Control": "no-store" } });
  }
}

export const GET = serveReviewImage;
export const HEAD = serveReviewImage;
