import { createTheme, responsiveFontSizes } from '@mui/material/styles';

// Central MUI theme; Bootstrap is used only for grid/utility CSS, not its own components.
let theme = createTheme({
  palette: {
    mode: 'light',
  },
  components: {
    MuiCssBaseline: {
      styleOverrides: {
        // Reserve the scrollbar gutter so short/long routes keep the same viewport
        // width; otherwise the full-width hero image rescales on every navigation.
        html: {
          scrollbarGutter: 'stable',
        },
      },
    },
  },
});

// Automatically scales heading/typography sizes down on smaller viewports.
theme = responsiveFontSizes(theme);

export default theme;
