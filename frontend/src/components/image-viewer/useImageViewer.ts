"use client";

import type { PointerEvent, WheelEvent } from "react";
import { useCallback, useRef, useState } from "react";

import {
  clampImageViewerZoom,
  imageViewerCenter,
  imageViewerDistance,
  IMAGE_VIEWER_MIN_ZOOM,
  IMAGE_VIEWER_ZOOM_STEP,
  type ImageViewerPinch,
  type ImageViewerPoint,
} from "./imageViewerUtils";

export type ImageViewerSwipe = "previous" | "next";

export function useImageViewer(onSwipe?: (direction: ImageViewerSwipe) => void) {
  const [viewerZoom, setViewerZoom] = useState(IMAGE_VIEWER_MIN_ZOOM);
  const [viewerOffset, setViewerOffset] = useState<ImageViewerPoint>({ x: 0, y: 0 });
  const viewerPointersRef = useRef<Map<number, ImageViewerPoint>>(new Map());
  const viewerPinchRef = useRef<ImageViewerPinch | null>(null);
  const viewerSwipeRef = useRef<{ readonly pointerId: number; readonly x: number; readonly y: number } | null>(null);
  const viewerDragRef = useRef<
    | {
        readonly pointerId: number;
        readonly startX: number;
        readonly startY: number;
        readonly originX: number;
        readonly originY: number;
      }
    | null
  >(null);

  const updateImageViewerZoom = useCallback((nextZoom: number | ((currentZoom: number) => number)) => {
    viewerSwipeRef.current = null;
    setViewerZoom((currentZoom) => {
      const resolvedZoom = typeof nextZoom === "function" ? nextZoom(currentZoom) : nextZoom;
      const clampedZoom = clampImageViewerZoom(resolvedZoom);

      if (clampedZoom === IMAGE_VIEWER_MIN_ZOOM) {
        setViewerOffset({ x: 0, y: 0 });
      }

      return clampedZoom;
    });
  }, []);

  const startImageViewerGesture = useCallback(
    (event: PointerEvent<HTMLDivElement>) => {
      try {
        event.currentTarget.setPointerCapture(event.pointerId);
      } catch {
        // Browser-emulated touch events can miss an active pointer; bubbling still keeps the gesture usable.
      }

      viewerPointersRef.current.set(event.pointerId, { x: event.clientX, y: event.clientY });
      viewerSwipeRef.current = null;

      const points = Array.from(viewerPointersRef.current.values());
      if (points.length >= 2) {
        const [firstPoint, secondPoint] = points;
        viewerDragRef.current = null;
        viewerPinchRef.current = {
          distance: imageViewerDistance(firstPoint, secondPoint),
          zoom: viewerZoom,
          center: imageViewerCenter(firstPoint, secondPoint),
          offset: viewerOffset,
        };
        return;
      }

      viewerPinchRef.current = null;
      if (onSwipe && event.pointerType === "touch" && viewerZoom === IMAGE_VIEWER_MIN_ZOOM) {
        viewerSwipeRef.current = { pointerId: event.pointerId, x: event.clientX, y: event.clientY };
      }
      if (viewerZoom > IMAGE_VIEWER_MIN_ZOOM) {
        viewerDragRef.current = {
          pointerId: event.pointerId,
          startX: event.clientX,
          startY: event.clientY,
          originX: viewerOffset.x,
          originY: viewerOffset.y,
        };
      }
    },
    [onSwipe, viewerOffset, viewerZoom],
  );

  const moveImageViewerGesture = useCallback((event: PointerEvent<HTMLDivElement>) => {
    if (!viewerPointersRef.current.has(event.pointerId)) {
      return;
    }

    viewerPointersRef.current.set(event.pointerId, { x: event.clientX, y: event.clientY });
    const points = Array.from(viewerPointersRef.current.values());

    if (points.length >= 2 && viewerPinchRef.current) {
      const [firstPoint, secondPoint] = points;
      const nextDistance = imageViewerDistance(firstPoint, secondPoint);
      if (viewerPinchRef.current.distance <= 0) {
        return;
      }

      const nextZoom = clampImageViewerZoom(viewerPinchRef.current.zoom * (nextDistance / viewerPinchRef.current.distance));
      const nextCenter = imageViewerCenter(firstPoint, secondPoint);
      const scaleShift = nextZoom / viewerPinchRef.current.zoom - 1;

      setViewerZoom(nextZoom);
      if (nextZoom === IMAGE_VIEWER_MIN_ZOOM) {
        setViewerOffset({ x: 0, y: 0 });
        return;
      }

      setViewerOffset({
        x:
          viewerPinchRef.current.offset.x +
          (nextCenter.x - viewerPinchRef.current.center.x) -
          (viewerPinchRef.current.center.x - window.innerWidth / 2) * scaleShift,
        y:
          viewerPinchRef.current.offset.y +
          (nextCenter.y - viewerPinchRef.current.center.y) -
          (viewerPinchRef.current.center.y - window.innerHeight / 2) * scaleShift,
      });
      return;
    }

    const drag = viewerDragRef.current;
    if (!drag || drag.pointerId !== event.pointerId) {
      return;
    }

    setViewerOffset({
      x: drag.originX + event.clientX - drag.startX,
      y: drag.originY + event.clientY - drag.startY,
    });
  }, []);

  const finishImageViewerGesture = useCallback((event: PointerEvent<HTMLDivElement>) => {
    const swipe = viewerSwipeRef.current;
    if (swipe?.pointerId === event.pointerId) {
      viewerSwipeRef.current = null;
    }
    viewerPointersRef.current.delete(event.pointerId);

    if (viewerDragRef.current?.pointerId === event.pointerId) {
      viewerDragRef.current = null;
    }

    const points = Array.from(viewerPointersRef.current.values());
    if (points.length < 2) {
      viewerPinchRef.current = null;
    }

    // Only a completed single-touch gesture at the fitted scale changes pages.
    if (event.type === "pointerup" && swipe?.pointerId === event.pointerId && viewerZoom === IMAGE_VIEWER_MIN_ZOOM) {
      const deltaX = event.clientX - swipe.x;
      const deltaY = event.clientY - swipe.y;
      if (Math.abs(deltaX) >= 48 && Math.abs(deltaX) > Math.abs(deltaY) * 1.5) {
        onSwipe?.(deltaX < 0 ? "next" : "previous");
      }
    }
  }, [onSwipe, viewerZoom]);

  const zoomImageViewerFromWheel = useCallback(
    (event: WheelEvent<HTMLDivElement>) => {
      const direction = event.deltaY < 0 ? 1 : -1;
      updateImageViewerZoom((currentZoom) => currentZoom + direction * IMAGE_VIEWER_ZOOM_STEP);
    },
    [updateImageViewerZoom],
  );

  return {
    viewerZoom,
    viewerOffset,
    updateImageViewerZoom,
    startImageViewerGesture,
    moveImageViewerGesture,
    finishImageViewerGesture,
    zoomImageViewerFromWheel,
  };
}
