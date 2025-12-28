/**
 * Vite Configuration for MyEventLane Radix Theme
 *
 * Build commands (run from theme directory):
 *   cd web/themes/custom/myeventlane_radix
 *   ddev npm install
 *   ddev npm run build    # Production build
 *   ddev npm run dev      # Dev server with HMR
 *
 * The dev server runs on port 5174 (different from myeventlane_theme's 5173).
 * Production builds output to ./dist/ with stable filenames for Drupal.
 */

import { defineConfig } from 'vite';
import path from 'path';
// Note: PurgeCSS integration can be added via vite-plugin-purgecss if needed
// For now, PurgeCSS can be run separately in production builds

export default defineConfig({
  root: 'src',
  base: '/themes/custom/myeventlane_radix/dist/',

  server: {
    host: true,
    port: 5174,
    strictPort: true,
    origin: 'https://myeventlane.ddev.site:5174',
    hmr: {
      host: 'myeventlane.ddev.site',
      protocol: 'wss',
      port: 5174,
    },
  },

  css: {
    devSourcemap: true,
    preprocessorOptions: {
      scss: {
        // Use modern Sass API (silence deprecation warning)
        api: 'modern-compiler',
        silenceDeprecations: ['legacy-js-api'],
      },
    },
  },

  build: {
    outDir: '../dist',
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,

    rollupOptions: {
      input: {
        main: path.resolve(__dirname, 'src/scss/main.scss'),
      },
      output: {
        // Stable filename for Drupal libraries.yml compatibility
        assetFileNames: (assetInfo) => {
          if ((assetInfo.name || '').endsWith('.css')) {
            return '[name][extname]';
          }
          return '[name][extname]';
        },
      },
    },
    // Use esbuild minification (default)
    minify: 'esbuild',
  },
});
