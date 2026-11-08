import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const componentSource = await readFile(new URL("./SectionTitle.tsx", import.meta.url), "utf8");
const styles = await readFile(new URL("./content.css", import.meta.url), "utf8");

test("SectionTitle owns the on-dark heading role", () => {
  assert.match(componentSource, /tone\?: "default" \| "on-dark"/);
  assert.match(componentSource, /ui-section-title--on-dark/);
  assert.match(styles, /\.ui-section-title--on-dark \{[\s\S]*color: #fff;/);
  assert.match(styles, /\.ui-section-title--on-dark span \{[\s\S]*color: var\(--color-brand-accent-lime\);/);
});
