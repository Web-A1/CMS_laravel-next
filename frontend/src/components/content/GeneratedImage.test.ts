import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import ts from "typescript";
import type { StaticGeneratedImageAsset } from "./GeneratedImage";

const require = createRequire(import.meta.url);
const output = ts.transpileModule(readFileSync(new URL("./GeneratedImage.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
}).outputText;
const sandbox = { exports: {} as typeof import("./GeneratedImage") };
new Function("require", "exports", output)(require, sandbox.exports);
const image: StaticGeneratedImageAsset = { avifSrc: "/large.avif", webpSrc: "/large.webp", width: 1254, height: 1254 };
const variants = [
  { avifSrc: "/small.avif", webpSrc: "/small.webp", width: 384, height: 384 },
  image,
];

test("single-resolution consumers retain AVIF selection, WebP fallback and lazy intrinsic dimensions", () => {
  const html = renderToStaticMarkup(React.createElement(sandbox.exports.default, { image, alt: "Портрет" }));
  assert.equal(html, '<picture><source srcSet="/large.avif" type="image/avif"/><img src="/large.webp" alt="Портрет" width="1254" height="1254" loading="lazy" decoding="async"/></picture>');
});

test("media-limited images offer both codecs only within the query and use an inline fallback", () => {
  const html = renderToStaticMarkup(React.createElement(sandbox.exports.default, {
    image: { ...image, variants }, media: "(max-width: 480px)", alt: "", loading: "eager", fetchPriority: "high",
  }));
  assert.equal(html.match(/media="\(max-width: 480px\)"/g)?.length, 2);
  assert.match(html, /<img src="data:image\/svg\+xml,/);
  assert.doesNotMatch(html, /<img[^>]*srcSet=/);
  assert.match(html, /type="image\/webp"/);
});

test("resolution variants offer both codecs the same width descriptors and effective slot size", () => {
  const html = renderToStaticMarkup(React.createElement(sandbox.exports.default, {
    image: { ...image, variants }, sizes: "(min-width: 64rem) 305px, 436px", alt: "",
  }));
  assert.match(html, /<source srcSet="\/small.avif 384w, \/large.avif 1254w" sizes="\(min-width: 64rem\) 305px, 436px" type="image\/avif"/);
  assert.match(html, /<img src="\/large.webp" srcSet="\/small.webp 384w, \/large.webp 1254w" sizes="\(min-width: 64rem\) 305px, 436px" alt=""/);
  assert.equal(html.match(/<img /g)?.length, 1);
});

test("art direction retains media-specific dimensions and codec order with resolution variants", () => {
  const html = renderToStaticMarkup(React.createElement(sandbox.exports.default, {
    image, responsiveSources: [{ media: "(min-width: 64rem)", image: { ...image, variants } }],
    sizes: "50vw", alt: "", loading: "eager", fetchPriority: "high",
  }));
  assert.ok(html.indexOf('type="image/avif" width="1254"') < html.indexOf('type="image/webp" width="1254"'));
  assert.equal(html.match(/media="\(min-width: 64rem\)"/g)?.length, 2);
  assert.match(html, /loading="eager" decoding="async" fetchPriority="high"/);
  assert.equal(html.match(/<img /g)?.length, 1);
});

test("shared sources can precede legacy picture fallbacks without adding another image", () => {
  const html = renderToStaticMarkup(React.createElement("picture", null,
    React.createElement(sandbox.exports.GeneratedImageSources, {
      image: { ...image, variants }, media: "(min-width: 768px)", sizes: "1150px",
    }),
    React.createElement("source", { srcSet: "/legacy.png" }),
    React.createElement("img", { src: "/portrait.png", alt: "Материал" }),
  ));
  assert.ok(html.indexOf('type="image/avif"') < html.indexOf('type="image/webp"'));
  assert.ok(html.indexOf('type="image/webp"') < html.indexOf('srcSet="/legacy.png"'));
  assert.equal(html.match(/media="\(min-width: 768px\)"/g)?.length, 2);
  assert.equal(html.match(/srcSet="\/small\.(?:avif|webp) 384w, \/large\.(?:avif|webp) 1254w"/g)?.length, 2);
  assert.equal(html.match(/sizes="1150px"/g)?.length, 2);
  assert.equal(html.match(/<img /g)?.length, 1);
});
