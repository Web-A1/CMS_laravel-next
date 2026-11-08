import type { MetadataRoute } from "next";

export function isSiteIndexingEnabled(flag: string | undefined, siteUrl: string | undefined): boolean {
  if (flag !== "true" || !siteUrl) return false;
  try {
    const url = new URL(siteUrl);
    return url.origin === "https://christmedschool.com" && url.pathname === "/"
      && !url.search && !url.hash && !url.username && !url.password;
  } catch {
    return false;
  }
}

export function buildIndexingFlag(stagingBuild: string | undefined, flag: string | undefined, siteUrl: string | undefined): string {
  return stagingBuild !== "1" && isSiteIndexingEnabled(flag, siteUrl) ? "true" : "false";
}

export function robotsPolicy(flag: string | undefined, siteUrl?: string): MetadataRoute.Robots {
  if (!isSiteIndexingEnabled(flag, siteUrl)) {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  return { rules: { userAgent: "*", allow: "/", disallow: ["/auth", "/account", "/lk", "/admin", "/backend"] } };
}
