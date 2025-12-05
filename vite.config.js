// vite.config.js
import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
  root: '.',
  base: './',
  server: {
    port: 3000,
    strictPort: true,
    watch: {
      usePolling: true
    }
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        main: '/var/www/html/web/themes/custom/myeventlane_theme/src/js/main.js',
      },
      output: {
        entryFileNames: '[name].js',
        assetFileNames: '[name].css',
      }
    }
  },
  css: {
    preprocessorOptions: {
      scss: {
        additionalData: ''
      }
    }
  }
});