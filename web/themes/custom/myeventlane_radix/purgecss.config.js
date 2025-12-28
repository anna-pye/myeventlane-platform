/**
 * PurgeCSS Configuration for MyEventLane Radix Theme
 *
 * Removes unused CSS in production builds.
 * Bootstrap classes and MyEventLane classes (mel-*) are preserved.
 *
 * Usage:
 *   - Integrated into Vite build (via vite-plugin-purgecss)
 *   - Or run standalone: npx purgecss --config purgecss.config.js
 */

module.exports = {
  content: [
    // Theme templates
    './templates/**/*.twig',
    './src/**/*.scss',
    './src/**/*.js',
    
    // Custom modules (may reference theme classes)
    '../../modules/custom/**/*.twig',
    '../../modules/custom/**/*.php',
    
    // Radix base theme templates (for Bootstrap classes)
    '../../contrib/radix/**/*.twig',
  ],
  
  // Safelist: Always preserve these classes
  safelist: [
    // MyEventLane custom classes
    /^mel-/,
    
    // Bootstrap utility classes (commonly used)
    /^btn-/,
    /^bg-/,
    /^text-/,
    /^border-/,
    /^rounded-/,
    /^shadow-/,
    /^d-/,
    /^flex-/,
    /^grid-/,
    /^gap-/,
    /^p-/,
    /^m-/,
    /^w-/,
    /^h-/,
    
    // Bootstrap component classes
    /^card/,
    /^navbar/,
    /^dropdown/,
    /^modal/,
    /^form-/,
    /^alert/,
    /^badge/,
    /^list-group/,
    /^table/,
    
    // Drupal classes
    /^visually-hidden/,
    /^skip-link/,
    /^js-/,
    
    // Dynamic classes (may be added via JavaScript)
    /^is-/,
    /^has-/,
    /^active/,
    /^show/,
    /^collapsed/,
  ],
  
  // Default extractor for Tailwind-like classes
  defaultExtractor: (content) => content.match(/[\w-/:]+(?<!:)/g) || [],
  
  // Output options
  output: './dist/',
  
  // Font face rules
  fontFace: true,
  
  // Keyframes
  keyframes: true,
};
