/** @type {import('tailwindcss').Config} */
const { addDynamicIconSelectors } = require('@iconify/tailwind');

module.exports = {
  prefix: 'adt-tw-',
  important: '.adt-tw-wrapper', // Make all Tailwind utilities have higher specificity
  content: ['./src/**/*.{js,jsx,ts,tsx,vue,css}', './templates/**/*.php', './views/**/*.php'],
  theme: {
    extend: {
      fontFamily: {
        sans: [
          'Lato',
          'ui-sans-serif',
          'system-ui',
          '-apple-system',
          'BlinkMacSystemFont',
          'Segoe UI',
          'Roboto',
          'Helvetica Neue',
          'Arial',
          'sans-serif',
        ],
      },
      colors: {
        primary: {
          DEFAULT: '#E63D78',
          light: '#F17CA6', // 20% lighter
          dark: '#C42C61', // 20% darker
          50: '#FDF2F6',
          100: '#FAE6EE',
          200: '#F6C0D6',
          300: '#F29ABD',
          400: '#ED74A5',
          500: '#E63D78', // Your primary color
          600: '#D42F69',
          700: '#AD2755',
          800: '#871F41',
          900: '#601733',
          950: '#401024',
        },
      },
    },
  },
  plugins: [
    // Add Iconify dynamic icon selectors with options
    addDynamicIconSelectors(),
  ],
  corePlugins: {
    preflight: false, // Disable Tailwind's base reset styles
  },
};
