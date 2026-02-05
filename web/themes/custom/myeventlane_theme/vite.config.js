/**
 * Vite Configuration for MyEventLane Theme
 *
 * Build commands (run from theme directory):
 *   cd web/themes/custom/myeventlane_theme
 *   ddev npm install
 *   ddev npm run build    # Production build
 *   ddev npm run dev      # Dev server with HMR
 *
 * The dev server runs on port 5173 and proxies HMR to the DDEV site.
 * Production builds output to ./dist/ with stable filenames for Drupal.
 */

import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
  root: 'src',
  base: '/themes/custom/myeventlane_theme/dist/',

  server: {
    host: true,
    port: 5173,
    strictPort: true,
    origin: 'https://myeventlane.ddev.site:5173',
    hmr: {
      host: 'myeventlane.ddev.site',
      protocol: 'wss',
      port: 5173,
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
        main: path.resolve(__dirname, 'src/js/main.js'),
        'account-dropdown': path.resolve(__dirname, 'src/js/account-dropdown.js'),
        'front-pie': path.resolve(__dirname, 'src/js/front-pie.js'),
        front: path.resolve(__dirname, 'src/scss/front.scss'),
      },
      output: {
        // Cache-busting filenames. Drupal libraries are rewritten at runtime
        // using dist/.vite/manifest.json (hook_library_info_alter).
        entryFileNames: 'assets/[name].[hash].js',
        chunkFileNames: 'assets/[name].[hash].js',
        assetFileNames: 'assets/[name].[hash][extname]',
      },
      // Ensure we don't tree-shake or bundle external Commerce/Stripe JS
      external: [],
      // Preserve global variables that Commerce payment JS needs
      preserveEntrySignatures: 'strict',
    },
    // Use esbuild minification (default) - preserves Commerce payment JS
    minify: 'esbuild',
  },
});
