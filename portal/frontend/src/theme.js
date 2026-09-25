import { createTheme, responsiveFontSizes } from '@mui/material/styles';

// Create the MUI theme for the current portal color mode. The public homepage
// uses its own CSS and is kept in light mode by ColorModeProvider.
export default function createAppTheme(mode) {
  let theme = createTheme({
    palette: {
      mode,
      ...(mode === 'dark' && {
        primary: { main: '#78b7ff' },
        background: {
          default: '#101820',
          paper: '#18232d',
        },
      }),
    },
    components: {
      MuiCssBaseline: {
        styleOverrides: {
          // Let short routes such as Login use the full viewport width. A permanent
          // scrollbar gutter appears as a blank strip on the right on mobile.
          html: {
            scrollbarGutter: 'auto',
          },
        },
      },
      MuiTableCell: {
        styleOverrides: {
          head: ({ theme: currentTheme }) => ({
            backgroundColor: currentTheme.palette.mode === 'dark' ? '#263849' : '#2f6f8f',
            color: '#ffffff',
            fontWeight: 700,
            whiteSpace: 'nowrap',
          }),
        },
      },
      MuiTableSortLabel: {
        styleOverrides: {
          icon: { color: 'inherit', opacity: 1 },
          root: {
            '&.Mui-focusVisible': {
              outline: '3px solid #ffffff',
              outlineOffset: '2px',
            },
          },
        },
      },
    },
  });

  // Automatically scales heading/typography sizes down on smaller viewports.
  theme = responsiveFontSizes(theme);

  return theme;
}
