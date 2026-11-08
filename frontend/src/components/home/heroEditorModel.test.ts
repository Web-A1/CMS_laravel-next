import assert from "node:assert/strict";
import test from "node:test";
const { adjustHeroPlacement, clampPanelPosition, parseHeroDraft } = await import(new URL("./heroEditorModel.ts", import.meta.url).href) as typeof import("./heroEditorModel");

const defaults = { "3": { x: 200, y: 30, width: 400 }, "5": { x: 800, y: 80, width: 240 } };
test("editor movements use pixel steps and preserve other dimensions", () => {
  const card = defaults["3"];
  assert.deepEqual(adjustHeroPlacement(card, "ArrowLeft", 10), { ...card, x: 190 });
  assert.deepEqual(adjustHeroPlacement(card, "ArrowRight"), { ...card, x: 201 });
  assert.deepEqual(adjustHeroPlacement(card, "ArrowUp"), { ...card, y: 29 });
  assert.deepEqual(adjustHeroPlacement(card, "ArrowDown", 10), { ...card, y: 40 });
  assert.deepEqual(adjustHeroPlacement(card, "+", 10), { ...card, width: 410 });
  assert.equal(adjustHeroPlacement({ ...card, width: 1 }, "-").width, 1);
  assert.equal(adjustHeroPlacement({ ...card, x: 10000 }, "ArrowRight").x, 10000);
  assert.equal(card.width, 400);
});

test("drafts validate current width, version, visible IDs, finite values and bounds", () => {
  const draft = { version: 1, viewport: { width: 1944, height: 1080 }, cards: defaults };
  assert.deepEqual(parseHeroDraft(JSON.stringify(draft), 1944, defaults), defaults);
  for (const value of [null, "{", JSON.stringify({ ...draft, version: 0 }), JSON.stringify(draft).replace('"width":1944', '"width":1024'), JSON.stringify({ ...draft, cards: { "3": defaults["3"] } }), JSON.stringify({ ...draft, cards: { ...defaults, "3": { ...defaults["3"], width: 0 } } }), JSON.stringify({ ...draft, cards: { ...defaults, "3": { ...defaults["3"], x: "200" } } })]) {
    assert.equal(parseHeroDraft(value, 1944, defaults), null);
  }
  assert.equal(parseHeroDraft('{"version":1,"viewport":{"width":1944},"cards":{"3":{"x":1e999,"y":0,"width":20},"5":{"x":0,"y":0,"width":20}}}', 1944, defaults), null);
});

test("panel clamping keeps its handle reachable after resize and expansion", () => {
  assert.deepEqual(clampPanelPosition(-200, 500, 400, 300, 1024, 600), { left: 8, top: 292 });
  assert.deepEqual(clampPanelPosition(2000, -100, 400, 300, 1024, 600), { left: 616, top: 8 });
  assert.deepEqual(clampPanelPosition(0, 0, 400, 900, 481, 600), { left: 8, top: 8 });
});

test("shadow editing preserves card geometry and migrates old drafts without losing placement", () => {
  const baseline = { "3": { ...defaults["3"], shadowX: -20, shadowY: 45 } };
  const card = baseline["3"];
  assert.deepEqual(adjustHeroPlacement(card, "ArrowRight", 10, "shadow"), { ...card, shadowX: -10 });
  assert.deepEqual(adjustHeroPlacement(card, "ArrowUp", 1, "shadow"), { ...card, shadowY: 44 });
  assert.deepEqual(adjustHeroPlacement(card, "+", 1, "shadow"), { ...card, width: 401 });
  const draft = { version: 1, viewport: { width: 1333 }, cards: { "3": { ...defaults["3"], x: 250 } } };
  assert.deepEqual(parseHeroDraft(JSON.stringify(draft), 1333, baseline), { "3": { ...card, x: 250 } });
  const edited = { ...draft, cards: { "3": { ...card, shadowX: 30, shadowY: -15 } } };
  assert.deepEqual(parseHeroDraft(JSON.stringify(edited), 1333, baseline), edited.cards);
  for (const shadowX of [null, "20", 10001]) {
    assert.equal(parseHeroDraft(JSON.stringify({ ...draft, cards: { "3": { ...card, shadowX } } }), 1333, baseline), null);
  }
});
