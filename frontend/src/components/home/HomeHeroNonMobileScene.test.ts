import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import ts from "typescript";
import type HomeHeroNonMobileScene from "./HomeHeroNonMobileScene";
import media from "./heroNonMobileMedia.json" with { type: "json" };
import shadows from "./heroNonMobileShadows.json" with { type: "json" };

const output = ts.transpileModule(readFileSync(new URL("./HomeHeroNonMobileScene.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX, esModuleInterop: true },
}).outputText;
const sandbox = { exports: {} as { default: typeof HomeHeroNonMobileScene } };
const generatedOutput = ts.transpileModule(readFileSync(new URL("../content/GeneratedImage.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
}).outputText;
const generated = { exports: {} };
new Function("require", "exports", generatedOutput)(createRequire(import.meta.url), generated.exports);
const localRequire = createRequire(import.meta.url);
const sharedMotion = await import(new URL("./homeMotion.ts", import.meta.url).href);
new Function("require", "exports", output)((id: string) => id === "@/components/content/GeneratedImage" ? generated.exports : id === "./homeMotion" ? sharedMotion : localRequire(id), sandbox.exports);

test("nine cards and pre-rendered shadows use AVIF/WebP pairs with matching media gates", () => {
  const html = renderToStaticMarkup(React.createElement(sandbox.exports.default));
  assert.equal(html.match(/<img /g)?.length, 18);
  assert.equal(html.match(/<img src="data:image\/svg\+xml,/g)?.length, 18);
  assert.equal(html.match(/media="\(width &gt;= 770px\)"/g)?.length, 20);
  assert.equal(html.match(/media="\(width &gt; 770px\)"/g)?.length, 4);
  assert.equal(html.match(/media="\(min-width: 64rem\)"/g)?.length, 12);
  assert.equal(html.match(/data-tablet-visible="false"/g)?.length, 3);
  assert.ok(html.includes("calc(50% + -881.5px + var(--hero-tablet-travel) * 0 + var(--hero-desktop-travel) * 0)"));
  assert.equal(html.match(/type="image\/avif"/g)?.length, 18);
  assert.equal(html.match(/type="image\/webp"/g)?.length, 18);
  assert.equal(html.match(/class="home-hero__float-shadow"/g)?.length, 9);
  assert.ok(!html.includes(".png"));
  assert.match(html, /aria-hidden="true"/);
  for (const image of Object.values(media)) {
    assert.equal(image.variants.length, 2);
    for (const variant of image.variants) {
      for (const file of [variant.avifSrc, variant.webpSrc]) {
        assert.ok(html.includes(`${file} ${variant.width}w`));
        assert.ok(readFileSync(new URL(`../../../public${file}`, import.meta.url)).length > 0);
      }
    }
  }
  for (const shadow of Object.values(shadows)) {
    assert.ok(shadow.paddingX > 0 && shadow.paddingY > 0);
    assert.ok(shadow.width > 0 && shadow.height > 0);
    for (const file of [shadow.avifSrc, shadow.webpSrc]) {
      assert.ok(html.includes(file));
      assert.ok(readFileSync(new URL(`../../../public${file}`, import.meta.url)).length > 0);
    }
  }
});
