/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      // Tuscan Color Palette - Inspired by Florence
      colors: {
        // Terracotta - Warm reds/oranges (Florentine rooftops)
        terracotta: {
          50: '#fef6f3',
          100: '#fdeae4',
          200: '#fbd5c9',
          300: '#f7b5a1',
          400: '#f18b6c',
          500: '#e86f4a', // Primary terracotta
          600: '#d45432',
          700: '#b14227',
          800: '#923924',
          900: '#793323',
          950: '#41170e',
        },
        // Gold - Warm yellows (Tuscan sunlight)
        gold: {
          50: '#fdfbea',
          100: '#fbf5c6',
          200: '#f8e98f',
          300: '#f4d64f',
          400: '#efc222', // Primary gold
          500: '#dfaa12',
          600: '#c0840c',
          700: '#995f0d',
          800: '#7e4b13',
          900: '#6b3e16',
          950: '#3e1f08',
        },
        // Olive - Muted greens (Tuscan olive groves)
        olive: {
          50: '#f7f8f3',
          100: '#edf0e3',
          200: '#dae1c9',
          300: '#bfcba3',
          400: '#a3b27d',
          500: '#869960', // Primary olive
          600: '#6a7b4b',
          700: '#52603c',
          800: '#434e33',
          900: '#39422d',
          950: '#1d2316',
        },
        // Stone - Warm grays (Florentine stone)
        stone: {
          50: '#f9f8f6',
          100: '#f1efeb',
          200: '#e2ddd5',
          300: '#cfc7ba',
          400: '#b8ac9a',
          500: '#a69682', // Primary stone
          600: '#998570',
          700: '#806e5e',
          800: '#6a5b50',
          900: '#574c43',
          950: '#2e2722',
        },
        // Renaissance Purple (Medici accent)
        renaissance: {
          50: '#f9f5ff',
          100: '#f2e8ff',
          200: '#e6d5ff',
          300: '#d3b4fe',
          400: '#b884fc',
          500: '#9c55f7',
          600: '#8633ea', // Primary renaissance
          700: '#7122cf',
          800: '#5f1fa9',
          900: '#4f1c89',
          950: '#330a66',
        },
      },
      // Custom shadows - Tuscan warmth
      boxShadow: {
        'tuscan-sm': '0 1px 2px 0 rgba(139, 90, 43, 0.05)',
        'tuscan': '0 1px 3px 0 rgba(139, 90, 43, 0.1), 0 1px 2px -1px rgba(139, 90, 43, 0.1)',
        'tuscan-md': '0 4px 6px -1px rgba(139, 90, 43, 0.1), 0 2px 4px -2px rgba(139, 90, 43, 0.1)',
        'tuscan-lg': '0 10px 15px -3px rgba(139, 90, 43, 0.1), 0 4px 6px -4px rgba(139, 90, 43, 0.1)',
        'tuscan-xl': '0 20px 25px -5px rgba(139, 90, 43, 0.1), 0 8px 10px -6px rgba(139, 90, 43, 0.1)',
        'card-hover': '0 10px 40px -10px rgba(139, 90, 43, 0.15)',
        'inner-glow': 'inset 0 2px 4px 0 rgba(255, 255, 255, 0.06)',
      },
      // Custom border radius
      borderRadius: {
        'tuscan': '0.625rem', // 10px - slightly softer
        'tuscan-lg': '0.875rem', // 14px
        'tuscan-xl': '1.25rem', // 20px
      },
      // Background gradients
      backgroundImage: {
        'tuscan-gradient': 'linear-gradient(135deg, #f9f8f6 0%, #f1efeb 100%)',
        'terracotta-gradient': 'linear-gradient(135deg, #fef6f3 0%, #fdeae4 100%)',
        'gold-gradient': 'linear-gradient(135deg, #fdfbea 0%, #fbf5c6 100%)',
        'olive-gradient': 'linear-gradient(135deg, #f7f8f3 0%, #edf0e3 100%)',
        'renaissance-gradient': 'linear-gradient(135deg, #8633ea 0%, #5f1fa9 100%)',
        'header-gradient': 'linear-gradient(135deg, #d45432 0%, #b14227 100%)',
      },
      // Mobile-optimized animations
      animation: {
        'slide-in-left': 'slideInLeft 0.3s ease-out forwards',
        'slide-in-bottom': 'slideInBottom 0.3s ease-out forwards',
        'fade-in': 'fadeIn 0.2s ease-out forwards',
        'scale-in': 'scaleIn 0.2s ease-out forwards',
        'shimmer': 'shimmer 2s linear infinite',
      },
      keyframes: {
        slideInLeft: {
          '0%': { transform: 'translateX(-100%)', opacity: '0' },
          '100%': { transform: 'translateX(0)', opacity: '1' },
        },
        slideInBottom: {
          '0%': { transform: 'translateY(100%)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' },
        },
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        scaleIn: {
          '0%': { transform: 'scale(0.95)', opacity: '0' },
          '100%': { transform: 'scale(1)', opacity: '1' },
        },
        shimmer: {
          '0%': { backgroundPosition: '-200% 0' },
          '100%': { backgroundPosition: '200% 0' },
        },
      },
    },
  },
  plugins: [
    function({ addUtilities }) {
      addUtilities({
        '.scrollbar-hide': {
          '-ms-overflow-style': 'none',
          'scrollbar-width': 'none',
          '&::-webkit-scrollbar': {
            display: 'none',
          },
        },
      });
    },
  ],
} 