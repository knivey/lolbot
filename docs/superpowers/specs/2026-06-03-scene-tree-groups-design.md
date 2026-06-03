# Scene Tree / Groups Design

## Goal

Add a scene tree layer on top of the existing `library/draw/` Canvas. Groups
(`<g>`) can hold child nodes and declare style properties (fill, stroke,
transform, opacity) that children inherit. Shape nodes hold a Path and render
using inherited or locally overridden properties.

The scene tree is a data structure. It renders onto an existing Canvas by
calling its existing methods (`drawPath`, `save`, `restore`,
`concatTransform`). No changes to Canvas itself.

This matches SVG `<g>` semantics and is the foundation the SVG parser
(milestone 9) will populate.

## Types

### SceneNode (interface)

```php
interface SceneNode {
    /** @return array<SceneNode> */
    public function getChildren(): array;
    public function render(Canvas $canvas, RenderContext $ctx): void;
}
```

### Group (implements SceneNode)

Container of child nodes with optional style properties. Like SVG `<g>`.

Properties (all optional, `null` means inherit from parent):

- `?Paint $fill`
- `?StrokeStyle $stroke`
- `?Transform $transform`
- `?float $opacity`
- `?float $fillOpacity`
- `?FillRule $fillRule`

Methods:

- `addChild(SceneNode $node): void`
- `removeChild(SceneNode $node): void`
- `getChildren(): array<SceneNode>`
- `render(Canvas $canvas, RenderContext $ctx): void`

Render behavior:

1. Merge own properties into parent `RenderContext` → child context
2. `canvas->save()`
3. If transform is set, `canvas->concatTransform($transform)`
4. For each child, `child->render($canvas, $childCtx)`
5. `canvas->restore()`

### Shape (implements SceneNode)

A leaf node holding a Path to be rendered. Like an SVG shape element
(`<path>`, `<circle>`, `<rect>`, etc.).

Same optional style properties as Group, plus:

- `Path $path` (required)

`getChildren()` returns `[]`.

Render behavior:

1. Merge own properties into parent `RenderContext` → effective context
2. `canvas->save()`
3. If own transform is non-null, `canvas->concatTransform($ownTransform)`
   (parent transform is already on the canvas from the Group's save/concat
   nesting)
4. `canvas->drawPath($this->path, $effectiveFill, $effectiveStroke, '', $effectiveFillRule, $effectiveFillOpacity, $effectiveOpacity)`
5. `canvas->restore()`

If both `fill` and `stroke` are null after inheritance, the shape is a no-op.

### RenderContext (immutable value object)

Carries the computed inherited state down the tree.

Properties:

- `Paint $fill` — default `new Color(0, null)` (black, no bg)
- `?StrokeStyle $stroke` — default `null` (no stroke)
- `Transform $transform` — default `Transform::identity()`
- `float $opacity` — default `1.0`
- `float $fillOpacity` — default `1.0`
- `FillRule $fillRule` — default `FillRule::NonZero`

Methods:

- `static defaults(): self` — returns a context with all defaults
- `merge(Group|Shape $node): self` — returns a new context with the node's
  non-null properties overriding

Inheritance rules on `merge()`:

| Property | Rule |
|----------|------|
| `fill` | Node's value replaces parent's. `null` on node → keep parent's |
| `stroke` | Same as fill |
| `fillRule` | Same as fill |
| `transform` | Composed: `parent.transform * node.transform`. `null` → keep parent's |
| `opacity` | Multiplied: `parent.opacity * node.opacity`. `null` → keep parent's |
| `fillOpacity` | Same as opacity |

## Usage Example

```php
$scene = new Group(
    transform: Transform::translate(10.0, 10.0),
    fill: new Color(4, null),
);
$scene->addChild(new Shape(
    path: Path::circle(0.0, 0.0, 5.0),
));
$scene->addChild(new Shape(
    path: Path::rect(2.0, 2.0, 8.0, 6.0),
    fill: new Color(9, null),
    opacity: 0.7,
));

$canvas = Canvas::createBlank(80, 48, true);
$canvas->fillColor(0, 0, new Color(1, 1));
$scene->render($canvas, RenderContext::defaults());
```

The circle inherits fill=Color(4) from the group. The rectangle overrides fill
and opacity. Both inherit the group's translate(10, 10) transform.

## Files

| File | Type |
|------|------|
| `library/draw/SceneNode.php` | interface |
| `library/draw/Group.php` | class |
| `library/draw/Shape.php` | class |
| `library/draw/RenderContext.php` | immutable value object |
| `tests/Canvas/SceneTreeTest.php` | unit + integration tests |

No changes to existing files.

## Out of Scope

- Clip paths / masks (milestone 10)
- Filters (milestone 11)
- Text elements (milestone 12)
- `<defs>` / `<use>` / `<symbol>` (milestone 13)
- Element IDs or lookup by ID
- Event system / mutation observers
- Lazy rendering / dirty rectangles
- viewBox / preserveAspectRatio (belongs with SVG parser)
