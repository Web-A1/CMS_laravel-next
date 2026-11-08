import assert from "node:assert/strict";
import test from "node:test";
const modulePath = "./buttonState.ts";
const { buttonState, revealGeometry }: typeof import("./buttonState") = await import(modulePath);

test("loading blocks activation without removing the focused button from Tab order", () => {
  assert.deepEqual(buttonState({ loading: true, disabled: true }), {
    blocked: true, nativeDisabled: false, ariaDisabled: true,
  });
  assert.deepEqual(buttonState({ disabled: true }), {
    blocked: true, nativeDisabled: true, ariaDisabled: true,
  });
});

test("explicit aria-disabled is respected independently of native disabled", () => {
  assert.equal(buttonState({ ariaDisabled: "true" }).blocked, true);
  assert.equal(buttonState({ ariaDisabled: true }).blocked, true);
  assert.equal(buttonState({ ariaDisabled: "false" }).blocked, false);
  assert.equal(buttonState({}).blocked, false);
});

test("reveal covers every corner from any entry edge without moving the origin", () => {
  for (const [width, height] of [[36, 36], [132, 32], [400, 64]]) {
    for (const [x, y] of [[0, 0], [width, height], [width / 2, 0], [0, height / 2]]) {
      const result = revealGeometry(width, height, x, y);
      assert.equal(result.x, x);
      assert.equal(result.y, y);
      for (const [cornerX, cornerY] of [[0, 0], [width, 0], [0, height], [width, height]]) {
        assert.ok(result.radius >= Math.hypot(cornerX - x, cornerY - y));
      }
    }
  }
});

test("out-of-bounds pointer coordinates clamp to the button edge", () => {
  assert.deepEqual(revealGeometry(100, 36, -5, 50), {
    x: 0, y: 36, radius: Math.hypot(100, 36) + 2,
  });
});
