import assert from "node:assert/strict";
import test from "node:test";
const modulePath = "./articleMediaRedirect.ts";
const { articleMediaRedirect, isArticleMediaPath }: typeof import("./articleMediaRedirect") = await import(modulePath);

test("article media redirects only to the exact configured object", () => {
  const filename = `${"a".repeat(64)}.png`;
  const base = "https://media.example.test";
  const target = `${base}/article-media/${filename}`;
  assert.equal(articleMediaRedirect(target, filename, base), target);
  for (const location of ["https://evil.test/file.png", `${target}?token=secret`, `${target}#fragment`, target.replace("https:", "http:")]) {
    assert.equal(articleMediaRedirect(location, filename, base), null);
  }
  assert.equal(articleMediaRedirect(target, filename, undefined), null);
  assert.equal(articleMediaRedirect(target, "../file.png", base), null);
  assert.equal(articleMediaRedirect(target, filename, "https://user:pass@media.example.test"), null);
});

test("allows registered variant path shapes without permitting arbitrary nested storage", () => {
  const path = `variants/${"a".repeat(64)}/v1/640.avif`;
  const base = "https://media.example.test";
  const target = `${base}/article-media/${path}`;
  assert.equal(isArticleMediaPath(path), true);
  assert.equal(articleMediaRedirect(target, path, base), target);
  for (const invalid of [path.replace("/v1/", "/../"), path.replace("/v1/", "/v2/"), path.replace("640.avif", "0.avif"), path.replace("640.avif", "640.svg"), `../${path}`, `${path}?x=1`]) {
    assert.equal(isArticleMediaPath(invalid), false);
    assert.equal(articleMediaRedirect(target, invalid, base), null);
  }
});
