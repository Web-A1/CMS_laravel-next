export interface ImageViewerPoint {
  readonly x: number;
  readonly y: number;
}

export interface ImageViewerPinch {
  readonly distance: number;
  readonly zoom: number;
  readonly center: ImageViewerPoint;
  readonly offset: ImageViewerPoint;
}

export const IMAGE_VIEWER_MIN_ZOOM = 1;
export const IMAGE_VIEWER_MAX_ZOOM = 6;
export const IMAGE_VIEWER_ZOOM_STEP = 0.35;

export function clampImageViewerZoom(value: number): number {
  return Math.min(IMAGE_VIEWER_MAX_ZOOM, Math.max(IMAGE_VIEWER_MIN_ZOOM, value));
}

export function imageViewerDistance(first: ImageViewerPoint, second: ImageViewerPoint): number {
  return Math.hypot(second.x - first.x, second.y - first.y);
}

export function imageViewerCenter(first: ImageViewerPoint, second: ImageViewerPoint): ImageViewerPoint {
  return { x: (first.x + second.x) / 2, y: (first.y + second.y) / 2 };
}
