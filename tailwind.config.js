/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{astro,html,js,jsx,md,mdx,svelte,ts,tsx,vue}'],
  theme: {
    extend: {
      // Custom colors for KSSMI brand
      colors: {
        'havana-bronze': '#8B7355',
        'havana-tortoise': '#5D4E37',
      },
      fontFamily: {
        'Manrope': ['Manrope', 'sans-serif'],
        'Plus_Jakarta_Sans': ['Plus Jakarta Sans', 'sans-serif'],
        'Raleway': ['Raleway', 'sans-serif'],
        'Playfair': ['"Playfair Display"', 'serif'],
      },
      // Custom breakpoints (sm: 640px for earlier desktop display)
      screens: {
        'sm': '640px',
        'md': '768px',
        'lg': '1024px',
        'xl': '1280px',
      }
    },
  },
  plugins: [],
}
