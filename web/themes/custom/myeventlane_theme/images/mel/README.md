# MyEventLane Theme Images

## Category hero images (preferred: term upload)

**Best option:** Upload images in the taxonomy term edit form (Structure → Taxonomy → Categories → Edit term). The "Category Image" field is used for category page heroes. Images uploaded there take precedence over theme files.

**Fallback:** Place theme files at `images/mel/categories/mel-category-{slug}.png` (e.g. `mel-category-movie.png`). Used when no image is uploaded on the term.

## Other hero images (listing pages)

| Page | Path |
|------|------|
| **Events** (/events) | `images/mel/hero/mel-hero-events.png` |
| **Search** (/search) | `images/mel/hero/mel-hero-search.png` |

## Empty state (nothing found)

| Context | Path |
|---------|------|
| Category / Events / Search | `images/mel/empty/mel-empty-events.png` |

Used when no events match (category page, events page, or search results).
