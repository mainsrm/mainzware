import { createTheme, responsiveFontSizes } from '@mui/material/styles';

// Central MUI theme; Bootstrap is used only for grid/utility CSS, not its own components.
let theme = createTheme({
  palette: {
    mode: 'light',
  },
});

// Automatically scales heading/typography sizes down on smaller viewports.
theme = responsiveFontSizes(theme);

export default theme;
