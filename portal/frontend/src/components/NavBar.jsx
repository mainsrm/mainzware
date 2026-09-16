import { useEffect, useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import Tabs from '@mui/material/Tabs';
import Tab from '@mui/material/Tab';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import MenuRoundedIcon from '@mui/icons-material/MenuRounded';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { navigationFor } from '../navigation';

const TABLET_NAV_QUERY = '(min-width:768px)';
const TABLET_NAV_MEDIA = '@media (min-width:768px)';

export default function NavBar() {
  const [accountMenuAnchor, setAccountMenuAnchor] = useState(null);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const { user, status, logout } = useAuth();
  const links = navigationFor(user);
  const currentValue = links.some((link) => link.to === pathname) ? pathname : false;
  const isTabletOrWider = useMediaQuery(TABLET_NAV_QUERY);

  useEffect(() => {
    if (isTabletOrWider) {
      setMobileNavOpen(false);
    }
  }, [isTabletOrWider]);

  const handleNavClick = (to) => {
    setMobileNavOpen(false);
    navigate(to);
  };

  return (
    <Box sx={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', alignItems: 'center', gap: 1 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', [TABLET_NAV_MEDIA]: { display: 'none' } }}>
        <Button
          aria-label={mobileNavOpen ? 'Close navigation menu' : 'Open navigation menu'}
          aria-controls="main-nav-drawer"
          aria-expanded={mobileNavOpen}
          aria-haspopup="dialog"
          color="inherit"
          startIcon={<MenuRoundedIcon />}
          onClick={() => setMobileNavOpen((open) => !open)}
          size="small"
          sx={{
            display: 'inline-flex',
            [TABLET_NAV_MEDIA]: { display: 'none' },
            border: '1px solid',
            borderColor: 'divider',
            borderRadius: 2,
            minHeight: 40,
            px: 1.5,
            textTransform: 'none',
          }}
        >
          Menu
        </Button>
      </Box>

      <Box component="nav" sx={{ display: 'none', [TABLET_NAV_MEDIA]: { display: 'flex' }, flex: '1 1 auto', minWidth: 0, alignItems: 'center' }}>
        <Tabs value={currentValue} variant="scrollable" aria-label="Mainz World navigation">
          {links.map((link) => (
            <Tab key={link.to} label={link.label} value={link.to} component={NavLink} to={link.to} />
          ))}
        </Tabs>
        <Button component={NavLink} to="/" size="small" sx={{ ml: 1, textTransform: 'none' }}>
          MainzWare
        </Button>
      </Box>

      <Drawer
        anchor="left"
        open={mobileNavOpen}
        onClose={() => setMobileNavOpen(false)}
        ModalProps={{ keepMounted: true }}
        PaperProps={{
          sx: {
            width: 'min(86vw, 320px)',
            borderTopRightRadius: 12,
            borderBottomRightRadius: 12,
          },
        }}
      >
        <Box id="main-nav-drawer" role="dialog" aria-label="Main navigation" sx={{ p: 2 }}>
          <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1 }}>
            <Typography component="h2" variant="subtitle1" sx={{ fontWeight: 700 }}>
              Mainz World
            </Typography>
            <IconButton aria-label="Close navigation menu" onClick={() => setMobileNavOpen(false)} size="small">
              <CloseRoundedIcon fontSize="small" />
            </IconButton>
          </Stack>
          <List disablePadding sx={{ display: 'grid', gap: 0.5 }}>
            {links.map((link) => (
              <ListItemButton
                key={link.to}
                selected={pathname === link.to}
                onClick={() => handleNavClick(link.to)}
                sx={{
                  borderRadius: 2,
                  minHeight: 44,
                  '&.Mui-selected': {
                    bgcolor: 'primary.main',
                    color: 'primary.contrastText',
                  },
                  '&.Mui-selected:hover': {
                    bgcolor: 'primary.dark',
                  },
                }}
              >
                <ListItemText primary={link.label} primaryTypographyProps={{ fontWeight: pathname === link.to ? 700 : 500 }} />
              </ListItemButton>
            ))}
            <ListItemButton component={NavLink} to="/" onClick={() => setMobileNavOpen(false)} sx={{ borderRadius: 2, minHeight: 44 }}>
              <ListItemText primary="MainzWare" />
            </ListItemButton>
          </List>
        </Box>
      </Drawer>

      {status === 'ready' && (
        <Box sx={{ px: { xs: 0, md: 2 }, ml: 'auto' }}>
          {user ? (
            <>
              <Button
                aria-controls={accountMenuAnchor ? 'account-menu' : undefined}
                aria-expanded={Boolean(accountMenuAnchor)}
                aria-haspopup="menu"
                endIcon={<ExpandMoreIcon />}
                onClick={(event) => setAccountMenuAnchor(event.currentTarget)}
                size="small"
              >
                {user.username}
              </Button>
              <Menu
                anchorEl={accountMenuAnchor}
                id="account-menu"
                onClose={() => setAccountMenuAnchor(null)}
                open={Boolean(accountMenuAnchor)}
              >
                <MenuItem component={NavLink} onClick={() => setAccountMenuAnchor(null)} to="/profile">My Profile</MenuItem>
                <MenuItem onClick={async () => {
                  setAccountMenuAnchor(null);
                  await logout();
                  navigate('/login');
                }}>
                  Log Out
                </MenuItem>
              </Menu>
            </>
          ) : (
            <Button size="small" component={NavLink} to="/login">Log In</Button>
          )}
        </Box>
      )}
    </Box>
  );
}
