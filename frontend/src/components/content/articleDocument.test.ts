import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

interface DocumentNode {
  type: { name: string; create?(attrs: Record<string, string>, content?: null, marks?: readonly unknown[]): DocumentNode };
  attrs?: Record<string, string>;
  marks?: readonly unknown[];
  textContent?: string;
  content?: readonly unknown[];
  children?: readonly DocumentNode[];
  firstChild?: DocumentNode;
  lastChild?: DocumentNode;
  nodeSize?: number;
}

interface CommandContext {
  state: {
    selection: {
      node: DocumentNode;
      from: number;
      to: number;
      $from: { depth: number; node(depth: number): DocumentNode; before(depth: number): number };
    };
    schema: {
      text(text: string): DocumentNode;
      nodes: Record<string, { create(attrs: null, content: DocumentNode | DocumentNode[]): DocumentNode }>;
    };
  };
  tr: { replaceRangeWith(from: number, to: number, node: DocumentNode): void };
  dispatch(): void;
}

interface FigureExtension {
  addCommands(): { setArticleCaption(text: string): (context: CommandContext) => boolean };
}

test("saving an unchanged caption preserves its marked node for image and figure selections", async () => {
  const source = await readFile(new URL("../../../public/article-editor/figure.js", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  const sandbox = { exports: {} as { default: () => { addExtensions(): FigureExtension[] } } };
  const core = { Node: { create: (config: unknown) => config }, Extension: { create: (config: unknown) => config } };
  const pmState = { NodeSelection: {}, Plugin: class {} };
  Function("window", "module", "exports", output)({ FilamentRichEditor: { tiptap: { core, pmState } } }, sandbox, sandbox.exports);
  // The factory runs before an editor exists, as it does during TipTap setup.
  const [figure] = sandbox.exports.default().addExtensions();
  const command = figure.addCommands().setArticleCaption;
  const imageType: DocumentNode["type"] = { name: "image", create: (attrs, _content, marks) => ({ type: imageType, attrs, marks }) };
  const image: DocumentNode = { type: imageType, attrs: { src: "/scheme.jpg", alt: "Scheme" } };
  const caption: DocumentNode = {
    type: { name: "articleFigcaption" }, textContent: "Marked caption",
    content: [{ text: "Marked ", marks: [{ type: "bold" }] }, { text: "caption", marks: [{ type: "italic" }] }],
  };
  const parent: DocumentNode = { type: { name: "articleFigure" }, firstChild: image, lastChild: caption, nodeSize: 20 };
  const schema: CommandContext["state"]["schema"] = {
    text: (text) => ({ type: { name: "text" }, textContent: text }),
    nodes: {
      articleFigcaption: { create: (_attrs, content) => ({ type: { name: "articleFigcaption" }, content: [content] }) },
      articleFigure: { create: (_attrs, children) => ({ type: { name: "articleFigure" }, children: Array.isArray(children) ? children : [children] }) },
    },
  };
  for (const selected of [image, parent]) {
    const replacements: DocumentNode[] = [];
    const context: CommandContext = {
      state: { schema, selection: { node: selected, from: 4, to: selected === parent ? 24 : 5,
        $from: { depth: selected === parent ? 0 : 1, node: () => parent, before: () => 4 } } },
      tr: { replaceRangeWith: (_from, _to, node) => { replacements.push(node); } },
      dispatch() {},
    };
    assert.equal(command("Marked caption")(context), true);
    assert.equal(replacements.at(-1)?.children?.[0], image);
    assert.equal(replacements.at(-1)?.children?.[1], caption);
    assert.equal(command("Edited caption")(context), true);
    assert.notEqual(replacements.at(-1)?.children?.[1], caption);
    assert.equal(replacements.at(-1)?.children?.[0].attrs?.alt, "Edited caption");
    assert.equal(command("")(context), true);
    assert.equal(replacements.at(-1)?.children?.length, 1);
    assert.equal(replacements.at(-1)?.children?.[0].attrs?.src, image.attrs?.src);
    assert.equal(replacements.at(-1)?.children?.[0].attrs?.alt, "");
  }
});
