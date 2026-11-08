import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

test("paid material destinations wrap within the mobile checkout width", async () => {
  const source = await readFile(new URL("./CheckoutFlow.tsx", import.meta.url), "utf8");
  assert.match(source, /className="mx-auto flex min-w-0 w-full flex-col gap-4"/);
  assert.match(source, /<TextLink[^>]+className="min-w-0 max-w-full \[&>span\]:min-w-0 \[&>span\]:whitespace-normal! \[&>span\]:break-words"[^>]+data-ui="checkout\.accessCard\.atom"/);
});

test("job redirect retains exact301 and relative same-origin destination", async () => {
  const path = "../../app/(site)/job.html/route.ts";
  const route: typeof import("../../app/(site)/job.html/route") = await import(path);
  const response = route.GET();
  assert.equal(response.status, 301);
  assert.equal(response.headers.get("Location"), "/");
});
