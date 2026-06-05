# Clip/Mask Design

## Goal

Add `<clipPath>` and `<mask>` support to the draw library's SVG rendering pipeline. This is **Milestone 10** from the SVG roadmap.

`<clipPath>` provides hard geometric clipping — pixels are either inside or outside the clip region. `<mask>` provides per-pixel alpha or luminance masking that allows partial transparency.

## Approach: ClipNode/MaskNode as SceneNode Wrappers

Clip and mask are modeled as new `SceneNode` implementations that wrap a child node alongside a clip/mask definition (a `Group` of shapes). This matches SVG's structural model where `clip-path` and `mask` attributes are applied to individual elements.

## New Classes

### ClipNode

```php
class ClipNode implements SceneNode
{
    public function __construct(
        public readonly SceneNode $child,
        public readonly Group $clipContent,
        public readonly GradientUnits $clipPathUnits = GradientUnits::UserSpaceOnUse,
        public readonly ?Transform $transform = null,
    ) {}

    public function getChildren(): array { return [$this->child]; }
}
```

- `child` — the element being clipped
- `clipContent` — a `Group` containing the clip geometry shapes
- `clipPathUnits` — `objectBoundingBox` (0-1 mapped to child's bbox) or `userSpaceOnUse`
- `transform` — the `transform` attribute on the `<clipPath>` element
- `getChildren()` returns `[$this->child]` — the clip content is metadata for rendering, not a tree child

### MaskNode

```php
class MaskNode implements SceneNode
{
    public function __construct(
        public readonly SceneNode $child,
        public readonly Group $maskContent,
        public readonly GradientUnits $maskUnits = GradientUnits::ObjectBoundingBox,
        public readonly GradientUnits $maskContentUnits = GradientUnits::UserSpaceOnUse,
        public readonly MaskType $maskType = MaskType::Luminance,
        public readonly ?Transform $transform = null,
    ) {}

    public function getChildren(): array { return [$this->child]; }
}
```

- `child` — the element being masked
- `maskContent` — a `Group` containing the mask content shapes
- `maskUnits` — coordinate system for the mask *region* (default `objectBoundingBox`)
- `maskContentUnits` — coordinate system for the mask *content* (default `userSpaceOnUse`)
- `maskType` — `Luminance` or `Alpha`
- `transform` — the `transform` attribute on the `<mask>` element
- `getChildren()` returns `[$this->child]` — the mask content is metadata for rendering, not a tree child

### MaskType

```php
enum MaskType: string
{
    case Luminance = 'luminance';
    case Alpha = 'alpha';
}
```

## Rendering Pipeline

### ClipNode::render(canvas, ctx)

1. Save canvas state
2. Compute the child's bbox (needed for `objectBoundingBox` unit mapping)
3. Build the effective transform for the clip content:
   - If `clipPathUnits` is `objectBoundingBox`: compose `Transform::translate(bx, by)->multiply(Transform::scale(bw, bh))` with the optional clipPath `transform`
   - If `clipPathUnits` is `userSpaceOnUse`: use the clipPath `transform` directly (if any)
4. Render `clipContent` Group onto a temp canvas (same size as main canvas), applying the effective transform
5. Render `child` onto a second temp canvas using the normal rendering pipeline
6. For each pixel: if the clip canvas has any fg paint at that position, keep the child pixel; otherwise clear it
7. Composite the result onto the main canvas
8. Restore canvas state

### MaskNode::render(canvas, ctx)

1. Save canvas state
2. Compute the child's bbox
3. Build the effective transform for the mask content:
   - If `maskContentUnits` is `objectBoundingBox`: compose bbox mapping with optional mask `transform`
   - If `maskContentUnits` is `userSpaceOnUse`: use the mask `transform` directly
4. Render `maskContent` Group onto a temp canvas, applying the effective transform
5. Render `child` onto a second temp canvas using the normal rendering pipeline
6. For each pixel in the mask canvas:
   - **Luminance mode**: compute `L = 0.2126 * R + 0.7152 * G + 0.0722 * B`, normalized to 0-1 from the IRC palette RGB values. Multiply with child pixel alpha.
   - **Alpha mode**: use the mask pixel's `fgAlpha` value directly. Multiply with child pixel alpha.
7. Composite the result onto the main canvas
8. Restore canvas state

### Nesting and Stacking

Both `ClipNode` and `MaskNode` are `SceneNode` implementations, so they compose naturally:
- A `Group` can contain `ClipNode`/`MaskNode` children
- A shape with both `clip-path` and `mask` is wrapped in both: inner = `ClipNode`, outer = `MaskNode` (clip first, then mask the clipped result)
- Clip/mask content can contain groups with inherited properties, transforms, gradients — they render through the normal pipeline onto the temp canvas

## SVG Parser Changes

### Element Dispatch

Add to `parseElement()` dispatch table:
- `'clipPath'` → `parseClipPathElement()` — parses children into a `Group`, returns empty Group (clip definitions don't render directly)
- `'mask'` → `parseMaskElement()` — same pattern

### Defs Collection

Extend `collectAllDefs()` to harvest `<clipPath>` and `<mask>` elements into the `$defs` dictionary by `id`, alongside existing gradient collection. The clip/mask content (child shapes) is parsed into a `Group` at collection time.

### Attribute Resolution

In both `buildShape()` and `parseGroupElement()`:
1. Check for `clip-path="url(#id)"` attribute — if present, look up the `ClipNode` definition from defs, wrap the result in a `ClipNode`
2. Check for `mask="url(#id)"` attribute — if present, look up the `MaskNode` definition from defs, wrap the result in a `MaskNode`
3. Both attributes resolved via `getEffectiveAttr()` (inline style → CSS → presentation attribute)

### Bbox Computation for objectBoundingBox

When wrapping with `ClipNode` or `MaskNode` using `objectBoundingBox` units, compute the child's bbox:
- For a `Shape`: use `Path::getBBox()` from the flattened path
- For a `Group`: accumulate bboxes from all children (union of all child bboxes)

## Compositor Changes

Add a static method for applying a mask canvas to a source canvas:

```php
public static function applyMask(Canvas $dst, Canvas $src, Canvas $mask, float $opacity, MaskType $maskType): void
```

- For each pixel, compute the mask value (luminance or alpha)
- Multiply mask value with source pixel alpha
- Blend onto destination at the given opacity

Add a static method for applying a binary clip:

```php
public static function applyClip(Canvas $dst, Canvas $src, Canvas $clip, float $opacity): void
```

- For each pixel, if clip has fg paint, copy source pixel to destination at the given opacity
- Otherwise skip (pixel remains unchanged on dst)

## Files

### New files in `library/draw/`

| File | Purpose |
|------|---------|
| `ClipNode.php` | SceneNode wrapping a child with clip geometry |
| `MaskNode.php` | SceneNode wrapping a child with mask geometry |
| `MaskType.php` | Enum: Luminance, Alpha |

### Modified files

| File | Changes |
|------|---------|
| `SVGParser.php` | `<clipPath>`/`<mask>` element handlers, defs collection, `clip-path`/`mask` attribute resolution |
| `Compositor.php` | `applyMask()` and `applyClip()` static methods |

### New test files in `tests/Canvas/`

| File | Coverage |
|------|----------|
| `ClipNodeTest.php` | Clip rendering: shapes clipped to circles/rects/paths, objectBoundingBox units, transform on clipPath, empty clip = nothing visible, clip on group |
| `MaskNodeTest.php` | Mask rendering: luminance mode, alpha mode, partial transparency, maskUnits/maskContentUnits, gradient masks |
| Tests added to `SVGParserTest.php` | Parsing `<clipPath>`/`<mask>` elements, attribute resolution, integration tests |

### No changes needed

`Canvas.php`, `Path.php`, `Shape.php`, `Group.php`, `Transform.php`, `RenderContext.php`, `SVGDocument.php`, gradient classes, paint classes. The existing infrastructure is sufficient — clip/mask are purely new node types that consume it.

## SVG Attributes Supported

### `<clipPath>` element
- `id` — identifier for `url(#id)` references
- `clipPathUnits` — `userSpaceOnUse` (default) or `objectBoundingBox`
- `transform` — affine transform applied to clip content

### `<mask>` element
- `id` — identifier for `url(#id)` references
- `maskUnits` — `objectBoundingBox` (default) or `userSpaceOnUse`
- `maskContentUnits` — `userSpaceOnUse` (default) or `objectBoundingBox`
- `mask-type` — `luminance` (default) or `alpha`
- `transform` — affine transform applied to mask content

### Presentation attributes (on any shape or group)
- `clip-path="url(#id)"` — reference a `<clipPath>` definition
- `mask="url(#id)"` — reference a `<mask>` definition
