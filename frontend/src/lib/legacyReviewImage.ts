const legacyPath = /^\/(?:images\/reviews|storage\/reviews)\/(?:[a-zA-Z0-9_@.-]+\/)*[a-zA-Z0-9_@.-]+\.(?:jpe?g|png|webp|avif)$/;

export async function serveLegacyReviewImage(path: string, backendOrigin: string, method = "GET"): Promise<Response> {
  const failure = (status: number): Response => new Response(null, { status, headers: { "Cache-Control": "no-store" } });
  if (!legacyPath.test(path) || path.split("/").some((part) => part === "." || part === "..")) return failure(404);

  try {
    const lookup = new URL("/review-images/legacy", backendOrigin);
    lookup.searchParams.set("path", path);
    // No browser cookies/authorization, and never follow an upstream redirect on the server.
    const upstream = await fetch(lookup, { redirect: "manual", cache: "no-store", signal: AbortSignal.timeout(15_000) });
    await upstream.body?.cancel();
    const location = upstream.headers.get("location");
    if (upstream.status !== 301 || !location) return failure(upstream.status === 404 ? 404 : 502);
    const target = new URL(location);
    if (target.protocol !== "https:" || target.username || target.password) return failure(502);
    // Next's internal Image fetcher cannot follow redirects. Serve the ready S3 bytes for old URLs.
    const image = await fetch(target, { method: method === "HEAD" ? "HEAD" : "GET", redirect: "error", cache: "no-store", signal: AbortSignal.timeout(15_000) });
    if (!image.ok || !/^image\/(?:jpeg|png|webp|avif)(?:;|$)/i.test(image.headers.get("content-type") ?? "")) {
      await image.body?.cancel();
      return failure(image.status === 404 ? 404 : 502);
    }
    const headers = new Headers({ "Cache-Control": "public, max-age=31536000, immutable", "X-Content-Type-Options": "nosniff" });
    for (const name of ["content-type", "etag", "last-modified"]) {
      const value = image.headers.get(name);
      if (value) headers.set(name, value);
    }
    return new Response(method === "HEAD" ? null : image.body, { headers });
  } catch {
    return failure(502);
  }
}
