"use client";

import { useEffect, useRef, useState, type CSSProperties } from "react";
import Button from "@/components/button/Button";
import { FormError } from "@/components/form/FormError";
import "./SupportVoicePlayer.css";

type Props = { url: string; seconds: number; disabled?: boolean; invalid?: boolean; describedBy?: string };
const finiteTime = (value: number) => Number.isFinite(value) && value > 0 ? value : 0;
const timestamp = (value: number) => `${Math.floor(value / 60)}:${String(Math.floor(value % 60)).padStart(2, "0")}`;

export default function SupportVoicePlayer({ url, seconds, disabled = false, invalid, describedBy }: Props) {
  const audioRef = useRef<HTMLAudioElement>(null);
  const active = useRef(true);
  const attempt = useRef(0);
  const [playing, setPlaying] = useState(false);
  const [starting, setStarting] = useState(false);
  const [position, setPosition] = useState(0);
  const [duration, setDuration] = useState(0);
  const [error, setError] = useState("");
  const total = finiteTime(duration) || finiteTime(seconds);
  const current = Math.min(finiteTime(position), total);

  useEffect(() => {
    active.current = true;
    const audio = audioRef.current;
    const requestCounter = attempt;
    return () => { active.current = false; requestCounter.current++; audio?.pause(); };
  }, [url]);

  function synchronize(audio: HTMLAudioElement) {
    setDuration(finiteTime(audio.duration));
    setPosition(finiteTime(audio.currentTime));
    setPlaying(!audio.paused && !audio.ended);
  }

  async function toggle() {
    const audio = audioRef.current;
    if (!audio || disabled || starting) return;
    setError("");
    if (!audio.paused) { attempt.current++; audio.pause(); return; }
    const request = ++attempt.current;
    setStarting(true);
    try { await audio.play(); }
    catch {
      if (active.current && request === attempt.current) {
        setPlaying(false);
        setError("Не удалось воспроизвести запись. Попробуйте ещё раз.");
      }
    } finally {
      if (active.current && request === attempt.current) setStarting(false);
    }
  }

  return <div className="min-w-0 flex-1 space-y-1" data-ui="support.voice.player">
    <audio ref={audioRef} src={url} preload="auto" aria-label="Голосовое сообщение" className="hidden"
      onLoadedMetadata={(event) => synchronize(event.currentTarget)}
      onDurationChange={(event) => synchronize(event.currentTarget)}
      onTimeUpdate={(event) => synchronize(event.currentTarget)}
      onPlay={(event) => synchronize(event.currentTarget)}
      onPause={(event) => synchronize(event.currentTarget)}
      onEnded={(event) => synchronize(event.currentTarget)}
      onError={() => { setPlaying(false); setStarting(false); setError("Не удалось прочитать запись. Попробуйте записать сообщение ещё раз."); }} />
    <div className="flex min-w-0 items-center gap-2">
      <Button type="button" variant="utility" size="sm" iconOnly disabled={disabled || starting} aria-label={playing ? "Приостановить голосовое сообщение" : "Воспроизвести голосовое сообщение"} data-ui="support.voice.play" onClick={toggle}>
        <svg className="size-4 ui-text-brand" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">{playing ? <path d="M7 5h4v14H7zm6 0h4v14h-4z" /> : <path d="M8 4v16l12-8z" />}</svg>
      </Button>
      <input type="range" min={0} max={total || 1} step={0.01} value={current} disabled={disabled || total === 0} aria-label="Позиция голосового сообщения" aria-valuetext={`${timestamp(current)} из ${timestamp(total)}`} aria-invalid={invalid} aria-describedby={error ? "support-voice-playback-error" : describedBy}
        className="support-voice-player__seek h-8 min-w-0 flex-1 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand-ink)] disabled:cursor-default" data-ui="support.voice.seek"
        style={{ "--voice-progress": `${total ? current / total * 100 : 0}%` } as CSSProperties}
        onChange={(event) => {
          const audio = audioRef.current;
          const value = Number(event.currentTarget.value);
          if (!audio || disabled || !Number.isFinite(value)) return;
          try { audio.currentTime = Math.min(Math.max(0, value), total); setPosition(audio.currentTime); }
          catch { setError("Не удалось перемотать запись. Попробуйте ещё раз."); }
        }} />
      <span className="shrink-0 text-xs tabular-nums ui-text-secondary" data-ui="support.voice.time">{timestamp(current)} / {timestamp(total)}</span>
    </div>
    <FormError id="support-voice-playback-error" inset={false}>{error}</FormError>
  </div>;
}
