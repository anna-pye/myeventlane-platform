# MyEventLane Vendor Theme

Vendor Console theme for MyEventLane - a Humanitix/Eventbrite quality event management UI.

## Design System

### Color Tokens

```scss
// Primary Brand
$ml-vendor-navy:        #0D1520;
$ml-vendor-slate:       #637185;
$ml-vendor-text-dark:   #1A1A1A;

// Backgrounds
$ml-vendor-bg:          #F7F8FA;
$ml-vendor-card:        #FFFFFF;
$ml-vendor-border:      #E6E6E6;

// Accents
$ml-accent-coral:       #FF8A8A;
$ml-accent-coral-light: #FFE0E0;
$ml-accent-green:       #5CC98B;
$ml-accent-yellow:      #FFEAAA;
```

### Typography

- **Font Family:** Inter, Nunito (with system fallbacks)
- **Page Titles:** 28-32px, semi-bold
- **Section Titles:** 20px, medium
- **Body:** 16px
- **Labels:** 15px, medium
- **Line Height:** 1.5

### Layout

- **Main Content Max Width:** 1080px
- **Right Sidebar Width:** 300px
- **Left Navigation Width:** 260-280px
- **Gutters:** 32px

## Component Library

### Layout Components
- `mel-layout--two-column` - Main + sidebar layout
- `mel-sidebar` - Left navigation
- `mel-helper-sidebar` - Right help/blog sidebar
- `mel-header` - Page header

### Form Components
- `mel-input` - Text inputs
- `mel-select` - Dropdowns
- `mel-textarea` - Multi-line text
- `mel-form-group` - Field wrapper
- `mel-help-text` - Field descriptions

### Content Components
- `mel-card` - Content containers
- `mel-tabs` - Tab navigation
- `mel-section-title` - Section headers
- `mel-upload-control` - File uploads

### Analytics Components
- `mel-kpi-card` - Metric cards
- `mel-chart` - Chart.js wrappers
- `mel-table` - Data tables
- `mel-inline-actions` - Row actions

## Development

### Prerequisites

- Node.js 18+
- npm

### Setup

```bash
# Navigate to theme directory
cd web/themes/custom/myeventlane_vendor_theme

# Install dependencies
npm install

# Start development server
npm run dev

# Build for production
npm run build
```

### File Structure

```
myeventlane_vendor_theme/
├── css/
│   └── fallback.css          # Pre-build fallback styles
├── dist/                      # Built assets (generated)
├── src/
│   ├── js/
│   │   └── main.js           # JavaScript entry
│   └── scss/
│       ├── base/             # Reset, typography, forms
│       ├── components/       # UI components
│       ├── layout/           # Layout systems
│       ├── pages/            # Page-specific styles
│       ├── tokens/           # Design tokens
│       └── main.scss         # SCSS entry
├── templates/
│   ├── dashboard/            # Dashboard templates
│   ├── event/                # Event management templates
│   ├── form/                 # Form overrides
│   ├── includes/             # Partial templates
│   └── layout/               # Layout templates
├── myeventlane_vendor_theme.info.yml
├── myeventlane_vendor_theme.libraries.yml
├── myeventlane_vendor_theme.theme
├── package.json
└── vite.config.js
```

## Theme Regions

| Region | Description |
|--------|-------------|
| `header` | Page header area |
| `navigation` | Left sidebar navigation |
| `content` | Main content area |
| `sidebar` | Legacy sidebar |
| `sidebar_help` | Right-hand help/blog sidebar |
| `footer` | Page footer |

## Browser Support

- Chrome (last 2 versions)
- Firefox (last 2 versions)
- Safari (last 2 versions)
- Edge (last 2 versions)

## After Build

After building, clear Drupal caches:

```bash
ddev drush cr
```

