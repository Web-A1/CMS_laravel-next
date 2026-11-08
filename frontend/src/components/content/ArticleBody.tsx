"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import ImageViewer from "@/components/image-viewer/ImageViewer";

export default function ArticleBody({ html }: { readonly html: string }) {
  const body = useRef<HTMLDivElement>(null);
  const [image, setImage] = useState<{ readonly src: string; readonly alt: string } | null>(null);
  const [imageUnavailable, setImageUnavailable] = useState(false);
  const closeImage = useCallback(() => setImage(null), []);

  useEffect(() => {
    const buttons: HTMLButtonElement[] = [];
    const tables: HTMLDivElement[] = [];
    let tableNumber = 0;
    for (const table of body.current?.querySelectorAll("table") ?? []) {
      tableNumber += 1;
      if (table.parentElement?.classList.contains("ui-table-scroll")) continue;
      const scroll = document.createElement("div");
      scroll.className = "ui-table-scroll";
      scroll.tabIndex = 0;
      scroll.setAttribute("role", "region");
      scroll.setAttribute("aria-label", table.caption?.textContent?.trim() || `Таблица ${tableNumber}`);
      table.before(scroll);
      scroll.append(table);
      tables.push(scroll);
    }
    for (const img of body.current?.querySelectorAll("img") ?? []) {
      if (img.closest("a")) continue;
      const button = document.createElement("button");
      const visual = img.closest("picture") ?? img;
      button.type = "button";
      button.className = "ui-image-zoom";
      button.setAttribute("aria-label", img.alt ? `Увеличить: ${img.alt}` : "Увеличить иллюстрацию");
      button.addEventListener("click", () => {
        button.focus({ preventScroll: true });
        setImageUnavailable(false);
        setImage({ src: img.dataset.fullSrc || img.src, alt: img.alt });
      });
      visual.before(button);
      button.append(visual);
      buttons.push(button);
    }
    return () => {
      for (const button of buttons) button.replaceWith(...button.childNodes);
      for (const scroll of tables) scroll.replaceWith(...scroll.childNodes);
    };
  }, [html]);

  return <>
    <div ref={body} className="ui-prose" data-ui="articles.body" dangerouslySetInnerHTML={{ __html: html }} />
    {image ? <ImageViewer label="Иллюстрация статьи" onClose={closeImage}>
      {({ className, style }) => imageUnavailable
        ? <p className="text-center text-sm text-white" role="status">Не удалось загрузить изображение.</p>
        // eslint-disable-next-line @next/next/no-img-element
        : <img src={image.src} alt={image.alt} className={className} style={style} draggable={false} onError={() => setImageUnavailable(true)} />}
    </ImageViewer> : null}
  </>;
}
