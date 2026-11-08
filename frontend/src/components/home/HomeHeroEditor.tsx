"use client";

import { Suspense, useEffect, useLayoutEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import Button from "@/components/button/Button";
import { adjustHeroPlacement, boundedHeroValue, clampPanelPosition, HERO_EDITOR_STORAGE_PREFIX, parseHeroDraft, type HeroPlacement, type HeroPlacements } from "./heroEditorModel";

const sceneSelector = '[data-ui="home.hero.nonMobileScene"]';
const labels: Readonly<Record<string, string>> = {
  1: "Антибиотики", 2: "АТФ", 3: "Остеология", 4: "Гистология", 5: "Развитие ЖКТ",
  6: "Эндокринная система", 7: "Ферменты", 8: "Анатомия живота", 9: "Пищеварительная система",
};
type Session = { width: number; defaults: HeroPlacements; cards: HeroPlacements; message: string };

function Editor({ width }: { width: number }) {
  const [session, setSession] = useState<Session | null>(null);
  const [selected, setSelected] = useState("3");
  const [collapsed, setCollapsed] = useState(false);
  const [exportText, setExportText] = useState("");
  const [target, setTarget] = useState<"card" | "shadow">("card");
  const panelRef = useRef<HTMLElement>(null);
  const dragRef = useRef<{ pointerId: number; x: number; y: number; left: number; top: number } | null>(null);

  // Cleanup runs before capturing the next width, so no edited pixel values leak
  // into the responsive CSS baseline. The server-rendered scene remains intact.
  useLayoutEffect(() => {
    const scene = document.querySelector<HTMLElement>(sceneSelector);
    if (!scene) return;
    const origin = scene.getBoundingClientRect();
    const elements = [...scene.querySelectorAll<HTMLElement>("[data-card]")].filter(el => getComputedStyle(el).display !== "none");
    const sources = [...scene.querySelectorAll("source")].map(el => ({ el, media: el.media, sizes: el.sizes }));
    const images = [...scene.querySelectorAll("img")].map(el => ({ el, sizes: el.sizes }));
    const originals = elements.map(el => ({ el, left: el.style.left, right: el.style.right, top: el.style.top, width: el.style.width, shadowX: el.style.getPropertyValue("--hero-card-shadow-x"), shadowY: el.style.getPropertyValue("--hero-card-shadow-y") }));
    scene.dataset.editing = "true";
    const defaults = Object.fromEntries(elements.map(el => {
      const rect = el.getBoundingClientRect();
      const style = getComputedStyle(el);
      return [el.dataset.card!, { x: rect.left - origin.left, y: rect.top - origin.top, width: rect.width,
        shadowX: parseFloat(style.getPropertyValue("--hero-card-shadow-x")) || 0,
        shadowY: parseFloat(style.getPropertyValue("--hero-card-shadow-y")) || 0 }];
    }));
    let message = "";
    let cards: HeroPlacements = defaults;
    try {
      const raw = localStorage.getItem(`${HERO_EDITOR_STORAGE_PREFIX}.${width}`);
      const saved = parseHeroDraft(raw, width, defaults);
      cards = saved ? { ...defaults, ...saved } : defaults;
      if (raw && cards === defaults) message = "Черновик повреждён. Загружены исходные позиции.";
    } catch { message = "Хранилище браузера недоступно. Используйте экспорт."; }
    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) {
        setSession({ width, defaults, cards, message });
        setSelected(current => cards[current] ? current : "3");
        setExportText("");
      }
    });
    const select = (event: MouseEvent) => {
      const el = event.target instanceof Element ? event.target.closest<HTMLElement>("[data-card]") : null;
      if (el?.dataset.card && defaults[el.dataset.card]) { setSelected(el.dataset.card); panelRef.current?.focus({ preventScroll: true }); }
    };
    scene.addEventListener("click", select);
    return () => {
      cancelled = true;
      delete scene.dataset.editing;
      for (const { el, media, sizes } of sources) { el.media = media; el.sizes = sizes; }
      for (const { el, sizes } of images) el.sizes = sizes;
      scene.removeEventListener("click", select);
      for (const original of originals) {
        Object.assign(original.el.style, { left: original.left, right: original.right, top: original.top, width: original.width });
        for (const [property, value] of [["--hero-card-shadow-x", original.shadowX], ["--hero-card-shadow-y", original.shadowY]]) {
          if (value) original.el.style.setProperty(property, value);
          else original.el.style.removeProperty(property);
        }
        delete original.el.dataset.selected;
      }
    };
  }, [width]);

  useLayoutEffect(() => {
    if (session?.width !== width) return;
    for (const [id, card] of Object.entries(session.cards)) {
      const el = document.querySelector<HTMLElement>(`${sceneSelector} [data-card="${id}"]`);
      if (!el) continue;
      Object.assign(el.style, { left: `${card.x}px`, right: "auto", top: `${card.y}px`, width: `${card.width}px` });
      el.style.setProperty("--hero-card-shadow-x", `${card.shadowX ?? 0}px`);
      el.style.setProperty("--hero-card-shadow-y", `${card.shadowY ?? 0}px`);
      for (const source of el.querySelectorAll("source")) source.sizes = `${card.width}px`;
      const image = el.querySelector("img");
      if (image) image.sizes = `${card.width}px`;
      el.dataset.selected = String(id === selected);
    }
  }, [session, selected, width]);

  useEffect(() => {
    if (session?.width !== width) return;
    try {
      localStorage.setItem(`${HERO_EDITOR_STORAGE_PREFIX}.${width}`, JSON.stringify({ version: 1, viewport: { width, height: innerHeight }, cards: session.cards }));
    } catch {
      let cancelled = false;
      queueMicrotask(() => { if (!cancelled) setSession(current => current ? { ...current, message: "Не удалось сохранить черновик. Используйте экспорт." } : current); });
      return () => { cancelled = true; };
    }
  }, [session?.cards, session?.width, width]);

  function adjust(action: string, step = 1) {
    setExportText("");
    setSession(current => current?.cards[selected] ? { ...current, cards: { ...current.cards, [selected]: adjustHeroPlacement(current.cards[selected], action, step, target) } } : current);
  }

  useEffect(() => {
    function keydown(event: KeyboardEvent) {
      if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey ||
          (event.target instanceof Element && event.target.closest('input,textarea,select,[contenteditable]:not([contenteditable="false"]),[role="dialog"]'))) return;
      const action = event.key === "=" ? "+" : event.key;
      if (!["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown", "+", "-"].includes(action)) return;
      event.preventDefault();
      setExportText("");
      setSession(current => current?.cards[selected] ? { ...current, cards: { ...current.cards, [selected]: adjustHeroPlacement(current.cards[selected], action, event.shiftKey ? 10 : 1, target) } } : current);
    }
    window.addEventListener("keydown", keydown);
    return () => window.removeEventListener("keydown", keydown);
  }, [selected, target]);

  function movePanel(left: number, top: number) {
    const panel = panelRef.current;
    if (!panel) return;
    const position = clampPanelPosition(left, top, panel.offsetWidth, panel.offsetHeight, document.documentElement.clientWidth, innerHeight);
    Object.assign(panel.style, { left: `${position.left}px`, top: `${position.top}px`, bottom: "auto" });
  }

  const ready = session?.width === width;
  useEffect(() => {
    const panel = panelRef.current;
    if (!panel) return;
    const keepOnScreen = () => { const rect = panel.getBoundingClientRect(); movePanel(rect.left, rect.top); };
    const observer = new ResizeObserver(keepOnScreen);
    observer.observe(panel);
    window.addEventListener("resize", keepOnScreen);
    return () => { observer.disconnect(); window.removeEventListener("resize", keepOnScreen); };
  }, [ready]);

  if (!ready || !session.cards[selected]) return null;
  const placement = session.cards[selected];
  function change(field: keyof HeroPlacement, value: number) {
    if (!Number.isFinite(value)) return;
    setExportText("");
    setSession(current => current ? { ...current, cards: { ...current.cards, [selected]: { ...current.cards[selected], [field]: boundedHeroValue(field, value) } } } : current);
  }
  async function exportDraft() {
    if (!session) return;
    const scene = document.querySelector(sceneSelector)?.getBoundingClientRect();
    const json = JSON.stringify({ version: 1, set: "non-mobile", anchor: "scene-top-left", viewport: { width, height: innerHeight }, scene: { width: scene?.width, height: scene?.height }, cards: session.cards }, null, 2);
    setExportText(json);
    let message = "Композиция скопирована. Пришлите JSON для фиксации в коде.";
    try { await navigator.clipboard.writeText(json); }
    catch { message = "Скопируйте JSON из поля ниже."; }
    setSession(current => current ? { ...current, message } : current);
  }

  return <aside ref={panelRef} tabIndex={-1} className="home-hero-editor flex flex-col gap-3 rounded-[var(--radius-card)] border bg-white p-4 text-sm" aria-label="Настройка карточек первого экрана" data-ui="home.hero.editor">
    <div className="flex items-center justify-between gap-2">
      <Button fullWidth={false} variant="ghost" className="home-hero-editor__handle" aria-label="Переместить панель" title="Перетащите панель или двигайте стрелками при фокусе на рукоятке"
        onPointerDown={event => {
          if (event.button !== 0) return;
          const rect = panelRef.current?.getBoundingClientRect();
          if (!rect) return;
          dragRef.current = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, left: rect.left, top: rect.top };
          event.currentTarget.setPointerCapture(event.pointerId);
        }}
        onPointerMove={event => { const drag = dragRef.current; if (drag?.pointerId === event.pointerId) movePanel(drag.left + event.clientX - drag.x, drag.top + event.clientY - drag.y); }}
        onPointerUp={() => { dragRef.current = null; }} onPointerCancel={() => { dragRef.current = null; }} onLostPointerCapture={() => { dragRef.current = null; }}
        onKeyDown={event => {
          if (!["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) return;
          event.preventDefault(); event.stopPropagation();
          const rect = panelRef.current?.getBoundingClientRect();
          if (!rect) return;
          const step = event.shiftKey ? 40 : 10;
          movePanel(rect.left + (event.key === "ArrowLeft" ? -step : event.key === "ArrowRight" ? step : 0), rect.top + (event.key === "ArrowUp" ? -step : event.key === "ArrowDown" ? step : 0));
        }}
      >⠿ Композиция · {width} px</Button>
      <Button fullWidth={false} variant="secondary" aria-expanded={!collapsed} onClick={() => setCollapsed(value => !value)}>{collapsed ? "Открыть" : "Свернуть"}</Button>
    </div>
    <div className="flex flex-col gap-3" hidden={collapsed} style={collapsed ? { display: "none" } : undefined}>
      <label className="flex flex-col gap-1">Карточка
        <select className="ui-input rounded border px-2 py-1" value={selected} onChange={event => { setSelected(event.target.value); panelRef.current?.focus({ preventScroll: true }); }}>
          {Object.keys(session.cards).map(id => <option key={id} value={id}>{id} · {labels[id]}</option>)}
        </select>
      </label>
      <label className="flex flex-col gap-1">Перемещать
        <select className="ui-input rounded border px-2 py-1" value={target} onChange={event => { setTarget(event.currentTarget.value === "shadow" ? "shadow" : "card"); panelRef.current?.focus({ preventScroll: true }); }}>
          <option value="card">Карточку</option>
          <option value="shadow">Тень</option>
        </select>
      </label>
      <div className="grid grid-cols-3 gap-2">
        {(["x", "y", "width"] as const).map(field => <label key={field} className="flex min-w-0 flex-col gap-1">
          {field === "width" ? "Ширина" : field.toUpperCase()}
          <input className="ui-input min-w-0 rounded border px-2 py-1" aria-label={`${field === "width" ? "Ширина" : field.toUpperCase()} карточки`} type="number" step="any" min={field === "width" ? 1 : -10000} max={field === "width" ? 5000 : 10000} value={placement[field]} onChange={event => change(field, event.currentTarget.valueAsNumber)} />
        </label>)}
      </div>
      <div className="grid grid-cols-2 gap-2">
        {(["shadowX", "shadowY"] as const).map(field => <label key={field} className="flex min-w-0 flex-col gap-1">Тень {field === "shadowX" ? "X" : "Y"}
          <input className="ui-input min-w-0 rounded border px-2 py-1" aria-label={`Смещение тени ${field === "shadowX" ? "X" : "Y"}`} type="number" step="any" min={-10000} max={10000} value={placement[field] ?? 0} onChange={event => change(field, event.currentTarget.valueAsNumber)} />
        </label>)}
      </div>
      <div className="flex flex-wrap gap-2">
        {([["ArrowLeft", "←", "Влево"], ["ArrowUp", "↑", "Вверх"], ["ArrowDown", "↓", "Вниз"], ["ArrowRight", "→", "Вправо"], ["-", "−", "Уменьшить"], ["+", "+", "Увеличить"]] as const).map(([action, text, label]) =>
          <Button key={action} fullWidth={false} variant="secondary" aria-label={label} onClick={event => adjust(action, event.shiftKey ? 10 : 1)}>{text}</Button>)}
      </div>
      <p>Стрелки двигают {target === "shadow" ? "тень относительно карточки" : "карточку"}, −/+ меняют размер карточки. Шаг 1 px, с Shift — 10 px. X/Y карточки — от левого верхнего угла сцены. Пропорции сохраняются.</p>
      <div className="flex flex-wrap gap-2">
        <Button fullWidth={false} onClick={exportDraft}>Копировать композицию</Button>
        <Button fullWidth={false} variant="secondary" onClick={() => { setExportText(""); setSession(current => current ? { ...current, cards: { ...current.cards, [selected]: current.defaults[selected] } } : current); }}>Сбросить карточку</Button>
        <Button fullWidth={false} variant="secondary" onClick={() => { setExportText(""); setSession(current => current ? { ...current, cards: current.defaults } : current); }}>Сбросить всё</Button>
      </div>
      <p>Черновик сохраняется отдельно для ширины {width} px и применяется только в редакторе.</p>
      {session.message ? <p role="status">{session.message}</p> : null}
      {exportText ? <label className="flex flex-col gap-1">JSON композиции<textarea className="ui-input rounded border px-2 py-1" readOnly rows={5} value={exportText} onFocus={event => event.target.select()} /></label> : null}
    </div>
  </aside>;
}

function EditorGate() {
  const searchParams = useSearchParams();
  const [width, setWidth] = useState<number | null>(null);
  useEffect(() => {
    if (process.env.NODE_ENV !== "development") return;
    const update = () => setWidth(innerWidth >= 770 ? innerWidth : null);
    const frame = requestAnimationFrame(update);
    window.addEventListener("resize", update);
    return () => { cancelAnimationFrame(frame); window.removeEventListener("resize", update); };
  }, []);
  return process.env.NODE_ENV === "development" && searchParams.get("hero-editor") === "1" && width !== null ? <Editor width={width} /> : null;
}

export default function HomeHeroEditor() {
  return <Suspense fallback={null}><EditorGate /></Suspense>;
}
