import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { NavLink, Outlet, useLocation } from 'react-router-dom';

const TOOLS = [
  { path: 'what-da-money', label: 'What Da Money' },
  { path: 'where-da-money', label: 'Where Da Money' },
  { path: 'snowball', label: 'Debt Snowball' },
];

export default function BudgetApp() {
  const { pathname } = useLocation();

  return (
    <>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ xs: 'flex-start', sm: 'baseline' }} sx={{ mb: 1.5 }}>
        <Typography variant="h4" component="h1">
          Budget App
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Budgets · Spending · Debt payoff
        </Typography>
      </Stack>

      <Paper component="nav" aria-label="Budget tools" variant="outlined" sx={{ mb: 2, p: 0.75 }}>
        <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap">
          {TOOLS.map((tool) => {
            const to = `/budget/${tool.path}`;
            const active = pathname === to;
            return (
              <Button
                key={tool.path}
                component={NavLink}
                to={to}
                variant={active ? 'contained' : 'text'}
                color={active ? 'primary' : 'inherit'}
                sx={{ textTransform: 'none', fontWeight: active ? 700 : 500 }}
              >
                {tool.label}
              </Button>
            );
          })}
        </Stack>
      </Paper>

      <Box>
        <Outlet />
      </Box>
    </>
  );
}
