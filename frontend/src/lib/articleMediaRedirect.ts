export function isArticleMediaPath(path: string): boolean {
  return /^(?:[a-f0-9]{64}\.(?:jpe?g|png|webp|avif|gif|pdf)|variants\/[a-f0-9]{64}\/v1\/[1-9][0-9]*\.(?:avif|webp))$/u.test(path);
}

/** Only relay the exact configured public object URL; never follow arbitrary upstream redirects. */
export function articleMediaRedirect(location: string | null, filename: string, publicBase: string | undefined): string | null {
  if (!location || !publicBase || !isArticleMediaPath(filename)) return null;
  try {
    const base = new URL(publicBase.endsWith("/") ? publicBase : `${publicBase}/`);
    if (base.protocol !== "https:" || base.username || base.password || base.search || base.hash) return null;
    const expected = new URL(`article-media/${filename}`, base).href;
    return location === expected ? expected : null;
  } catch {
    return null;
  }
}
