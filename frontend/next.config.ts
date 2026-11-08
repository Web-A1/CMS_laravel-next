import type { NextConfig } from "next";

import { securityResponseHeaders } from "./src/lib/securityResponseHeaders";
import { buildIndexingFlag } from "./src/lib/siteIndexingPolicy";

type RemotePattern = NonNullable<NonNullable<NextConfig["images"]>["remotePatterns"]>[number];

const localBackendOrigins = ["http://127.0.0.1:8000", "http://localhost:8000"] as const;

function splitOrigins(value: string | undefined): string[] {
  if (!value) return [];

  return value
    .split(",")
    .map((origin) => origin.trim())
    .filter(Boolean);
}

function imageRemotePattern(origin: string): RemotePattern | null {
  let url: URL;

  try {
    url = new URL(origin);
  } catch {
    return null;
  }

  if (url.protocol !== "http:" && url.protocol !== "https:") {
    return null;
  }

  return {
    protocol: url.protocol === "http:" ? "http" : "https",
    hostname: url.hostname,
    port: url.port,
    pathname: "/storage/**",
  };
}

function imageRemotePatterns(): RemotePattern[] {
  const patterns = [
    ...localBackendOrigins,
    ...splitOrigins(process.env.BACKEND_API_BASE_URL),
    ...splitOrigins(process.env.NEXT_PUBLIC_API_BASE_URL),
    ...splitOrigins(process.env.NEXT_IMAGE_REMOTE_ORIGINS),
  ]
    .map(imageRemotePattern)
    .filter((pattern): pattern is RemotePattern => pattern !== null);

  if (process.env.ARTICLE_MEDIA_PUBLIC_URL) {
    const base = new URL(process.env.ARTICLE_MEDIA_PUBLIC_URL);
    if (base.protocol !== "https:" || base.username || base.password || base.search || base.hash) {
      throw new Error("ARTICLE_MEDIA_PUBLIC_URL must be a public HTTPS base URL");
    }
    patterns.push({ protocol: "https", hostname: base.hostname, port: base.port,
      pathname: `${base.pathname.replace(/\/$/u, "")}/article-media/**`, search: "" });
  }

  return Array.from(
    new Map(
      patterns.map((pattern) => [
        `${pattern.protocol}:${pattern.hostname}:${pattern.port}:${pattern.pathname}`,
        pattern,
      ]),
    ).values(),
  );
}

const nextConfig: NextConfig = {
  // next.config env is compiled into server bundles; runtime env cannot reopen a staging artifact.
  env: {
    SITE_INDEXING_ENABLED: buildIndexingFlag(process.env.STAGING_BUILD, process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL),
  },
  allowedDevOrigins: ["127.0.0.1", "127.0.0.1"],
  poweredByHeader: false,
  // Five 10 MiB support images plus multipart overhead must reach the backend intact.
  experimental: { proxyClientMaxBodySize: "64mb" },
  async redirects() {
    return [
      { source: "/grg.html", destination: "/offers/legacy-product-127", permanent: true },
      { source: "/appendix.html", destination: "/offers/legacy-product-128", permanent: true },
      { source: "/miologiya", destination: "/subjects/anatomiya/miologiya", permanent: true },
    ];
  },
  images: {
    // Originals live in object storage; generated image cache must stay bounded.
    maximumDiskCacheSize: 128 * 1024 * 1024,
    dangerouslyAllowLocalIP: process.env.NODE_ENV !== "production",
    formats: ["image/avif", "image/webp"],
    remotePatterns: imageRemotePatterns(),
  },
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [...securityResponseHeaders,
          ...(buildIndexingFlag(process.env.STAGING_BUILD, process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL) === "true"
            ? [] : [{ key: "X-Robots-Tag", value: "noindex, nofollow" }])],
      },
      {
        source: "/:path*",
        has: [{ type: "host", value: "staging\\.christmedschool\\.com" }],
        headers: [{ key: "X-Robots-Tag", value: "noindex, nofollow" }],
      },
      {
        source: "/images/v2/subjects/generated/:asset(learning-.*)",
        headers: [
          {
            key: "Cache-Control",
            value: "public, max-age=31536000, immutable",
          },
        ],
      },
    ];
  },
  async rewrites() {
    const publicApi = process.env.NEXT_PUBLIC_API_BASE_URL;
    const backend = new URL(process.env.BACKEND_API_BASE_URL
      ?? (publicApi && /^https?:\/\//.test(publicApi) ? publicApi : "http://127.0.0.1:8000"));
    if (!["http:", "https:"].includes(backend.protocol) || backend.username || backend.password) {
      throw new Error("BACKEND_API_BASE_URL must be an HTTP(S) origin without credentials");
    }
    const origin = backend.origin;

    return {
      beforeFiles: [
        { source: "/admin", destination: `${origin}/admin` },
        { source: "/admin/:path*", destination: `${origin}/admin/:path*` },
        // Livewire 4.4 uses an installation-specific eight-hex endpoint prefix.
        { source: "/livewire-:hash([a-f0-9]{8})/update", destination: `${origin}/livewire-:hash/update` },
        { source: "/livewire-:hash([a-f0-9]{8})/upload-file", destination: `${origin}/livewire-:hash/upload-file` },
        { source: "/livewire-:hash([a-f0-9]{8})/preview-file/:path*", destination: `${origin}/livewire-:hash/preview-file/:path*` },
        { source: "/livewire-:hash([a-f0-9]{8})/:script(livewire(?:\\.min)?\\.js)", destination: `${origin}/livewire-:hash/:script` },
        { source: "/css/filament/:path*", destination: `${origin}/css/filament/:path*` },
        { source: "/js/filament/:path*", destination: `${origin}/js/filament/:path*` },
        { source: "/fonts/filament/:path*", destination: `${origin}/fonts/filament/:path*` },
      ],
      afterFiles: [{ source: "/backend/:path*", destination: `${origin}/:path*` }],
      fallback: [],
    };
  },
};

export default nextConfig;
