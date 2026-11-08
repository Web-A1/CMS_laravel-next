import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const buttonCssSource = await readFile(new URL("./button.css", import.meta.url), "utf8");
const buttonSource = await readFile(new URL("./Button.tsx", import.meta.url), "utf8");

test("shared full-width buttons stay mobile-first and compact above mobile", () => {
  assert.match(buttonSource, /fullWidth = true/);
  assert.match(buttonCssSource, /\.button--full \{[\s\S]*?max-width: 100%;[\s\S]*?width: 100%;/);
  assert.match(buttonCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.button--full \{[\s\S]*?align-self: start;/);
  assert.match(buttonCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.button--full \{[\s\S]*?justify-self: start;/);
  assert.match(buttonCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.button--full \{[\s\S]*?width: auto;/);
});
