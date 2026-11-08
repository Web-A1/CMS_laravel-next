"use client";

import { useState } from "react";
import Button, { ButtonLink, ButtonRouteLink, type ButtonSize, type ButtonVariant } from "./Button";
import ButtonArrow from "./ButtonArrow";
import CloseButton from "./CloseButton";
import ContactChannelButton from "./ContactChannelButton";

const roles: ReadonlyArray<{ variant: ButtonVariant; label: string }> = [
  { variant: "primary", label: "Основная" }, { variant: "secondary", label: "Вторичная" },
  { variant: "accent", label: "Лайм" }, { variant: "white", label: "Белая" },
];

export default function ButtonSystemPreview() {
  const [size, setSize] = useState<ButtonSize>("md");
  const [busy, setBusy] = useState(false);
  const [submissions, setSubmissions] = useState(0);
  return <div className="grid gap-8" data-ui="dev.buttons">
    <header className="grid gap-3">
      <h1 className="text-2xl font-bold">Система кнопок</h1>
      <p className="text-sm ui-text-secondary">Рабочие компоненты сайта. Наведение и нажатие проверяются курсором, фокус — клавишей Tab.</p>
      <label className="text-sm">Размер <select className="ui-input rounded border px-2 py-1" value={size} onChange={event => setSize(event.target.value === "sm" ? "sm" : "md")} data-ui="dev.buttons.size"><option value="sm">Компактный</option><option value="md">Обычный</option></select></label>
    </header>
    <section className="grid gap-5" aria-label="Цветовые роли">
      {roles.map(({ variant, label }) => <div key={variant} className="flex flex-wrap items-center gap-4 rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] p-4">
        <h2 className="w-24 text-sm font-bold">{label}</h2>
        <Button type="button" variant={variant} size={size} fullWidth={false} data-ui={`dev.buttons.${variant}.normal`}>Продолжить <ButtonArrow /></Button>
        <Button type="button" variant={variant} size={size} fullWidth={false} disabled>Недоступна</Button>
        <Button type="button" variant={variant} size={size} fullWidth={false} loading>Продолжить</Button>
      </div>)}
    </section>
    <section id="channels" className="flex flex-wrap items-center gap-4 rounded-[var(--radius-surface)] bg-[var(--color-brand)] p-6" aria-label="Telegram и MAX">
      <ContactChannelButton channel="telegram" label="Telegram" href="#channels" surface="brand" size={size} />
      <ContactChannelButton channel="max" label="MAX" href="#channels" surface="brand" size={size} />
    </section>
    <section className="grid gap-4" aria-label="Служебные действия">
      <h2 className="text-lg font-bold">Служебные действия и стрелки</h2>
      <div className="flex flex-wrap items-center gap-4">
        <CloseButton /> <CloseButton backdrop="translucent" />
        <div className="rounded-[var(--radius-surface)] bg-[var(--color-surface-ink)] p-3">
          <CloseButton data-surface="dark" aria-label="Закрыть на тёмном фоне" />
        </div>
        <Button variant="utility" size={size} iconOnly aria-label="Наверх"><ButtonArrow direction="up" chevron /></Button>
        <Button variant="glass" size={size} iconOnly aria-label="Сравнить материалы"><ButtonArrow direction="right" chevron /></Button>
        {(["left", "right", "up", "down"] as const).map(direction => <Button key={direction} variant="secondary" fullWidth={false} size={size} data-ui={`dev.buttons.arrow.${direction}`}><ButtonArrow direction={direction} />{direction}</Button>)}
      </div>
    </section>
    <section className="grid max-w-lg gap-4" aria-label="Загрузка и длинная подпись">
      <h2 className="text-lg font-bold">Загрузка и геометрия</h2>
      <form className="flex flex-wrap items-center gap-4" onSubmit={event => { event.preventDefault(); setSubmissions(value => value + 1); setBusy(true); }}>
        <Button type="submit" size={size} fullWidth={false} loading={busy} disabled={busy} data-ui="dev.buttons.submit">{busy ? "Сохранение изменений — пожалуйста, дождитесь завершения операции" : "Сохранить изменения"}</Button>
        <Button type="button" variant="secondary" size={size} fullWidth={false} onClick={() => setBusy(false)}>Завершить проверку</Button>
        <output className="text-sm" data-ui="dev.buttons.submissions">Отправок: {submissions}</output>
      </form>
      <Button size={size} fullWidth={false} data-ui="dev.buttons.long">Подобрать бесплатный урок по анатомии и начать подготовку к экзамену</Button>
      <div className="flex flex-wrap gap-4">
        <ButtonRouteLink href="/" size={size} fullWidth={false}>На главную <ButtonArrow direction="left" /></ButtonRouteLink>
        <ButtonLink href="#channels" variant="secondary" size={size} fullWidth={false}>Обычная ссылка</ButtonLink>
        <ButtonRouteLink href="/" size={size} fullWidth={false} disabled data-ui="dev.buttons.disabledRoute">Недоступный переход</ButtonRouteLink>
        <ButtonLink href="#channels" size={size} fullWidth={false} disabled data-ui="dev.buttons.disabledLink">Недоступная ссылка</ButtonLink>
      </div>
    </section>
  </div>;
}
