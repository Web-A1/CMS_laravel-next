import assert from "node:assert/strict";
import test from "node:test";
const modulePath = "./articlePresentation.ts";
const { articleIntroduction }: typeof import("./articlePresentation") = await import(modulePath);

test("catalog excerpts do not repeat or truncate the beginning of the document", () => {
  assert.equal(articleIntroduction({ excerpt: "Наружный слой", body: "<h2>Наружный <strong>слой</strong></h2><p>Продолжение.</p>", bodyFormat: "html" }), null);
  assert.equal(articleIntroduction({ excerpt: "А & Б < 2", body: "<p>А &amp; Б &lt; &#50;.</p>", bodyFormat: "html" }), null);
  assert.equal(articleIntroduction({ excerpt: "H₂O и pH", body: "<p>H&#x2082;O&nbsp;и pH.</p>", bodyFormat: "html" }), null);
  assert.equal(articleIntroduction({ excerpt: "Начало", body: "Начало\n\nтекста", bodyFormat: "text" }), null);
});

test("an independently written introduction stays visible without editing the body", () => {
  assert.equal(articleIntroduction({ excerpt: "Разберём строение железы.", body: "<h2>Что такое семенные пузырьки</h2><p>Учебный текст.</p>", bodyFormat: "html" }), "Разберём строение железы.");
  assert.equal(articleIntroduction({ excerpt: null, body: "<p>Текст</p>", bodyFormat: "html" }), null);
});
