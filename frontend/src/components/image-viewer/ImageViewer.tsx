"use client";

import { useLayoutEffect, useRef, type CSSProperties, type ReactNode } from "react";
import CloseButton from "@/components/button/CloseButton";
import { IMAGE_VIEWER_MIN_ZOOM, IMAGE_VIEWER_ZOOM_STEP } from "./imageViewerUtils";
import { useImageViewer, type ImageViewerSwipe } from "./useImageViewer";
import "./image-viewer.css";

interface ImageViewerContentProps {
  readonly zoom: number;
  readonly className: string;
  readonly style: CSSProperties;
}

interface ImageViewerProps {
  readonly label: string;
  readonly onClose: () => void;
  readonly controls?: ReactNode;
  readonly onSwipe?: (direction: ImageViewerSwipe) => void;
  readonly children: (props: ImageViewerContentProps) => ReactNode;
}

// Mounted only while open: gesture state resets for every viewing session.
export default function ImageViewer({ label, onClose, controls, onSwipe, children }: ImageViewerProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const stageRef = useRef<HTMLDivElement>(null);
  const {
    viewerZoom,
    viewerOffset,
    updateImageViewerZoom,
    startImageViewerGesture,
    moveImageViewerGesture,
    finishImageViewerGesture,
    zoomImageViewerFromWheel,
  } = useImageViewer(onSwipe);

  useLayoutEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const alreadyLocked = document.body.classList.contains("menu-scroll-lock");
    document.body.classList.add("menu-scroll-lock");
    dialog.showModal();
    // Start on the document; Tab moves to the close control with a visible ring.
    stageRef.current?.focus({ preventScroll: true });

    function onKeyDown(event: KeyboardEvent): void {
      // Keep shortcuts inside the topmost viewer, including when opened over a review.
      event.stopPropagation();
      if (event.key === "Escape") {
        event.preventDefault();
        onClose();
      } else if (event.key === "Tab") {
        const items = dialog?.querySelectorAll<HTMLElement>("button:not(:disabled), a[href], [tabindex='0']");
        const first = items?.[0];
        const last = items?.[items.length - 1];
        const active = document.activeElement;
        if (active === stageRef.current || (event.shiftKey ? active === first : active === last)) {
          event.preventDefault();
          (event.shiftKey ? last : first)?.focus();
        }
      } else if (event.metaKey || event.ctrlKey) {
        if (event.key === "+" || event.key === "=") {
          event.preventDefault();
          updateImageViewerZoom((zoom) => zoom + IMAGE_VIEWER_ZOOM_STEP);
        } else if (event.key === "-" || event.key === "_") {
          event.preventDefault();
          updateImageViewerZoom((zoom) => zoom - IMAGE_VIEWER_ZOOM_STEP);
        } else if (event.key === "0") {
          event.preventDefault();
          updateImageViewerZoom(IMAGE_VIEWER_MIN_ZOOM);
        }
      }
    }

    document.addEventListener("keydown", onKeyDown, true);
    return () => {
      document.removeEventListener("keydown", onKeyDown, true);
      dialog.close();
      if (!alreadyLocked) document.body.classList.remove("menu-scroll-lock");
      if (trigger?.isConnected) trigger.focus({ preventScroll: true });
    };
  }, [onClose, updateImageViewerZoom]);

  return (
    <dialog
      ref={dialogRef}
      className="image-viewer"
      aria-label={label}
      data-ui="image.viewer"
      data-controls={controls ? "true" : undefined}
      onCancel={(event) => { event.preventDefault(); onClose(); }}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          event.preventDefault();
          onClose();
        }
      }}
    >
      <CloseButton ref={closeRef} data-surface="dark" className="image-viewer__close" aria-label="Закрыть просмотр изображения" onClick={onClose} />
      <div
        ref={stageRef}
        className="image-viewer__stage"
        tabIndex={-1}
        data-draggable={viewerZoom > IMAGE_VIEWER_MIN_ZOOM ? "true" : undefined}
        onWheel={zoomImageViewerFromWheel}
        onPointerDown={startImageViewerGesture}
        onPointerMove={moveImageViewerGesture}
        onPointerUp={finishImageViewerGesture}
        onPointerCancel={finishImageViewerGesture}
        onLostPointerCapture={finishImageViewerGesture}
      >
        {children({
          zoom: viewerZoom,
          className: "image-viewer__image",
          style: { transform: `translate3d(${viewerOffset.x}px, ${viewerOffset.y}px, 0) scale(${viewerZoom})` },
        })}
      </div>
      {controls ? <div className="image-viewer__controls">{controls}</div> : null}
    </dialog>
  );
}
