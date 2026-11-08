import { backendApiBaseUrl } from "@/lib/apiBaseUrl";
import { serveLegacyReviewImage } from "@/lib/legacyReviewImage";

type Context = { readonly params: Promise<{ path: string[] }> };

async function serveImage(request: Request, context: Context): Promise<Response> {
  const { path } = await context.params;
  return serveLegacyReviewImage(`/images/reviews/${path.join("/")}`, new URL(backendApiBaseUrl()).origin, request.method);
}

export const GET = serveImage;
export const HEAD = serveImage;
