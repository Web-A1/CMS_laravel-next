import { type NextRequest, NextResponse } from "next/server";

import { buildProtectedAuthRequestHeaders } from "@/lib/authNavigation";

export function proxy(request: NextRequest): NextResponse {
  const requestHeaders = buildProtectedAuthRequestHeaders(
    request.headers,
    request.nextUrl.pathname,
    request.nextUrl.searchParams,
  );

  return NextResponse.next({
    request: {
      headers: requestHeaders,
    },
  });
}

export const config = {
  matcher: ["/lk/:path*", "/admin/:path*"],
};
