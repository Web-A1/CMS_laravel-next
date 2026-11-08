import { Fragment, type ImgHTMLAttributes, type ReactElement } from "react";

type StaticGeneratedImageSource = {
  readonly avifSrc: string;
  readonly webpSrc: string;
  readonly width: number;
  readonly height: number;
};

export type StaticGeneratedImageAsset = StaticGeneratedImageSource & {
  readonly variants?: readonly StaticGeneratedImageSource[];
};

type GeneratedImageProps = {
  readonly image: StaticGeneratedImageAsset;
  readonly media?: string;
  readonly responsiveSources?: readonly {
    readonly media: string;
    readonly image: StaticGeneratedImageAsset;
  }[];
  readonly alt: string;
  readonly className?: string;
  readonly sizes?: string;
  readonly loading?: NonNullable<ImgHTMLAttributes<HTMLImageElement>["loading"]>;
  readonly decoding?: NonNullable<ImgHTMLAttributes<HTMLImageElement>["decoding"]>;
  readonly fetchPriority?: ImgHTMLAttributes<HTMLImageElement>["fetchPriority"];
};

function imageSrcSet(image: StaticGeneratedImageAsset, format: "avifSrc" | "webpSrc"): string {
  return image.variants?.map((variant) => `${variant[format]} ${variant.width}w`).join(", ") ?? image[format];
}

export function GeneratedImageSources({ image, media, sizes }: Pick<GeneratedImageProps, "image" | "media" | "sizes">): ReactElement {
  return (
    <Fragment>
      <source media={media} srcSet={imageSrcSet(image, "avifSrc")} sizes={sizes} type="image/avif" width={image.width} height={image.height} />
      <source media={media} srcSet={imageSrcSet(image, "webpSrc")} sizes={sizes} type="image/webp" width={image.width} height={image.height} />
    </Fragment>
  );
}

export default function GeneratedImage({
  image,
  media,
  responsiveSources,
  alt,
  className,
  sizes,
  loading = "lazy",
  decoding = "async",
  fetchPriority,
}: GeneratedImageProps): ReactElement {
  return (
    <picture>
      {responsiveSources?.map(({ media: sourceMedia, image: sourceImage }) => (
        <GeneratedImageSources key={`${sourceMedia}-${sourceImage.webpSrc}`} image={sourceImage} media={media ? `${media} and ${sourceMedia}` : sourceMedia} sizes={sizes} />
      ))}
      <source media={media} srcSet={imageSrcSet(image, "avifSrc")} sizes={sizes} type="image/avif" />
      {media ? <source media={media} srcSet={imageSrcSet(image, "webpSrc")} sizes={sizes} type="image/webp" /> : null}
      <img
        src={media ? "data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%2F%3E" : image.webpSrc}
        srcSet={!media && image.variants ? imageSrcSet(image, "webpSrc") : undefined}
        sizes={sizes}
        alt={alt}
        className={className}
        width={image.width}
        height={image.height}
        loading={loading}
        decoding={decoding}
        fetchPriority={fetchPriority}
      />
    </picture>
  );
}
