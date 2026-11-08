import type { ArticleImageVariant } from "@/lib/articlesApi";

interface Props {
  readonly src: string;
  readonly alt: string;
  readonly width: number;
  readonly height: number;
  readonly sizes: string;
  readonly variants: ReadonlyArray<ArticleImageVariant>;
  readonly className?: string;
  readonly priority?: boolean;
}

export default function ArticleImage({ variants, priority = false, ...props }: Props) {
  const candidates = [...variants].sort((a, b) => a.width - b.width);
  const webp = candidates.filter((variant) => variant.format === "webp");
  const avif = candidates.filter((variant) => variant.format === "avif");
  const srcSet = (items: ReadonlyArray<ArticleImageVariant>) => items.map((item) => `${item.url} ${item.width}w`).join(", ");
  return <picture>
    {avif.length ? <source type="image/avif" srcSet={srcSet(avif)} sizes={props.sizes} /> : null}
    {/* S3 stores the encoded variants; avoid a second lossy transform and server cache. */}
    <img {...props} alt={props.alt} src={webp.at(-1)?.url ?? props.src} srcSet={webp.length ? srcSet(webp) : undefined}
      loading={priority ? "eager" : "lazy"} fetchPriority={priority ? "high" : undefined} decoding="async" />
  </picture>;
}
