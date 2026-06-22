# MEL v2 Homepage Wireframes — Annotated Notes

## Purpose
Structured notes for recreating wireframes in Figma. These annotations specify spacing, hierarchy, interactions, and responsive behavior.

---

## 1. Homepage — Sticker Wall + Vibe Mixer (Desktop)

### Layout Structure
- **Container**: Max-width 1100px, centered, padding: 32px sides
- **Grid System**: 12-column grid (implicit, for alignment)

### Hero Section
- **Position**: Top of page, below header
- **Layout**: Left-aligned content, right visual (optional)
- **Headline**: 
  - Font: Nunito Bold, 48px (3xl)
  - Color: `--mel-ink` (#293241)
  - Margin bottom: 16px
- **Subheadline**: 
  - Font: Nunito Regular, 18px (md)
  - Color: `--mel-muted` (#6b7280)
  - Margin bottom: 24px
- **Search Bar** (Label-maker style):
  - Full width, max-width: 600px
  - Height: 56px
  - Border radius: 28px (full)
  - Border: 2px solid `--mel-primary`
  - Padding: 16px 24px
  - Placeholder: "Search events, venues, artists..."
  - Icon: Search icon on left, 20px
- **Spacing**: Hero section margin bottom: 64px

### Vibe Mixer Row
- **Position**: Below hero, above Sticker Wall
- **Layout**: Horizontal row, flex-wrap on mobile
- **Section Label**: 
  - "Find your vibe" (optional, can be hidden)
  - Font: Nunito Semibold, 14px (sm)
  - Color: `--mel-muted`
  - Margin bottom: 12px
- **Chip Container**:
  - Display: flex, gap: 12px
  - Wrap: yes (2 lines max on desktop)
- **Vibe Chips**:
  - Examples: "Chill", "Loud", "Cute", "Artsy", "Family", "Outdoors"
  - Height: 36px
  - Padding: 8px 16px
  - Border radius: 18px (full)
  - Background: `--mel-card` (#ffffff)
  - Border: 2px solid `--mel-primary` (transparent when not selected)
  - Font: Nunito Semibold, 14px (sm)
  - Color: `--mel-ink` when selected, `--mel-muted` when not
  - Selected state: Background `--mel-primary`, text white, border `--mel-primary`
  - Hover: Slight scale (1.05), transition 0.15s ease
  - Focus: Visible ring (3px, `--mel-secondary`)
- **Sliders** (Optional, can be collapsed):
  - "Energy" slider: 0-100, default 50
  - "Budget" slider: 0-100, default 50
  - Width: 200px each
  - Height: 8px track, 20px thumb
  - Color: `--mel-primary` for active track
  - Position: Below chips, or in "More filters" toggle
- **Spacing**: Vibe Mixer margin bottom: 48px

### Sticker Wall Grid
- **Layout**: CSS Grid, responsive columns
  - Mobile (0-699px): 1 column
  - Tablet (700-1099px): 2 columns
  - Desktop (1100px+): 3-4 columns (prefer 3 for breathing room)
- **Gap**: 24px (desktop), 18px (tablet), 14px (mobile)
- **Card Rotation** (Variant A only):
  - Deterministic tilt classes:
    - `.mel-tilt-1`: `rotate(-1deg)`
    - `.mel-tilt-2`: `rotate(1deg)`
    - `.mel-tilt-3`: `rotate(-0.5deg)`
  - Applied by loop index: `index % 3`
  - Transition: `transform 0.2s ease` (respects reduced motion)
- **Card Shadows**:
  - Rest: `0 10px 30px rgba(41, 50, 65, 0.08)`
  - Hover: `0 12px 40px rgba(41, 50, 65, 0.12)`
  - Transition: `box-shadow 0.2s ease`
- **Spacing**: Sticker Wall margin bottom: 64px

### Secondary Rows
- **Layout**: Horizontal scroll on mobile, grid on desktop
- **Row Headers**:
  - Examples: "Tonight", "Free & under $20", "Near you"
  - Font: Nunito Bold, 24px (xl)
  - Color: `--mel-ink`
  - Margin bottom: 16px
- **Row Container** (Mobile):
  - `display: flex`
  - `overflow-x: auto`
  - `scroll-snap-type: x mandatory`
  - `gap: 16px`
  - `padding-bottom: 8px` (for scrollbar)
- **Row Container** (Desktop):
  - `display: grid`
  - `grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))`
  - `gap: 18px`
- **Spacing**: Each row margin bottom: 48px

---

## 2. Homepage — Sticker Wall + Vibe Mixer (Mobile)

### Layout Adjustments
- **Container**: Full width, padding: 16px sides
- **Single column** for main content

### Hero Section (Mobile)
- **Layout**: Stacked vertically
- **Headline**: 
  - Font size: 36px (2xl)
  - Margin bottom: 12px
- **Subheadline**: 
  - Font size: 16px (base)
  - Margin bottom: 20px
- **Search Bar**: 
  - Full width
  - Height: 48px
  - Font size: 16px
- **Spacing**: Hero margin bottom: 40px

### Vibe Mixer Row (Mobile)
- **Chips**: 
  - Wrap to 2 lines max
  - Gap: 8px
  - Font size: 13px
  - Height: 32px
  - Padding: 6px 12px
- **Sliders**: 
  - Hidden by default
  - Toggle: "More filters" button
  - When expanded: Full width, stacked vertically
- **Spacing**: Vibe Mixer margin bottom: 32px

### Sticker Wall Grid (Mobile)
- **Layout**: 1 column
- **Gap**: 14px
- **Card Rotation**: Same tilt classes (subtle on mobile)
- **Spacing**: Sticker Wall margin bottom: 40px

### Secondary Rows (Mobile)
- **Layout**: Horizontal scroll only
- **Card Width**: 280px (fixed, no shrink)
- **Scroll Snap**: Enabled
- **Spacing**: Each row margin bottom: 32px

---

## 3. Event Card — Poster Card States

### Poster Card Base
- **Dimensions**: 
  - Aspect ratio: 16:9 (image)
  - Min height: 320px (desktop), 240px (mobile)
- **Border radius**: 18px (`--mel-radius-lg`)
- **Background**: `--mel-card` (#ffffff)
- **Overflow**: Hidden
- **Clickable**: Entire card (link wrapper)

### Image Container
- **Position**: Relative
- **Aspect ratio**: 16:9
- **Object fit**: Cover
- **Object position**: Center
- **Overlay elements**: Date badge, stamp, vibe chips (if any)

### Date Badge (Optional)
- **Position**: Absolute, top-left
  - Top: 12px
  - Left: 12px
- **Background**: `rgba(255, 255, 255, 0.95)` with backdrop-filter blur
- **Border radius**: 8px
- **Padding**: 6px 10px
- **Font**: Nunito Bold, 12px (xs)
- **Z-index**: 2

### Stamp (Optional)
- **Position**: Absolute, top-right
  - Top: 12px
  - Right: 12px
- **Examples**: "TONIGHT", "SOLD OUT", "NEW"
- **Background**: `--mel-primary` or `--mel-secondary`
- **Color**: White
- **Padding**: 4px 10px
- **Border radius**: 999px (full)
- **Font**: Nunito Bold, 11px (xs), uppercase, letter-spacing 0.5px
- **Transform**: `rotate(-2deg)` (slight rotation for "sticker" feel)
- **Z-index**: 2
- **Box shadow**: `0 2px 8px rgba(0, 0, 0, 0.15)`

### Vibe Chips (Optional, on image)
- **Position**: Absolute, bottom-left (above title if overlay)
  - Bottom: 60px (if title overlay), 12px (if no title overlay)
  - Left: 12px
- **Display**: Flex, gap: 6px
- **Chip style**: Same as Vibe Mixer chips, but smaller
  - Height: 24px
  - Padding: 4px 10px
  - Font: Nunito Semibold, 11px (xs)
- **Z-index**: 2

### Title Placement — Mode A: Overlay
- **Position**: Absolute, bottom of image
  - Bottom: 12px
  - Left: 12px
  - Right: 12px
- **Scrim Gradient**:
  - `linear-gradient(transparent, rgba(0, 0, 0, 0.55))`
  - Position: Bottom 40% of image
  - Pseudo-element on image wrapper or title container
- **Title Text**:
  - Font: Nunito Bold, 20px (lg)
  - Color: White (`--mel-text-inverse`)
  - Line height: 1.25
  - Line clamp: 2 lines
  - Margin: 0
  - Z-index: 3 (above scrim)
- **Text Shadow**: `0 1px 3px rgba(0, 0, 0, 0.3)` (for readability)
- **Padding**: 12px (to avoid edge collision)

### Title Placement — Mode B: Below Image
- **Position**: In card body, below image
- **Padding**: 14px 16px 0
- **Title Text**:
  - Font: Nunito Bold, 18px (lg)
  - Color: `--mel-ink` (#293241)
  - Line height: 1.25
  - Line clamp: 2 lines
  - Margin: 0 0 8px

### Card Body (Mode B only, or for meta info)
- **Padding**: 14px 16px 16px
- **Meta Items** (Date, Time, Location):
  - Font: Nunito Regular, 14px (sm)
  - Color: `--mel-muted` (#6b7280)
  - Display: Flex column, gap: 4px
  - Icons: 16px, margin-right: 6px
- **Price**:
  - Font: Nunito Semibold, 14px (sm)
  - Color: `--mel-ink`
  - Margin top: 8px

### CTA Button
- **Position**: 
  - Overlay mode: Absolute, bottom-right of image (if space) OR in card body
  - Below mode: In card body, bottom
- **Style**: 
  - Background: `--mel-primary`
  - Color: White
  - Padding: 10px 20px
  - Border radius: 999px (full)
  - Font: Nunito Semibold, 14px (sm)
  - Hover: Background `--mel-primary-hover`, scale 1.05

### Hover States
- **Card**: 
  - Transform: `translateY(-4px)` (desktop only)
  - Box shadow: Increased
  - Transition: `0.2s ease`
- **Respect reduced motion**: No transform on hover if `prefers-reduced-motion`

---

## 4. Event Card — Compact Card

### Compact Card Base
- **Layout**: Horizontal flex row
- **Dimensions**: 
  - Height: 80px (mobile), 100px (desktop)
  - Width: 100% (in container)
- **Border radius**: 12px (`--mel-radius-md`)
- **Background**: `--mel-card` (#ffffff)
- **Box shadow**: `0 2px 8px rgba(0, 0, 0, 0.08)`
- **Clickable**: Entire card (link wrapper)
- **Padding**: 12px
- **Gap**: 12px (between thumb and content)

### Thumbnail (Optional)
- **Width**: 80px (mobile), 100px (desktop)
- **Height**: 100% (matches card height minus padding)
- **Border radius**: 8px
- **Object fit**: Cover
- **Flex shrink**: 0

### Content Area
- **Flex**: 1 (grows to fill)
- **Display**: Flex column
- **Justify**: Space between

### Title
- **Font**: Nunito Bold, 16px (base)
- **Color**: `--mel-ink`
- **Line clamp**: 2 lines
- **Margin**: 0 0 4px

### Meta Row
- **Display**: Flex row
- **Gap**: 12px
- **Font**: Nunito Regular, 13px (sm)
- **Color**: `--mel-muted`
- **Items**: Date, Price (side by side)

### Hover States
- **Card**: 
  - Background: Slight tint (`rgba(255, 111, 97, 0.05)`)
  - Box shadow: Increased
  - Transform: None (too small for lift)

---

## Interaction Notes

### Clickable Areas
- **Poster Card**: Entire card (wrapped in `<a>`)
- **Compact Card**: Entire card (wrapped in `<a>`)
- **Vibe Chips**: Individual chips (buttons/links)
- **Search Bar**: Input field + submit button

### Focus States
- **All interactive elements**: 
  - Focus ring: 3px, `--mel-secondary` color
  - Offset: 2px
  - Visible on keyboard navigation

### Reduced Motion
- **Disable**: 
  - Card rotations (tilt classes)
  - Hover transforms
  - Transitions (where appropriate)
- **Keep**: 
  - Color changes
  - Opacity changes

### Accessibility
- **Contrast**: 
  - Overlay title: Scrim ensures WCAG AA (4.5:1 minimum)
  - Text on colored backgrounds: Tested
- **Focus**: All interactive elements have visible focus
- **Semantic HTML**: Cards use `<article>` or proper link structure
- **Alt text**: All images have descriptive alt text

---

## Stamp Placement Rules

### Priority Order (Top to Bottom, Left to Right)
1. **Date Badge**: Top-left (if present)
2. **Stamp**: Top-right (if present)
3. **Vibe Chips**: Bottom-left (if present, above title if overlay)
4. **Title Overlay**: Bottom (if overlay mode)
5. **CTA**: Bottom-right (if space allows) OR in card body

### Collision Avoidance
- **Stamp and Date Badge**: Never overlap (stamp is top-right, date is top-left)
- **Title Overlay and Vibe Chips**: Chips sit above title (60px from bottom if title overlay)
- **Title Overlay and CTA**: CTA moves to card body if no space

---

## Color Reference (from tokens)

- `--mel-bg`: #fef5ec (page background)
- `--mel-card`: #ffffff (card background)
- `--mel-ink`: #293241 (primary text)
- `--mel-muted`: #6b7280 (secondary text)
- `--mel-primary`: #f26d5b (coral, primary CTA)
- `--mel-secondary`: #6e7ef2 (lavender, secondary actions)
- `--mel-accent`: #f5c04c (yellow, highlights)

---

## Spacing Scale Reference

- `--mel-space-1`: 6px
- `--mel-space-2`: 10px
- `--mel-space-3`: 14px
- `--mel-space-4`: 18px
- `--mel-space-5`: 24px
- `--mel-space-6`: 32px

---

## Typography Scale Reference

- `xs`: 12px
- `sm`: 14px
- `base`: 16px
- `md`: 18px
- `lg`: 20px
- `xl`: 24px
- `2xl`: 30px
- `3xl`: 36px
- `4xl`: 48px

---

End of Wireframe Annotations
