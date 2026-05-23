/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{astro,html,js,jsx,md,mdx,svelte,ts,tsx,vue}'],
  theme: {
    extend: {
      // Custom colors for KSSMI brand
      // PREFERRED: Use these named theme classes for consistency:
      //   text-havana-tortoise  (instead of text-[#5D4E37])
      //   bg-havana-tortoise    (instead of bg-[#5D4E37])
      //   text-havana-bronze    (instead of text-[#8B7355])
      //   bg-havana-bronze      (instead of bg-[#8B7355])
      //   border-havana-bronze  (instead of border-[#8B7355])
      // Note: Arbitrary values like text-[#5D4E37] still work fine
      // and are safe to use; the named classes are preferred for new code.
      colors: {
        'havana-bronze': '#8B7355',
        'havana-tortoise': '#5D4E37',
        'brand': {
          'gold': '#c9a66b',
          'brown': '#6B5340',
          'dark': '#2F221B',
          'deep': '#503629',
        },
      },
      fontFamily: {
        'sans': ['Plus Jakarta Sans', 'sans-serif'],
        'Manrope': ['Manrope', 'sans-serif'],
        'Raleway': ['Raleway', 'sans-serif'],
        'Playfair': ['"Playfair Display"', 'serif'],
        'arabic': ['Noto Sans Arabic', 'sans-serif'],
      },
    },
  },
  plugins: [
    require('@tailwindcss/typography'),
  ],
}
