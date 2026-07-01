# Debug Grid Collapse Issue

## Current Problem
Cards are rendering as "skinny vertical strips" instead of proper card widths.

## Root Cause Analysis

The issue is likely one of these:

1. **Views Output Structure**: Views might be outputting:
   ```html
   <div class="mel-sticker-wall">
     <div class="mel-event-grid">  <!-- This has minmax(0, 1fr) -->
       <div class="views-row">
         <article class="mel-event-card">...</article>
       </div>
     </div>
   </div>
   ```

2. **CSS Specificity**: The `.mel-event-grid` styles with `minmax(0, 1fr)` might be winning over the homepage overrides.

3. **Card Structure**: Cards might have internal flex containers that are collapsing.

## Fixes Applied

### 1. Homepage Grid Override
- Added high-specificity overrides for `.mel-sticker-wall .mel-event-grid`
- Changed grid to `repeat(auto-fit, minmax(260px, 1fr))`
- Added `min-width: 260px !important` to all grid items

### 2. Card Width Constraints
- Added `min-width: 0` to prevent flex shrink (but allow grid to control)
- Added `width: 100%` to cards
- Added `box-sizing: border-box`

### 3. Aspect Ratio
- Poster cards: `aspect-ratio: 3 / 4`
- Applied to both old (`.mel-card-media`) and new (`.mel-card__image-wrapper`) structures

## Verification Steps

1. **Inspect HTML in Browser DevTools**:
   - Find a card element
   - Check the actual class names on parent containers
   - Verify if `.mel-sticker-wall` is present
   - Check if `.mel-event-grid` or `.view-content` is inside

2. **Check Computed Styles**:
   - Inspect the grid container
   - Verify `grid-template-columns` value
   - Check if `minmax(260px, 1fr)` is applied (not `minmax(0, 1fr)`)

3. **Check Card Styles**:
   - Inspect a card
   - Verify `width` and `min-width` values
   - Check if `aspect-ratio` is `3 / 4`

## If Still Broken

The Views template might need to be updated to NOT output `.mel-event-grid` when inside `.mel-sticker-wall`, or we need to use a different Views template for the homepage.

Option: Create `views-view-unformatted--upcoming-events--block-upcoming.html.twig` that outputs cards directly without the grid wrapper.
