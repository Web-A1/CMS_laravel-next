import assert from "node:assert/strict";
import test from "node:test";

const navigationLabelModulePath = "./assessmentQuestionNavigationLabel.ts";
const { assessmentQuestionDisplayPrompt, assessmentQuestionNavigationLabel } = await import(navigationLabelModulePath);

test("removes only a terminal full stop from the displayed question", () => {
  assert.equal(
    assessmentQuestionDisplayPrompt("Перечислите типы фиброзных соединений костей."),
    "Перечислите типы фиброзных соединений костей",
  );
  assert.equal(
    assessmentQuestionDisplayPrompt("Какие связки укрепляют срединный атлантоосевой сустав?"),
    "Какие связки укрепляют срединный атлантоосевой сустав?",
  );
  assert.equal(
    assessmentQuestionDisplayPrompt("При соединении височной и теменной костей образуется шов:"),
    "При соединении височной и теменной костей образуется шов:",
  );
});

test("removes instructional introductions from assessment navigation labels", () => {
  assert.equal(
    assessmentQuestionNavigationLabel("fundamentals-01", "Перечислите типы фиброзных соединений костей."),
    "Фиброзные соединения",
  );
  assert.equal(
    assessmentQuestionNavigationLabel(
      "fundamentals-02",
      "Как называются соединения между костями с помощью соединительной ткани?",
    ),
    "Соединительная ткань",
  );
  assert.equal(
    assessmentQuestionNavigationLabel("fundamentals-03", "Заполните классификацию суставов."),
    "Классификация суставов",
  );
  assert.equal(
    assessmentQuestionNavigationLabel("expanded-04", "Перечислите, чем образованы стенки грудной клетки?"),
    "Стенки грудной клетки",
  );
});

test("numbers diagram questions without revealing their answers", () => {
  assert.equal(
    assessmentQuestionNavigationLabel(
      "diagram-01",
      "Напишите, что изображено на картинке, и подпишите основные образования, помеченные стрелками.",
    ),
    "Схема 1",
  );
  assert.equal(
    assessmentQuestionNavigationLabel(
      "diagram-09",
      "Напишите, что изображено на картинке, и подпишите основные образования, помеченные стрелками.",
    ),
    "Схема 9",
  );
});

test("falls back to a concise prompt-derived label for future questions", () => {
  assert.equal(
    assessmentQuestionNavigationLabel("future-question", "Перечислите новые образования."),
    "Новые образования",
  );
});
