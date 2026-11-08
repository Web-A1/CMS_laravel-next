"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import Button from "@/components/button/Button";
import { FormError } from "@/components/form/FormError";
import { FormStatus } from "@/components/form/FormStatus";
import { saveAccountProfileName } from "@/lib/accountProfileApi";
import { notifyClientAccountProfileUpdated } from "@/lib/clientAccount";

export default function AccountNameForm({ initialName, canUpdate }: {
  readonly initialName: string;
  readonly canUpdate: boolean;
}) {
  const router = useRouter();
  const [name, setName] = useState(initialName);
  const [savedName, setSavedName] = useState(initialName);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [nameError, setNameError] = useState("");
  const [notice, setNotice] = useState("");

  async function save(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (!canUpdate || busy || name === savedName) return;
    setBusy(true);
    setError("");
    setNameError("");
    setNotice("");
    const result = await saveAccountProfileName(name);
    if (result.status === "ready") {
      setName(result.profile.name);
      setSavedName(result.profile.name);
      setNotice("Имя сохранено.");
      notifyClientAccountProfileUpdated(result.profile);
      router.refresh();
    } else {
      setError(result.message);
      setNameError(result.nameError);
    }
    setBusy(false);
  }

  return (
    <form onSubmit={save} aria-busy={busy} className="max-w-xl" data-ui="lk.profileSummary.nameForm" data-state={busy ? "loading" : error ? "error" : notice ? "success" : "idle"}>
      <label htmlFor="lk-profile-name" className="mb-2 block text-sm font-medium">Имя</label>
      <input id="lk-profile-name" type="text" autoComplete="name" required maxLength={255}
        className="ui-input h-12 w-full rounded-[var(--radius-control)] border px-4 text-base outline-none disabled:cursor-not-allowed disabled:opacity-70"
        value={name} disabled={!canUpdate || busy} aria-invalid={!!nameError}
        aria-describedby={nameError ? "lk-profile-name-error" : undefined}
        data-ui="lk.profileSummary.nameInput"
        onChange={event => { setName(event.target.value); setError(""); setNameError(""); setNotice(""); }}
      />
      <FormError id="lk-profile-name-error" className="mt-2 px-0">{nameError}</FormError>
      {!canUpdate ? <FormStatus className="mt-3 px-0">Имя доступно только для просмотра.</FormStatus> : null}
      <Button type="submit" fullWidth={false} className="mt-5" loading={busy}
        disabled={!canUpdate || busy || name === savedName} data-ui="lk.profileSummary.nameSaveButton">
        {busy ? "Сохраняем…" : "Сохранить"}
      </Button>
      <FormError className="mt-3 px-0" dataUi="lk.profileSummary.nameError">{error}</FormError>
      <FormStatus className="mt-3 px-0" tone="success" dataUi="lk.profileSummary.nameNotice">{notice}</FormStatus>
    </form>
  );
}
