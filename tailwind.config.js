/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./public/**/*.{php,html}'],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#6366f1',
          dark: '#4f46e5',
          navy: '#151a2e',
        },
        ink: {
          DEFAULT: '#0f1117',
          surface: '#161923',
          border: '#2a2f3d',
          muted: '#8b93a7',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
      boxShadow: {
        card: '0 1px 3px rgba(16,24,40,.08), 0 1px 2px rgba(16,24,40,.04)',
        'card-hover': '0 4px 12px rgba(16,24,40,.10)',
      },
      borderRadius: {
        xl: '0.75rem',
      },
    },
  },
  plugins: [],
}
