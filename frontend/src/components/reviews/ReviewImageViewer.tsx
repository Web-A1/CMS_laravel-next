import ReviewMediaImage from "./ReviewMediaImage";
import ImageViewer from "@/components/image-viewer/ImageViewer";
import type { PublicReview } from "@/lib/reviewsApi";
import { reviewMediaAlt } from "./ReviewCard";
import { reviewContainedImageSizes } from "./reviewMediaVariants";

interface ReviewImageViewerProps {
  readonly media: PublicReview["media"][number];
  readonly review: PublicReview;
  readonly onClose: () => void;
}

export default function ReviewImageViewer({
  media,
  review,
  onClose,
}: ReviewImageViewerProps) {
  return (
    <ImageViewer label="Просмотр изображения отзыва" onClose={onClose}>
      {({ zoom, className, style }) => (
        <ReviewMediaImage
          role="viewer"
          variants={media.variants}
          className={className}
          src={media.url}
          alt={reviewMediaAlt(media, review)}
          width={media.width ?? 1440}
          height={media.height ?? 1440}
          sizes={reviewContainedImageSizes(media.width, media.height, "viewer", zoom)}
          style={style}
        />
      )}
    </ImageViewer>
  );
}
