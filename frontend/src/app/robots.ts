import type { MetadataRoute } from "next";
import { robotsPolicy } from "@/lib/siteIndexingPolicy";

export default function robots(): MetadataRoute.Robots {
  return robotsPolicy(process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL);
}
