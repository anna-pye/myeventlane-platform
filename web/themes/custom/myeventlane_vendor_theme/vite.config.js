import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        styles: resolve(__dirname, 'src/scss/main.scss'),
        workspace: resolve(__dirname, 'src/scss/workspace.scss'),
        'vendor-wizard': resolve(__dirname, 'src/scss/vendor-wizard.scss'),
        main: resolve(__dirname, 'src/js/main.js'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name].js',
        assetFileNames: (assetInfo) => {
          // Rename CSS files appropriately based on entry point
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            // vendor-wizard entry produces vendor-wizard.css
            if (assetInfo.name === 'vendor-wizard.css' || assetInfo.name?.includes('vendor-wizard')) {
              return 'vendor-wizard.css';
            }
            // workspace entry produces workspace.css
            if (assetInfo.name === 'workspace.css' || assetInfo.name?.includes('workspace')) {
              return 'workspace.css';
            }
            // styles entry produces main.css
            return 'main.css';
          }
          return '[name].[ext]';
        },
      },
    },
    cssCodeSplit: true,
    sourcemap: true,
  },
  css: {
    preprocessorOptions: {
      scss: {
        // Use the modern API for Sass
        api: 'modern-compiler',
      },
    },
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
      // Stable path to public theme SCSS for shared partials (CI-safe; no ../../../).
      '@mel-theme': resolve(__dirname, '../myeventlane_theme/src/scss'),
    },
  },
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    origin: 'http://localhost:5173',
  },
});

