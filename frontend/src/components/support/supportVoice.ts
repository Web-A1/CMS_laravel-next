export const SUPPORT_VOICE_SECONDS = 180;
export const SUPPORT_VOICE_BYTES = 10 * 1024 * 1024;
// Prefer explicit AAC when available, including Safari's established MP4 path.
const MIME_TYPES = ["audio/mp4;codecs=mp4a.40.2", "audio/mp4", "audio/webm;codecs=opus"];

export type VoiceState = {
  phase: "idle" | "requesting" | "recording" | "stopping" | "ready" | "error";
  seconds: number;
  file?: File;
  url?: string;
  error?: string;
};
export const EMPTY_VOICE: VoiceState = { phase: "idle", seconds: 0 };
export function voiceBusy(state: VoiceState): boolean {
  return state.phase === "requesting" || state.phase === "recording" || state.phase === "stopping";
}

// One modal owns one session. Generation invalidates permission requests after close.
export function createSupportVoice(onChange: (state: VoiceState) => void) {
  let state: VoiceState = EMPTY_VOICE;
  let generation = 0;
  let recorder: MediaRecorder | undefined;
  let stream: MediaStream | undefined;
  let timer: ReturnType<typeof setInterval> | undefined;
  let deadline: ReturnType<typeof setTimeout> | undefined;
  let started = 0;
  let chunks: Blob[] = [];
  let bytes = 0;
  const publish = (next: VoiceState) => { state = next; onChange(next); };
  function release() {
    clearInterval(timer);
    clearTimeout(deadline);
    timer = undefined;
    deadline = undefined;
    if (recorder) {
      recorder.onstart = null;
      recorder.ondataavailable = null;
      recorder.onstop = null;
      recorder.onerror = null;
      if (recorder.state !== "inactive") { try { recorder.stop(); } catch { /* Already stopping. */ } }
      recorder = undefined;
    }
    stream?.getTracks().forEach((track) => track.stop());
    stream = undefined;
    chunks = [];
    bytes = 0;
  }
  function clear(notify = true) {
    generation++;
    release();
    if (state.url) URL.revokeObjectURL(state.url);
    state = EMPTY_VOICE;
    if (notify) onChange(state);
  }
  function fail(message: string) {
    release();
    publish({ phase: "error", seconds: 0, error: message });
  }
  function stop() {
    if (state.phase !== "recording" || !recorder) return;
    clearInterval(timer);
    clearTimeout(deadline);
    publish({ ...state, phase: "stopping", seconds: Math.min(SUPPORT_VOICE_SECONDS, Math.floor((Date.now() - started) / 1000)) });
    try { recorder.stop(); } catch { fail("Не удалось завершить запись. Попробуйте записать ещё раз или напишите сообщение."); }
  }
  async function start() {
    if (voiceBusy(state) || state.file) return;
    clear();
    if (typeof MediaRecorder === "undefined" || typeof MediaRecorder.isTypeSupported !== "function" || typeof navigator === "undefined" || !navigator.mediaDevices?.getUserMedia) {
      fail("Запись голоса недоступна в этом браузере. Напишите сообщение или откройте форму в другом браузере.");
      return;
    }
    const mimeType = MIME_TYPES.find((mime) => MediaRecorder.isTypeSupported(mime));
    if (!mimeType) { fail("Браузер не поддерживает нужный формат записи. Напишите сообщение или используйте другой браузер."); return; }
    const current = generation;
    publish({ phase: "requesting", seconds: 0 });
    try {
      const acquired = await navigator.mediaDevices.getUserMedia({ audio: true });
      if (current !== generation) { acquired.getTracks().forEach((track) => track.stop()); return; }
      stream = acquired;
      recorder = new MediaRecorder(acquired, { mimeType });
      const activeRecorder = recorder;
      recorder.onstart = () => {
        if (current !== generation || recorder !== activeRecorder || state.phase !== "requesting") return;
        started = Date.now();
        publish({ phase: "recording", seconds: 0 });
        timer = setInterval(() => {
          const seconds = Math.min(SUPPORT_VOICE_SECONDS, Math.floor((Date.now() - started) / 1000));
          if (seconds >= SUPPORT_VOICE_SECONDS) stop();
          else publish({ phase: "recording", seconds });
        }, 250);
        deadline = setTimeout(stop, SUPPORT_VOICE_SECONDS * 1000);
      };
      recorder.ondataavailable = (event) => {
        if (current !== generation || !event.data.size) return;
        bytes += event.data.size;
        if (bytes > SUPPORT_VOICE_BYTES) { fail("Запись превышает 10 МиБ. Запишите более короткое сообщение."); return; }
        chunks.push(event.data);
      };
      recorder.onerror = () => fail("Запись прервалась. Попробуйте ещё раз или напишите сообщение.");
      recorder.onstop = () => {
        if (current !== generation) return;
        const blob = new Blob(chunks, { type: activeRecorder.mimeType });
        const seconds = Math.min(SUPPORT_VOICE_SECONDS, Math.floor((Date.now() - started) / 1000));
        release();
        if (!blob.size) { fail("Запись получилась пустой. Попробуйте ещё раз или напишите сообщение."); return; }
        const extension = blob.type.startsWith("audio/webm") ? "webm" : "m4a";
        const file = new File([blob], `voice.${extension}`, { type: blob.type });
        publish({ phase: "ready", seconds, file, url: URL.createObjectURL(file) });
      };
      // Local preview needs the completed recording, not periodic streaming chunks.
      recorder.start();
    } catch (error) {
      if (current !== generation) return;
      fail(error instanceof DOMException && error.name === "NotAllowedError"
        ? "Нет доступа к микрофону. Разрешите его в настройках браузера или напишите сообщение."
        : "Не удалось включить микрофон. Проверьте его подключение и попробуйте ещё раз или напишите сообщение.");
    }
  }
  return { start, stop, clear, getState: () => state };
}
