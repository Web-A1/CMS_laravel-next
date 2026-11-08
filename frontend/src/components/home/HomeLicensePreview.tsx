"use client";

import Image from "next/image";
import { useCallback, useState } from "react";
import Button, { ButtonLink } from "@/components/button/Button";
import ButtonArrow from "@/components/button/ButtonArrow";
import ImageViewer from "@/components/image-viewer/ImageViewer";
import "@/components/content/prose.css";

export default function HomeLicensePreview() {
  const [open, setOpen] = useState(false);
  const [page, setPage] = useState(1);
  const closeViewer = useCallback(() => setOpen(false), []);

  return (
    <>
      <div className="home-fact-card__document-trigger">
        <span className="ui-image-zoom">
          <Image
            src="/images/home/license/license-preview.webp"
            width={640}
            height={905}
            sizes="(max-width: 480px) 40vw, (max-width: 1023px) 35vw, 280px"
            alt="Первая страница выписки из реестра образовательных лицензий"
            className="home-fact-card__document rounded-lg bg-white"
          />
        </span>
      </div>
      <button
        type="button"
        className="ui-image-zoom absolute inset-0 z-10 rounded-lg"
        aria-label="Посмотреть лицензию"
        aria-haspopup="dialog"
        data-ui="home.facts.license.open"
        onClick={(event) => {
          event.currentTarget.focus({ preventScroll: true });
          setPage(1);
          setOpen(true);
        }}
      />
      {open ? (
        <ImageViewer
          label="Образовательная лицензия — 2 страницы"
          onClose={closeViewer}
          onSwipe={(direction) => setPage((current) => Math.max(1, Math.min(2, current + (direction === "next" ? 1 : -1))))}
          controls={
            <>
              <div className="mx-auto flex w-fit items-center justify-center gap-2">
                <Button variant="ghost" size="md" iconOnly fullWidth={false} data-surface="dark" disabled={page === 1} onClick={() => setPage(1)} aria-label="Предыдущая страница лицензии" data-ui="home.facts.license.previous">
                  <ButtonArrow direction="left" chevron />
                </Button>
                <span className="text-sm tabular-nums" role="status" aria-live="polite" aria-atomic="true">{page} из 2</span>
                <Button variant="ghost" size="md" iconOnly fullWidth={false} data-surface="dark" disabled={page === 2} onClick={() => setPage(2)} aria-label="Следующая страница лицензии" data-ui="home.facts.license.next">
                  <ButtonArrow direction="right" chevron />
                </Button>
              </div>
              <ButtonLink href="https://staging.christmedschool.com/documents/лицензия.pdf" target="_blank" rel="noopener noreferrer" variant="ghost" size="sm" iconOnly fullWidth={false} data-surface="dark" className="image-viewer__secondary-action" aria-label="Открыть PDF лицензии на сайте школы" title="Открыть PDF" data-ui="home.facts.license.download">
                <ButtonArrow download />
              </ButtonLink>
            </>
          }
        >
          {({ className, style }) => (
            <Image
              key={page}
              src={`/images/home/license/license-page-${page}.webp`}
              width={1698}
              height={2400}
              alt={`Выписка из реестра образовательных лицензий — страница ${page} из 2`}
              className={className}
              style={style}
              unoptimized
              loading="eager"
              draggable={false}
            />
          )}
        </ImageViewer>
      ) : null}
    </>
  );
}
